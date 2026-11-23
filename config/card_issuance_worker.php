<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/design_mapping.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer_batch.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer_a4.php';
require_once __DIR__ . '/card_issuance_worker_output.php';
require_once __DIR__ . '/card_issuance_worker_recovery.php';
require_once __DIR__ . '/card_issuance_worker_storage.php';

/** Claims and processes one queued or expired emission job. */
function cardIssuanceWorkerRunOne(PDO $pdo, ?int $now = null): bool
{
    $now ??= time();
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $stamp = gmdate('Y-m-d H:i:s', $now);
    $pdo->beginTransaction();
    try {
        if ($driver === 'sqlite') {
            $pdo->exec('UPDATE lotes_importacao SET worker_attempts = worker_attempts WHERE id = -1');
        }
        $sql = "SELECT id FROM lotes_importacao WHERE origem_tipo = 'emissao' AND (status_lote = 'na_fila' "
            . "OR (status_lote = 'processando' AND worker_lease_until < :now)) ORDER BY id LIMIT 1";
        if ($driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $select = $pdo->prepare($sql);
        $select->execute(['now' => $stamp]);
        $jobId = (int) $select->fetchColumn();
        if ($jobId < 1) {
            $pdo->commit();
            return false;
        }
        $claim = $pdo->prepare(
            "UPDATE lotes_importacao SET status_lote = 'processando', worker_lease_until = :lease, erro_codigo = NULL, "
            . 'worker_attempts = worker_attempts + 1 WHERE id = :id AND origem_tipo = \'emissao\' '
            . "AND (status_lote = 'na_fila' OR (status_lote = 'processando' AND worker_lease_until < :expired_at) )"
        );
        $claim->execute(['lease' => gmdate('Y-m-d H:i:s', $now + 900), 'id' => $jobId, 'expired_at' => $stamp]);
        if ($claim->rowCount() !== 1) {
            $pdo->commit();
            return false;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    try {
        cardIssuanceWorkerProcess($pdo, $jobId, $now);
    } catch (Throwable $exception) {
        cardIssuanceWorkerFailOrRetry($pdo, $jobId, $exception, $now);
    }
    return true;
}

/** @return array{processed:int,skipped:bool} */
function cardIssuanceWorkerRunBatch(PDO $pdo, int $maxJobs = 1): array
{
    if ($maxJobs < 1 || $maxJobs > 20) {
        throw new InvalidArgumentException('ISSUANCE_WORKER_LIMIT_INVALID');
    }
    $lockPath = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . 'card-issuance-worker.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('ISSUANCE_WORKER_LOCK_UNAVAILABLE');
    }
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['processed' => 0, 'skipped' => true];
    }

    $processed = 0;
    try {
        while ($processed < $maxJobs && cardIssuanceWorkerRunOne($pdo)) {
            ++$processed;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return ['processed' => $processed, 'skipped' => false];
}

function cardIssuanceWorkerProcess(PDO $pdo, int $jobId, int $now): void
{
    $query = $pdo->prepare(
        'SELECT tenant_id, subcliente_id, design_id, lote_origem_id, titular_origem_id, mapa_de_para_usado_json, '
        . 'chave_idempotencia_hash, modo_saida, total_registros, worker_attempts '
        . 'FROM lotes_importacao WHERE id = :id AND origem_tipo = \'emissao\' AND status_lote = \'processando\' LIMIT 1'
    );
    $query->execute(['id' => $jobId]);
    $job = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($job)) {
        return;
    }
    $tenantId = (int) $job['tenant_id'];
    $subclientId = $job['subcliente_id'] === null ? null : (int) $job['subcliente_id'];
    $designQuery = $pdo->prepare(
        'SELECT mapa_campos_json, mapeamento_fixo_json, bg_frente_url, bg_verso_url '
        . 'FROM designs_carteirinha WHERE id = :design AND tenant_id = :tenant LIMIT 1'
    );
    $designQuery->execute(['design' => (int) $job['design_id'], 'tenant' => $tenantId]);
    $designRow = $designQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($designRow)) {
        throw new RuntimeException('ISSUANCE_DESIGN_UNAVAILABLE');
    }
    $design = json_decode((string) $designRow['mapa_campos_json'], true, 512, JSON_THROW_ON_ERROR);
    $mappingJson = $job['mapa_de_para_usado_json'] === null
        ? (string) $designRow['mapeamento_fixo_json']
        : (string) $job['mapa_de_para_usado_json'];
    $mapping = json_decode($mappingJson, true, 512, JSON_THROW_ON_ERROR);
    $backgrounds = [
        'frente' => cardIssuanceWorkerReadAsset($tenantId, (string) $designRow['bg_frente_url']),
        'verso' => cardIssuanceWorkerReadAsset($tenantId, (string) $designRow['bg_verso_url']),
    ];
    $sourceLotId = ($job['lote_origem_id'] ?? null) === null ? null : (int) $job['lote_origem_id'];
    $sourceHolderId = ($job['titular_origem_id'] ?? null) === null ? null : (int) $job['titular_origem_id'];
    if (($sourceLotId === null) === ($sourceHolderId === null)) {
        throw new RuntimeException('ISSUANCE_SOURCE_INVALID');
    }
    if ($sourceHolderId !== null) {
        $membersQuery = $pdo->prepare(
            'SELECT id, nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, '
            . 'data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
            . 'FROM titulares WHERE id = :holder AND tenant_id = :tenant AND ativo = 1 '
            . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) LIMIT 1'
        );
        $membersQuery->execute([
            'holder' => $sourceHolderId, 'tenant' => $tenantId,
            'child_match' => $subclientId, 'child_null' => $subclientId,
        ]);
    } else {
        $membersQuery = $pdo->prepare(
            'SELECT id, nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, '
            . 'data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
            . 'FROM titulares WHERE lote_origem_id = :source AND tenant_id = :tenant '
            . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) ORDER BY id'
        );
        $membersQuery->execute([
            'source' => $sourceLotId, 'tenant' => $tenantId,
            'child_match' => $subclientId, 'child_null' => $subclientId,
        ]);
    }
    $members = $membersQuery->fetchAll(PDO::FETCH_ASSOC);
    if (count($members) !== (int) $job['total_registros'] || $members === []) {
        throw new RuntimeException('ISSUANCE_SOURCE_CHANGED');
    }

    $cards = [];
    foreach (array_chunk($members, 25) as $chunk) {
        foreach ($chunk as $member) {
            $existing = cardIssuanceWorkerFindExisting($pdo, $jobId, (int) $member['id']);
            if ($existing !== null) {
                continue;
            }
            $extra = json_decode((string) ($member['dados_extras_json'] ?? '{}'), true);
            $record = ['titular' => $member + ['dados_extras' => is_array($extra) ? $extra : []]];
            $code = bin2hex(random_bytes(32));
            $systemContext = [];
            foreach ($design['elementos'] as $element) {
                if ($element['tipo'] === 'qrcode_validacao') {
                    $systemContext['_sistema_url_validacao'] = cardIssuanceWorkerValidationUrl($pdo, $tenantId, $code);
                    break;
                }
            }
            $resolved = designMappingResolve($design, $mapping, $record, $systemContext);
            if (!$resolved['valid']) {
                throw new RuntimeException('ISSUANCE_MAPPING_INVALID');
            }
            $values = $resolved['values'];
            foreach ($design['elementos'] as $element) {
                if ($element['tipo'] === 'codigo_barras') {
                    $values[$element['chave_variavel']] = $code;
                }
            }
            $photoAssets = [];
            if (is_string($member['foto_url'] ?? null) && $member['foto_url'] !== '') {
                $photoAssets['foto'] = cardIssuanceWorkerReadAsset($tenantId, $member['foto_url']);
            }
            $card = ['design' => $design, 'values' => $values, 'backgrounds' => $backgrounds, 'photo_assets' => $photoAssets];
            $cards[] = ['member' => $member, 'code' => $code, 'card' => $card];
        }
        if ($job['modo_saida'] === 'webp') {
            foreach ($cards as $entry) {
                cardIssuanceWorkerPersistWebp($pdo, $job, $jobId, $entry, $now);
            }
            $cards = [];
        }
        $pdo->prepare("UPDATE lotes_importacao SET worker_lease_until = :lease WHERE id = :id AND status_lote = 'processando'")
            ->execute(['lease' => gmdate('Y-m-d H:i:s', time() + 900), 'id' => $jobId]);
        $progressCount = $pdo->prepare('SELECT COUNT(*) FROM carteirinhas_emitidas WHERE lote_id = :job');
        $progressCount->execute(['job' => $jobId]);
        $progress = $pdo->prepare('UPDATE lotes_importacao SET processados_sucesso = :done WHERE id = :id');
        $progress->execute(['done' => (int) $progressCount->fetchColumn(), 'id' => $jobId]);
    }

    if ($job['modo_saida'] !== 'webp') {
        if ($cards === []) {
            // A recovered PDF job whose card records were already saved can be finalized without rerendering.
            cardIssuanceWorkerFinalize($pdo, $jobId, $now);
            return;
        }
        $renderCards = array_column($cards, 'card');
        $rendered = match ($job['modo_saida']) {
            'pdf_cr80' => cardRenderPdfBatch($renderCards),
            'pdf_a4_8up' => cardRenderPdfA4Batch($renderCards, '8up'),
            'pdf_a4_10up' => cardRenderPdfA4Batch($renderCards, '10up'),
            default => throw new RuntimeException('ISSUANCE_OUTPUT_INVALID'),
        };
        if (!$rendered['valid']) {
            throw new RuntimeException('ISSUANCE_RENDER_FAILED');
        }
        $key = cardIssuanceWorkerStore($tenantId, $jobId, 0, 'pdf', $rendered['pdf']);
        $individualPdfKeys = [];
        try {
            foreach ($cards as $entry) {
                $holderId = (int) $entry['member']['id'];
                $individual = match ($job['modo_saida']) {
                    'pdf_cr80' => cardRenderPdfBatch([$entry['card']]),
                    'pdf_a4_8up' => cardRenderPdfA4Batch([$entry['card']], '8up'),
                    'pdf_a4_10up' => cardRenderPdfA4Batch([$entry['card']], '10up'),
                    default => throw new RuntimeException('ISSUANCE_OUTPUT_INVALID'),
                };
                if (!$individual['valid']) {
                    throw new RuntimeException('ISSUANCE_INDIVIDUAL_RENDER_FAILED');
                }
                $individualPdfKeys[$holderId] = cardIssuanceWorkerStore(
                    $tenantId,
                    $jobId,
                    $holderId,
                    'pdf',
                    $individual['pdf']
                );
            }
        } catch (Throwable $exception) {
            foreach ($individualPdfKeys as $individualPdfKey) {
                cardIssuanceWorkerDeleteAsset($tenantId, $individualPdfKey);
            }
            cardIssuanceWorkerDeleteAsset($tenantId, $key);
            throw $exception;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE lotes_importacao SET pdf_consolidado_url = :key WHERE id = :id')
                ->execute(['key' => $key, 'id' => $jobId]);
            foreach ($cards as $entry) {
                $holderId = (int) $entry['member']['id'];
                cardIssuanceWorkerInsertCard($pdo, $job, $jobId, $entry, null, null, $individualPdfKeys[$holderId], $now);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cardIssuanceWorkerDeleteAsset($tenantId, $key);
            foreach ($individualPdfKeys as $individualPdfKey) {
                cardIssuanceWorkerDeleteAsset($tenantId, $individualPdfKey);
            }
            throw $exception;
        }
    }
    cardIssuanceWorkerFinalize($pdo, $jobId, $now);
}
