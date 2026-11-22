<?php
declare(strict_types=1);

final class TenantContext
{
    /** @param array<string, scalar|null> $branding */
    public function __construct(
        public readonly string $tipo,
        public readonly ?int $tenantId,
        public readonly ?int $subclienteId,
        public readonly array $branding
    ) {
    }
}

/** Return a canonical ASCII hostname, or null when the input is not a hostname. */
function tenantResolverNormalizeHost(string $value): ?string
{
    if ($value === '' || trim($value) !== $value || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
        return null;
    }

    $host = $value;
    if (preg_match('/^([A-Za-z0-9.-]+):([0-9]{1,5})$/D', $host, $matches) === 1) {
        $port = (int) $matches[2];
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $host = $matches[1];
    } elseif (str_contains($host, ':')) {
        return null;
    }

    $host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
    if ($host === '' || strlen($host) > 253) {
        return null;
    }

    foreach (explode('.', $host) as $label) {
        if (strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
            return null;
        }
    }

    return $host;
}

/** @param array<string, string>|null $configuration */
function tenantResolverIsPlatformHost(string $requestHost, ?array $configuration = null): bool
{
    $configuration ??= [];
    $host = tenantResolverNormalizeHost($requestHost);
    $platformHost = tenantResolverNormalizeHost(
        $configuration['PLATFORM_HOSTNAME'] ?? (getenv('PLATFORM_HOSTNAME') ?: '')
    );

    return $host !== null && $platformHost !== null && hash_equals($platformHost, $host);
}

/** @param array<string, string>|null $configuration */
function tenantResolverResolve(PDO $pdo, string $requestHost, ?array $configuration = null): ?TenantContext
{
    $host = tenantResolverNormalizeHost($requestHost);
    $profile = null;
    if (function_exists('installationProfileCurrent')) {
        $profile = installationProfileCurrent($pdo);
    } else {
        require_once __DIR__ . '/installation_profile.php';
        $profile = installationProfileCurrent($pdo);
    }
    if (is_array($profile)) {
        if ($profile['modo'] === 'local_host') {
            $host = installationProfileNormalizeHostHeader($requestHost);
            $serverHost = installationProfileNormalizeHostHeader((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if (!installationProfileIsLoopbackRequest() || $serverHost === null || $host === null || !hash_equals($serverHost, $host)) {
                return null;
            }
            return installationProfileTenantContext($pdo, $profile);
        }
        if ($profile['modo'] === 'hospedado') {
            $configuration = [
                'PLATFORM_HOSTNAME' => (string) ($profile['hostname_plataforma'] ?? ''),
                'TENANT_BASE_DOMAIN' => (string) ($profile['dominio_base'] ?? ''),
            ];
        }
    }
    if ($host === null) {
        return null;
    }

    $configuration ??= [];
    $baseDomain = tenantResolverNormalizeHost(
        $configuration['TENANT_BASE_DOMAIN'] ?? (getenv('TENANT_BASE_DOMAIN') ?: '')
    );

    if (tenantResolverIsPlatformHost($host, $configuration)) {
        return new TenantContext('admin', null, null, []);
    }

    if ($baseDomain !== null && str_ends_with($host, '.' . $baseDomain)) {
        $prefix = substr($host, 0, -strlen('.' . $baseDomain));
        $parts = explode('.', $prefix);
        if (count($parts) === 1) {
            $statement = $pdo->prepare(
                "SELECT id, nome_organizacao FROM tenants "
                . "WHERE slug_subdominio = :slug AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 2"
            );
            $statement->execute(['slug' => $parts[0]]);
            $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) !== 1) {
                return null;
            }
            $tenant = $matches[0];

            return new TenantContext('cliente', (int) $tenant['id'], null, [
                'nome_organizacao' => (string) $tenant['nome_organizacao'],
            ]);
        }

        if (count($parts) === 2) {
            $statement = $pdo->prepare(
                "SELECT child.id, child.parent_id, child.nome_organizacao AS child_name, root.nome_organizacao AS root_name "
                . "FROM tenants child JOIN tenants root ON root.id = child.parent_id "
                . "WHERE child.slug_subdominio = :child_slug AND root.slug_subdominio = :root_slug "
                . "AND child.tipo = 'subcliente' AND child.status_conta = 'ativo' "
                . "AND root.tipo = 'cliente' AND root.parent_id IS NULL AND root.status_conta = 'ativo' LIMIT 2"
            );
            $statement->execute(['child_slug' => $parts[0], 'root_slug' => $parts[1]]);
            $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) !== 1) {
                return null;
            }
            $tenant = $matches[0];

            return new TenantContext('subcliente', (int) $tenant['parent_id'], (int) $tenant['id'], [
                'nome_organizacao' => (string) $tenant['root_name'],
                'nome_unidade' => (string) $tenant['child_name'],
            ]);
        }

        return null;
    }

    $statement = $pdo->prepare(
        "SELECT t.id, t.nome_organizacao FROM dominios_tenant d "
        . "JOIN tenants t ON t.id = d.tenant_id "
        . "WHERE d.hostname = :hostname AND d.estado = 'ativo' "
        . "AND d.verificado_em IS NOT NULL AND d.tls_validado_em IS NOT NULL "
        . "AND t.tipo = 'cliente' AND t.parent_id IS NULL AND t.status_conta = 'ativo' LIMIT 2"
    );
    $statement->execute(['hostname' => $host]);
    $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches) !== 1) {
        return null;
    }
    $tenant = $matches[0];

    return new TenantContext('cliente', (int) $tenant['id'], null, [
        'nome_organizacao' => (string) $tenant['nome_organizacao'],
    ]);
}

/** @param array<string, mixed> $identity */
function tenantContextAuthorizesIdentity(TenantContext $context, array $identity): bool
{
    $userId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        return false;
    }
    $role = $identity['papel_sistema'] ?? null;
    $tenantId = filter_var($identity['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $subclienteId = filter_var($identity['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($context->tipo === 'admin') {
        return $role === 'superadmin' && ($identity['tenant_id'] ?? null) === null;
    }
    if (!in_array($context->tipo, ['cliente', 'subcliente'], true)
        || $role === 'superadmin' || $tenantId === false || $tenantId !== $context->tenantId) {
        return false;
    }

    if ($context->tipo === 'cliente') {
        return in_array($role, ['titular_cliente', 'subacesso'], true)
            && ($identity['subcliente_id'] ?? null) === null;
    }

    return in_array($role, ['responsavel_sub', 'subacesso'], true)
        && $subclienteId !== false && $subclienteId === $context->subclienteId;
}

/**
 * @param array<string, mixed> $identity Authenticated identity from the server-side session.
 * @param array{tenant_id?: int, subcliente_id?: int|null}|null $adminTarget Explicit target required for a superadmin.
 * @return array{sql: string, params: array<string, int>}
 */
function tenantScopeBuildPredicate(array $identity, ?array $adminTarget = null, string $tableAlias = ''): array
{
    if ($tableAlias !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tableAlias) !== 1) {
        throw new InvalidArgumentException('Tenant scope alias is invalid.');
    }
    $userId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }
    $column = static fn (string $name): string => ($tableAlias !== '' ? $tableAlias . '.' : '') . $name;
    $role = $identity['papel_sistema'] ?? null;

    if ($role === 'superadmin') {
        if (($identity['tenant_id'] ?? null) !== null) {
            throw new DomainException('TENANT_SCOPE_INVALIDO');
        }
        $tenantId = filter_var($adminTarget['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subclienteId = filter_var($adminTarget['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false || (($adminTarget['subcliente_id'] ?? null) !== null && $subclienteId === false)) {
            throw new DomainException('TENANT_SCOPE_INVALIDO');
        }
        $sql = $column('tenant_id') . ' = :scope_tenant_id';
        $params = ['scope_tenant_id' => $tenantId];
        if ($subclienteId !== false) {
            $sql .= ' AND ' . $column('subcliente_id') . ' = :scope_subcliente_id';
            $params['scope_subcliente_id'] = $subclienteId;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    if (!in_array($role, ['titular_cliente', 'responsavel_sub', 'subacesso'], true)) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }
    $tenantId = filter_var($identity['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tenantId === false) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }
    $sql = $column('tenant_id') . ' = :scope_tenant_id';
    $params = ['scope_tenant_id' => $tenantId];
    $subclienteId = filter_var($identity['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($role === 'titular_cliente' && ($identity['subcliente_id'] ?? null) !== null) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }
    if ($role === 'responsavel_sub' && ($identity['subcliente_id'] ?? null) === null) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }
    if (($identity['subcliente_id'] ?? null) !== null) {
        if ($subclienteId === false) {
            throw new DomainException('TENANT_SCOPE_INVALIDO');
        }
        $sql .= ' AND ' . $column('subcliente_id') . ' = :scope_subcliente_id';
        $params['scope_subcliente_id'] = $subclienteId;
    }

    return ['sql' => $sql, 'params' => $params];
}
