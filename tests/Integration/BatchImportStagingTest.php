<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/titular_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_parser.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_staging.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-stage-' . $suffix;
    $uploadDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-stage-upload-' . $suffix;
    $previousStoragePath = getenv('IEPR_PRIVATE_STORAGE_PATH');
    mkdir($uploadDirectory, 0700, true);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    $fixture = null;
    $planId = null;
    $ownerId = null;
    $createdLotIds = [];

    try {
        $testTenant = batchImportStagingTestCreateTenant($pdo, $suffix);
        $fixture = $testTenant['fixture'];
        $planId = $testTenant['plan_id'];
        $ownerId = $testTenant['owner_id'];
        $context = $testTenant['context'];
        $identity = $testTenant['identity'];
        $csvPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'php-upload-' . $suffix . '.tmp';
        $clientFileName = 'titulares-originais-' . $suffix . '.csv';
        file_put_contents($csvPath, "nome;whatsapp\nAna Import;(11) 90000-0000\n");

        $staged = batchImportStageFile($pdo, $context, $identity, $fixture['tenant_id'], null, $csvPath, $clientFileName, 1_800_000_000);
        $lotId = $staged['lote_id'];
        $createdLotIds[] = $lotId;
        $lotQuery = $pdo->prepare(
            'SELECT tenant_id, subcliente_id, usuario_id, origem_tipo, arquivo_original_url, '
            . 'colunas_detectadas_json, total_registros, status_lote FROM lotes_importacao WHERE id = :id'
        );
        $lotQuery->execute(['id' => $lotId]);
        $lot = $lotQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lot)) {
            throw new RuntimeException($driver . ': a staged import lot must be persisted.');
        }
        $storedFile = batchImportStagingResolveStoredPath($fixture['tenant_id'], (string) ($lot['arquivo_original_url'] ?? ''));
        if ($staged['preview'][0]['nome_completo'] !== 'Ana Import'
            || $staged['preview'][0]['whatsapp'] !== '+5511900000000'
            || array_key_exists('rows', $staged)
            || (int) $lot['tenant_id'] !== $fixture['tenant_id'] || $lot['subcliente_id'] !== null
            || (int) $lot['usuario_id'] !== $ownerId || $lot['origem_tipo'] !== 'csv'
            || str_contains((string) $lot['arquivo_original_url'], $clientFileName)
            || str_contains((string) $lot['colunas_detectadas_json'], 'Ana Import')
            || json_decode((string) $lot['colunas_detectadas_json'], true) !== ['nome_completo', 'whatsapp']
            || (int) $lot['total_registros'] !== 1 || $lot['status_lote'] !== 'aguardando_mapa'
            || !is_string($storedFile) || !is_file($storedFile)) {
            throw new RuntimeException($driver . ': staging must persist only scoped metadata and a private opaque file key.');
        }
        $outsidePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'outside.csv';
        $backupPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'staged-backup.csv';
        batchImportStagingTestAssertNoSymlinkFollowed(
            $fixture['tenant_id'], (string) $lot['arquivo_original_url'], (string) $storedFile, $outsidePath, $backupPath, $driver
        );
        batchImportStagingAssertFailure(
            static fn() => batchImportStageUploadedFile(
                $pdo, $context, $identity, $fixture['tenant_id'], null,
                ['tmp_name' => $csvPath, 'name' => $clientFileName, 'error' => UPLOAD_ERR_OK]
            ),
            'IMPORT_FILE_INVALID'
        );

        $foreignContext = new TenantContext('cliente', $fixture['tenant_id'] + 1000, null, []);
        batchImportStagingAssertFailure(
            static fn() => batchImportStageFile(
                $pdo, $foreignContext, $identity, $fixture['tenant_id'], null, $csvPath, $clientFileName
            ),
            'TITULAR_CREATE_DENIED'
        );

        $invalidPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'malicioso.txt';
        file_put_contents($invalidPath, 'not an import');
        batchImportStagingAssertFailure(
            static fn() => batchImportStageFile(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $invalidPath, basename($invalidPath)
            ),
            'IMPORT_FORMAT_UNSUPPORTED'
        );
        $oversizePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'oversize.csv';
        batchImportStagingTestCreateOversizeFile($oversizePath, $driver);
        batchImportStagingAssertFailure(
            static fn() => batchImportStageFile(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $oversizePath, basename($oversizePath)
            ),
            'IMPORT_FILE_SIZE_INVALID'
        );
        $malformedPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'malformed.json';
        file_put_contents($malformedPath, '{broken');
        batchImportStagingAssertFailure(
            static fn() => batchImportStageFile(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $malformedPath, basename($malformedPath)
            ),
            'IMPORT_JSON_INVALID'
        );
        $fileCount = glob($storagePath . DIRECTORY_SEPARATOR . $fixture['tenant_id'] . DIRECTORY_SEPARATOR . '*') ?: [];
        if (count($fileCount) !== 1) {
            throw new RuntimeException($driver . ': fatal parser errors must remove the staged source file.');
        }
        $configuredStoragePath = getenv('IEPR_PRIVATE_STORAGE_PATH');
        putenv('IEPR_PRIVATE_STORAGE_PATH=' . dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public');
        batchImportStagingAssertFailure(
            static fn() => batchImportStageFile(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $csvPath, $clientFileName
            ),
            'IMPORT_STORAGE_UNAVAILABLE'
        );
        putenv('IEPR_PRIVATE_STORAGE_PATH=' . $configuredStoragePath);

        $trigger = 'import_staging_insert_fail';
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            $pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON lotes_importacao BEGIN SELECT RAISE(ABORT, 'test'); END");
        } else {
            $pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON lotes_importacao FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test'");
        }
        $rollbackPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'rollback.json';
        file_put_contents($rollbackPath, '[{"nome":"Rollback"}]');
        try {
            batchImportStagingAssertFailure(
                static fn() => batchImportStageFile(
                    $pdo, $context, $identity, $fixture['tenant_id'], null, $rollbackPath, basename($rollbackPath)
                ),
                'IMPORT_STAGE_FAILED'
            );
        } finally {
            $pdo->exec("DROP TRIGGER {$trigger}");
        }
        $remainingFiles = glob($storagePath . DIRECTORY_SEPARATOR . $fixture['tenant_id'] . DIRECTORY_SEPARATOR . '*') ?: [];
        if (count($remainingFiles) !== 1) {
            throw new RuntimeException($driver . ': a failed lot insert must remove its staged file.');
        }

        $jsonPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'php-json-upload-' . $suffix . '.tmp';
        file_put_contents($jsonPath, '[{"nome":"JSON staging"}]');
        $jsonStaged = batchImportStageFile(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $jsonPath, 'titulares.json'
        );
        $createdLotIds[] = $jsonStaged['lote_id'];
        if ($jsonStaged['preview'][0]['nome_completo'] !== 'JSON staging') {
            throw new RuntimeException($driver . ': JSON uploads must be parsed using the client format extension.');
        }
        batchImportStagingCancel(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $jsonStaged['lote_id']
        );

        $invalidPhonePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'invalid-phone.csv';
        file_put_contents($invalidPhonePath, "nome;whatsapp\nAna Invalid;(11) 80000-0000\n");
        $invalidPhoneLot = batchImportStageFile(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $invalidPhonePath, basename($invalidPhonePath)
        );
        $createdLotIds[] = $invalidPhoneLot['lote_id'];
        $errorsJson = json_encode($invalidPhoneLot['errors'], JSON_THROW_ON_ERROR);
        if (count($invalidPhoneLot['errors']) !== 1 || !str_contains($errorsJson, 'IMPORT_PHONE_INVALID')
            || str_contains($errorsJson, '80000') || str_contains($errorsJson, 'Ana Invalid')) {
            throw new RuntimeException($driver . ': preview validation errors must identify the field without PII.');
        }
        batchImportStagingCancel(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $invalidPhoneLot['lote_id']
        );

        batchImportStagingAssertFailure(
            static fn() => batchImportStagingCancel(
                $pdo, $foreignContext, $identity, $fixture['tenant_id'], null, $lotId
            ),
            'IMPORT_LOT_NOT_FOUND'
        );
        if (!is_file((string) $storedFile)) {
            throw new RuntimeException($driver . ': a cross-scope cancel request must not remove a source file.');
        }
        batchImportStagingCancel($pdo, $context, $identity, $fixture['tenant_id'], null, $lotId);
        $lotQuery = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $lotQuery->execute(['id' => $lotId]);
        if ($lotQuery->fetchColumn() !== 'cancelado' || is_file((string) $storedFile)) {
            throw new RuntimeException($driver . ': cancellation must be scoped and remove the private source.');
        }

        $expiredPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'expired.csv';
        file_put_contents($expiredPath, "nome\nExpired Import\n");
        $expired = batchImportStageFile(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $expiredPath, basename($expiredPath), 1_700_000_000
        );
        $createdLotIds[] = $expired['lote_id'];
        $expiredQuery = $pdo->prepare('SELECT arquivo_original_url FROM lotes_importacao WHERE id = :id');
        $expiredQuery->execute(['id' => $expired['lote_id']]);
        $expiredFile = batchImportStagingResolveStoredPath($fixture['tenant_id'], (string) $expiredQuery->fetchColumn());
        $activePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'active.csv';
        file_put_contents($activePath, "nome\nActive Import\n");
        $active = batchImportStageFile(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $activePath, basename($activePath), 1_700_000_000
        );
        $createdLotIds[] = $active['lote_id'];
        $emissionInsert = $pdo->prepare(
            "INSERT INTO lotes_importacao (tenant_id, origem_tipo, total_registros, status_lote, criado_em) "
            . "VALUES (:tenant, 'emissao', 1, 'na_fila', :created)"
        );
        $emissionInsert->execute(['tenant' => $fixture['tenant_id'], 'created' => gmdate('Y-m-d H:i:s', 1_700_000_000)]);
        $emissionJobId = (int) $pdo->lastInsertId();
        $createdLotIds[] = $emissionJobId;
        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'processando' WHERE id = :id")
            ->execute(['id' => $active['lote_id']]);
        $activeQuery = $pdo->prepare('SELECT arquivo_original_url FROM lotes_importacao WHERE id = :id');
        $activeQuery->execute(['id' => $active['lote_id']]);
        $activeFile = batchImportStagingResolveStoredPath($fixture['tenant_id'], (string) $activeQuery->fetchColumn());
        batchImportStagingAssertFailure(
            static fn() => batchImportStagingCancel($pdo, $context, $identity, $fixture['tenant_id'], null, $active['lote_id']),
            'IMPORT_LOT_NOT_CANCELLABLE'
        );
        $cleanupAt = 1_700_000_000 + 86400;
        if (batchImportStagingCleanupExpired($pdo, $cleanupAt - 1) !== 0
            || !is_string($expiredFile) || !is_file($expiredFile)
            || batchImportStagingCleanupExpired($pdo, $cleanupAt) !== 1
            || !is_string($expiredFile) || is_file($expiredFile) || !is_string($activeFile) || !is_file($activeFile)) {
            throw new RuntimeException($driver . ': cleanup must expire stale pending lots and leave processing lots intact.');
        }
        $expiredStatus = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $expiredStatus->execute(['id' => $expired['lote_id']]);
        if ($expiredStatus->fetchColumn() !== 'expirado') {
            throw new RuntimeException($driver . ': an expired import lot must expose its terminal state.');
        }
        $emissionStatus = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $emissionStatus->execute(['id' => $emissionJobId]);
        if ($emissionStatus->fetchColumn() !== 'na_fila') {
            throw new RuntimeException($driver . ': import cleanup must never expire a queued card issuance job.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' private import staging, scoped cancellation and expiry cleanup.' . "\n");
    } finally {
        foreach ($createdLotIds as $lotId) {
            $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :id')->execute(['id' => $lotId]);
        }
        if ($fixture !== null) {
            $pdo->prepare('DELETE FROM lotes_importacao WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            if ($ownerId !== null) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]);
            }
            tenantTestCleanup($pdo, $fixture);
        }
        if ($planId !== null) {
            $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
            $pdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
        }
        batchImportStagingTestRemoveTree($storagePath);
        batchImportStagingTestRemoveTree($uploadDirectory);
        $previousStoragePath === false
            ? putenv('IEPR_PRIVATE_STORAGE_PATH')
            : putenv('IEPR_PRIVATE_STORAGE_PATH=' . $previousStoragePath);
    }
}

return 'pass';
