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
    $unitOwnerId = null;
    $unitSourceLotId = null;
    $designId = null;
    $testAssetKeys = [];
    $originalAppBaseUrl = getenv('APP_BASE_URL');
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
        $crossTenantRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'] + 10000, null, 1, 1, 'webp',
                'request-key-other-tenant', strtotime('2026-01-01 00:00:30 UTC')
            );
        } catch (DomainException $exception) {
            $crossTenantRejected = $exception->getMessage() === 'ISSUANCE_DENIED';
        }
        if (!$crossTenantRejected) {
            throw new RuntimeException($driver . ': the authenticated tenant must match the requested tenant.');
        }
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
            unset($background);
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
                'elementos' => [[
                    'id' => 'validation-qr', 'lado' => 'frente', 'tipo' => 'qrcode_validacao',
                    'chave_variavel' => '_sistema_url_validacao', 'x' => 800, 'y' => 400, 'tamanho' => 160,
                ]],
            ];
            $fixedMapping = ['versao_schema' => '1.0', 'mapeamentos' => []];
            $pdo->prepare('UPDATE designs_carteirinha SET mapa_campos_json = :map, mapeamento_fixo_json = :mapping WHERE id = :id')
                ->execute([
                    'map' => json_encode($designMap, JSON_THROW_ON_ERROR),
                    'mapping' => json_encode($fixedMapping, JSON_THROW_ON_ERROR),
                    'id' => $designId,
                ]);
            if ($originalAppBaseUrl === false || trim($originalAppBaseUrl) === '') {
                putenv('APP_BASE_URL=https://ieprcard.example.test');
            }
            if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:30:00 UTC'))) {
                throw new RuntimeException($driver . ': the worker must process a valid WebP job.');
            }
            $completed = $pdo->prepare('SELECT status_lote, processados_sucesso FROM lotes_importacao WHERE id = :id');
            $completed->execute(['id' => $second['job_id']]);
            $completedRow = $completed->fetch(PDO::FETCH_ASSOC);
            $issued = $pdo->prepare('SELECT codigo_validacao_qr, snapshot_dados_json, webp_frente_url, webp_verso_url FROM carteirinhas_emitidas WHERE lote_id = :job');
            $issued->execute(['job' => $second['job_id']]);
            $issuedRow = $issued->fetch(PDO::FETCH_ASSOC);
            $snapshot = is_array($issuedRow) ? json_decode((string) $issuedRow['snapshot_dados_json'], true) : null;
            if (!is_array($completedRow) || $completedRow['status_lote'] !== 'concluido'
                || (int) $completedRow['processados_sucesso'] !== 1 || !is_array($issuedRow)
                || preg_match('/^[a-f0-9]{64}$/D', (string) $issuedRow['codigo_validacao_qr']) !== 1
                || !str_starts_with((string) $issuedRow['webp_frente_url'], 'private://')
                || !str_starts_with((string) $issuedRow['webp_verso_url'], 'private://')
                || !is_array($snapshot)
                || !str_ends_with((string) ($snapshot['_sistema_url_validacao'] ?? ''), '/validar/' . $issuedRow['codigo_validacao_qr'])) {
                throw new RuntimeException($driver . ': successful worker output must persist an opaque QR token, matching validation URL, private WebP pair, and snapshot.');
            }
            $issuanceKey = $pdo->prepare('SELECT chave_idempotencia_hash FROM lotes_importacao WHERE id = :job');
            $issuanceKey->execute(['job' => $second['job_id']]);
            $oldDerivedCode = hash_hmac('sha256', $second['job_id'] . ':' . $holderId, (string) $issuanceKey->fetchColumn());
            if (hash_equals($oldDerivedCode, (string) $issuedRow['codigo_validacao_qr'])) {
                throw new RuntimeException($driver . ': QR validation tokens must not be derived from the request idempotency key.');
            }
            $webpValidationCode = (string) $issuedRow['codigo_validacao_qr'];
            $testAssetKeys[] = $issuedRow['webp_frente_url'];
            $testAssetKeys[] = $issuedRow['webp_verso_url'];

            $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'processando', worker_lease_until = :expired WHERE id = :id")
                ->execute(['expired' => '2025-12-31 23:59:00', 'id' => $second['job_id']]);
            if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:35:00 UTC'))) {
                throw new RuntimeException($driver . ': the worker must reclaim a valid expired job.');
            }
            $recoveredCards = $pdo->prepare('SELECT codigo_validacao_qr FROM carteirinhas_emitidas WHERE lote_id = :job');
            $recoveredCards->execute(['job' => $second['job_id']]);
            $recoveredRows = $recoveredCards->fetchAll(PDO::FETCH_COLUMN);
            if (count($recoveredRows) !== 1 || !hash_equals($webpValidationCode, (string) $recoveredRows[0])) {
                throw new RuntimeException($driver . ': recovering a completed card must retain its validation token.');
            }

            $pdfJob = cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'pdf_cr80',
                'request-key-00000005', strtotime('2026-01-01 00:36:00 UTC')
            );
            if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:37:00 UTC'))) {
                throw new RuntimeException($driver . ': the worker must process a valid CR80 PDF job.');
            }
            $pdfResult = $pdo->prepare('SELECT status_lote, pdf_consolidado_url FROM lotes_importacao WHERE id = :id');
            $pdfResult->execute(['id' => $pdfJob['job_id']]);
            $pdfRow = $pdfResult->fetch(PDO::FETCH_ASSOC);
            $pdfBytes = is_array($pdfRow) ? cardIssuanceWorkerReadAsset($fixture['tenant_id'], (string) $pdfRow['pdf_consolidado_url']) : '';
            $pdfCards = $pdo->prepare('SELECT codigo_validacao_qr FROM carteirinhas_emitidas WHERE lote_id = :job AND pdf_individual_url IS NULL');
            $pdfCards->execute(['job' => $pdfJob['job_id']]);
            $pdfCardCodes = $pdfCards->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($pdfRow) || $pdfRow['status_lote'] !== 'concluido'
                || !str_starts_with((string) $pdfRow['pdf_consolidado_url'], 'private://')
                || !str_starts_with($pdfBytes, '%PDF-1.4')
                || count($pdfCardCodes) !== 1 || hash_equals($webpValidationCode, (string) $pdfCardCodes[0])) {
                throw new RuntimeException($driver . ': PDF jobs must save a private consolidated PDF and a new QR token for each deliberate issuance.');
            }
            $testAssetKeys[] = $pdfRow['pdf_consolidado_url'];

            $pdo->prepare('UPDATE tenants SET limite_carteirinhas = 2, consumo_carteirinhas = 0 WHERE id = :tenant')
                ->execute(['tenant' => $fixture['tenant_id']]);
            foreach ([
                ['mode' => 'pdf_a4_8up', 'key' => 'request-key-a4-8up-0001'],
                ['mode' => 'pdf_a4_10up', 'key' => 'request-key-a4-10up-001'],
            ] as $a4Case) {
                $a4Job = cardIssuanceCreateJob(
                    $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId,
                    $a4Case['mode'], $a4Case['key'], strtotime('2026-01-01 00:38:00 UTC')
                );
                if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:39:00 UTC'))) {
                    throw new RuntimeException($driver . ': the worker must process each A4 layout.');
                }
                $a4Result = $pdo->prepare('SELECT status_lote, pdf_consolidado_url FROM lotes_importacao WHERE id = :id');
                $a4Result->execute(['id' => $a4Job['job_id']]);
                $a4Row = $a4Result->fetch(PDO::FETCH_ASSOC);
                $a4Bytes = is_array($a4Row) ? cardIssuanceWorkerReadAsset($fixture['tenant_id'], (string) $a4Row['pdf_consolidado_url']) : '';
                if (!is_array($a4Row) || $a4Row['status_lote'] !== 'concluido'
                    || !str_starts_with((string) $a4Row['pdf_consolidado_url'], 'private://')
                    || !str_starts_with($a4Bytes, '%PDF-1.4')) {
                    throw new RuntimeException($driver . ': A4 jobs must persist a private consolidated PDF.');
                }
                $testAssetKeys[] = $a4Row['pdf_consolidado_url'];
            }
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

        $pdo->prepare('UPDATE tenants SET limite_carteirinhas = 3, consumo_carteirinhas = 0, status_conta = :status, permissoes_unidade_json = :permissions WHERE id = :tenant')
            ->execute([
                'status' => 'ativo',
                'permissions' => json_encode(['carteirinha.gerar'], JSON_THROW_ON_ERROR),
                'tenant' => $fixture['tenant_id'],
            ]);
        $pdo->prepare('UPDATE tenants SET status_conta = :status, limite_carteirinhas = 1, consumo_carteirinhas = 0 WHERE id = :id')
            ->execute(['status' => 'ativo', 'id' => $fixture['subcliente_id']]);
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :child, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'child' => $fixture['subcliente_id'], 'role' => 'responsavel_sub',
            'name' => 'Issuance unit owner', 'email' => 'issuance-unit-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $unitOwnerId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE designs_carteirinha SET compartilhado_subclientes = 1 WHERE id = :id')->execute(['id' => $designId]);
        $pdo->prepare(
            'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, origem_tipo, total_registros, '
            . 'processados_sucesso, status_lote, criado_em, finalizado_em) '
            . 'VALUES (:tenant, :child, :user, :origin, 1, 1, :status, :created, :finished)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'child' => $fixture['subcliente_id'], 'user' => $unitOwnerId,
            'origin' => 'csv', 'status' => 'concluido', 'created' => '2026-01-01 00:00:00',
            'finished' => '2026-01-01 00:00:01',
        ]);
        $unitSourceLotId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, lote_origem_id, ativo, criado_em) '
            . 'VALUES (:tenant, :child, :name, :source, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'child' => $fixture['subcliente_id'], 'name' => 'Unit issuance fixture',
            'source' => $unitSourceLotId, 'created' => '2026-01-01 00:00:00',
        ]);
        $unitContext = new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []);
        $unitIdentity = [
            'id' => $unitOwnerId, 'tenant_id' => $fixture['tenant_id'],
            'subcliente_id' => $fixture['subcliente_id'], 'papel_sistema' => 'responsavel_sub',
        ];
        $wrongUnitRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $unitContext, $unitIdentity, $fixture['tenant_id'], null, $unitSourceLotId, $designId,
                'webp', 'request-key-wrong-unit', strtotime('2026-01-01 00:49:00 UTC')
            );
        } catch (DomainException $exception) {
            $wrongUnitRejected = $exception->getMessage() === 'ISSUANCE_DENIED';
        }
        if (!$wrongUnitRejected) {
            throw new RuntimeException($driver . ': a unit identity must not issue outside its authenticated unit.');
        }
        $unitJob = cardIssuanceCreateJob(
            $pdo, $unitContext, $unitIdentity, $fixture['tenant_id'], $fixture['subcliente_id'],
            $unitSourceLotId, $designId, 'webp', 'request-key-unit-000001', strtotime('2026-01-01 00:50:00 UTC')
        );
        $fixedQuotaRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $unitContext, $unitIdentity, $fixture['tenant_id'], $fixture['subcliente_id'],
                $unitSourceLotId, $designId, 'webp', 'request-key-unit-000002', strtotime('2026-01-01 00:51:00 UTC')
            );
        } catch (DomainException $exception) {
            $fixedQuotaRejected = $exception->getMessage() === 'COTA_EXCEDIDA';
        }
        $unitQuotas = $pdo->prepare('SELECT id, consumo_carteirinhas FROM tenants WHERE id IN (:root, :child) ORDER BY id');
        $unitQuotas->execute(['root' => $fixture['tenant_id'], 'child' => $fixture['subcliente_id']]);
        $unitQuotaRows = $unitQuotas->fetchAll(PDO::FETCH_ASSOC);
        if (($unitJob['reused'] ?? true) !== false || !$fixedQuotaRejected || count($unitQuotaRows) !== 2
            || (int) $unitQuotaRows[0]['consumo_carteirinhas'] !== 1 || (int) $unitQuotaRows[1]['consumo_carteirinhas'] !== 1) {
            throw new RuntimeException($driver . ': unit issuance must honor both the Sede and fixed unit quota.');
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
        if ($unitSourceLotId !== null) {
            $pdo->prepare('DELETE FROM lotes_importacao WHERE lote_origem_id = :source')->execute(['source' => $unitSourceLotId]);
            $pdo->prepare('DELETE FROM titulares WHERE lote_origem_id = :source')->execute(['source' => $unitSourceLotId]);
            $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :source')->execute(['source' => $unitSourceLotId]);
        }
        if ($unitOwnerId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $unitOwnerId]);
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
        putenv($originalAppBaseUrl === false ? 'APP_BASE_URL' : 'APP_BASE_URL=' . $originalAppBaseUrl);
    }
}

return 'pass';
