<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_confirmation.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $previousAppEnvironment = getenv('APP_ENV');
    putenv('APP_ENV=test');
    $metricsNow = strtotime('2001-04-05 06:07:00 UTC');
    $metricsDay = '2001-04-05';
    $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
        ->execute(['day' => $metricsDay, 'environment' => 'test']);
    $suffix = bin2hex(random_bytes(5));
    $storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-import-confirm-' . $suffix;
    $uploadDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-import-confirm-upload-' . $suffix;
    $previousStoragePath = getenv('IEPR_PRIVATE_STORAGE_PATH');
    $previousAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
    mkdir($uploadDirectory, 0700, true);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    $fixture = null;
    $planId = null;
    $ownerId = null;
    $adminId = null;
    $lotId = null;
    $photoLotId = null;
    $invalidPhotoLotId = null;
    $rollbackPhotoLotId = null;
    $rollbackLotId = null;
    $largeLotId = null;
    $originTrigger = 'import_confirm_origin_fail';
    $originTriggerExists = false;

    try {
        $testTenant = batchImportStagingTestCreateTenant($pdo, $suffix);
        $fixture = $testTenant['fixture'];
        $planId = $testTenant['plan_id'];
        $ownerId = $testTenant['owner_id'];
        $context = $testTenant['context'];
        $identity = $testTenant['identity'];
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        )->execute([
            'role' => 'superadmin',
            'name' => 'Import test admin',
            'email' => 'import-admin-' . $suffix . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $adminContext = new TenantContext('admin', null, null, []);
        $adminIdentity = [
            'id' => $adminId,
            'tenant_id' => null,
            'subcliente_id' => null,
            'papel_sistema' => 'superadmin',
        ];
        $sourcePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'source.csv';
        file_put_contents($sourcePath, "nome;celular\nAda Lovelace;+5511999999999\n");
        $staged = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $sourcePath,
            'titulares.csv',
            $metricsNow
        );
        $lotId = $staged['lote_id'];

        $invalidMappingRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $lotId,
                ['nome_completo' => 'nome_completo', 'whatsapp' => 'nome_completo']
            );
        } catch (DomainException $exception) {
            $invalidMappingRejected = $exception->getMessage() === 'IMPORT_MAPPING_INVALID';
        }
        $unitContext = new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []);
        $scopeRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $unitContext,
                $identity,
                $fixture['tenant_id'],
                null,
                $lotId,
                ['nome_completo' => 'nome_completo', 'whatsapp' => 'whatsapp']
            );
        } catch (DomainException $exception) {
            $scopeRejected = $exception->getMessage() === 'IMPORT_LOT_NOT_FOUND';
        }
        $pendingLot = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $pendingLot->execute(['id' => $lotId]);
        $holderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        if (!$invalidMappingRejected || !$scopeRejected || $pendingLot->fetchColumn() !== 'aguardando_mapa'
            || (int) $holderCount->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': invalid mapping or out-of-scope confirmation must leave the lot untouched.');
        }

        $result = batchImportConfirm(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $lotId,
            ['nome_completo' => 'nome_completo', 'whatsapp' => 'whatsapp'],
            $metricsNow + 10
        );

        $holder = $pdo->prepare(
            'SELECT id, nome_completo, whatsapp, lote_origem_id FROM titulares WHERE tenant_id = :tenant ORDER BY id DESC LIMIT 1'
        );
        $holder->execute(['tenant' => $fixture['tenant_id']]);
        $record = $holder->fetch(PDO::FETCH_ASSOC);
        $memberIds = batchImportLotTitularIds(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $lotId
        );
        $foreignLotRejected = false;
        try {
            batchImportLotTitularIds(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'] + 1,
                null,
                $lotId
            );
        } catch (DomainException $exception) {
            $foreignLotRejected = $exception->getMessage() === 'IMPORT_LOT_NOT_FOUND';
        }
        $unitMismatchRejected = false;
        try {
            batchImportLotTitularIds(
                $pdo,
                $adminContext,
                $adminIdentity,
                $fixture['tenant_id'],
                $fixture['subcliente_id'],
                $lotId
            );
        } catch (DomainException $exception) {
            $unitMismatchRejected = $exception->getMessage() === 'IMPORT_LOT_NOT_FOUND';
        }
        $lot = $pdo->prepare(
            'SELECT mapa_de_para_usado_json, total_registros, processados_sucesso, status_lote, arquivo_original_url '
            . 'FROM lotes_importacao WHERE id = :id'
        );
        $lot->execute(['id' => $lotId]);
        $lotRecord = $lot->fetch(PDO::FETCH_ASSOC);

        if (($result['lote_id'] ?? null) !== $lotId || ($result['imported'] ?? null) !== 1
            || !is_array($record) || $record['nome_completo'] !== 'Ada Lovelace'
            || $record['whatsapp'] !== '+5511999999999'
            || (int) $record['lote_origem_id'] !== $lotId
            || $memberIds !== [(int) $record['id']] || !$foreignLotRejected || !$unitMismatchRejected
            || !is_array($lotRecord) || json_decode((string) $lotRecord['mapa_de_para_usado_json'], true)
                !== ['nome_completo' => 'nome_completo', 'whatsapp' => 'whatsapp']
            || (int) $lotRecord['total_registros'] !== 1 || (int) $lotRecord['processados_sucesso'] !== 1
            || $lotRecord['status_lote'] !== 'concluido' || $lotRecord['arquivo_original_url'] !== null) {
            throw new RuntimeException($driver . ': confirming a staged lot must create the holder and finish the lot.');
        }
        $dailyMetrics = $pdo->prepare(
            'SELECT pico_demanda_diario, registros_processados, duracao_media_ms, fila_maxima, falhas '
            . 'FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment'
        );
        $dailyMetrics->execute(['day' => $metricsDay, 'environment' => 'test']);
        $metricRecord = $dailyMetrics->fetch(PDO::FETCH_ASSOC);
        if (!is_array($metricRecord) || (int) $metricRecord['pico_demanda_diario'] !== 1
            || (int) $metricRecord['registros_processados'] !== 1 || (int) $metricRecord['duracao_media_ms'] !== 10000
            || (int) $metricRecord['fila_maxima'] < 1 || (int) $metricRecord['falhas'] !== 0) {
            throw new RuntimeException($driver . ': batch staging and confirmation must feed daily metrics without personal data.');
        }

        $rollbackPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'rollback.csv';
        file_put_contents($rollbackPath, "nome\nAtomic one\nAtomic two\n");
        $rollbackLotId = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $rollbackPath,
            'rollback.csv'
        )['lote_id'];
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            $pdo->exec(
                "CREATE TRIGGER {$originTrigger} BEFORE UPDATE ON titulares "
                . "WHEN NEW.lote_origem_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'test origin failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$originTrigger} BEFORE UPDATE ON titulares FOR EACH ROW "
                . "BEGIN IF NEW.lote_origem_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT = 'test origin failure'; END IF; END"
            );
        }
        $originTriggerExists = true;
        $rolledBack = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $rollbackLotId,
                ['nome_completo' => 'nome_completo']
            );
        } catch (RuntimeException $exception) {
            $rolledBack = $exception->getMessage() === 'TITULAR_CREATE_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$originTrigger}");
            $originTriggerExists = false;
        }
        $holderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        $rollbackState = $pdo->prepare(
            'SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = :id'
        );
        $rollbackState->execute(['id' => $rollbackLotId]);
        $rollbackRecord = $rollbackState->fetch(PDO::FETCH_ASSOC);
        if (!$rolledBack || (int) $holderCount->fetchColumn() !== 1 || !is_array($rollbackRecord)
            || $rollbackRecord['status_lote'] !== 'aguardando_mapa'
            || !is_string($rollbackRecord['arquivo_original_url'])) {
            throw new RuntimeException($driver . ': an audit failure must roll back every holder and leave the lot retryable.');
        }
        $retry = batchImportConfirm(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $rollbackLotId,
            ['nome_completo' => 'nome_completo']
        );
        $retryMemberIds = batchImportLotTitularIds(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $rollbackLotId
        );
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        if (($retry['imported'] ?? null) !== 2 || (int) $holderCount->fetchColumn() !== 3
            || count($retryMemberIds) !== 2 || count(array_unique($retryMemberIds)) !== 2) {
            throw new RuntimeException($driver . ': retry must import each holder once after a full rollback.');
        }

        $pdo->prepare('UPDATE tenants SET limite_titulares = 600 WHERE id = :tenant')
            ->execute(['tenant' => $fixture['tenant_id']]);
        $photoZipPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'photos.zip';
        $photoZip = new ZipArchive();
        if ($photoZip->open($photoZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the photo archive fixture.');
        }
        $photoImage = imagecreatetruecolor(8, 8);
        $photoColor = imagecolorallocate($photoImage, 35, 120, 205);
        imagefill($photoImage, 0, 0, $photoColor);
        ob_start();
        imagepng($photoImage);
        $photoBytes = ob_get_clean();
        imagedestroy($photoImage);
        if (!is_string($photoBytes)) {
            throw new RuntimeException('Could not encode the photo fixture.');
        }
        $photoZip->addFromString('529.982.247-25.png', $photoBytes);
        $photoZip->addFromString('MAT-A2.png', $photoBytes);
        $photoZip->close();
        $photoSourcePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'photo-titulares.csv';
        file_put_contents(
            $photoSourcePath,
            "nome;cpf;matricula\nAda Photo CPF;52998224725;MAT-A1\nGrace Photo Matricula;11144477735;mat-a2\n"
        );
        $photoStaged = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $photoSourcePath,
            'photo-titulares.csv',
            $metricsNow + 20,
            $photoZipPath
        );
        $photoLotId = $photoStaged['lote_id'];
        $photoLotSource = $pdo->prepare('SELECT arquivo_original_url FROM lotes_importacao WHERE id = :id');
        $photoLotSource->execute(['id' => $photoLotId]);
        $photoSourceKey = (string) $photoLotSource->fetchColumn();
        $stagedPhotoArchivePath = batchImportPhotoArchiveResolvePath($fixture['tenant_id'], $photoSourceKey);
        if (!is_file($stagedPhotoArchivePath)) {
            throw new RuntimeException($driver . ': the optional photo archive must be staged privately with its import.');
        }
        $photoImport = batchImportConfirm(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $photoLotId,
            ['nome_completo' => 'nome_completo', 'cpf' => 'cpf', 'matricula' => 'matricula'],
            $metricsNow + 30
        );
        $photoHoldersQuery = $pdo->prepare(
            'SELECT id, nome_completo, foto_url FROM titulares WHERE tenant_id = :tenant AND lote_origem_id = :lot '
            . 'ORDER BY nome_completo'
        );
        $photoHoldersQuery->execute(['tenant' => $fixture['tenant_id'], 'lot' => $photoLotId]);
        $photoHolders = $photoHoldersQuery->fetchAll(PDO::FETCH_ASSOC);
        if (($photoImport['imported'] ?? null) !== 2 || count($photoHolders) !== 2) {
            throw new RuntimeException($driver . ': photo archive confirmation must create both mapped holders.');
        }
        foreach ($photoHolders as $photoHolder) {
            $photoKey = (string) ($photoHolder['foto_url'] ?? '');
            if (!str_starts_with($photoKey, 'private://') || str_contains($photoKey, '52998224725')
                || str_contains($photoKey, 'MAT-A2')) {
                throw new RuntimeException($driver . ': matched photos must be stored under opaque private keys.');
            }
            $photoFile = cardIssuanceWorkerResolvePath($fixture['tenant_id'], $photoKey);
            if (!is_file($photoFile) || file_get_contents($photoFile) !== $photoBytes) {
                throw new RuntimeException($driver . ': matched CPF/matricula photos must be retained for card issuance.');
            }
        }
        $invalidArchiveCases = [
            [
                'entries' => [['UNKNOWN.png', $photoBytes]],
                'records' => [['cpf' => null, 'matricula' => 'EXPECTED']],
            ],
            [
                'entries' => [['DUP.png', $photoBytes]],
                'records' => [['cpf' => null, 'matricula' => 'DUP'], ['cpf' => null, 'matricula' => 'DUP']],
            ],
            [
                'entries' => [['MATCH.png', $photoBytes], ['match.PNG', $photoBytes]],
                'records' => [['cpf' => null, 'matricula' => 'MATCH']],
            ],
            [
                'entries' => [['../MATCH.png', $photoBytes]],
                'records' => [['cpf' => null, 'matricula' => 'MATCH']],
            ],
        ];
        foreach ($invalidArchiveCases as $caseIndex => $invalidArchiveCase) {
            $caseArchivePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'invalid-case-' . $caseIndex . '.zip';
            $caseArchive = new ZipArchive();
            if ($caseArchive->open($caseArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create an invalid photo archive case.');
            }
            foreach ($invalidArchiveCase['entries'] as [$entryName, $entryBytes]) {
                $caseArchive->addFromString($entryName, $entryBytes);
            }
            $caseArchive->close();
            $caseRejected = false;
            try {
                batchImportPhotoArchiveReadMatches($caseArchivePath, $invalidArchiveCase['records']);
            } catch (DomainException $exception) {
                $caseRejected = $exception->getMessage() === 'IMPORT_PHOTO_ARCHIVE_INVALID';
            } finally {
                @unlink($caseArchivePath);
            }
            if (!$caseRejected) {
                throw new RuntimeException($driver . ': orphaned, ambiguous, duplicate or traversal entries must be rejected.');
            }
        }
        if (!is_array($photoHolders[0]) || !is_array($photoHolders[1])) {
            throw new RuntimeException($driver . ': matched photo holder rows must be valid.');
        }
        $photoReplayRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $photoLotId,
                ['nome_completo' => 'nome_completo', 'cpf' => 'cpf', 'matricula' => 'matricula'],
                $metricsNow + 35
            );
        } catch (DomainException $exception) {
            $photoReplayRejected = $exception->getMessage() === 'IMPORT_LOT_NOT_CONFIRMABLE';
        }
        $photoHoldersQuery->execute(['tenant' => $fixture['tenant_id'], 'lot' => $photoLotId]);
        if (!$photoReplayRejected || count($photoHoldersQuery->fetchAll(PDO::FETCH_ASSOC)) !== 2) {
            throw new RuntimeException($driver . ': replaying a completed photo import must not duplicate holders.');
        }
        if (is_file($stagedPhotoArchivePath)) {
            throw new RuntimeException($driver . ': confirmation must remove the temporary photo archive.');
        }

        $badPhotoZipPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'invalid-image.zip';
        $badPhotoZip = new ZipArchive();
        if ($badPhotoZip->open($badPhotoZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the invalid photo archive fixture.');
        }
        $badPhotoZip->addFromString('BROKEN-1.png', 'not an image');
        $badPhotoZip->close();
        $badPhotoSourcePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'invalid-photo.csv';
        file_put_contents($badPhotoSourcePath, "nome;matricula\nInvalid Photo;BROKEN-1\n");
        $invalidPhotoLotId = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $badPhotoSourcePath,
            'invalid-photo.csv',
            $metricsNow + 40,
            $badPhotoZipPath
        )['lote_id'];
        $invalidPhotoSource = $pdo->prepare('SELECT arquivo_original_url FROM lotes_importacao WHERE id = :id');
        $invalidPhotoSource->execute(['id' => $invalidPhotoLotId]);
        $invalidPhotoSourceKey = (string) $invalidPhotoSource->fetchColumn();
        $invalidPhotoArchivePath = batchImportPhotoArchiveResolvePath($fixture['tenant_id'], $invalidPhotoSourceKey);
        $invalidPhotoRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $invalidPhotoLotId,
                ['nome_completo' => 'nome_completo', 'matricula' => 'matricula'],
                $metricsNow + 50
            );
        } catch (DomainException $exception) {
            $invalidPhotoRejected = $exception->getMessage() === 'IMPORT_PHOTO_ARCHIVE_INVALID';
        }
        $invalidPhotoState = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $invalidPhotoState->execute(['id' => $invalidPhotoLotId]);
        $invalidPhotoHolderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE lote_origem_id = :lot');
        $invalidPhotoHolderCount->execute(['lot' => $invalidPhotoLotId]);
        if (!$invalidPhotoRejected || $invalidPhotoState->fetchColumn() !== 'aguardando_mapa'
            || (int) $invalidPhotoHolderCount->fetchColumn() !== 0 || !is_file($invalidPhotoArchivePath)) {
            throw new RuntimeException($driver . ': invalid images must reject confirmation atomically and remain retryable.');
        }
        batchImportStagingCancel($pdo, $context, $identity, $fixture['tenant_id'], null, $invalidPhotoLotId);
        if (is_file($invalidPhotoArchivePath)) {
            throw new RuntimeException($driver . ': canceling an invalid photo import must remove its staged ZIP.');
        }

        $rollbackPhotoZipPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'rollback-photos.zip';
        $rollbackPhotoZip = new ZipArchive();
        if ($rollbackPhotoZip->open($rollbackPhotoZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the rollback photo archive fixture.');
        }
        $rollbackPhotoZip->addFromString('ROLLBACK-PHOTO.png', $photoBytes);
        $rollbackPhotoZip->close();
        $rollbackPhotoSourcePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'rollback-photo.csv';
        file_put_contents($rollbackPhotoSourcePath, "nome;matricula\nRollback Photo;ROLLBACK-PHOTO\n");
        $rollbackPhotoLotId = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $rollbackPhotoSourcePath,
            'rollback-photo.csv',
            $metricsNow + 45,
            $rollbackPhotoZipPath
        )['lote_id'];
        $tenantAssetDirectory = $storagePath . DIRECTORY_SEPARATOR . (string) $fixture['tenant_id'];
        $filesBeforePhotoRollback = array_map(
            static fn (string $path): string => basename($path),
            glob($tenantAssetDirectory . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []
        );
        $photoTrigger = 'import_confirm_photo_fail';
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            $pdo->exec(
                "CREATE TRIGGER {$photoTrigger} BEFORE UPDATE OF foto_url ON titulares "
                . "WHEN NEW.foto_url IS NOT NULL BEGIN SELECT RAISE(ABORT, 'test photo failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$photoTrigger} BEFORE UPDATE ON titulares FOR EACH ROW "
                . "BEGIN IF NEW.foto_url IS NOT NULL THEN SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT = 'test photo failure'; END IF; END"
            );
        }
        $photoRollbackRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $rollbackPhotoLotId,
                ['nome_completo' => 'nome_completo', 'matricula' => 'matricula'],
                $metricsNow + 55
            );
        } catch (RuntimeException $exception) {
            $photoRollbackRejected = $exception->getMessage() === 'TITULAR_CREATE_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$photoTrigger}");
        }
        $filesAfterPhotoRollback = array_map(
            static fn (string $path): string => basename($path),
            glob($tenantAssetDirectory . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []
        );
        $rollbackPhotoState = $pdo->prepare('SELECT status_lote FROM lotes_importacao WHERE id = :id');
        $rollbackPhotoState->execute(['id' => $rollbackPhotoLotId]);
        $rollbackPhotoHolderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE lote_origem_id = :lot');
        $rollbackPhotoHolderCount->execute(['lot' => $rollbackPhotoLotId]);
        if (!$photoRollbackRejected || $filesAfterPhotoRollback !== $filesBeforePhotoRollback
            || $rollbackPhotoState->fetchColumn() !== 'aguardando_mapa'
            || (int) $rollbackPhotoHolderCount->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': a database rollback must remove new photos and keep the lot retryable.');
        }
        batchImportStagingCancel($pdo, $context, $identity, $fixture['tenant_id'], null, $rollbackPhotoLotId);

        $photoKeyToAnonymize = (string) $photoHolders[0]['foto_url'];
        $photoPathToAnonymize = cardIssuanceWorkerResolvePath($fixture['tenant_id'], $photoKeyToAnonymize);
        putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(32)));
        titularAnonymizationRequest(
            $pdo,
            $fixture['tenant_id'],
            (int) $photoHolders[0]['id'],
            hash('sha256', 'photo-anonymization-' . $photoHolders[0]['id']),
            $metricsNow + 60
        );
        $anonymization = titularAnonymizationProcessDue($pdo, $metricsNow + 70, 10);
        if ($anonymization['failed'] !== 0 || is_file($photoPathToAnonymize)) {
            throw new RuntimeException($driver . ': holder photo must remain removed after successful anonymization.');
        }

        $pdo->prepare('UPDATE tenants SET limite_titulares = 600 WHERE id = :tenant')
            ->execute(['tenant' => $fixture['tenant_id']]);
        $largePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'large.csv';
        $largeRows = array_map(static fn(int $number): string => 'Large import ' . $number, range(1, 500));
        file_put_contents($largePath, "nome\n" . implode("\n", $largeRows) . "\n");
        $largeLotId = batchImportStageFile(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $largePath,
            'large.csv'
        )['lote_id'];
        $pdo->prepare('UPDATE tenants SET limite_titulares = 100 WHERE id = :tenant')
            ->execute(['tenant' => $fixture['tenant_id']]);
        $quotaRejected = false;
        try {
            batchImportConfirm(
                $pdo,
                $context,
                $identity,
                $fixture['tenant_id'],
                null,
                $largeLotId,
                ['nome_completo' => 'nome_completo']
            );
        } catch (DomainException $exception) {
            $quotaRejected = $exception->getMessage() === 'TITULAR_CREATE_QUOTA_EXCEEDED';
        }
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        $largeLotState = $pdo->prepare(
            'SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = :id'
        );
        $largeLotState->execute(['id' => $largeLotId]);
        $pendingLargeLot = $largeLotState->fetch(PDO::FETCH_ASSOC);
        if (!$quotaRejected || (int) $holderCount->fetchColumn() !== 5 || !is_array($pendingLargeLot)
            || $pendingLargeLot['status_lote'] !== 'aguardando_mapa'
            || !is_string($pendingLargeLot['arquivo_original_url'])) {
            throw new RuntimeException($driver . ': quota rejection must leave the entire staged import retryable.');
        }
        $pdo->prepare('UPDATE tenants SET limite_titulares = 600 WHERE id = :tenant')
            ->execute(['tenant' => $fixture['tenant_id']]);
        $largeResult = batchImportConfirm(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $largeLotId,
            ['nome_completo' => 'nome_completo']
        );
        $largeMemberIds = batchImportLotTitularIds(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $largeLotId
        );
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        if (($largeResult['imported'] ?? null) !== 500 || (int) $holderCount->fetchColumn() !== 505
            || count($largeMemberIds) !== 500 || count(array_unique($largeMemberIds)) !== 500) {
            throw new RuntimeException($driver . ': a complete 500-row import must fit in one atomic operation.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' confirms a staged titular import and completes the lot.' . "\n");
    } finally {
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
            ->execute(['day' => $metricsDay, 'environment' => 'test']);
        putenv('APP_ENV=' . ($previousAppEnvironment === false ? '' : $previousAppEnvironment));
        if ($originTriggerExists) {
            $pdo->exec("DROP TRIGGER {$originTrigger}");
        }
        foreach ([$lotId, $photoLotId, $invalidPhotoLotId, $rollbackPhotoLotId, $rollbackLotId, $largeLotId] as $cleanupLotId) {
            if ($cleanupLotId !== null) {
                $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :id')->execute(['id' => $cleanupLotId]);
            }
        }
        if ($fixture !== null) {
            $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            $pdo->prepare('DELETE FROM lotes_importacao WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            if ($ownerId !== null) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]);
            }
            if ($adminId !== null) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminId]);
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
        $previousAnonymizationKey === false
            ? putenv('IEPR_ANONYMIZATION_KEY')
            : putenv('IEPR_ANONYMIZATION_KEY=' . $previousAnonymizationKey);
    }
}

return 'pass';
