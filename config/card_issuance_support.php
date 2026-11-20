<?php
declare(strict_types=1);

/** @param array<string, mixed> $existing @return array{job_id:int,status:string,quantity:int,reused:bool} */
function cardIssuanceExistingJobResult(
    array $existing,
    int $tenantId,
    ?int $subclienteId,
    ?int $sourceLotId,
    ?int $titularId,
    int $designId,
    string $outputMode,
    ?string $mappingOverrideJson
): array {
    if (cardIssuanceNullableId($existing['lote_origem_id'] ?? null) !== $sourceLotId
        || cardIssuanceNullableId($existing['titular_origem_id'] ?? null) !== $titularId
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
