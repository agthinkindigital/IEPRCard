<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_quota_policy.php';
require_once __DIR__ . '/cpf.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $input */
function titularCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    array $input
): int {
    $normalized = titularCreateNormalize($input);
    if ($tenantId < 1 || ($subclienteId !== null && $subclienteId < 1)) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
        throw new DomainException('TITULAR_CREATE_DENIED');
    }

    $titularId = null;
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $subclienteId,
            $normalized,
            &$titularId
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('TITULAR_CREATE_DENIED');
            }

            $root = $pdo->prepare(
                "SELECT plano_id, status_conta, limite_titulares FROM tenants "
                . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
            );
            $root->execute(['id' => $tenantId]);
            $rootState = $root->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rootState) || $rootState['status_conta'] !== 'ativo'
                || tenantQuotaNullableInteger($rootState['plano_id']) === null) {
                throw new DomainException('TITULAR_CREATE_ROOT_INACTIVE');
            }

            $planId = (int) $rootState['plano_id'];
            $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
            $plan->execute(['id' => $planId]);
            if ((int) $plan->fetchColumn() !== 1) {
                throw new DomainException('TITULAR_CREATE_PLAN_UNAVAILABLE');
            }
            $feature = $pdo->prepare(
                'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :key LIMIT 1'
            );
            $feature->execute(['plan' => $planId, 'key' => 'titular.criar']);
            if ((int) $feature->fetchColumn() !== 1) {
                throw new DomainException('TITULAR_CREATE_PLAN_UNAVAILABLE');
            }

            $childLimit = null;
            if ($subclienteId !== null) {
                $child = $pdo->prepare(
                    "SELECT status_conta, limite_titulares FROM tenants "
                    . "WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' LIMIT 1"
                );
                $child->execute(['id' => $subclienteId, 'parent' => $tenantId]);
                $childState = $child->fetch(PDO::FETCH_ASSOC);
                if (!is_array($childState) || $childState['status_conta'] !== 'ativo') {
                    throw new DomainException('TITULAR_CREATE_TARGET_INVALID');
                }
                $childLimit = tenantQuotaNullableInteger($childState['limite_titulares']);
            }

            $rootLimit = tenantQuotaNullableInteger($rootState['limite_titulares']);
            if ($rootLimit === null) {
                throw new DomainException('TITULAR_CREATE_QUOTA_UNCONFIGURED');
            }
            if (titularCreateCount($pdo, $tenantId) >= $rootLimit
                || $subclienteId !== null && $childLimit !== null
                    && titularCreateCount($pdo, $tenantId, $subclienteId) >= $childLimit) {
                throw new DomainException('TITULAR_CREATE_QUOTA_EXCEEDED');
            }

            if ($normalized['categoria_id'] !== null) {
                $category = $pdo->prepare(
                    'SELECT id FROM categorias_titular WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1'
                );
                $category->execute(['id' => $normalized['categoria_id'], 'tenant' => $tenantId]);
                if ($category->fetchColumn() === false) {
                    throw new DomainException('TITULAR_CREATE_TARGET_INVALID');
                }
            }

            if ($normalized['cpf'] !== null) {
                $duplicate = $pdo->prepare('SELECT id FROM titulares WHERE tenant_id = :tenant AND cpf = :cpf LIMIT 1');
                $duplicate->execute(['tenant' => $tenantId, 'cpf' => $normalized['cpf']]);
                if ($duplicate->fetchColumn() !== false) {
                    throw new DomainException('TITULAR_CREATE_CPF_CONFLICT');
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $insert = $pdo->prepare(
                'INSERT INTO titulares (tenant_id, subcliente_id, categoria_id, nome_completo, cpf, cargo_funcao, '
                . 'matricula, acesso_app_liberado, ativo, criado_em) '
                . 'VALUES (:tenant, :subclient, :category, :name, :cpf, :role, :registration, 0, 1, :created_at)'
            );
            $insert->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'category' => $normalized['categoria_id'],
                'name' => $normalized['nome_completo'],
                'cpf' => $normalized['cpf'],
                'role' => $normalized['cargo_funcao'],
                'registration' => $normalized['matricula'],
                'created_at' => $now,
            ]);
            $titularId = (int) $pdo->lastInsertId();

            $audit = $pdo->prepare(
                'INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_usuario_id, ator_tipo, acao, entidade, '
                . 'entidade_id, antes_json, depois_json, criado_em) VALUES (:tenant, :subclient, :actor, :actor_type, '
                . ':action, :entity, :entity_id, NULL, :after_json, :created_at)'
            );
            $audit->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'actor' => (int) $identity['id'],
                'actor_type' => (string) $identity['papel_sistema'],
                'action' => 'titular.created',
                'entity' => 'titulares',
                'entity_id' => $titularId,
                'after_json' => json_encode([
                    'categoria_id' => $normalized['categoria_id'],
                    'subcliente_id' => $subclienteId,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TITULAR_CREATE_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);

    return $titularId ?? throw new RuntimeException('TITULAR_CREATE_FAILED');
}

/** @return array{nome_completo:string,cpf:?string,matricula:?string,cargo_funcao:?string,categoria_id:?int} */
function titularCreateNormalize(array $input): array
{
    $allowedKeys = ['nome_completo', 'cpf', 'matricula', 'cargo_funcao', 'categoria_id'];
    if (array_diff(array_keys($input), $allowedKeys) !== []) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    $name = titularCreateText($input['nome_completo'] ?? null, 180, false);
    $registration = titularCreateText($input['matricula'] ?? null, 80, true);
    $role = titularCreateText($input['cargo_funcao'] ?? null, 120, true);
    $cpf = titularCreateCpf($input['cpf'] ?? null);
    $categoryValue = $input['categoria_id'] ?? null;
    $categoryId = null;
    if ($categoryValue !== null && $categoryValue !== '') {
        if (!is_int($categoryValue) && (!is_string($categoryValue) || preg_match('/^[1-9][0-9]*$/D', $categoryValue) !== 1)) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
        $categoryId = filter_var($categoryValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($categoryId === false) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
    }

    return [
        'nome_completo' => $name,
        'cpf' => $cpf,
        'matricula' => $registration,
        'cargo_funcao' => $role,
        'categoria_id' => $categoryId,
    ];
}

function titularCreateText(mixed $value, int $maximumLength, bool $nullable): ?string
{
    if ($value === null && $nullable) {
        return null;
    }
    if (!is_string($value)) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    $normalized = trim($value);
    if ($normalized === '' && $nullable) {
        return null;
    }
    $length = preg_match_all('/./us', $normalized);
    if ($normalized === '' || $length === false || $length > $maximumLength) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }

    return $normalized;
}

function titularCreateCpf(mixed $value): ?string
{
    try {
        return cpfNormalizeBr($value);
    } catch (InvalidArgumentException) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
}

function titularCreateCount(PDO $pdo, int $tenantId, ?int $subclienteId = null): int
{
    $sql = 'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND ativo = 1';
    if ($subclienteId !== null) {
        $sql .= ' AND subcliente_id = :subclient';
    }
    $count = $pdo->prepare($sql);
    $count->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
    if ($subclienteId !== null) {
        $count->bindValue(':subclient', $subclienteId, PDO::PARAM_INT);
    }
    $count->execute();

    return (int) $count->fetchColumn();
}

function titularCreateCanManage(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId
): bool {
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    $tenantScopeMatches = ($context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null)
        || ($context->tipo === 'subcliente' && $context->tenantId === $tenantId
            && $context->subclienteId === $subclienteId);

    return $tenantScopeMatches && pode($pdo, $context, $identity, 'titular.criar', $subclienteId);
}
