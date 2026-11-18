<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/tenant_quota_policy.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $input @return array{subcliente_id:int} */
function tenantSubclientCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    array $input,
    ?array $configuration = null
): array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('TENANT_SUBCLIENT_FAILED');
    }
    $organization = tenantProvisionName($input['nome_organizacao'] ?? null, 180);
    $slug = is_string($input['slug_subdominio'] ?? null)
        ? strtolower(trim($input['slug_subdominio']))
        : '';
    if ($tenantId < 1 || $organization === null
        || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $slug) !== 1) {
        throw new DomainException('TENANT_SUBCLIENT_INVALID');
    }
    if (tenantProvisionSlugIsReserved($slug, $configuration)) {
        throw new DomainException('TENANT_SUBCLIENT_RESERVED_SLUG');
    }
    if (!tenantSubclientCanCreate($pdo, $context, $identity, $tenantId)) {
        throw new DomainException('TENANT_SUBCLIENT_DENIED');
    }

    $createdId = null;
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $organization,
            $slug,
            &$createdId
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!tenantSubclientCanCreate($pdo, $context, $identity, $tenantId)) {
                throw new DomainException('TENANT_SUBCLIENT_DENIED');
            }
            $root = tenantQuotaLoadRoot($pdo, $tenantId);
            $state = $pdo->prepare(
                'SELECT t.status_conta, p.ativo FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id '
                . 'WHERE t.id = :id AND t.parent_id IS NULL AND t.tipo = \'cliente\' LIMIT 1'
            );
            $state->execute(['id' => $tenantId]);
            $rootState = $state->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rootState) || $rootState['status_conta'] !== 'ativo') {
                throw new DomainException('TENANT_SUBCLIENT_ROOT_INACTIVE');
            }
            if ((int) ($rootState['ativo'] ?? 0) !== 1) {
                throw new DomainException('TENANT_SUBCLIENT_PLAN_UNAVAILABLE');
            }

            $limit = tenantQuotaNullableInteger($root['limite_subclientes']);
            if ($limit === null) {
                throw new DomainException('TENANT_SUBCLIENT_QUOTA_UNCONFIGURED');
            }
            if (tenantQuotaChildCount($pdo, $tenantId) >= $limit) {
                throw new DomainException('TENANT_SUBCLIENT_QUOTA_EXCEEDED');
            }
            $existing = $pdo->prepare('SELECT id FROM tenants WHERE slug_subdominio = :slug LIMIT 1');
            $existing->execute(['slug' => $slug]);
            if ($existing->fetchColumn() !== false) {
                throw new DomainException('TENANT_SUBCLIENT_SLUG_CONFLICT');
            }

            $insert = $pdo->prepare(
                "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, "
                . "permissoes_unidade_json, criado_em) VALUES (:parent, 'subcliente', :name, :slug, 'ativo', '[]', :created_at)"
            );
            try {
                $insert->execute([
                    'parent' => $tenantId,
                    'name' => $organization,
                    'slug' => $slug,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (PDOException $exception) {
                if (tenantSubclientIsUniqueConflict($exception)) {
                    throw new DomainException('TENANT_SUBCLIENT_SLUG_CONFLICT');
                }
                throw $exception;
            }
            $createdId = (int) $pdo->lastInsertId();
            tenantQuotaAudit(
                $pdo,
                $tenantId,
                $createdId,
                $createdId,
                $identity,
                'subclient.created',
                [],
                ['tipo' => 'subcliente', 'status_conta' => 'ativo']
            );
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TENANT_SUBCLIENT_FAILED');
        }
        throw $exception;
    }

    if (!is_int($createdId) || $createdId < 1) {
        throw new RuntimeException('TENANT_SUBCLIENT_FAILED');
    }

    return ['subcliente_id' => $createdId];
}

/** @param array<string, mixed> $identity */
function tenantSubclientCanCreate(PDO $pdo, TenantContext $context, array $identity, int $tenantId): bool
{
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    return $context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null
        && ($identity['papel_sistema'] ?? null) === 'titular_cliente'
        && pode($pdo, $context, $identity, 'subcliente.criar');
}

function tenantSubclientIsUniqueConflict(PDOException $exception): bool
{
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);

    return $exception->getCode() === '23000' && in_array($driverCode, [19, 1062], true);
}
