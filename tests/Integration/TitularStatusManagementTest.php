<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/titular_management.php';
require_once dirname(__DIR__, 2) . '/config/titular_read.php';

$oldStoragePath = getenv('IEPR_PRIVATE_STORAGE_PATH');
$testStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iepr-titular-management-' . bin2hex(random_bytes(5));
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $testStoragePath);

$previousAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $account = batchImportStagingTestCreateTenant($pdo, $suffix);
    $fixture = $account['fixture'];
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')
        ->execute(['plan' => $account['plan_id'], 'feature' => 'titular.editar']);
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')
        ->execute(['plan' => $account['plan_id'], 'feature' => 'titular.ver']);
    motorPermissoesCacheLimpar($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, ativo, acesso_app_liberado, criado_em) '
        . 'VALUES (:tenant, :name, 1, 1, :created)'
    );
    $insert->execute([
        'tenant' => $fixture['tenant_id'], 'name' => 'Status Test ' . $suffix, 'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $titularId = (int) $pdo->lastInsertId();
    $now = strtotime('2026-11-25 12:34:56 UTC');

    $changed = titularManagementSetStatus(
        $pdo,
        $account['context'],
        $account['identity'],
        $titularId,
        false,
        $now
    );
    $rowQuery = $pdo->prepare('SELECT ativo, acesso_app_liberado, inativado_em FROM titulares WHERE id = :id');
    $rowQuery->execute(['id' => $titularId]);
    $row = $rowQuery->fetch(PDO::FETCH_ASSOC);
    $auditQuery = $pdo->prepare(
        "SELECT ator_usuario_id, acao, entidade_id, antes_json, depois_json FROM auditoria_processos "
        . "WHERE tenant_id = :tenant AND entidade = 'titulares' AND entidade_id = :id ORDER BY id DESC LIMIT 1"
    );
    $auditQuery->execute(['tenant' => $fixture['tenant_id'], 'id' => $titularId]);
    $audit = $auditQuery->fetch(PDO::FETCH_ASSOC);
    $listing = titularListar($pdo, $account['context'], $account['identity'], ['situacao' => 'inativo']);
    if (!is_array($row) || (int) $row['ativo'] !== 0 || (int) $row['acesso_app_liberado'] !== 0
        || $row['inativado_em'] !== '2026-11-25 12:34:56'
        || ($changed['situacao'] ?? null) !== 'inativo'
        || ($changed['inativado_em'] ?? null) !== '2026-11-25 12:34:56'
        || $listing['total'] !== 1 || ($listing['items'][0]['inativado_em'] ?? null) !== '2026-11-25 12:34:56'
        || !is_array($audit) || (int) $audit['ator_usuario_id'] !== $account['owner_id']
        || $audit['acao'] !== 'titular.deactivated' || (int) $audit['entidade_id'] !== $titularId
        || str_contains((string) $audit['antes_json'], 'Status Test')
        || str_contains((string) $audit['depois_json'], 'Status Test')) {
        throw new RuntimeException($driver . ': deactivation must set a precise timestamp, revoke portal access and audit only the status change.');
    }

    $key = base64_decode((string) getenv('IEPR_ANONYMIZATION_KEY'), true);
    if (!is_string($key)) throw new RuntimeException('The anonymization test key was invalid.');
    $retentionId = hash_hmac('sha256', 'retention:' . $fixture['tenant_id'] . ':' . $titularId . ':2026-11-25 12:34:56', $key);
    $dueAt = strtotime('2027-11-25 12:34:56 UTC');
    titularAnonymizationQueueExpiredInactive($pdo, $key, gmdate('Y-m-d H:i:s', $dueAt), 10);
    $requestQuery = $pdo->prepare(
        'SELECT estado, referencia_titular_cifrada FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id'
    );
    $requestQuery->execute(['id' => $retentionId]);
    $queuedRequest = $requestQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($queuedRequest) || $queuedRequest['estado'] !== 'pendente') {
        throw new RuntimeException($driver . ': an inactive holder past 12 months must have a pending retention request.');
    }
    titularManagementSetStatus($pdo, $account['context'], $account['identity'], $titularId, true, $dueAt + 1);
    $reactivatedQuery = $pdo->prepare('SELECT nome_completo, ativo, acesso_app_liberado, inativado_em FROM titulares WHERE id = :id');
    $reactivatedQuery->execute(['id' => $titularId]);
    $reactivated = $reactivatedQuery->fetch(PDO::FETCH_ASSOC);
    $requestQuery->execute(['id' => $retentionId]);
    $cancelledRequest = $requestQuery->fetch(PDO::FETCH_ASSOC);
    $afterReactivate = titularAnonymizationProcessDue($pdo, $dueAt + 2);
    $reactivatedQuery->execute(['id' => $titularId]);
    $afterWorker = $reactivatedQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($reactivated) || (int) $reactivated['ativo'] !== 1
        || (int) $reactivated['acesso_app_liberado'] !== 0 || $reactivated['inativado_em'] !== '2026-11-25 12:34:56'
        || !is_array($cancelledRequest) || $cancelledRequest['estado'] !== 'cancelada'
        || $cancelledRequest['referencia_titular_cifrada'] !== ''
        || ($afterReactivate['processed'] ?? null) !== 0 || !is_array($afterWorker)
        || $afterWorker['nome_completo'] !== 'Status Test ' . $suffix || (int) $afterWorker['ativo'] !== 1) {
        throw new RuntimeException($driver . ': reactivation must cancel a queued 12-month retention request without restoring portal access.');
    }

    $nextInactiveAt = $dueAt + 10;
    titularManagementSetStatus($pdo, $account['context'], $account['identity'], $titularId, false, $nextInactiveAt);
    titularManagementSetStatus($pdo, $account['context'], $account['identity'], $titularId, true, $nextInactiveAt + 1);
    $lateAt = strtotime(titularAnonymizationTwelveMonthCutoff($nextInactiveAt) . ' UTC') + 1;
    $staleRequestId = hash_hmac(
        'sha256',
        'retention:' . $fixture['tenant_id'] . ':' . $titularId . ':' . gmdate('Y-m-d H:i:s', $nextInactiveAt),
        $key
    );
    $insertStale = $pdo->prepare(
        "INSERT INTO solicitacoes_anonimizacao (solicitacao_id, referencia_titular_cifrada, origem, estado, solicitado_em, prazo_em) "
        . "VALUES (:id, :reference, 'retencao_12m', 'pendente', :requested, :deadline)"
    );
    $insertStale->execute([
        'id' => $staleRequestId,
        'reference' => titularAnonymizationEncryptReference($fixture['tenant_id'], $titularId, $key),
        'requested' => gmdate('Y-m-d H:i:s', $lateAt),
        'deadline' => gmdate('Y-m-d H:i:s', $lateAt),
    ]);
    $staleWorker = titularAnonymizationProcessDue($pdo, $lateAt + 1);
    $requestQuery->execute(['id' => $staleRequestId]);
    $raceRequest = $requestQuery->fetch(PDO::FETCH_ASSOC);
    $reactivatedQuery->execute(['id' => $titularId]);
    $raceHolder = $reactivatedQuery->fetch(PDO::FETCH_ASSOC);
    if (($staleWorker['processed'] ?? null) !== 1 || !is_array($raceRequest)
        || $raceRequest['estado'] !== 'cancelada' || $raceRequest['referencia_titular_cifrada'] !== ''
        || !is_array($raceHolder) || (int) $raceHolder['ativo'] !== 1
        || $raceHolder['nome_completo'] !== 'Status Test ' . $suffix) {
        throw new RuntimeException($driver . ': a retention request racing with reactivation must never anonymize an active member: '
            . json_encode([$staleWorker, $raceRequest, $raceHolder]));
    }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng') || !function_exists('imagejpeg')) {
        throw new RuntimeException('GD image support is required for individual photo management.');
    }
    $photoHolderInsert = $pdo->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, ativo, acesso_app_liberado, criado_em) '
        . 'VALUES (:tenant, :name, 1, 0, :created)'
    );
    $photoHolderInsert->execute([
        'tenant' => $fixture['tenant_id'], 'name' => 'Photo Test ' . $suffix, 'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $photoHolderId = (int) $pdo->lastInsertId();
    $image = imagecreatetruecolor(3, 2);
    ob_start();
    imagepng($image);
    $pngBytes = (string) ob_get_clean();
    imagedestroy($image);
    $photoResult = titularManagementReplacePhoto(
        $pdo,
        $account['context'],
        $account['identity'],
        $photoHolderId,
        $pngBytes
    );
    $storedPhotoQuery = $pdo->prepare('SELECT foto_url FROM titulares WHERE id = :id AND tenant_id = :tenant');
    $storedPhotoQuery->execute(['id' => $photoHolderId, 'tenant' => $fixture['tenant_id']]);
    $storedPhotoKey = $storedPhotoQuery->fetchColumn();
    $storedPhotoPath = is_string($storedPhotoKey) ? titularManagementResolvePhotoPath($fixture['tenant_id'], $storedPhotoKey) : '';
    if (!is_array($photoResult) || !is_string($storedPhotoKey)
        || preg_match('~^private://' . $fixture['tenant_id'] . '/[a-f0-9]{48}\.png$~D', $storedPhotoKey) !== 1
        || !is_file($storedPhotoPath) || file_get_contents($storedPhotoPath) !== $pngBytes
        || isset($photoResult['public_url']) || ($photoResult['updated'] ?? null) !== true) {
        throw new RuntimeException($driver . ': an individual photo must be stored privately and linked to the scoped holder.');
    }
    $jpegImage = imagecreatetruecolor(3, 2);
    ob_start();
    imagejpeg($jpegImage, null, 85);
    $jpegBytes = (string) ob_get_clean();
    imagedestroy($jpegImage);
    titularManagementReplacePhoto($pdo, $account['context'], $account['identity'], $photoHolderId, $jpegBytes);
    $storedPhotoQuery->execute(['id' => $photoHolderId, 'tenant' => $fixture['tenant_id']]);
    $jpegPhotoKey = $storedPhotoQuery->fetchColumn();
    $jpegPhotoPath = is_string($jpegPhotoKey) ? titularManagementResolvePhotoPath($fixture['tenant_id'], $jpegPhotoKey) : '';
    if (!is_string($jpegPhotoKey) || preg_match('~^private://' . $fixture['tenant_id'] . '/[a-f0-9]{48}\.jpg$~D', $jpegPhotoKey) !== 1
        || !is_file($jpegPhotoPath) || getimagesize($jpegPhotoPath)['mime'] !== 'image/jpeg' || is_file($storedPhotoPath)) {
        throw new RuntimeException($driver . ': an individual JPEG photo must be accepted, privately stored, and replace the previous image.');
    }
    try {
        titularManagementReplacePhoto($pdo, $account['context'], $account['identity'], $photoHolderId, 'not an image');
        throw new RuntimeException($driver . ': invalid photo bytes must be rejected.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'TITULAR_PHOTO_INVALID') throw $exception;
    }
    if (!is_file($jpegPhotoPath)) {
        throw new RuntimeException($driver . ': rejecting an invalid replacement must preserve the current photo.');
    }
    titularManagementReplacePhoto($pdo, $account['context'], $account['identity'], $photoHolderId, $pngBytes);
    if (is_file($jpegPhotoPath)) {
        throw new RuntimeException($driver . ': replacing a photo must remove the previous private file.');
    }
}

if (is_string($oldStoragePath)) putenv('IEPR_PRIVATE_STORAGE_PATH=' . $oldStoragePath);
else putenv('IEPR_PRIVATE_STORAGE_PATH');
if (is_dir($testStoragePath)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStoragePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($testStoragePath);
}

if (is_string($previousAnonymizationKey)) {
    putenv('IEPR_ANONYMIZATION_KEY=' . $previousAnonymizationKey);
} else {
    putenv('IEPR_ANONYMIZATION_KEY');
}

fwrite(STDOUT, "PASS: titular deactivation stores its date, blocks portal access and minimizes audit data.\n");
