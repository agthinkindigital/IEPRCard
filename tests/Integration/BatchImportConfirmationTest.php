<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_confirmation.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
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
            'titulares.csv'
        );
        $lotId = $staged['lote_id'];

        $result = batchImportConfirm(
            $pdo,
            $context,
            $identity,
            $fixture['tenant_id'],
            null,
            $lotId,
            ['nome_completo' => 'nome_completo', 'whatsapp' => 'whatsapp']
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

        fwrite(STDOUT, 'PASS: ' . $driver . ' confirms a staged titular import and completes the lot.' . "\n");
    } finally {
        if ($lotId !== null) {
            $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :id')->execute(['id' => $lotId]);
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
