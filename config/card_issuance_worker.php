<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/design_mapping.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer_batch.php';
require_once dirname(__DIR__) . '/includes/card_pdf_renderer_a4.php';

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
        'SELECT tenant_id, subcliente_id, design_id, lote_origem_id, mapa_de_para_usado_json, '
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
    $membersQuery = $pdo->prepare(
        'SELECT id, nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, '
        . 'data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
        . 'FROM titulares WHERE lote_origem_id = :source AND tenant_id = :tenant '
        . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) ORDER BY id'
    );
    $membersQuery->execute([
        'source' => (int) $job['lote_origem_id'], 'tenant' => $tenantId,
        'child_match' => $subclientId, 'child_null' => $subclientId,
    ]);
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
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE lotes_importacao SET pdf_consolidado_url = :key WHERE id = :id')
                ->execute(['key' => $key, 'id' => $jobId]);
            foreach ($cards as $entry) {
                cardIssuanceWorkerInsertCard($pdo, $job, $jobId, $entry, null, null, null, $now);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            cardIssuanceWorkerDeleteAsset($tenantId, $key);
            throw $exception;
        }
    }
    cardIssuanceWorkerFinalize($pdo, $jobId, $now);
}

/** @param array<string,mixed> $job @param array<string,mixed> $entry */
function cardIssuanceWorkerPersistWebp(PDO $pdo, array $job, int $jobId, array $entry, int $now): void
{
    $rendered = cardRenderWebpSides($entry['card']['design'], $entry['card']['values'], $entry['card']['backgrounds'], $entry['card']['photo_assets']);
    if (!$rendered['valid']) {
        throw new RuntimeException('ISSUANCE_RENDER_FAILED');
    }
    $tenantId = (int) $job['tenant_id'];
    $holderId = (int) $entry['member']['id'];
    $front = cardIssuanceWorkerStore($tenantId, $jobId, $holderId * 2, 'webp', $rendered['images']['frente']);
    $back = cardIssuanceWorkerStore($tenantId, $jobId, $holderId * 2 + 1, 'webp', $rendered['images']['verso']);
    $pdo->beginTransaction();
    try {
        cardIssuanceWorkerInsertCard($pdo, $job, $jobId, $entry, $front, $back, null, $now);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cardIssuanceWorkerDeleteAsset($tenantId, $front);
        cardIssuanceWorkerDeleteAsset($tenantId, $back);
        throw $exception;
    }
}

/** @param array<string,mixed> $job @param array<string,mixed> $entry */
function cardIssuanceWorkerInsertCard(PDO $pdo, array $job, int $jobId, array $entry, ?string $front, ?string $back, ?string $pdf, int $now): void
{
    $snapshot = $entry['card']['values'];
    $stmt = $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas (tenant_id, subcliente_id, titular_id, design_id, lote_id, '
        . 'codigo_validacao_qr, status_carteirinha, snapshot_dados_json, webp_frente_url, webp_verso_url, '
        . 'pdf_individual_url, emitida_em) VALUES (:tenant, :child, :holder, :design, :job, :code, :status, :snapshot, :front, :back, :pdf, :created)'
    );
    $stmt->execute([
        'tenant' => (int) $job['tenant_id'], 'child' => $job['subcliente_id'], 'holder' => (int) $entry['member']['id'],
        'design' => (int) $job['design_id'], 'job' => $jobId, 'code' => $entry['code'], 'status' => 'ativa',
        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'front' => $front, 'back' => $back, 'pdf' => $pdf, 'created' => gmdate('Y-m-d H:i:s', $now),
    ]);
}

function cardIssuanceWorkerFindExisting(PDO $pdo, int $jobId, int $holderId): ?array
{
    $query = $pdo->prepare('SELECT id FROM carteirinhas_emitidas WHERE lote_id = :job AND titular_id = :holder LIMIT 1');
    $query->execute(['job' => $jobId, 'holder' => $holderId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function cardIssuanceWorkerFinalize(PDO $pdo, int $jobId, int $now): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE reservas_cota SET estado = 'consumida', concluido_em = :at WHERE lote_id = :job AND estado = 'reservada'")
            ->execute(['at' => gmdate('Y-m-d H:i:s', $now), 'job' => $jobId]);
        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'concluido', processados_sucesso = total_registros, worker_lease_until = NULL, erro_codigo = NULL, finalizado_em = :at WHERE id = :id")
            ->execute(['at' => gmdate('Y-m-d H:i:s', $now), 'id' => $jobId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $exception;
    }
}

function cardIssuanceWorkerFailOrRetry(PDO $pdo, int $jobId, Throwable $cause, int $now): void
{
    $query = $pdo->prepare('SELECT tenant_id, subcliente_id, total_registros, worker_attempts FROM lotes_importacao WHERE id = :id LIMIT 1');
    $query->execute(['id' => $jobId]);
    $job = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($job)) { return; }
    if ((int) $job['worker_attempts'] < 3) {
        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'na_fila', worker_lease_until = NULL, processados_erro = 0 WHERE id = :id")
            ->execute(['id' => $jobId]);
        return;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE reservas_cota SET estado = 'liberada', concluido_em = :at WHERE lote_id = :job AND estado = 'reservada'")
            ->execute(['at' => gmdate('Y-m-d H:i:s', $now), 'job' => $jobId]);
        $root = $pdo->prepare('UPDATE tenants SET consumo_carteirinhas = CASE WHEN consumo_carteirinhas >= :quantity_check THEN consumo_carteirinhas - :quantity_sub ELSE 0 END WHERE id = :tenant');
        $root->execute(['quantity_check' => (int) $job['total_registros'], 'quantity_sub' => (int) $job['total_registros'], 'tenant' => (int) $job['tenant_id']]);
        if ($job['subcliente_id'] !== null) {
            $pdo->prepare('UPDATE tenants SET consumo_carteirinhas = CASE WHEN consumo_carteirinhas >= :quantity_check THEN consumo_carteirinhas - :quantity_sub ELSE 0 END WHERE id = :child AND parent_id = :parent')
                ->execute(['quantity_check' => (int) $job['total_registros'], 'quantity_sub' => (int) $job['total_registros'], 'child' => (int) $job['subcliente_id'], 'parent' => (int) $job['tenant_id']]);
        }
        $errorCode = preg_match('/^[A-Z0-9_]{1,80}$/D', $cause->getMessage()) === 1
            ? $cause->getMessage()
            : 'ISSUANCE_PROCESSING_FAILED';
        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'falhou', worker_lease_until = NULL, processados_erro = total_registros, erro_codigo = :error, finalizado_em = :at WHERE id = :id")
            ->execute(['error' => $errorCode, 'at' => gmdate('Y-m-d H:i:s', $now), 'id' => $jobId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $exception;
    }
    cardIssuanceWorkerCompensate($pdo, $jobId, (int) $job['tenant_id']);
}

function cardIssuanceWorkerCompensate(PDO $pdo, int $jobId, int $tenantId): void
{
    $cleanupFailed = false;
    $job = $pdo->prepare('SELECT pdf_consolidado_url FROM lotes_importacao WHERE id = :job AND tenant_id = :tenant');
    $job->execute(['job' => $jobId, 'tenant' => $tenantId]);
    $pdfKey = $job->fetchColumn();
    if (is_string($pdfKey) && $pdfKey !== '') {
        try { cardIssuanceWorkerDeleteAsset($tenantId, $pdfKey); } catch (Throwable) { $cleanupFailed = true; }
        $pdo->prepare('UPDATE lotes_importacao SET pdf_consolidado_url = NULL WHERE id = :job AND tenant_id = :tenant')
            ->execute(['job' => $jobId, 'tenant' => $tenantId]);
    }
    $query = $pdo->prepare('SELECT webp_frente_url, webp_verso_url, pdf_individual_url FROM carteirinhas_emitidas WHERE lote_id = :job AND tenant_id = :tenant');
    $query->execute(['job' => $jobId, 'tenant' => $tenantId]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ($row as $key) {
            if (is_string($key) && $key !== '') {
                try { cardIssuanceWorkerDeleteAsset($tenantId, $key); } catch (Throwable) { $cleanupFailed = true; }
            }
        }
    }
    $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE lote_id = :job AND tenant_id = :tenant')->execute(['job' => $jobId, 'tenant' => $tenantId]);
    if ($cleanupFailed) {
        $pdo->prepare("UPDATE lotes_importacao SET erro_codigo = 'ISSUANCE_COMPENSATION_FAILED' WHERE id = :job AND tenant_id = :tenant")
            ->execute(['job' => $jobId, 'tenant' => $tenantId]);
    }
}

function cardIssuanceWorkerReadAsset(int $tenantId, string $storedKey): string
{
    $path = cardIssuanceWorkerResolvePath($tenantId, $storedKey);
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 20_000_000) { throw new RuntimeException('ISSUANCE_ASSET_UNAVAILABLE'); }
    return $bytes;
}

function cardIssuanceWorkerStore(int $tenantId, int $jobId, int $item, string $extension, string $bytes): string
{
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_UNAVAILABLE'); }
    if (is_link($dir) || !is_string(realpath($dir)) || !batchImportStagingPathWithin((string) realpath($dir), $root)) {
        throw new RuntimeException('ISSUANCE_STORAGE_UNAVAILABLE');
    }
    $token = substr(hash('sha256', $jobId . ':' . $item . ':' . $extension), 0, 48);
    $path = $dir . DIRECTORY_SEPARATOR . $token . '.' . $extension;
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('ISSUANCE_STORAGE_WRITE_FAILED');
    }
    @chmod($path, 0600);
    return 'private://' . $tenantId . '/' . $token . '.' . $extension;
}

function cardIssuanceWorkerResolvePath(int $tenantId, string $key): string
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $key, $matches) !== 1
        || (int) $matches[1] !== $tenantId) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . $tenantId;
    if (is_link($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $realDir = realpath($dir);
    if (!is_string($realDir) || !batchImportStagingPathWithin($realDir, $root)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $path = $realDir . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path) || !is_file($path) || !batchImportStagingPathWithin((string) realpath($path), $root)) { throw new RuntimeException('ISSUANCE_ASSET_UNAVAILABLE'); }
    return $path;
}

function cardIssuanceWorkerDeleteAsset(int $tenantId, string $key): void
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $key, $matches) !== 1
        || (int) $matches[1] !== $tenantId) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $realDir = realpath($dir);
    if (!is_string($realDir) || !batchImportStagingPathWithin($realDir, $root)) { return; }
    $path = $realDir . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (!file_exists($path)) { return; }
    if (is_link($path) || !is_file($path) || !batchImportStagingPathWithin((string) realpath($path), $root) || !unlink($path)) {
        throw new RuntimeException('ISSUANCE_STORAGE_DELETE_FAILED');
    }
}

function cardIssuanceWorkerValidationUrl(PDO $pdo, int $tenantId, string $code): string
{
    $domain = $pdo->prepare(
        "SELECT hostname FROM dominios_tenant WHERE tenant_id = :tenant AND estado = 'ativo' "
        . 'AND verificado_em IS NOT NULL AND tls_validado_em IS NOT NULL ORDER BY tls_validado_em DESC LIMIT 1'
    );
    $domain->execute(['tenant' => $tenantId]);
    $hostname = $domain->fetchColumn();
    $base = is_string($hostname) && preg_match('/^[a-z0-9.-]+$/iD', $hostname) === 1
        ? 'https://' . $hostname
        : rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');
    if ($base === '') { throw new RuntimeException('ISSUANCE_VALIDATION_URL_UNCONFIGURED'); }
    return $base . '/validar/' . $code;
}
