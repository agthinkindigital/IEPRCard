<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $planId = null;
    $ownerId = null;
    $sourceLotId = null;
    $designId = null;
    $secondDesignId = null;
    $testAssetKeys = [];
    try {
        $suffix = bin2hex(random_bytes(4));
        $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')
            ->execute(['name' => 'Issuance mapping ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
        )->execute(['plan' => $planId, 'key' => 'carteirinha.gerar']);
        $pdo->prepare(
            'UPDATE tenants SET plano_id = :plan, limite_carteirinhas = 5, consumo_carteirinhas = 0 WHERE id = :tenant'
        )->execute(['plan' => $planId, 'tenant' => $fixture['tenant_id']]);
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'role' => 'titular_cliente',
            'name' => 'Mapping owner',
            'email' => 'mapping-' . $suffix . '@example.test',
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

        $design = [
            'versao_schema' => '1.0',
            'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'],
            'elementos' => [[
                'id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
                'x' => 20, 'y' => 20, 'largura_max' => 400, 'fonte_arquivo' => 'Inter-Bold.otf',
                'tamanho_pt' => 20, 'cor_hex' => '#0F172A', 'alinhamento' => 'left', 'obrigatorio' => true,
            ]],
        ];
        $fixedMapping = [
            'versao_schema' => '1.0',
            'mapeamentos' => ['nome' => ['tipo' => 'campo', 'origem' => 'titular.nome_completo']],
        ];
        $pdo->prepare(
            'INSERT INTO designs_carteirinha '
            . '(tenant_id, nome_layout, orientacao, ativo, mapa_campos_json, mapeamento_fixo_json, criado_em) '
            . 'VALUES (:tenant, :name, :orientation, 1, :design, :mapping, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Mapping design ' . $suffix,
            'orientation' => 'horizontal',
            'design' => json_encode($design, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode($fixedMapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created' => '2026-01-01 00:00:00',
        ]);
        $designId = (int) $pdo->lastInsertId();

        $importMapping = ['NOME' => 'nome_completo'];
        $pdo->prepare(
            'INSERT INTO lotes_importacao '
            . '(tenant_id, subcliente_id, usuario_id, origem_tipo, mapa_de_para_usado_json, total_registros, '
            . 'processados_sucesso, status_lote, criado_em, finalizado_em) '
            . 'VALUES (:tenant, NULL, :user, :origin, :mapping, 1, 1, :status, :created, :finished)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'user' => $ownerId,
            'origin' => 'csv',
            'mapping' => json_encode($importMapping, JSON_THROW_ON_ERROR),
            'status' => 'concluido',
            'created' => '2026-01-01 00:00:00',
            'finished' => '2026-01-01 00:00:01',
        ]);
        $sourceLotId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, lote_origem_id, ativo, criado_em) '
            . 'VALUES (:tenant, NULL, :name, :lot, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Mapping source', 'lot' => $sourceLotId,
            'created' => '2026-01-01 00:00:00',
        ]);

        $override = [
            'versao_schema' => '1.0',
            'mapeamentos' => ['nome' => ['tipo' => 'valor', 'valor' => 'Job A']],
        ];
        $job = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'mapping-request-' . $suffix, strtotime('2026-01-01 00:02:00 UTC'), $override
        );
        $retry = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
            'mapping-request-' . $suffix, strtotime('2026-01-01 00:03:00 UTC'), $override
        );
        $conflictRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
                'mapping-request-' . $suffix, strtotime('2026-01-01 00:04:00 UTC'), [
                    'versao_schema' => '1.0',
                    'mapeamentos' => ['nome' => ['tipo' => 'valor', 'valor' => 'Different mapping']],
                ]
            );
        } catch (DomainException $exception) {
            $conflictRejected = $exception->getMessage() === 'ISSUANCE_IDEMPOTENCY_CONFLICT';
        }
        $invalidRejected = false;
        try {
            cardIssuanceCreateJob(
                $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $designId, 'webp',
                'invalid-mapping-' . $suffix, strtotime('2026-01-01 00:05:00 UTC'), [
                    'versao_schema' => '1.0',
                    'mapeamentos' => ['missing_design_variable' => ['tipo' => 'valor', 'valor' => 'must fail']],
                ]
            );
        } catch (DomainException $exception) {
            $invalidRejected = $exception->getMessage() === 'ISSUANCE_MAPPING_INVALID';
        }

        $persisted = $pdo->prepare('SELECT mapa_de_para_usado_json FROM lotes_importacao WHERE id = :id');
        $persisted->execute(['id' => $job['job_id']]);
        $persistedMapping = json_decode((string) $persisted->fetchColumn(), true);
        $sourceMapping = $pdo->prepare('SELECT mapa_de_para_usado_json FROM lotes_importacao WHERE id = :id');
        $sourceMapping->execute(['id' => $sourceLotId]);
        $sourceMappingValue = json_decode((string) $sourceMapping->fetchColumn(), true);
        $quota = $pdo->prepare('SELECT consumo_carteirinhas FROM tenants WHERE id = :tenant');
        $quota->execute(['tenant' => $fixture['tenant_id']]);
        $jobs = $pdo->prepare('SELECT COUNT(*) FROM lotes_importacao WHERE lote_origem_id = :source AND origem_tipo = :origin');
        $jobs->execute(['source' => $sourceLotId, 'origin' => 'emissao']);
        if (($job['reused'] ?? true) !== false || ($retry['job_id'] ?? null) !== $job['job_id']
            || ($retry['reused'] ?? false) !== true || !$conflictRejected
            || !$invalidRejected || (int) $quota->fetchColumn() !== 1 || (int) $jobs->fetchColumn() !== 1
            || $persistedMapping != $override || $sourceMappingValue !== $importMapping) {
            throw new RuntimeException($driver . ': an emission job must persist its own mapping without changing the source import mapping.');
        }

        $pdo->prepare(
            'INSERT INTO designs_carteirinha '
            . '(tenant_id, nome_layout, orientacao, ativo, mapa_campos_json, mapeamento_fixo_json, criado_em) '
            . 'VALUES (:tenant, :name, :orientation, 1, :design, :mapping, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Second mapping design ' . $suffix,
            'orientation' => 'horizontal',
            'design' => json_encode($design, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode($fixedMapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created' => '2026-01-01 00:00:01',
        ]);
        $secondDesignId = (int) $pdo->lastInsertId();
        $secondOverride = [
            'versao_schema' => '1.0',
            'mapeamentos' => ['nome' => ['tipo' => 'valor', 'valor' => 'Job B']],
        ];
        $secondJob = cardIssuanceCreateJob(
            $pdo, $context, $identity, $fixture['tenant_id'], null, $sourceLotId, $secondDesignId, 'webp',
            'second-mapping-' . $suffix, strtotime('2026-01-01 00:06:00 UTC'), $secondOverride
        );

        $background = imagecreatetruecolor(1011, 638);
        $white = imagecolorallocate($background, 255, 255, 255);
        imagefill($background, 0, 0, $white);
        ob_start();
        imagepng($background);
        $backgroundBytes = ob_get_clean();
        unset($background);
        $assetRoot = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . (string) $fixture['tenant_id'];
        if (!is_dir($assetRoot) && !mkdir($assetRoot, 0700, true) && !is_dir($assetRoot)) {
            throw new RuntimeException($driver . ': private rendering fixture storage could not be created.');
        }
        $backgroundKeys = [];
        foreach (['frente', 'verso'] as $side) {
            $token = substr(hash('sha256', $driver . ':' . $side . ':' . $sourceLotId), 0, 48);
            $key = 'private://' . $fixture['tenant_id'] . '/' . $token . '.png';
            if (file_put_contents($assetRoot . DIRECTORY_SEPARATOR . $token . '.png', $backgroundBytes) === false) {
                throw new RuntimeException($driver . ': private rendering fixture could not be written.');
            }
            $testAssetKeys[] = $key;
            $backgroundKeys[$side] = $key;
        }
        foreach ([$designId, $secondDesignId] as $activeDesignId) {
            $pdo->prepare(
                'UPDATE designs_carteirinha SET bg_frente_url = :front, bg_verso_url = :back WHERE id = :id'
            )->execute([
                'front' => $backgroundKeys['frente'], 'back' => $backgroundKeys['verso'], 'id' => $activeDesignId,
            ]);
        }

        if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:10:00 UTC'))) {
            throw new RuntimeException($driver . ': the worker should render the first mapping-specific job.');
        }
        $firstSnapshotQuery = $pdo->prepare('SELECT snapshot_dados_json FROM carteirinhas_emitidas WHERE lote_id = :job');
        $firstSnapshotQuery->execute(['job' => $job['job_id']]);
        $firstSnapshot = json_decode((string) $firstSnapshotQuery->fetchColumn(), true);
        if (($firstSnapshot['nome'] ?? null) !== 'Job A') {
            cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:10:30 UTC'));
            cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:11:00 UTC'));
            $jobState = $pdo->prepare('SELECT status_lote, erro_codigo FROM lotes_importacao WHERE id = :id');
            $jobState->execute(['id' => $job['job_id']]);
            $state = $jobState->fetch(PDO::FETCH_ASSOC);
            throw new RuntimeException($driver . ': job mapping snapshot mismatch: '
                . json_encode(['snapshot' => $firstSnapshot, 'state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'processando', worker_lease_until = :expired WHERE id = :id")
            ->execute(['expired' => '2025-12-31 23:59:00', 'id' => $job['job_id']]);
        if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:11:00 UTC'))) {
            throw new RuntimeException($driver . ': an expired mapping-specific job lease should be recoverable.');
        }
        $recoveredCards = $pdo->prepare('SELECT COUNT(*) FROM carteirinhas_emitidas WHERE lote_id = :job');
        $recoveredCards->execute(['job' => $job['job_id']]);
        if ((int) $recoveredCards->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': a recovered mapping-specific job must not duplicate its card.');
        }

        if (!cardIssuanceWorkerRunOne($pdo, strtotime('2026-01-01 00:12:00 UTC'))) {
            throw new RuntimeException($driver . ': the worker should process another design from the same source lot.');
        }
        $secondSnapshotQuery = $pdo->prepare('SELECT snapshot_dados_json FROM carteirinhas_emitidas WHERE lote_id = :job');
        $secondSnapshotQuery->execute(['job' => $secondJob['job_id']]);
        $secondSnapshot = json_decode((string) $secondSnapshotQuery->fetchColumn(), true);
        $sourceMapping->execute(['id' => $sourceLotId]);
        if (($secondSnapshot['nome'] ?? null) !== 'Job B'
            || json_decode((string) $sourceMapping->fetchColumn(), true) !== $importMapping) {
            throw new RuntimeException($driver . ': separate designs and jobs must retain independent mappings and source data.');
        }
    } finally {
        if ($fixture !== null) {
            $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            foreach ($testAssetKeys as $assetKey) {
                cardIssuanceWorkerDeleteAsset($fixture['tenant_id'], $assetKey);
            }
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
        if ($secondDesignId !== null) {
            $pdo->prepare('DELETE FROM designs_carteirinha WHERE id = :id')->execute(['id' => $secondDesignId]);
        }
        tenantTestCleanup($pdo, $fixture);
        if ($planId !== null) {
            $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
            $pdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
        }
    }
}

return 'pass';
