<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_quota_policy_support.php';

/** @param array<string, mixed> $identity @return array{job_id:int,status:string,quantity:int,reused:bool} */
function cardIssuanceCreateJob(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    int $sourceLotId,
    int $designId,
    string $outputMode,
    string $idempotencyKey,
    ?int $now = null
): array {
    if ($tenantId < 1 || $sourceLotId < 1 || $designId < 1
        || ($subclienteId !== null && $subclienteId < 1)
        || !in_array($outputMode, ['webp', 'pdf_cr80', 'pdf_a4_8up', 'pdf_a4_10up'], true)
        || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 200
        || preg_match('/^[A-Za-z0-9._~-]+$/D', $idempotencyKey) !== 1) {
        throw new DomainException('ISSUANCE_REQUEST_INVALID');
    }
    if (!pode($pdo, $context, $identity, 'carteirinha.gerar', $subclienteId)) {
        throw new DomainException('ISSUANCE_DENIED');
    }

    $idempotencyHash = hash('sha256', $idempotencyKey);
    $result = null;
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $subclienteId,
            $sourceLotId,
            $designId,
            $outputMode,
            $idempotencyHash,
            $now,
            &$result
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!pode($pdo, $context, $identity, 'carteirinha.gerar', $subclienteId)) {
                throw new DomainException('ISSUANCE_DENIED');
            }

            $existingQuery = $pdo->prepare(
                'SELECT id, subcliente_id, lote_origem_id, design_id, modo_saida, total_registros, status_lote '
                . 'FROM lotes_importacao WHERE tenant_id = :tenant AND chave_idempotencia_hash = :key_hash LIMIT 1'
            );
            $existingQuery->execute(['tenant' => $tenantId, 'key_hash' => $idempotencyHash]);
            $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                $result = cardIssuanceExistingJobResult(
                    $existing,
                    $tenantId,
                    $subclienteId,
                    $sourceLotId,
                    $designId,
                    $outputMode
                );
                return;
            }

            $source = $pdo->prepare(
                'SELECT subcliente_id, status_lote FROM lotes_importacao '
                . 'WHERE id = :id AND tenant_id = :tenant LIMIT 1'
            );
            $source->execute(['id' => $sourceLotId, 'tenant' => $tenantId]);
            $sourceRecord = $source->fetch(PDO::FETCH_ASSOC);
            if (!is_array($sourceRecord)
                || cardIssuanceNullableId($sourceRecord['subcliente_id']) !== $subclienteId
                || $sourceRecord['status_lote'] !== 'concluido') {
                throw new DomainException('ISSUANCE_SOURCE_NOT_FOUND');
            }

            $designQuery = $pdo->prepare(
                'SELECT compartilhado_subclientes FROM designs_carteirinha '
                . 'WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1'
            );
            $designQuery->execute(['id' => $designId, 'tenant' => $tenantId]);
            $sharedWithSubclients = $designQuery->fetchColumn();
            if ($sharedWithSubclients === false || $subclienteId !== null && (int) $sharedWithSubclients !== 1) {
                throw new DomainException('ISSUANCE_DESIGN_NOT_FOUND');
            }

            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM titulares WHERE lote_origem_id = :lot AND tenant_id = :tenant '
                . 'AND ((subcliente_id = :subclient_match) OR (subcliente_id IS NULL AND :subclient_null IS NULL))'
            );
            $count->execute([
                'lot' => $sourceLotId,
                'tenant' => $tenantId,
                'subclient_match' => $subclienteId,
                'subclient_null' => $subclienteId,
            ]);
            $quantity = (int) $count->fetchColumn();
            if ($quantity < 1 || $quantity > 500) {
                throw new DomainException('ISSUANCE_SOURCE_EMPTY_OR_TOO_LARGE');
            }
            if ($outputMode !== 'webp' && $quantity > 50) {
                throw new DomainException('ISSUANCE_PDF_MAX_50');
            }

            cardIssuanceReserveQuota($pdo, $tenantId, $subclienteId, $quantity);
            $createdAt = gmdate('Y-m-d H:i:s', $now ?? time());
            $insertJob = $pdo->prepare(
                'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, design_id, lote_origem_id, '
                . 'chave_idempotencia_hash, origem_tipo, modo_saida, total_registros, processados_sucesso, '
                . 'processados_erro, status_lote, criado_em) '
                . 'VALUES (:tenant, :subclient, :user, :design, :source, :key_hash, :origin, :mode, :total, 0, 0, :status, :created)'
            );
            $insertJob->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'user' => (int) $identity['id'],
                'design' => $designId,
                'source' => $sourceLotId,
                'key_hash' => $idempotencyHash,
                'origin' => 'emissao',
                'mode' => $outputMode,
                'total' => $quantity,
                'status' => 'na_fila',
                'created' => $createdAt,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            if ($jobId < 1) {
                throw new RuntimeException('ISSUANCE_JOB_FAILED');
            }

            $reservation = $pdo->prepare(
                'INSERT INTO reservas_cota (tenant_id, subcliente_id, lote_id, quantidade, estado, expira_em, criado_em) '
                . 'VALUES (:tenant, :subclient, :job, :quantity, :state, :expires, :created)'
            );
            $reservation->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'job' => $jobId,
                'quantity' => $quantity,
                'state' => 'reservada',
                'expires' => gmdate('Y-m-d H:i:s', ($now ?? time()) + 86400),
                'created' => $createdAt,
            ]);
            $result = [
                'job_id' => $jobId,
                'status' => 'na_fila',
                'quantity' => $quantity,
                'reused' => false,
            ];
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'TENANT_QUOTA_FAILED') {
            throw $exception;
        }
        $existingQuery = $pdo->prepare(
            'SELECT id, subcliente_id, lote_origem_id, design_id, modo_saida, total_registros, status_lote '
            . 'FROM lotes_importacao WHERE tenant_id = :tenant AND chave_idempotencia_hash = :key_hash LIMIT 1'
        );
        $existingQuery->execute(['tenant' => $tenantId, 'key_hash' => $idempotencyHash]);
        $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            throw $exception;
        }
        return cardIssuanceExistingJobResult(
            $existing,
            $tenantId,
            $subclienteId,
            $sourceLotId,
            $designId,
            $outputMode
        );
    }

    if (!is_array($result)) {
        throw new RuntimeException('ISSUANCE_JOB_FAILED');
    }

    return $result;
}

/** @param array<string, mixed> $existing @return array{job_id:int,status:string,quantity:int,reused:bool} */
function cardIssuanceExistingJobResult(
    array $existing,
    int $tenantId,
    ?int $subclienteId,
    int $sourceLotId,
    int $designId,
    string $outputMode
): array {
    if ((int) ($existing['lote_origem_id'] ?? 0) !== $sourceLotId
        || cardIssuanceNullableId($existing['subcliente_id'] ?? null) !== $subclienteId
        || (int) ($existing['design_id'] ?? 0) !== $designId
        || (string) ($existing['modo_saida'] ?? '') !== $outputMode) {
        throw new DomainException('ISSUANCE_IDEMPOTENCY_CONFLICT');
    }

    return [
        'job_id' => (int) $existing['id'],
        'status' => (string) $existing['status_lote'],
        'quantity' => (int) $existing['total_registros'],
        'reused' => true,
    ];
}

function cardIssuanceNullableId(mixed $value): ?int
{
    return $value === null ? null : (int) $value;
}

function cardIssuanceReserveQuota(PDO $pdo, int $tenantId, ?int $subclienteId, int $quantity): void
{
    $root = $pdo->prepare(
        "SELECT limite_carteirinhas, consumo_carteirinhas FROM tenants "
        . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
    );
    $root->execute(['id' => $tenantId]);
    $rootRow = $root->fetch(PDO::FETCH_ASSOC);
    if (!is_array($rootRow)) {
        throw new DomainException('ISSUANCE_TENANT_NOT_FOUND');
    }
    $rootLimit = tenantQuotaNullableInteger($rootRow['limite_carteirinhas']);
    $rootConsumed = (int) $rootRow['consumo_carteirinhas'];
    if ($rootLimit === null) {
        throw new DomainException('TENANT_QUOTA_UNCONFIGURED');
    }
    if ($rootConsumed < 0 || $rootConsumed > $rootLimit - $quantity) {
        throw new DomainException('COTA_EXCEDIDA');
    }
    $rootUpdate = $pdo->prepare(
        "UPDATE tenants SET consumo_carteirinhas = consumo_carteirinhas + :quantity "
        . "WHERE id = :id AND parent_id IS NULL AND consumo_carteirinhas <= :remaining"
    );
    $rootUpdate->execute(['quantity' => $quantity, 'id' => $tenantId, 'remaining' => $rootLimit - $quantity]);
    if ($rootUpdate->rowCount() !== 1) {
        throw new DomainException('COTA_EXCEDIDA');
    }

    if ($subclienteId === null) {
        return;
    }
    $child = $pdo->prepare(
        "SELECT limite_carteirinhas, consumo_carteirinhas FROM tenants "
        . "WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' AND status_conta = 'ativo' LIMIT 1"
    );
    $child->execute(['id' => $subclienteId, 'parent' => $tenantId]);
    $childRow = $child->fetch(PDO::FETCH_ASSOC);
    if (!is_array($childRow)) {
        throw new DomainException('ISSUANCE_SCOPE_NOT_FOUND');
    }
    $childLimit = tenantQuotaNullableInteger($childRow['limite_carteirinhas']);
    $childConsumed = (int) $childRow['consumo_carteirinhas'];
    if ($childLimit !== null && ($childConsumed < 0 || $childConsumed > $childLimit - $quantity)) {
        throw new DomainException('COTA_EXCEDIDA');
    }
    $childUpdate = $pdo->prepare(
        'UPDATE tenants SET consumo_carteirinhas = consumo_carteirinhas + :quantity '
        . 'WHERE id = :id AND parent_id = :parent AND consumo_carteirinhas <= :remaining'
    );
    $childUpdate->execute([
        'quantity' => $quantity,
        'id' => $subclienteId,
        'parent' => $tenantId,
        'remaining' => $childLimit === null ? PHP_INT_MAX - $quantity : $childLimit - $quantity,
    ]);
    if ($childUpdate->rowCount() !== 1) {
        throw new DomainException('COTA_EXCEDIDA');
    }
}
