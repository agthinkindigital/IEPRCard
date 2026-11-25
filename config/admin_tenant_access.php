<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_tenant_plans.php';
require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/tenant_delegated_permissions.php';
require_once __DIR__ . '/tenant_quota_policy.php';
require_once __DIR__ . '/privacy_subjects.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $input @return array{user_id:int,profile_id:int} */
function adminTenantSedeAccessCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    array $input
): array {
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    $name = tenantProvisionName($input['nome'] ?? null, 180);
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = $input['senha'] ?? null;
    $profileName = tenantProvisionName($input['perfil'] ?? null, 120);
    $permissions = tenantDelegatedPermissionsNormalize($input['permissoes'] ?? null);
    if ($tenantId < 1 || $name === null || $profileName === null
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 1024) {
        throw new DomainException('ADMIN_TENANT_ACCESS_INVALID');
    }

    $result = null;
    tenantQuotaRunLocked($pdo, $tenantId, function () use (
        $pdo, $context, $identity, $tenantId, $name, $email, $password, $profileName, $permissions, &$result
    ): void {
        motorPermissoesCacheLimpar($pdo);
        adminTenantPlanRequireAccess($pdo, $context, $identity);
        $rootQuery = $pdo->prepare(
            "SELECT plano_id, status_conta, limite_usuarios_equipe FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
        );
        $rootQuery->execute(['id' => $tenantId]);
        $root = $rootQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($root) || $root['status_conta'] !== 'ativo') throw new DomainException('ADMIN_TENANT_ACCESS_TARGET_INVALID');
        $planId = (int) $root['plano_id'];
        $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
        $plan->execute(['id' => $planId]);
        if ((int) $plan->fetchColumn() !== 1) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        $createFeature = $pdo->prepare(
            'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = \'usuario.criar\' LIMIT 1'
        );
        $createFeature->execute(['plan' => $planId]);
        if ((int) $createFeature->fetchColumn() !== 1) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        tenantDelegatedPermissionsValidatePlan($pdo, $planId, $permissions);
        $limit = tenantQuotaNullableInteger($root['limite_usuarios_equipe']);
        if ($limit === null) throw new DomainException('TENANT_QUOTA_UNCONFIGURED');
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM usuarios WHERE tenant_id = :tenant AND ativo = 1 AND papel_sistema IN ('responsavel_sub', 'subacesso')"
        );
        $count->execute(['tenant' => $tenantId]);
        if ((int) $count->fetchColumn() >= $limit) throw new DomainException('TENANT_QUOTA_EXCEEDED');
        $duplicate = $pdo->prepare('SELECT id FROM usuarios WHERE LOWER(email) = :email LIMIT 1');
        $duplicate->execute(['email' => $email]);
        if ($duplicate->fetchColumn() !== false) throw new DomainException('TENANT_OPERATOR_EMAIL_CONFLICT');

        $now = gmdate('Y-m-d H:i:s');
        $profile = $pdo->prepare(
            "INSERT INTO perfis_acesso (tenant_id, nome_perfil, escopo_permitido, permissoes_acoes_json, criado_em) VALUES (:tenant, :name, 'sede', :permissions, :created)"
        );
        $profile->execute([
            'tenant' => $tenantId, 'name' => $profileName,
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created' => $now,
        ]);
        $profileId = (int) $pdo->lastInsertId();
        $insertUser = $pdo->prepare(
            "INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) "
            . "VALUES (:tenant, NULL, :profile, :creator, 'subacesso', :name, :email, :password, 1, :created)"
        );
        $insertUser->execute([
            'tenant' => $tenantId, 'profile' => $profileId, 'creator' => (int) $identity['id'], 'name' => $name,
            'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'created' => $now,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $subject = privacySubjectCreate($pdo, 'pessoa', $tenantId, $now);
        privacySubjectLinkRecord($pdo, $subject['id'], 'usuario', $userId);
        tenantQuotaAudit($pdo, $tenantId, $userId, null, $identity, 'tenant.sede_access_created', [], [
            'papel_sistema' => 'subacesso', 'perfil_acesso_id' => $profileId,
        ]);
        $result = ['user_id' => $userId, 'profile_id' => $profileId];
    });
    motorPermissoesCacheLimpar($pdo);
    return $result ?? throw new RuntimeException('ADMIN_TENANT_ACCESS_FAILED');
}
