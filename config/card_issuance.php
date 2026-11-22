<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_quota_policy_support.php';
require_once __DIR__ . '/design_mapping.php';
require_once __DIR__ . '/card_issuance_source.php';
require_once __DIR__ . '/card_issuance_support.php';

/** @param array<string, mixed> $identity @return array{job_id:int,status:string,quantity:int,reused:bool} */
function cardIssuanceCreateJob(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    ?int $sourceLotId,
    int $designId,
    string $outputMode,
    string $idempotencyKey,
    ?int $now = null,
    mixed $mappingOverride = null,
    ?int $titularId = null
): array {
    if ($tenantId < 1 || $designId < 1
        || (($sourceLotId === null) === ($titularId === null))
        || ($sourceLotId !== null && $sourceLotId < 1)
        || ($titularId !== null && $titularId < 1)
        || ($subclienteId !== null && $subclienteId < 1)
        || !in_array($outputMode, ['webp', 'pdf_cr80', 'pdf_a4_8up', 'pdf_a4_10up'], true)
        || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 200
        || preg_match('/^[A-Za-z0-9._~-]+$/D', $idempotencyKey) !== 1) {
        throw new DomainException('ISSUANCE_REQUEST_INVALID');
    }
    $mappingOverrideJson = cardIssuanceEncodeMappingOverride($mappingOverride);
    if ($context->tenantId !== $tenantId
        || (int) ($identity['tenant_id'] ?? 0) !== $tenantId
        || !in_array($context->tipo, ['cliente', 'subcliente'], true)
        || ($context->tipo === 'subcliente' && $context->subclienteId !== $subclienteId)) {
        throw new DomainException('ISSUANCE_DENIED');
    }
    if (!cardIssuanceCanGenerate($pdo, $context, $identity, $tenantId, $subclienteId)) {
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
            $titularId,
            $designId,
            $outputMode,
            $idempotencyHash,
            $mappingOverrideJson,
            $now,
            &$result
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!cardIssuanceCanGenerate($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('ISSUANCE_DENIED');
            }

            $existingQuery = $pdo->prepare(
                'SELECT id, subcliente_id, lote_origem_id, titular_origem_id, design_id, modo_saida, total_registros, status_lote, '
                . 'mapa_de_para_usado_json '
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
                    $titularId,
                    $designId,
                    $outputMode,
                    $mappingOverrideJson
                );
                return;
            }

            $designQuery = $pdo->prepare(
                'SELECT compartilhado_subclientes, mapa_campos_json FROM designs_carteirinha '
                . 'WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1'
            );
            $designQuery->execute(['id' => $designId, 'tenant' => $tenantId]);
            $designRecord = $designQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($designRecord)
                || $subclienteId !== null && (int) $designRecord['compartilhado_subclientes'] !== 1) {
                throw new DomainException('ISSUANCE_DESIGN_NOT_FOUND');
            }

            $quantity = cardIssuanceSourceQuantity(
                $pdo,
                $tenantId,
                $subclienteId,
                $sourceLotId,
                $titularId,
                $designRecord,
                $outputMode,
                $mappingOverrideJson
            );

            cardIssuanceReserveQuota($pdo, $tenantId, $subclienteId, $quantity);
            $createdAt = gmdate('Y-m-d H:i:s', $now ?? time());
            $insertJob = $pdo->prepare(
                'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, design_id, lote_origem_id, titular_origem_id, '
                . 'mapa_de_para_usado_json, chave_idempotencia_hash, origem_tipo, modo_saida, total_registros, processados_sucesso, '
                . 'processados_erro, status_lote, criado_em) '
                . 'VALUES (:tenant, :subclient, :user, :design, :source, :holder, :mapping, :key_hash, :origin, :mode, :total, 0, 0, :status, :created)'
            );
            $insertJob->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'user' => ($identity['id'] ?? null) === null ? null : (int) $identity['id'],
                'design' => $designId,
                'source' => $sourceLotId,
                'holder' => $titularId,
                'mapping' => $mappingOverrideJson,
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
            'SELECT id, subcliente_id, lote_origem_id, titular_origem_id, design_id, modo_saida, total_registros, status_lote, '
            . 'mapa_de_para_usado_json '
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
            $titularId,
            $designId,
            $outputMode,
            $mappingOverrideJson
        );
    }

    if (!is_array($result)) {
        throw new RuntimeException('ISSUANCE_JOB_FAILED');
    }

    return $result;
}

/** @param array<string,mixed> $identity */
function cardIssuanceCanGenerate(PDO $pdo, TenantContext $context, array $identity, int $tenantId, ?int $subclientId): bool
{
    if (($identity['papel_sistema'] ?? null) === 'integracao_whatsapp') {
        require_once __DIR__ . '/whatsapp_gateway_webhook.php';
        return whatsappGatewayServicePrincipalCan($pdo, $context, $identity, $tenantId, $subclientId, 'carteirinha.gerar');
    }
    return pode($pdo, $context, $identity, 'carteirinha.gerar', $subclientId);
}
