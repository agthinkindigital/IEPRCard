<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_quota_policy_support.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $limits */
function tenantQuotaSetRootLimits(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    array $limits
): void {
    $normalized = tenantQuotaNormalizeLimits(
        $limits,
        ['limite_subclientes', 'limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'],
        false
    );
    if ($tenantId < 1 || $context->tipo !== 'admin' || $context->tenantId !== null
        || $context->subclienteId !== null || ($identity['papel_sistema'] ?? null) !== 'superadmin'
        || !pode($pdo, $context, $identity, 'admin.clientes')) {
        throw new DomainException('TENANT_QUOTA_DENIED');
    }

    tenantQuotaRunLocked($pdo, $tenantId, function () use ($pdo, $context, $identity, $tenantId, $normalized): void {
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $context, $identity, 'admin.clientes')) {
            throw new DomainException('TENANT_QUOTA_DENIED');
        }
        $root = tenantQuotaLoadRoot($pdo, $tenantId);
        $childCount = tenantQuotaChildCount($pdo, $tenantId);
        if ($childCount > $normalized['limite_subclientes']) {
            throw new DomainException('TENANT_QUOTA_EXCEEDED');
        }
        foreach (['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'] as $dimension) {
            if (tenantQuotaFixedAllocationTotal($pdo, $tenantId, $dimension) > $normalized[$dimension]) {
                throw new DomainException('TENANT_QUOTA_EXCEEDED');
            }
        }

        $before = tenantQuotaRootLimits($root);
        $update = $pdo->prepare(
            'UPDATE tenants SET limite_subclientes = :subclients, limite_usuarios_equipe = :users, '
            . 'limite_titulares = :holders, limite_carteirinhas = :cards WHERE id = :id '
            . 'AND parent_id IS NULL AND tipo = \'cliente\''
        );
        $update->execute([
            'subclients' => $normalized['limite_subclientes'],
            'users' => $normalized['limite_usuarios_equipe'],
            'holders' => $normalized['limite_titulares'],
            'cards' => $normalized['limite_carteirinhas'],
            'id' => $tenantId,
        ]);
        tenantQuotaAudit($pdo, $tenantId, $tenantId, null, $identity, 'quota.root_limits_changed', $before, $normalized);
    });
}

/** @param array<string, mixed> $identity @param array<string, mixed> $allocation */
function tenantQuotaSetSubclientAllocation(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    int $subclienteId,
    array $allocation
): void {
    $normalized = tenantQuotaNormalizeLimits(
        $allocation,
        ['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'],
        true
    );
    if ($tenantId < 1 || $subclienteId < 1
        || !tenantQuotaCanManageSubclientAllocation($pdo, $context, $identity, $tenantId)) {
        throw new DomainException('TENANT_QUOTA_DENIED');
    }

    tenantQuotaRunLocked($pdo, $tenantId, function () use (
        $pdo,
        $context,
        $identity,
        $tenantId,
        $subclienteId,
        $normalized
    ): void {
        motorPermissoesCacheLimpar($pdo);
        if (!tenantQuotaCanManageSubclientAllocation($pdo, $context, $identity, $tenantId)) {
            throw new DomainException('TENANT_QUOTA_DENIED');
        }
        $root = tenantQuotaLoadRoot($pdo, $tenantId);
        $child = $pdo->prepare(
            "SELECT limite_usuarios_equipe, limite_titulares, limite_carteirinhas FROM tenants "
            . "WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' LIMIT 1"
        );
        $child->execute(['id' => $subclienteId, 'parent' => $tenantId]);
        $current = $child->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            throw new DomainException('TENANT_QUOTA_TARGET_INVALID');
        }

        foreach (['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'] as $dimension) {
            $rootLimit = tenantQuotaNullableInteger($root[$dimension]);
            if ($rootLimit === null) {
                throw new DomainException('TENANT_QUOTA_UNCONFIGURED');
            }
            $total = tenantQuotaFixedAllocationTotal($pdo, $tenantId, $dimension, $subclienteId)
                + ($normalized[$dimension] ?? 0);
            if ($total > $rootLimit) {
                throw new DomainException('TENANT_QUOTA_EXCEEDED');
            }
        }

        $before = tenantQuotaAllocation($current);
        $update = $pdo->prepare(
            'UPDATE tenants SET limite_usuarios_equipe = :users, limite_titulares = :holders, '
            . 'limite_carteirinhas = :cards WHERE id = :id AND parent_id = :parent AND tipo = \'subcliente\''
        );
        $update->execute([
            'users' => $normalized['limite_usuarios_equipe'],
            'holders' => $normalized['limite_titulares'],
            'cards' => $normalized['limite_carteirinhas'],
            'id' => $subclienteId,
            'parent' => $tenantId,
        ]);
        tenantQuotaAudit(
            $pdo,
            $tenantId,
            $subclienteId,
            $subclienteId,
            $identity,
            ($identity['papel_sistema'] ?? null) === 'superadmin'
                ? 'quota.subclient_override_changed'
                : 'quota.subclient_allocation_changed',
            $before,
            $normalized
        );
    });
}
