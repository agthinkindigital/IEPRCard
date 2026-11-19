<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (getenv('CI') === 'true') {
        throw new RuntimeException('CI must provide PDO SQLite for the issuance quota race test.');
    }
    fwrite(STDOUT, "SKIP: PDO SQLite is unavailable for issuance quota race test.\n");
    return 'skip';
}

$suffix = bin2hex(random_bytes(5));
$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-issuance-race-' . $suffix . '.sqlite';
$barrier = $databasePath . '.start';
$resultPaths = [$databasePath . '.a.json', $databasePath . '.b.json'];
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$planId = null;
$ownerId = null;
$designId = null;
$sourceLotId = null;
$processes = [];
$pipes = [];
try {
    $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')->execute(['name' => 'Issuance race ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')
        ->execute(['plan' => $planId, 'feature' => 'carteirinha.gerar']);
    $pdo->prepare('UPDATE tenants SET plano_id = :plan, limite_carteirinhas = 1, consumo_carteirinhas = 0 WHERE id = :tenant')
        ->execute(['plan' => $planId, 'tenant' => $fixture['tenant_id']]);
    $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'role' => 'titular_cliente', 'name' => 'Quota race owner',
        'email' => 'issuance-race-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
    ]);
    $ownerId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, ativo, criado_em) '
        . 'VALUES (:tenant, :name, :orientation, 1, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'name' => 'Quota race design', 'orientation' => 'horizontal',
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $designId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, origem_tipo, total_registros, '
        . 'processados_sucesso, status_lote, criado_em, finalizado_em) '
        . 'VALUES (:tenant, NULL, :user, :origin, 1, 1, :status, :created, :finished)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'user' => $ownerId, 'origin' => 'csv', 'status' => 'concluido',
        'created' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s'),
    ]);
    $sourceLotId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, lote_origem_id, ativo, criado_em) '
        . 'VALUES (:tenant, NULL, :name, :source, 1, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'name' => 'Quota race member', 'source' => $sourceLotId,
        'created' => gmdate('Y-m-d H:i:s'),
    ]);

    $workerPath = __DIR__ . '/CardIssuanceQuotaRaceWorker.php';
    $phpCommand = [PHP_BINARY];
    $phpCommand[] = '-d';
    $phpCommand[] = 'extension_dir=' . ini_get('extension_dir');
    foreach (['pdo_sqlite', 'sqlite3'] as $extension) {
        $phpCommand[] = '-d';
        $phpCommand[] = 'extension=' . $extension;
    }
    foreach (['race-request-key-0001', 'race-request-key-0002'] as $index => $key) {
        $process = proc_open([
            ...$phpCommand, $workerPath, $databasePath, (string) $fixture['tenant_id'], (string) $ownerId,
            (string) $sourceLotId, (string) $designId, $key, $barrier, $resultPaths[$index],
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, __DIR__);
        if (!is_resource($process)) {
            throw new RuntimeException('Issuance quota race worker could not start.');
        }
        $processes[] = $process;
        $pipes[] = $workerPipes;
    }
    file_put_contents($barrier, 'go');
    foreach ($processes as $index => $process) {
        fclose($pipes[$index][0]);
        $stdout = stream_get_contents($pipes[$index][1]);
        $stderr = stream_get_contents($pipes[$index][2]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Issuance quota race worker failed: ' . $stdout . ' ' . $stderr);
        }
    }
    $results = array_map(static function (string $path): array {
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }, $resultPaths);
    $createdCount = count(array_filter($results, static fn (array $result): bool => ($result['result'] ?? null) === 'created'));
    $quotaFailures = count(array_filter($results, static fn (array $result): bool => ($result['result'] ?? null) === 'COTA_EXCEDIDA'));
    $jobs = (int) $pdo->query("SELECT COUNT(*) FROM lotes_importacao WHERE tenant_id = " . (int) $fixture['tenant_id'] . " AND origem_tipo = 'emissao'")->fetchColumn();
    $reservations = (int) $pdo->query('SELECT COUNT(*) FROM reservas_cota WHERE tenant_id = ' . (int) $fixture['tenant_id'] . " AND estado = 'reservada'")->fetchColumn();
    $quota = $pdo->prepare('SELECT consumo_carteirinhas FROM tenants WHERE id = :tenant');
    $quota->execute(['tenant' => $fixture['tenant_id']]);
    if ($createdCount !== 1 || $quotaFailures !== 1 || $jobs !== 1 || $reservations !== 1 || (int) $quota->fetchColumn() !== 1) {
        throw new RuntimeException('Concurrent issuance requests must reserve the single available quota exactly once: '
            . json_encode(['results' => $results, 'jobs' => $jobs, 'reservations' => $reservations], JSON_THROW_ON_ERROR));
    }
    fwrite(STDOUT, "PASS: concurrent issuance requests cannot exceed the Sede quota.\n");
} finally {
    foreach ($processes as $index => $process) {
        if (is_resource($process)) {
            foreach ($pipes[$index] ?? [] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_terminate($process);
            proc_close($process);
        }
    }
    if (isset($fixture['tenant_id'])) {
        $pdo->prepare('DELETE FROM lotes_importacao WHERE lote_origem_id = :source')->execute(['source' => $sourceLotId]);
        $pdo->prepare('DELETE FROM titulares WHERE lote_origem_id = :source')->execute(['source' => $sourceLotId]);
        $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :source')->execute(['source' => $sourceLotId]);
        if ($ownerId !== null) { $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]); }
        if ($designId !== null) { $pdo->prepare('DELETE FROM designs_carteirinha WHERE id = :id')->execute(['id' => $designId]); }
        tenantTestCleanup($pdo, $fixture);
    }
    if ($planId !== null) {
        $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
        $pdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
    }
    unset($pdo);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $barrier, ...$resultPaths] as $path) {
        if (is_file($path)) { @unlink($path); }
    }
}

return 'pass';
