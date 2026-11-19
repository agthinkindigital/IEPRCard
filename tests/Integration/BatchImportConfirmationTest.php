<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_confirmation.php';

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
    mkdir($uploadDirectory, 0700, true);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    $fixture = null;
    $planId = null;
    $ownerId = null;
    $lotId = null;
    $rollbackLotId = null;
    $largeLotId = null;
    $auditTrigger = 'import_confirm_audit_fail';
    $auditTriggerExists = false;

    try {
        $testTenant = batchImportStagingTestCreateTenant($pdo, $suffix);
        $fixture = $testTenant['fixture'];
        $planId = $testTenant['plan_id'];
        $ownerId = $testTenant['owner_id'];
        $context = $testTenant['context'];
        $identity = $testTenant['identity'];
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
            'SELECT nome_completo, whatsapp FROM titulares WHERE tenant_id = :tenant ORDER BY id DESC LIMIT 1'
        );
        $holder->execute(['tenant' => $fixture['tenant_id']]);
        $record = $holder->fetch(PDO::FETCH_ASSOC);
        $lot = $pdo->prepare(
            'SELECT mapa_de_para_usado_json, total_registros, processados_sucesso, status_lote, arquivo_original_url '
            . 'FROM lotes_importacao WHERE id = :id'
        );
        $lot->execute(['id' => $lotId]);
        $lotRecord = $lot->fetch(PDO::FETCH_ASSOC);

        if (($result['lote_id'] ?? null) !== $lotId || ($result['imported'] ?? null) !== 1
            || !is_array($record) || $record['nome_completo'] !== 'Ada Lovelace'
            || $record['whatsapp'] !== '+5511999999999'
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
                "CREATE TRIGGER {$auditTrigger} BEFORE INSERT ON auditoria_processos "
                . "WHEN NEW.acao = 'titular.created' BEGIN SELECT RAISE(ABORT, 'test audit failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$auditTrigger} BEFORE INSERT ON auditoria_processos FOR EACH ROW "
                . "BEGIN IF NEW.acao = 'titular.created' THEN SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT = 'test audit failure'; END IF; END"
            );
        }
        $auditTriggerExists = true;
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
            $pdo->exec("DROP TRIGGER {$auditTrigger}");
            $auditTriggerExists = false;
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
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        if (($retry['imported'] ?? null) !== 2 || (int) $holderCount->fetchColumn() !== 3) {
            throw new RuntimeException($driver . ': retry must import each holder once after a full rollback.');
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
        if (!$quotaRejected || (int) $holderCount->fetchColumn() !== 3 || !is_array($pendingLargeLot)
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
        $holderCount->execute(['tenant' => $fixture['tenant_id']]);
        if (($largeResult['imported'] ?? null) !== 500 || (int) $holderCount->fetchColumn() !== 503) {
            throw new RuntimeException($driver . ': a complete 500-row import must fit in one atomic operation.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' confirms a staged titular import and completes the lot.' . "\n");
    } finally {
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
            ->execute(['day' => $metricsDay, 'environment' => 'test']);
        putenv('APP_ENV=' . ($previousAppEnvironment === false ? '' : $previousAppEnvironment));
        if ($auditTriggerExists) {
            $pdo->exec("DROP TRIGGER {$auditTrigger}");
        }
        foreach ([$lotId, $rollbackLotId, $largeLotId] as $cleanupLotId) {
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
