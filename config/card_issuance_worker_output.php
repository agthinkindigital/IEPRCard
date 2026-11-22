<?php
declare(strict_types=1);

require_once __DIR__ . '/privacy_subjects.php';

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
    $cardId = (int) $pdo->lastInsertId();
    $subject = privacySubjectEnsureTitular($pdo, (int) $entry['member']['id'], (int) $job['tenant_id']);
    privacySubjectLinkRecord(
        $pdo,
        $subject['id'],
        'carteirinha_emitida',
        $cardId
    );
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
        $pdo->prepare(
            "UPDATE whatsapp_conversas SET estado = 'entrega_pendente', atualizado_em = :at "
            . "WHERE job_id = :job AND estado = 'emissao_pendente'"
        )->execute(['at' => gmdate('Y-m-d H:i:s', $now), 'job' => $jobId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $exception;
    }
}
