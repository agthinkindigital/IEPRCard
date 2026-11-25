<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_quota_policy.php';

/** @param array<string,mixed> $identity @param array<string,mixed> $input */
function adminTenantPlanCreate(PDO $pdo, TenantContext $context, array $identity, array $input): int
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('ADMIN_TENANT_PLAN_FAILED');
    }
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    $name = adminTenantPlanName($input['nome'] ?? null);
    $description = adminTenantPlanDescription($input['descricao'] ?? null);
    $active = $input['ativo'] ?? null;
    $permissions = adminTenantPlanPermissions($input['permissoes'] ?? null);
    $limits = tenantQuotaNormalizeLimits(
        is_array($input['cotas'] ?? null) ? $input['cotas'] : [],
        ['limite_subclientes', 'limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'],
        false
    );
    if ($name === null || $description === null || !is_bool($active)) {
        throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
    }

    $features = adminTenantPlanPermissionCatalog($pdo);
    if (array_diff($permissions, $features) !== []) {
        throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
    }
    foreach (adminTenantPlanQuotaFeatureMap() as $limitKey => $featureKey) {
        if ($limits[$limitKey] > 0 && !in_array($featureKey, $permissions, true)) {
            throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
        }
    }

    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        adminTenantPlanRequireAccess($pdo, $context, $identity);
        $duplicate = $pdo->prepare('SELECT id FROM planos WHERE nome = :name LIMIT 1');
        $duplicate->execute(['name' => $name]);
        if ($duplicate->fetchColumn() !== false) {
            throw new DomainException('ADMIN_TENANT_PLAN_NAME_CONFLICT');
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $defaultPermissions = json_encode($permissions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $insertPlan = $pdo->prepare(
            'INSERT INTO planos (nome, descricao, preco, permissoes_default_json, ativo, ordem, criado_em) '
            . 'VALUES (:name, :description, 0, :permissions, :active, '
            . '(SELECT COALESCE(MAX(ordem), -1) + 1 FROM planos), :created_at)'
        );
        $insertPlan->execute([
            'name' => $name,
            'description' => $description,
            'permissions' => $defaultPermissions,
            'active' => $active ? 1 : 0,
            'created_at' => $createdAt,
        ]);
        $planId = (int) $pdo->lastInsertId();
        $insertFeature = $pdo->prepare(
            'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado, limite_valor) '
            . 'VALUES (:plan, :feature, :enabled, :limit_value)'
        );
        $quotaFeatures = array_flip(adminTenantPlanQuotaFeatureMap());
        foreach ($features as $featureKey) {
            $quotaKey = $quotaFeatures[$featureKey] ?? null;
            $insertFeature->execute([
                'plan' => $planId,
                'feature' => $featureKey,
                'enabled' => in_array($featureKey, $permissions, true) ? 1 : 0,
                'limit_value' => $quotaKey === null ? null : $limits[$quotaKey],
            ]);
        }
        $pdo->commit();
        motorPermissoesCacheLimpar($pdo);

        return $planId;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('ADMIN_TENANT_PLAN_FAILED');
    }
}

/** @param array<string, mixed> $identity */
function adminTenantPlanSetActive(PDO $pdo, TenantContext $context, array $identity, int $planId, bool $active): void
{
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    if ($planId < 1 || $pdo->inTransaction()) throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        adminTenantPlanRequireAccess($pdo, $context, $identity);
        $query = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
        $query->execute(['id' => $planId]);
        $current = $query->fetchColumn();
        if ($current === false) throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
        if (!$active) {
            $assigned = $pdo->prepare(
                "SELECT COUNT(*) FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND plano_id = :plan AND status_conta = 'ativo'"
            );
            $assigned->execute(['plan' => $planId]);
            if ((int) $assigned->fetchColumn() > 0) throw new DomainException('ADMIN_TENANT_PLAN_IN_USE');
        }
        $update = $pdo->prepare('UPDATE planos SET ativo = :active WHERE id = :id');
        $update->execute(['active' => $active ? 1 : 0, 'id' => $planId]);
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('ADMIN_TENANT_PLAN_FAILED');
    }
    motorPermissoesCacheLimpar($pdo);
}

/** @return list<string> */
function adminTenantPlanPermissionCatalog(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT chave FROM permissoes WHERE chave NOT LIKE 'admin.%' AND chave NOT LIKE 'financeiro.%' "
        . 'ORDER BY chave'
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter($rows, static fn(mixed $key): bool => is_string($key)));
}

/** @return array<string, string> */
function adminTenantPlanQuotaFeatureMap(): array
{
    return tenantQuotaPlanFeatureMap();
}

/** @param array<string,mixed> $identity */
function adminTenantPlanRequireAccess(PDO $pdo, TenantContext $context, array $identity): void
{
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || ($identity['papel_sistema'] ?? null) !== 'superadmin'
        || !pode($pdo, $context, $identity, 'admin.planos')) {
        throw new DomainException('ADMIN_TENANT_PLAN_DENIED');
    }
}

function adminTenantPlanName(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $name = trim($value);
    $length = preg_match_all('/./us', $name);

    return $name !== '' && $length !== false && $length <= 120 ? $name : null;
}

function adminTenantPlanDescription(mixed $value): ?string
{
    if ($value === null || $value === '') return '';
    if (!is_string($value)) return null;
    $description = trim($value);
    $length = preg_match_all('/./us', $description);

    return $length !== false && $length <= 1000 ? $description : null;
}

/** @return list<string> */
function adminTenantPlanPermissions(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
        throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
    }
    $permissions = [];
    foreach ($value as $key) {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $key) !== 1) {
            throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
        }
        $permissions[] = $key;
    }

    return array_values(array_unique($permissions));
}
