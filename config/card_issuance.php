<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_quota_policy_support.php';
require_once __DIR__ . '/design_mapping.php';

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
    ?int $now = null,
    mixed $mappingOverride = null
): array {
    if ($tenantId < 1 || $sourceLotId < 1 || $designId < 1
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
            $mappingOverrideJson,
            $now,
            &$result
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!pode($pdo, $context, $identity, 'carteirinha.gerar', $subclienteId)) {
                throw new DomainException('ISSUANCE_DENIED');
            }

            $existingQuery = $pdo->prepare(
                'SELECT id, subcliente_id, lote_origem_id, design_id, modo_saida, total_registros, status_lote, '
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
                    $designId,
                    $outputMode,
                    $mappingOverrideJson
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
                'SELECT compartilhado_subclientes, mapa_campos_json FROM designs_carteirinha '
                . 'WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1'
            );
            $designQuery->execute(['id' => $designId, 'tenant' => $tenantId]);
            $designRecord = $designQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($designRecord)
                || $subclienteId !== null && (int) $designRecord['compartilhado_subclientes'] !== 1) {
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

            if ($mappingOverrideJson !== null) {
                try {
                    $designMap = json_decode((string) $designRecord['mapa_campos_json'], true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new DomainException('ISSUANCE_MAPPING_INVALID');
                }
                $sampleQuery = $pdo->prepare(
                    'SELECT nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, '
                    . 'data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
                    . 'FROM titulares WHERE lote_origem_id = :source AND tenant_id = :tenant '
                    . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) '
                    . 'ORDER BY id LIMIT 1'
                );
                $sampleQuery->execute([
                    'source' => $sourceLotId,
                    'tenant' => $tenantId,
                    'child_match' => $subclienteId,
                    'child_null' => $subclienteId,
                ]);
                $sample = $sampleQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($sample)) {
                    throw new DomainException('ISSUANCE_SOURCE_EMPTY_OR_TOO_LARGE');
                }
                $extra = json_decode((string) ($sample['dados_extras_json'] ?? '{}'), true);
                $record = ['titular' => $sample + ['dados_extras' => is_array($extra) ? $extra : []]];
                $systemContext = [];
                foreach (is_array($designMap['elementos'] ?? null) ? $designMap['elementos'] : [] as $element) {
                    if (($element['tipo'] ?? null) === 'qrcode_validacao') {
                        $systemContext['_sistema_url_validacao'] = 'https://preview.invalid/validation';
                        break;
                    }
                }
                if (!designMappingResolve($designMap, $mappingOverrideJson, $record, $systemContext)['valid']) {
                    throw new DomainException('ISSUANCE_MAPPING_INVALID');
                }
            }

            cardIssuanceReserveQuota($pdo, $tenantId, $subclienteId, $quantity);
            $createdAt = gmdate('Y-m-d H:i:s', $now ?? time());
            $insertJob = $pdo->prepare(
                'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, design_id, lote_origem_id, '
                . 'mapa_de_para_usado_json, chave_idempotencia_hash, origem_tipo, modo_saida, total_registros, processados_sucesso, '
                . 'processados_erro, status_lote, criado_em) '
                . 'VALUES (:tenant, :subclient, :user, :design, :source, :mapping, :key_hash, :origin, :mode, :total, 0, 0, :status, :created)'
            );
            $insertJob->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'user' => (int) $identity['id'],
                'design' => $designId,
                'source' => $sourceLotId,
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
            'SELECT id, subcliente_id, lote_origem_id, design_id, modo_saida, total_registros, status_lote, '
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

/** @param array<string, mixed> $existing @return array{job_id:int,status:string,quantity:int,reused:bool} */
function cardIssuanceExistingJobResult(
    array $existing,
    int $tenantId,
    ?int $subclienteId,
    int $sourceLotId,
    int $designId,
    string $outputMode,
    ?string $mappingOverrideJson
): array {
    if ((int) ($existing['lote_origem_id'] ?? 0) !== $sourceLotId
        || cardIssuanceNullableId($existing['subcliente_id'] ?? null) !== $subclienteId
        || (int) ($existing['design_id'] ?? 0) !== $designId
        || (string) ($existing['modo_saida'] ?? '') !== $outputMode
        || ($existing['mapa_de_para_usado_json'] ?? null) !== $mappingOverrideJson) {
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

function cardIssuanceEncodeMappingOverride(mixed $mappingOverride): ?string
{
    if ($mappingOverride === null) {
        return null;
    }

    $mapping = is_string($mappingOverride) ? designMappingDecode($mappingOverride) : $mappingOverride;
    if (!is_array($mapping) || array_is_list($mapping)) {
        throw new DomainException('ISSUANCE_MAPPING_INVALID');
    }
    try {
        $encoded = json_encode(
            cardIssuanceCanonicalizeMappingValue($mapping),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $exception) {
        throw new DomainException('ISSUANCE_MAPPING_INVALID', 0, $exception);
    }
    if (strlen($encoded) > 262144) {
        throw new DomainException('ISSUANCE_MAPPING_INVALID');
    }

    return $encoded;
}

function cardIssuanceCanonicalizeMappingValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('cardIssuanceCanonicalizeMappingValue', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = cardIssuanceCanonicalizeMappingValue($child);
    }
    return $value;
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
