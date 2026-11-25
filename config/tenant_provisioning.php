<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/installation_profile.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/tenant_quota_policy_support.php';

/** @param array<string,mixed> $identity @param array<string,mixed> $input @param array<string,string>|null $configuration @return array{tenant_id:int,owner_user_id:int} */
function tenantProvisionCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    array $input,
    ?array $configuration = null,
    ?array $initialLimits = null
): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || !pode($pdo, $context, $identity, 'admin.clientes')) {
        throw new DomainException('TENANT_PROVISION_DENIED');
    }
    if ($configuration === null) {
        $installation = installationProfileCurrent($pdo);
        if (is_array($installation) && $installation['modo'] === 'hospedado') {
            $configuration = [
                'PLATFORM_HOSTNAME' => (string) ($installation['hostname_plataforma'] ?? ''),
                'TENANT_BASE_DOMAIN' => (string) ($installation['dominio_base'] ?? ''),
            ];
        }
    }

    $organization = tenantProvisionName($input['nome_organizacao'] ?? null, 180);
    $ownerName = tenantProvisionName($input['nome_titular'] ?? null, 180);
    $email = is_string($input['email_titular'] ?? null) ? strtolower(trim($input['email_titular'])) : '';
    $password = $input['senha_titular'] ?? null;
    $planId = filter_var($input['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $input['status_conta'] ?? null;
    $slug = is_string($input['slug_subdominio'] ?? null) ? strtolower(trim($input['slug_subdominio'])) : '';

    if ($organization === null || $ownerName === null
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 1024
        || $planId === false || !in_array($status, ['pendente', 'ativo'], true)
        || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $slug) !== 1) {
        throw new DomainException('TENANT_PROVISION_INVALID');
    }
    $normalizedLimits = $initialLimits === null ? null : tenantQuotaNormalizeLimits(
        $initialLimits,
        ['limite_subclientes', 'limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'],
        false
    );
    if (tenantProvisionSlugIsReserved($slug, $configuration)) {
        throw new DomainException('TENANT_PROVISION_RESERVED_SLUG');
    }

    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $context, $identity, 'admin.clientes')) {
            throw new DomainException('TENANT_PROVISION_DENIED');
        }

        $plan = $pdo->prepare('SELECT id FROM planos WHERE id = :id AND ativo = 1 LIMIT 1');
        $plan->execute(['id' => (int) $planId]);
        if ($plan->fetchColumn() === false) {
            throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        }
        if ($normalizedLimits !== null) {
            tenantQuotaAssertWithinPlan($pdo, (int) $planId, $normalizedLimits);
        }

        $existingSlug = $pdo->prepare('SELECT id FROM tenants WHERE slug_subdominio = :slug LIMIT 1');
        $existingSlug->execute(['slug' => $slug]);
        if ($existingSlug->fetchColumn() !== false) {
            throw new DomainException('TENANT_SLUG_CONFLICT');
        }

        $now = gmdate('Y-m-d H:i:s');
        $insertTenant = $pdo->prepare(
            "INSERT INTO tenants (parent_id, plano_id, tipo, nome_organizacao, nome_responsavel, slug_subdominio, "
            . "status_conta, limite_subclientes, limite_usuarios_equipe, limite_titulares, limite_carteirinhas, "
            . "permissoes_unidade_json, criado_em) "
            . "VALUES (NULL, :plan_id, 'cliente', :organization, :owner_name, :slug, :status, :limit_units, "
            . ":limit_users, :limit_holders, :limit_cards, :permissions, :created_at)"
        );
        $insertTenant->execute([
            'plan_id' => (int) $planId,
            'organization' => $organization,
            'owner_name' => $ownerName,
            'slug' => $slug,
            'status' => $status,
            'limit_units' => $normalizedLimits['limite_subclientes'] ?? null,
            'limit_users' => $normalizedLimits['limite_usuarios_equipe'] ?? null,
            'limit_holders' => $normalizedLimits['limite_titulares'] ?? null,
            'limit_cards' => $normalizedLimits['limite_carteirinhas'] ?? null,
            'permissions' => '[]',
            'created_at' => $now,
        ]);
        $tenantId = (int) $pdo->lastInsertId();

        $insertOwner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, '
            . 'papel_sistema, nome, email, senha_hash, ativo, criado_em) '
            . 'VALUES (:tenant_id, NULL, NULL, :creator_id, :role, :name, :email, :password_hash, :active, :created_at)'
        );
        $insertOwner->execute([
            'tenant_id' => $tenantId,
            'creator_id' => (int) $identity['id'],
            'role' => 'titular_cliente',
            'name' => $ownerName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => $status === 'ativo' ? 1 : 0,
            'created_at' => $now,
        ]);
        $ownerUserId = (int) $pdo->lastInsertId();
        $ownerSubject = privacySubjectCreate($pdo, 'pessoa', $tenantId, $now);
        privacySubjectLinkRecord($pdo, $ownerSubject['id'], 'usuario', $ownerUserId);
        privacySubjectLinkRecord($pdo, $ownerSubject['id'], 'tenant_contato', $tenantId);
        if ($status === 'pendente') {
            $pdo->prepare("UPDATE privacy_subjects SET estado = 'inativo', inativado_em = :inactive WHERE id = :id")
                ->execute(['inactive' => $now, 'id' => $ownerSubject['id']]);
        }

        $auditAfter = json_encode([
            'plano_id' => (int) $planId,
            'status_conta' => $status,
            'slug_subdominio' => $slug,
            'titular_usuario_id' => $ownerUserId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $audit = $pdo->prepare(
            'INSERT INTO auditoria_processos (tenant_id, ator_usuario_id, ator_tipo, acao, entidade, entidade_id, '
            . 'antes_json, depois_json, criado_em) '
            . 'VALUES (:tenant_id, :actor_id, :actor_type, :action, :entity, :entity_id, NULL, :after_json, :created_at)'
        );
        $audit->execute([
            'tenant_id' => $tenantId,
            'actor_id' => (int) $identity['id'],
            'actor_type' => 'superadmin',
            'action' => 'tenant.provisioned',
            'entity' => 'tenants',
            'entity_id' => $tenantId,
            'after_json' => $auditAfter,
            'created_at' => $now,
        ]);

        if ($ownsTransaction) $pdo->commit();

        return ['tenant_id' => $tenantId, 'owner_user_id' => $ownerUserId];
    } catch (DomainException $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('TENANT_PROVISION_FAILED');
    }
}

function tenantProvisionName(mixed $value, int $maximumLength): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = trim($value);
    $length = preg_match_all('/./us', $name);
    if ($name === '' || $length === false || $length > $maximumLength) {
        return null;
    }
    return $name;
}

/** @param array<string,string>|null $configuration */
function tenantProvisionSlugIsReserved(string $slug, ?array $configuration = null): bool
{
    if ($slug === 'admin') {
        return true;
    }
    $configuration ??= [];
    $platformHost = tenantResolverNormalizeHost(
        $configuration['PLATFORM_HOSTNAME'] ?? (getenv('PLATFORM_HOSTNAME') ?: '')
    );
    $baseDomain = tenantResolverNormalizeHost(
        $configuration['TENANT_BASE_DOMAIN'] ?? (getenv('TENANT_BASE_DOMAIN') ?: '')
    );
    if ($platformHost === null || $baseDomain === null
        || !str_ends_with($platformHost, '.' . $baseDomain)) {
        return false;
    }
    $prefix = substr($platformHost, 0, -strlen('.' . $baseDomain));
    return !str_contains($prefix, '.') && hash_equals($prefix, $slug);
}
