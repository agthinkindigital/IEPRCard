<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_resolver.php';

/** @return WeakMap<PDO, array<string, bool>> */
function &motorPermissoesCacheStore(): WeakMap
{
    static $cache;
    if (!$cache instanceof WeakMap) {
        $cache = new WeakMap();
    }
    return $cache;
}

/** Clear request-local permission decisions, or all decisions when no PDO is supplied. */
function motorPermissoesCacheLimpar(?PDO $pdo = null): void
{
    $cache =& motorPermissoesCacheStore();
    if ($pdo === null) {
        $cache = new WeakMap();
        return;
    }
    unset($cache[$pdo]);
}

/** @param array<string, mixed> $identity Authenticated server-side session identity. */
function pode(PDO $pdo, TenantContext $context, array $identity, string $permission, ?int $subclienteId = null): bool
{
    $cache =& motorPermissoesCacheStore();
    if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $permission) !== 1) {
        return false;
    }

    $userId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        return false;
    }
    $cacheKey = hash('sha256', json_encode([
        $userId,
        $identity['tenant_id'] ?? null,
        $identity['subcliente_id'] ?? null,
        $identity['papel_sistema'] ?? null,
        $context->tipo,
        $context->tenantId,
        $context->subclienteId,
        $permission,
        $subclienteId,
    ], JSON_THROW_ON_ERROR));
    $pdoCache = $cache[$pdo] ?? [];
    if (array_key_exists($cacheKey, $pdoCache)) {
        return $pdoCache[$cacheKey];
    }

    $allowed = motorPermissoesAvaliar($pdo, $context, $identity, $permission, $subclienteId);
    $pdoCache[$cacheKey] = $allowed;
    $cache[$pdo] = $pdoCache;

    return $allowed;
}

/** @param array<string, mixed> $identity */
function motorPermissoesAvaliar(PDO $pdo, TenantContext $context, array $identity, string $permission, ?int $subclienteId): bool
{
    $userId = (int) $identity['id'];
    $userQuery = $pdo->prepare(
        'SELECT id, tenant_id, subcliente_id, perfil_acesso_id, papel_sistema, ativo '
        . 'FROM usuarios WHERE id = :id LIMIT 1'
    );
    $userQuery->execute(['id' => $userId]);
    $user = $userQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) || (int) $user['ativo'] !== 1
        || (string) $user['papel_sistema'] !== (string) ($identity['papel_sistema'] ?? '')
        || !motorPermissoesSessionIdMatches($identity['tenant_id'] ?? null, $user['tenant_id'])
        || !motorPermissoesSessionIdMatches($identity['subcliente_id'] ?? null, $user['subcliente_id'])) {
        return false;
    }

    $role = (string) $user['papel_sistema'];
    $permissionQuery = $pdo->prepare('SELECT id FROM permissoes WHERE chave = :permission LIMIT 1');
    $permissionQuery->execute(['permission' => $permission]);
    $permissionId = $permissionQuery->fetchColumn();
    if ($permissionId === false) {
        return false;
    }
    if ($role === 'superadmin') {
        return $user['tenant_id'] === null && $user['subcliente_id'] === null
            && $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && str_starts_with($permission, 'admin.');
    }
    if ($role === 'titular_final' || !in_array($role, ['titular_cliente', 'responsavel_sub', 'subacesso'], true)
        || !tenantContextAuthorizesIdentity($context, $identity)
        || $user['tenant_id'] === null) {
        return false;
    }

    $tenantId = (int) $user['tenant_id'];
    $subclienteUserId = motorPermissoesNullableId($user['subcliente_id']);
    if (($role === 'titular_cliente' && ($subclienteUserId !== null || $context->tipo !== 'cliente'))
        || ($role === 'responsavel_sub' && ($subclienteUserId === null || $context->tipo !== 'subcliente'))
        || ($role === 'subacesso' && !in_array($context->tipo, ['cliente', 'subcliente'], true))) {
        return false;
    }

    $targetSubclienteId = $subclienteId;
    if ($role === 'responsavel_sub' && $targetSubclienteId !== $subclienteUserId) {
        return false;
    }
    if ($role === 'subacesso') {
        $profileId = motorPermissoesNullableId($user['perfil_acesso_id']);
        if ($profileId === null) {
            return false;
        }
        $profileQuery = $pdo->prepare(
            'SELECT tenant_id, escopo_permitido, permissoes_acoes_json FROM perfis_acesso WHERE id = :id LIMIT 1'
        );
        $profileQuery->execute(['id' => $profileId]);
        $profile = $profileQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile) || motorPermissoesNullableId($profile['tenant_id']) !== $tenantId) {
            return false;
        }
        $scope = (string) $profile['escopo_permitido'];
        if (($scope === 'sede' && ($targetSubclienteId !== null || $subclienteUserId !== null))
            || ($scope === 'subcliente' && ($subclienteUserId === null || $targetSubclienteId !== $subclienteUserId))
            || ($scope === 'rede' && $subclienteUserId !== null)
            || !in_array($scope, ['sede', 'subcliente', 'rede'], true)) {
            return false;
        }
        $profilePermissions = motorPermissoesDecodeList($profile['permissoes_acoes_json']);
    } else {
        $profilePermissions = [];
    }

    $rootQuery = $pdo->prepare(
        "SELECT id, plano_id, status_conta, permissoes_unidade_json FROM tenants "
        . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
    );
    $rootQuery->execute(['id' => $tenantId]);
    $root = $rootQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($root) || (string) $root['status_conta'] !== 'ativo'
        || motorPermissoesNullableId($root['plano_id']) === null) {
        return false;
    }

    if ($targetSubclienteId !== null) {
        $childQuery = $pdo->prepare(
            "SELECT id FROM tenants WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' AND status_conta = 'ativo' LIMIT 1"
        );
        $childQuery->execute(['id' => $targetSubclienteId, 'parent' => $tenantId]);
        if ($childQuery->fetchColumn() === false) {
            return false;
        }
    }
    if ($subclienteUserId !== null && ($targetSubclienteId !== $subclienteUserId || $context->subclienteId !== $subclienteUserId)) {
        return false;
    }

    $featureQuery = $pdo->prepare(
        'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :feature LIMIT 1'
    );
    $featureQuery->execute(['plan' => (int) $root['plano_id'], 'feature' => $permission]);
    if ((int) $featureQuery->fetchColumn() !== 1) {
        return false;
    }

    $delegated = $role === 'titular_cliente' || $targetSubclienteId === null
        || (motorPermissoesCanDelegateToUnit($permission)
            && in_array($permission, motorPermissoesDecodeList($root['permissoes_unidade_json']), true));
    $roleAllows = match ($role) {
        'titular_cliente' => true,
        'responsavel_sub' => $delegated,
        'subacesso' => in_array($permission, $profilePermissions, true),
        default => false,
    };

    if ($role !== 'titular_cliente') {
        $override = motorPermissoesLoadOverride($pdo, $userId, (int) $permissionId, $targetSubclienteId);
        if ($override === null && $targetSubclienteId !== null) {
            $override = motorPermissoesLoadOverride($pdo, $userId, (int) $permissionId, null);
        }
        if ($override !== null) {
            $roleAllows = $override;
        }
    }

    return $roleAllows && $delegated;
}

function motorPermissoesCanDelegateToUnit(string $permission): bool
{
    return str_starts_with($permission, 'titular.')
        || str_starts_with($permission, 'carteirinha.')
        || str_starts_with($permission, 'design.')
        || $permission === 'telemetria.unidade';
}

function motorPermissoesNullableId(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $validated === false ? null : (int) $validated;
}

function motorPermissoesSessionIdMatches(mixed $sessionValue, mixed $databaseValue): bool
{
    if ($databaseValue === null) {
        return $sessionValue === null;
    }
    $sessionId = filter_var($sessionValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $sessionId !== false && (int) $sessionId === (int) $databaseValue;
}

/** @return list<string> */
function motorPermissoesDecodeList(mixed $value): array
{
    if (!is_string($value) || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }
    $permissions = [];
    foreach ($decoded as $permission) {
        if (is_string($permission) && preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $permission) === 1) {
            $permissions[] = $permission;
        }
    }
    return array_values(array_unique($permissions));
}

function motorPermissoesLoadOverride(PDO $pdo, int $userId, int $permissionId, ?int $subclienteId): ?bool
{
    $scopePredicate = $subclienteId === null ? 'subcliente_id IS NULL' : 'subcliente_id = :subcliente';
    $statement = $pdo->prepare(
        'SELECT permitido FROM usuario_permissoes '
        . 'WHERE usuario_id = :user AND permissao_id = :permission AND ' . $scopePredicate . ' LIMIT 1'
    );
    $statement->bindValue(':user', $userId, PDO::PARAM_INT);
    $statement->bindValue(':permission', $permissionId, PDO::PARAM_INT);
    if ($subclienteId !== null) {
        $statement->bindValue(':subcliente', $subclienteId, PDO::PARAM_INT);
    }
    $statement->execute();
    $value = $statement->fetchColumn();
    return $value === false ? null : (int) $value === 1;
}
