<?php
declare(strict_types=1);

/** @param array<string, mixed> $values @param list<string> $keys @return array<string, int|null> */
function tenantQuotaNormalizeLimits(array $values, array $keys, bool $nullable): array
{
    $actualKeys = array_keys($values);
    sort($actualKeys, SORT_STRING);
    $expectedKeys = $keys;
    sort($expectedKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys) {
        throw new DomainException('TENANT_QUOTA_INVALID');
    }
    $normalized = [];
    foreach ($keys as $key) {
        $value = $values[$key];
        if ($value === null && $nullable) {
            $normalized[$key] = null;
            continue;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new DomainException('TENANT_QUOTA_INVALID');
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new DomainException('TENANT_QUOTA_INVALID');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($integer === false) {
            throw new DomainException('TENANT_QUOTA_INVALID');
        }
        $normalized[$key] = $integer;
    }

    return $normalized;
}

/** @param array<string, mixed> $identity */
function tenantQuotaCanManageSubclientAllocation(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId
): bool {
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    return $context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null
        && ($identity['papel_sistema'] ?? null) === 'titular_cliente'
        && pode($pdo, $context, $identity, 'cota.distribuir');
}

/** @return array<string, mixed> */
function tenantQuotaLoadRoot(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare(
        "SELECT id, limite_subclientes, limite_usuarios_equipe, limite_titulares, limite_carteirinhas "
        . "FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
    );
    $statement->execute(['id' => $tenantId]);
    $root = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($root)) {
        throw new DomainException('TENANT_QUOTA_TARGET_INVALID');
    }

    return $root;
}

function tenantQuotaChildCount(PDO $pdo, int $tenantId): int
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :id AND tipo = 'subcliente'");
    $statement->execute(['id' => $tenantId]);

    return (int) $statement->fetchColumn();
}

function tenantQuotaFixedAllocationTotal(PDO $pdo, int $tenantId, string $dimension, ?int $excludeSubclientId = null): int
{
    if (!in_array($dimension, ['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'], true)) {
        throw new InvalidArgumentException('TENANT_QUOTA_DIMENSION_INVALID');
    }
    $sql = "SELECT COALESCE(SUM({$dimension}), 0) FROM tenants WHERE parent_id = :parent AND tipo = 'subcliente'";
    if ($excludeSubclientId !== null) {
        $sql .= ' AND id <> :exclude_id';
    }
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':parent', $tenantId, PDO::PARAM_INT);
    if ($excludeSubclientId !== null) {
        $statement->bindValue(':exclude_id', $excludeSubclientId, PDO::PARAM_INT);
    }
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/** @param array<string, mixed> $root @return array<string, int|null> */
function tenantQuotaRootLimits(array $root): array
{
    return [
        'limite_subclientes' => tenantQuotaNullableInteger($root['limite_subclientes']),
        'limite_usuarios_equipe' => tenantQuotaNullableInteger($root['limite_usuarios_equipe']),
        'limite_titulares' => tenantQuotaNullableInteger($root['limite_titulares']),
        'limite_carteirinhas' => tenantQuotaNullableInteger($root['limite_carteirinhas']),
    ];
}

/** @param array<string, mixed> $allocation @return array<string, int|null> */
function tenantQuotaAllocation(array $allocation): array
{
    return [
        'limite_usuarios_equipe' => tenantQuotaNullableInteger($allocation['limite_usuarios_equipe']),
        'limite_titulares' => tenantQuotaNullableInteger($allocation['limite_titulares']),
        'limite_carteirinhas' => tenantQuotaNullableInteger($allocation['limite_carteirinhas']),
    ];
}

function tenantQuotaNullableInteger(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    $integer = filter_var($value, FILTER_VALIDATE_INT);

    return $integer === false ? null : (int) $integer;
}

/** @return array<string, string> */
function tenantQuotaPlanFeatureMap(): array
{
    return [
        'limite_subclientes' => 'subcliente.criar',
        'limite_usuarios_equipe' => 'usuario.criar',
        'limite_titulares' => 'titular.criar',
        'limite_carteirinhas' => 'carteirinha.gerar',
    ];
}

/** @return array<string, int|null> */
function tenantQuotaPlanCeilings(PDO $pdo, int $planId): array
{
    if ($planId < 1) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
    $statement = $pdo->prepare(
        'SELECT habilitado, limite_valor FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :feature LIMIT 1'
    );
    $ceilings = [];
    foreach (tenantQuotaPlanFeatureMap() as $limitKey => $featureKey) {
        $statement->execute(['plan' => $planId, 'feature' => $featureKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $ceilings[$limitKey] = null;
            continue;
        }
        if ((int) $row['habilitado'] !== 1) {
            $ceilings[$limitKey] = 0;
            continue;
        }
        $ceilings[$limitKey] = tenantQuotaNullableInteger($row['limite_valor']);
    }

    return $ceilings;
}

/** @param array<string, int|null> $limits */
function tenantQuotaAssertWithinPlan(PDO $pdo, int $planId, array $limits): void
{
    $ceilings = tenantQuotaPlanCeilings($pdo, $planId);
    foreach ($ceilings as $key => $ceiling) {
        if ($ceiling !== null && $limits[$key] > $ceiling) {
            throw new DomainException('TENANT_QUOTA_EXCEEDED');
        }
    }
}

function tenantQuotaRunLocked(PDO $pdo, int $tenantId, Closure $operation): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('TENANT_QUOTA_FAILED');
    }
    $pdo->beginTransaction();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lock = $pdo->prepare(
                "SELECT id FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' FOR UPDATE"
            );
            $lock->execute(['id' => $tenantId]);
            if ($lock->fetchColumn() === false) {
                throw new DomainException('TENANT_QUOTA_TARGET_INVALID');
            }
        } else {
            $lock = $pdo->prepare(
                "UPDATE tenants SET id = id WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente'"
            );
            $lock->execute(['id' => $tenantId]);
        }
        $operation();
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('TENANT_QUOTA_FAILED');
    }
}

/** @param array<string, mixed> $identity @param array<string, int|null> $before @param array<string, int|null> $after */
function tenantQuotaAudit(
    PDO $pdo,
    int $tenantId,
    int $entityId,
    ?int $subclienteId,
    array $identity,
    string $action,
    array $before,
    array $after
): void {
    $statement = $pdo->prepare(
        'INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_usuario_id, ator_tipo, acao, entidade, entidade_id, '
        . 'antes_json, depois_json, criado_em) VALUES (:tenant, :subcliente, :actor, :actor_type, :action, :entity, :entity_id, '
        . ':before_json, :after_json, :created_at)'
    );
    $statement->execute([
        'tenant' => $tenantId,
        'subcliente' => $subclienteId,
        'actor' => (int) $identity['id'],
        'actor_type' => (string) $identity['papel_sistema'],
        'action' => $action,
        'entity' => 'tenants',
        'entity_id' => $entityId,
        'before_json' => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'after_json' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}
