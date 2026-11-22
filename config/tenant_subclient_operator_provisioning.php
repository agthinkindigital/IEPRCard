<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_quota_policy.php';
require_once __DIR__ . '/privacy_subjects.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $input */
function tenantSubclientOperatorCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    int $subclienteId,
    array $input
): int {
    $name = tenantSubclientOperatorName($input['nome'] ?? null);
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = $input['senha'] ?? null;
    if ($name === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 1024
        || $tenantId < 1 || $subclienteId < 1) {
        throw new DomainException('TENANT_OPERATOR_INVALID');
    }
    if (!tenantSubclientOperatorCanManage($pdo, $context, $identity, $tenantId)) {
        throw new DomainException('TENANT_OPERATOR_DENIED');
    }

    $userId = null;
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $subclienteId,
            $name,
            $email,
            $password,
            &$userId
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!tenantSubclientOperatorCanManage($pdo, $context, $identity, $tenantId)) {
                throw new DomainException('TENANT_OPERATOR_DENIED');
            }

            $root = $pdo->prepare(
                "SELECT plano_id, status_conta, limite_usuarios_equipe FROM tenants "
                . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
            );
            $root->execute(['id' => $tenantId]);
            $rootState = $root->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rootState) || $rootState['status_conta'] !== 'ativo'
                || tenantQuotaNullableInteger($rootState['plano_id']) === null) {
                throw new DomainException('TENANT_OPERATOR_ROOT_INACTIVE');
            }

            $planId = (int) $rootState['plano_id'];
            $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
            $plan->execute(['id' => $planId]);
            if ((int) $plan->fetchColumn() !== 1) {
                throw new DomainException('TENANT_OPERATOR_PLAN_UNAVAILABLE');
            }
            $feature = $pdo->prepare(
                'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :key LIMIT 1'
            );
            $feature->execute(['plan' => $planId, 'key' => 'usuario.criar']);
            if ((int) $feature->fetchColumn() !== 1) {
                throw new DomainException('TENANT_OPERATOR_PLAN_UNAVAILABLE');
            }

            $child = $pdo->prepare(
                "SELECT status_conta, limite_usuarios_equipe FROM tenants "
                . "WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' LIMIT 1"
            );
            $child->execute(['id' => $subclienteId, 'parent' => $tenantId]);
            $childState = $child->fetch(PDO::FETCH_ASSOC);
            if (!is_array($childState) || $childState['status_conta'] !== 'ativo') {
                throw new DomainException('TENANT_OPERATOR_TARGET_INVALID');
            }

            $rootLimit = tenantQuotaNullableInteger($rootState['limite_usuarios_equipe']);
            if ($rootLimit === null) {
                throw new DomainException('TENANT_OPERATOR_QUOTA_UNCONFIGURED');
            }
            $rootUsage = tenantSubclientOperatorCount($pdo, $tenantId);
            if ($rootUsage >= $rootLimit) {
                throw new DomainException('TENANT_OPERATOR_QUOTA_EXCEEDED');
            }

            $childLimit = tenantQuotaNullableInteger($childState['limite_usuarios_equipe']);
            if ($childLimit !== null && tenantSubclientOperatorCount($pdo, $tenantId, $subclienteId) >= $childLimit) {
                throw new DomainException('TENANT_OPERATOR_QUOTA_EXCEEDED');
            }

            $duplicate = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(email) = :email LIMIT 1');
            $duplicate->execute(['email' => $email]);
            if ($duplicate->fetchColumn() !== false) {
                throw new DomainException('TENANT_OPERATOR_EMAIL_CONFLICT');
            }

            $now = gmdate('Y-m-d H:i:s');
            $insert = $pdo->prepare(
                'INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, '
                . 'papel_sistema, nome, email, senha_hash, ativo, criado_em) '
                . 'VALUES (:tenant, :subclient, NULL, :creator, \'responsavel_sub\', :name, :email, :password, 1, :created_at)'
            );
            $insert->execute([
                'tenant' => $tenantId,
                'subclient' => $subclienteId,
                'creator' => (int) $identity['id'],
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => $now,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $subject = privacySubjectCreate($pdo, 'pessoa', $tenantId, $now);
            privacySubjectLinkRecord($pdo, $subject['id'], 'usuario', $userId);

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
                'action' => 'tenant.subclient_operator_created',
                'entity' => 'usuarios',
                'entity_id' => $userId,
                'after_json' => json_encode([
                    'papel_sistema' => 'responsavel_sub',
                    'subcliente_id' => $subclienteId,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TENANT_OPERATOR_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);

    return $userId ?? throw new RuntimeException('TENANT_OPERATOR_FAILED');
}

function tenantSubclientOperatorCount(PDO $pdo, int $tenantId, ?int $subclienteId = null): int
{
    $sql = "SELECT COUNT(*) FROM usuarios WHERE tenant_id = :tenant AND ativo = 1 "
        . "AND papel_sistema IN ('responsavel_sub', 'subacesso')";
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

function tenantSubclientOperatorCanManage(PDO $pdo, TenantContext $context, array $identity, int $tenantId): bool
{
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    return $context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null
        && ($identity['papel_sistema'] ?? null) === 'titular_cliente'
        && pode($pdo, $context, $identity, 'usuario.criar');
}

function tenantSubclientOperatorName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = trim($value);
    $length = preg_match_all('/./us', $name);
    if ($name === '' || $length === false || $length > 180) {
        return null;
    }

    return $name;
}
