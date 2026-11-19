<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $planId = null;
    $ownerId = null;
    $sourceLotId = null;
    $designId = null;
    $testAssetKeys = [];
    try {
        $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')
            ->execute(['name' => 'Card issuance ' . bin2hex(random_bytes(4))]);
        $planId = (int) $pdo->lastInsertId();
        $feature = $pdo->prepare(
            'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
        );
        $feature->execute(['plan' => $planId, 'key' => 'carteirinha.gerar']);
        $pdo->prepare(
            'UPDATE tenants SET plano_id = :plan, limite_carteirinhas = 2, consumo_carteirinhas = 0 '
            . 'WHERE id = :tenant'
        )->execute(['plan' => $planId, 'tenant' => $fixture['tenant_id']]);
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'role' => 'titular_cliente',
            'name' => 'Issuance owner',
            'email' => 'issuance-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $ownerId = (int) $pdo->lastInsertId();
        $identity = [
            'id' => $ownerId,
            'tenant_id' => $fixture['tenant_id'],
            'subcliente_id' => null,
            'papel_sistema' => 'titular_cliente',
        ];
        $context = new TenantContext('cliente', $fixture['tenant_id'], null, []);
        $pdo->prepare(
            'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, ativo, criado_em) '
            . 'VALUES (:tenant, :name, :orientation, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Issuance design',
            'orientation' => 'horizontal',
            'created' => '2026-01-01 00:00:00',
        ]);
        $designId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, origem_tipo, total_registros, '
            . 'processados_sucesso, status_lote, criado_em, finalizado_em) '
            . 'VALUES (:tenant, NULL, :user, :origin, 1, 1, :status, :created, :finished)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'user' => $ownerId,
            'origin' => 'csv',
            'status' => 'concluido',
            'created' => '2026-01-01 00:00:00',
            'finished' => '2026-01-01 00:00:01',
        ]);
        $sourceLotId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, lote_origem_id, ativo, criado_em) '
            . 'VALUES (:tenant, NULL, :name, :lot, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Issuance fixture',
            'lot' => $sourceLotId,
            'created' => '2026-01-01 00:00:00',
        ]);
        $holderId = (int) $pdo->lastInsertId();

        $first = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'request-key-00000001', strtotime('2026-01-01 00:01:00 UTC')
        );
        $retry = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'request-key-00000001', strtotime('2026-01-01 00:02:00 UTC')
        );
        $conflictRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'pdf_cr80',
                'request-key-00000001', strtotime('2026-01-01 00:03:00 UTC')
            );
        } catch (DomainException $exception) {
            $conflictRejected = $exception->getMessage() === 'ISSUANCE_IDEMPOTENCY_CONFLICT';
        }
        $second = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'request-key-00000002', strtotime('2026-01-01 00:04:00 UTC')
        );
        $quotaRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
                'request-key-00000003', strtotime('2026-01-01 00:05:00 UTC')
            );
        } catch (DomainException $exception) {
            $quotaRejected = $exception->getMessage() === 'COTA_EXCEDIDA';
        }

        $jobs = $pdo->prepare(
            'SELECT id, lote_origem_id, design_id, chave_idempotencia_hash, total_registros, status_lote '
            . 'FROM lotes_importacao WHERE lote_origem_id = :source ORDER BY id'
        );
        $jobs->execute(['source' => $sourceLotId]);
        $jobRows = $jobs->fetchAll(PDO::FETCH_ASSOC);
        $reservations = $pdo->prepare('SELECT lote_id, quantidade, estado FROM reservas_cota ORDER BY lote_id');
        $reservations->execute();
        $reservationRows = $reservations->fetchAll(PDO::FETCH_ASSOC);
        $quota = $pdo->prepare('SELECT consumo_carteirinhas FROM tenants WHERE id = :tenant');
        $quota->execute(['tenant' => $fixture['tenant_id']]);

        if (($first['job_id'] ?? null) < 1 || ($first['reused'] ?? true) !== false
            || ($retry['job_id'] ?? null) !== $first['job_id'] || ($retry['reused'] ?? false) !== true
            || ($second['job_id'] ?? null) === $first['job_id'] || !$conflictRejected || !$quotaRejected
            || count($jobRows) !== 2 || count($reservationRows) !== 2
            || (int) $quota->fetchColumn() !== 2
            || (int) $jobRows[0]['lote_origem_id'] !== $sourceLotId
            || (int) $jobRows[1]['lote_origem_id'] !== $sourceLotId
            || (int) $jobRows[0]['design_id'] !== $designId || (int) $jobRows[1]['design_id'] !== $designId
            || (int) $jobRows[0]['total_registros'] !== 1 || (int) $jobRows[1]['total_registros'] !== 1
            || $jobRows[0]['chave_idempotencia_hash'] === 'request-key-00000001'
            || $jobRows[1]['chave_idempotencia_hash'] === 'request-key-00000002'
            || $jobRows[0]['status_lote'] !== 'na_fila' || $jobRows[1]['status_lote'] !== 'na_fila'
            || (int) $reservationRows[0]['quantidade'] !== 1 || (int) $reservationRows[1]['quantidade'] !== 1
            || $reservationRows[0]['estado'] !== 'reservada' || $reservationRows[1]['estado'] !== 'reservada') {
            throw new RuntimeException($driver . ': each deliberate emission must have one idempotent job and atomic quota reservation.');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:10:00 UTC') + ($attempt * 301))) {
                throw new RuntimeException($driver . ': the worker must claim queued jobs and retry expired leases.');
            }
        }
        $failedJob = $pdo->prepare('SELECT status_lote, worker_attempts, worker_lease_until, erro_codigo FROM lotes_importacao WHERE id = :id');
        $failedJob->execute(['id' => $first['job_id']]);
        $failedRow = $failedJob->fetch(PDO::FETCH_ASSOC);
        $failedReservation = $pdo->prepare('SELECT estado FROM reservas_cota WHERE lote_id = :id');
        $failedReservation->execute(['id' => $first['job_id']]);
        $updatedQuota = $pdo->prepare('SELECT consumo_carteirinhas FROM tenants WHERE id = :tenant');
        $updatedQuota->execute(['tenant' => $fixture['tenant_id']]);
        if (!is_array($failedRow) || $failedRow['status_lote'] !== 'falhou'
            || (int) $failedRow['worker_attempts'] !== 3 || $failedRow['worker_lease_until'] !== null
            || !is_string($failedRow['erro_codigo']) || preg_match('/^[A-Z0-9_]{1,80}$/D', $failedRow['erro_codigo']) !== 1
            || $failedReservation->fetchColumn() !== 'liberada' || (int) $updatedQuota->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': terminal worker failure must release only its own quota reservation.');
        }

        $rendering = renderingCapabilitiesCheck();
        if ($rendering['ready']) {
            $background = imagecreatetruecolor(1011, 638);
            $white = imagecolorallocate($background, 255, 255, 255);
            imagefill($background, 0, 0, $white);
            ob_start();
            imagepng($background);
            $backgroundBytes = ob_get_clean();
            imagedestroy($background);
            $backgroundRoot = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . (string) $fixture['tenant_id'];
            if (!is_dir($backgroundRoot)) { mkdir($backgroundRoot, 0700, true); }
            foreach (['frente', 'verso'] as $side) {
                $token = substr(hash('sha256', $driver . ':' . $side . ':' . $sourceLotId), 0, 48);
                $key = 'private://' . $fixture['tenant_id'] . '/' . $token . '.png';
                file_put_contents($backgroundRoot . DIRECTORY_SEPARATOR . $token . '.png', $backgroundBytes);
                $testAssetKeys[] = $key;
                $pdo->prepare('UPDATE designs_carteirinha SET ' . ($side === 'frente' ? 'bg_frente_url' : 'bg_verso_url') . ' = :key WHERE id = :id')
                    ->execute(['key' => $key, 'id' => $designId]);
            }
            $designMap = [
                'versao_schema' => '1.0',
                'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'],
                'elementos' => [],
            ];
            $fixedMapping = ['versao_schema' => '1.0', 'mapeamentos' => []];
            $pdo->prepare('UPDATE designs_carteirinha SET mapa_campos_json = :map, mapeamento_fixo_json = :mapping WHERE id = :id')
                ->execute([
                    'map' => json_encode($designMap, JSON_THROW_ON_ERROR),
                    'mapping' => json_encode($fixedMapping, JSON_THROW_ON_ERROR),
                    'id' => $designId,
                ]);
            if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:30:00 UTC'))) {
                throw new RuntimeException($driver . ': the worker must process a valid WebP job.');
            }
            $completed = $pdo->prepare('SELECT status_lote, processados_sucesso FROM lotes_importacao WHERE id = :id');
            $completed->execute(['id' => $second['job_id']]);
            $completedRow = $completed->fetch(PDO::FETCH_ASSOC);
            $issued = $pdo->prepare('SELECT snapshot_dados_json, webp_frente_url, webp_verso_url FROM carteirinhas_emitidas WHERE lote_id = :job');
            $issued->execute(['job' => $second['job_id']]);
            $issuedRow = $issued->fetch(PDO::FETCH_ASSOC);
            if (!is_array($completedRow) || $completedRow['status_lote'] !== 'concluido'
                || (int) $completedRow['processados_sucesso'] !== 1 || !is_array($issuedRow)
                || !str_starts_with((string) $issuedRow['webp_frente_url'], 'private://')
                || !str_starts_with((string) $issuedRow['webp_verso_url'], 'private://')
                || !is_array(json_decode((string) $issuedRow['snapshot_dados_json'], true))) {
                throw new RuntimeException($driver . ': successful worker output must persist a private WebP pair and snapshot.');
            }
            $testAssetKeys[] = $issuedRow['webp_frente_url'];
            $testAssetKeys[] = $issuedRow['webp_verso_url'];
        } elseif (getenv('CI') === 'true') {
            throw new RuntimeException($driver . ': CI must provide the renderer extensions for the worker output test.');
        }

        $pdo->prepare('UPDATE tenants SET limite_carteirinhas = 500, consumo_carteirinhas = 0 WHERE id = :tenant')
            ->execute(['tenant' => $fixture['tenant_id']]);
        $bulkMember = $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, lote_origem_id, ativo, criado_em) '
            . 'VALUES (:tenant, NULL, :name, :source, 1, :created)'
        );
        for ($index = 2; $index <= 500; $index++) {
            $bulkMember->execute([
                'tenant' => $fixture['tenant_id'], 'name' => 'Bulk holder ' . $index,
                'source' => $sourceLotId, 'created' => '2026-01-01 00:00:00',
            ]);
        }
        $bulkJob = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'request-key-00000004', strtotime('2026-01-01 00:40:00 UTC')
        );
        $bulkTotal = $pdo->prepare('SELECT total_registros FROM lotes_importacao WHERE id = :id');
        $bulkTotal->execute(['id' => $bulkJob['job_id']]);
        if ((int) $bulkTotal->fetchColumn() !== 500 || ($bulkJob['reused'] ?? true) !== false) {
            throw new RuntimeException($driver . ': WebP issuance must queue a 500-member source lot.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' idempotency, 500-member queue, quota reservation, worker retry and compensation.' . "\n");
    } finally {
        if ($fixture !== null) {
            foreach ($testAssetKeys as $assetKey) {
                cardIssuanceWorkerDeleteAsset($fixture['tenant_id'], (string) $assetKey);
            }
            $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        }
        if ($sourceLotId !== null) {
            $pdo->prepare('DELETE FROM lotes_importacao WHERE lote_origem_id = :source')->execute(['source' => $sourceLotId]);
            $pdo->prepare('DELETE FROM titulares WHERE lote_origem_id = :source')->execute(['source' => $sourceLotId]);
            $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :source')->execute(['source' => $sourceLotId]);
        }
        if ($ownerId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]);
        }
        if ($designId !== null) {
            $pdo->prepare('DELETE FROM designs_carteirinha WHERE id = :id')->execute(['id' => $designId]);
        }
        if ($fixture !== null) {
            tenantTestCleanup($pdo, $fixture);
        }
        if ($planId !== null) {
            $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
            $pdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
        }
    }
}

return 'pass';
