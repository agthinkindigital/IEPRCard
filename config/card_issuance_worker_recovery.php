<?php
declare(strict_types=1);

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
