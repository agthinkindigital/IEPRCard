<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/installation_profile.php';

/** @param array<string, string> $configuration
 *  @return array{id:int,hostname:string,token:string,txt_name:string,txt_value:string}
 */
function domainOnboardingCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    string $hostname,
    array $configuration,
    ?int $timestamp = null
): array
{
    $installation = installationProfileCurrent($pdo);
    if (($installation['modo'] ?? null) === 'local_host') {
        throw new DomainException('DOMAIN_UNAVAILABLE');
    }
    if ($configuration === [] && is_array($installation) && $installation['modo'] === 'hospedado') {
        $configuration = [
            'PLATFORM_HOSTNAME' => (string) ($installation['hostname_plataforma'] ?? ''),
            'TENANT_BASE_DOMAIN' => (string) ($installation['dominio_base'] ?? ''),
        ];
    }
    $normalized = tenantResolverNormalizeHost($hostname);
    $tenantId = $context->tenantId;
    if ($context->tipo !== 'cliente' || $tenantId === null
        || ($identity['papel_sistema'] ?? null) !== 'titular_cliente'
        || ($identity['subcliente_id'] ?? null) !== null
        || !tenantContextAuthorizesIdentity($context, $identity)
        || $normalized === null || filter_var($normalized, FILTER_VALIDATE_IP) !== false
        || domainOnboardingIsReservedHost($normalized, $configuration)) {
        throw new DomainException('DOMAIN_UNAVAILABLE');
    }

    $tenant = $pdo->prepare(
        "SELECT id FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo'"
    );
    $tenant->execute(['id' => $tenantId]);
    if ($tenant->fetchColumn() === false) {
        throw new DomainException('DOMAIN_UNAVAILABLE');
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $createdAt = gmdate('Y-m-d H:i:s', $timestamp ?? time());
    try {
        $insert = $pdo->prepare(
            "INSERT INTO dominios_tenant (tenant_id, hostname, estado, token_verificacao_hash, criado_em) "
            . "VALUES (:tenant_id, :hostname, 'pendente_verificacao', :token_hash, :created_at)"
        );
        $insert->execute([
            'tenant_id' => $tenantId,
            'hostname' => $normalized,
            'token_hash' => $tokenHash,
            'created_at' => $createdAt,
        ]);
    } catch (PDOException $exception) {
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            throw new DomainException('DOMAIN_UNAVAILABLE', 0, $exception);
        }
        throw $exception;
    }

    $txtName = '_ieprcard-verification.' . $normalized;
    $txtValue = 'ieprcard-verification=' . $token;

    return [
        'id' => (int) $pdo->lastInsertId(),
        'hostname' => $normalized,
        'token' => $token,
        'txt_name' => $txtName,
        'txt_value' => $txtValue,
    ];
}

/** @param array<string, string> $configuration */
function domainOnboardingIsReservedHost(string $hostname, array $configuration): bool
{
    foreach (['PLATFORM_HOSTNAME', 'TENANT_BASE_DOMAIN'] as $key) {
        $reserved = tenantResolverNormalizeHost($configuration[$key] ?? (getenv($key) ?: ''));
        if ($reserved !== null && ($hostname === $reserved || str_ends_with($hostname, '.' . $reserved))) {
            return true;
        }
    }

    return false;
}

/** @param callable(string):list<string> $lookupTxt */
function domainOnboardingVerify(PDO $pdo, int $domainId, string $token, callable $lookupTxt, ?int $timestamp = null): bool
{
    if ($domainId < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return false;
    }

    $query = $pdo->prepare(
        "SELECT d.hostname, d.token_verificacao_hash FROM dominios_tenant d "
        . "JOIN tenants t ON t.id = d.tenant_id "
        . "WHERE d.id = :id AND d.estado = 'pendente_verificacao' "
        . "AND t.parent_id IS NULL AND t.tipo = 'cliente' AND t.status_conta = 'ativo'"
    );
    $query->execute(['id' => $domainId]);
    $domain = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($domain) || !is_string($domain['token_verificacao_hash'])) {
        return false;
    }

    $expectedHash = hash('sha256', $token);
    $tokenMatches = hash_equals($domain['token_verificacao_hash'], $expectedHash);
    $txtName = '_ieprcard-verification.' . (string) $domain['hostname'];
    $txtMatches = false;
    foreach ($lookupTxt($txtName) as $record) {
        $prefix = 'ieprcard-verification=';
        if (!str_starts_with($record, $prefix)) {
            continue;
        }
        $recordToken = substr($record, strlen($prefix));
        $recordHash = hash('sha256', $recordToken);
        if (hash_equals($domain['token_verificacao_hash'], $recordHash)) {
            $txtMatches = true;
        }
    }
    if (!$tokenMatches || !$txtMatches) {
        return false;
    }

    $update = $pdo->prepare(
        "UPDATE dominios_tenant SET estado = 'tls_pendente', verificado_em = :verified_at, token_verificacao_hash = NULL "
        . "WHERE id = :id AND estado = 'pendente_verificacao' AND token_verificacao_hash = :token_hash"
    );
    $update->execute([
        'verified_at' => gmdate('Y-m-d H:i:s', $timestamp ?? time()),
        'id' => $domainId,
        'token_hash' => $domain['token_verificacao_hash'],
    ]);

    return $update->rowCount() === 1;
}

/** @return list<string> */
function domainOnboardingLookupTxt(string $name): array
{
    if (!function_exists('dns_get_record') || !defined('DNS_TXT')) {
        return [];
    }
    $prefix = '_ieprcard-verification.';
    if (!str_starts_with($name, $prefix)
        || tenantResolverNormalizeHost(substr($name, strlen($prefix))) !== substr($name, strlen($prefix))) {
        return [];
    }

    $records = @dns_get_record($name, DNS_TXT);
    if (!is_array($records)) {
        return [];
    }

    $values = [];
    foreach ($records as $record) {
        if (isset($record['txt']) && is_string($record['txt'])) {
            $values[] = $record['txt'];
        }
    }
    return $values;
}
