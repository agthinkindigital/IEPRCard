<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_quota_policy.php';

/** @param array<string, mixed> $identity @param mixed $permissions @return list<string> */
function tenantDelegatedPermissionsSet(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    mixed $permissions
): array {
    $normalized = tenantDelegatedPermissionsNormalize($permissions);
    if ($tenantId < 1 || !tenantDelegationCanManage($pdo, $context, $identity, $tenantId)) {
        throw new DomainException('TENANT_DELEGATION_DENIED');
    }

    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $normalized
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!tenantDelegationCanManage($pdo, $context, $identity, $tenantId)) {
                throw new DomainException('TENANT_DELEGATION_DENIED');
            }
            $root = tenantQuotaLoadRoot($pdo, $tenantId);
            $state = $pdo->prepare(
                'SELECT status_conta, plano_id, permissoes_unidade_json FROM tenants WHERE id = :id AND parent_id IS NULL '
                . "AND tipo = 'cliente' LIMIT 1"
            );
            $state->execute(['id' => $tenantId]);
            $rootState = $state->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rootState) || $rootState['status_conta'] !== 'ativo'
                || tenantQuotaNullableInteger($rootState['plano_id']) === null) {
                throw new DomainException('TENANT_DELEGATION_ROOT_INACTIVE');
            }

            $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
            $plan->execute(['id' => (int) $rootState['plano_id']]);
            if ((int) $plan->fetchColumn() !== 1) {
                throw new DomainException('TENANT_DELEGATION_PLAN_UNAVAILABLE');
            }
            tenantDelegatedPermissionsValidatePlan($pdo, (int) $rootState['plano_id'], $normalized);

            $before = tenantDelegatedPermissionsDecode($rootState['permissoes_unidade_json'] ?? null);
            $update = $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :id');
            $update->execute([
                'permissions' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'id' => $tenantId,
            ]);
            tenantQuotaAudit(
                $pdo,
                $tenantId,
                $tenantId,
                null,
                $identity,
                'tenant.unit_permissions_changed',
                ['permissoes_unidade' => $before],
                ['permissoes_unidade' => $normalized]
            );
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TENANT_DELEGATION_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);

    return $normalized;
}

/** @param mixed $permissions @return list<string> */
function tenantDelegatedPermissionsNormalize(mixed $permissions): array
{
    if (!is_array($permissions) || !array_is_list($permissions) || count($permissions) > 100) {
        throw new DomainException('TENANT_DELEGATION_INVALID');
    }
    $normalized = [];
    foreach ($permissions as $permission) {
        if (!is_string($permission)
            || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $permission) !== 1) {
            throw new DomainException('TENANT_DELEGATION_INVALID');
        }
        if (!tenantDelegatedPermissionIsAllowed($permission)) {
            throw new DomainException('TENANT_DELEGATION_PERMISSION_FORBIDDEN');
        }
        $normalized[] = $permission;
    }
    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_STRING);

    return $normalized;
}

function tenantDelegatedPermissionIsAllowed(string $permission): bool
{
    return motorPermissoesCanDelegateToUnit($permission);
}

/** @param list<string> $permissions */
function tenantDelegatedPermissionsValidatePlan(PDO $pdo, int $planId, array $permissions): void
{
    $findPermission = $pdo->prepare('SELECT id FROM permissoes WHERE chave = :key LIMIT 1');
    $findFeature = $pdo->prepare(
        'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :key LIMIT 1'
    );
    foreach ($permissions as $permission) {
        $findPermission->execute(['key' => $permission]);
        if ($findPermission->fetchColumn() === false) {
            throw new DomainException('TENANT_DELEGATION_PERMISSION_UNKNOWN');
        }
        $findFeature->execute(['plan' => $planId, 'key' => $permission]);
        if ((int) $findFeature->fetchColumn() !== 1) {
            throw new DomainException('TENANT_DELEGATION_PERMISSION_UNAVAILABLE');
        }
    }
}

/** @param mixed $permissions @return list<string> */
function tenantDelegatedPermissionsDecode(mixed $permissions): array
{
    if (!is_string($permissions) || $permissions === '') {
        return [];
    }
    $decoded = json_decode($permissions, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }
    $valid = [];
    foreach ($decoded as $permission) {
        if (is_string($permission) && tenantDelegatedPermissionIsAllowed($permission)) {
            $valid[] = $permission;
        }
    }
    $valid = array_values(array_unique($valid));
    sort($valid, SORT_STRING);

    return $valid;
}

/** @param array<string, mixed> $identity */
function tenantDelegationCanManage(PDO $pdo, TenantContext $context, array $identity, int $tenantId): bool
{
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    return $context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null
        && ($identity['papel_sistema'] ?? null) === 'titular_cliente'
        && pode($pdo, $context, $identity, 'usuario.permissoes');
}
