<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/tenant_provisioning.php';

$configuration = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $slug = 'provision-' . $suffix;
    $pendingSlug = 'pending-' . $suffix;
    $rollbackSlug = 'rollback-' . $suffix;
    $ownerEmail = 'owner-' . $suffix . '@example.test';
    $adminId = null;
    $planId = null;
    $inactivePlanId = null;
    $triggerName = 'ieprcard_fail_tenant_owner_' . substr($suffix, 0, 8);

    try {
        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Provisioning admin',
            'email' => 'admin-' . $suffix . '@example.test',
            'password' => password_hash('provision-admin-password', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Provision plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $feature = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
        $feature->execute(['plan' => $planId, 'key' => 'titular.ver']);
        $feature->execute(['plan' => $planId, 'key' => 'admin.clientes']);

        $identity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];
        $adminContext = new TenantContext('admin', null, null, []);
        $active = tenantProvisionCreate($pdo, $adminContext, $identity, [
            'nome_organizacao' => 'Organização de teste ' . $suffix,
            'slug_subdominio' => $slug,
            'nome_titular' => 'Titular de teste',
            'email_titular' => strtoupper($ownerEmail),
            'senha_titular' => 'tenant-password-123',
            'plano_id' => $planId,
            'status_conta' => 'ativo',
        ], $configuration);
        if (($active['tenant_id'] ?? null) < 1 || ($active['owner_user_id'] ?? null) < 1) {
            throw new RuntimeException($driver . ': provisioning must return the created tenant and owner IDs.');
        }
        $tenant = $pdo->prepare('SELECT tipo, parent_id, plano_id, nome_organizacao, nome_responsavel, slug_subdominio, status_conta FROM tenants WHERE id = :id');
        $tenant->execute(['id' => $active['tenant_id']]);
        $tenantRow = $tenant->fetch(PDO::FETCH_ASSOC);
        $owner = $pdo->prepare('SELECT tenant_id, subcliente_id, criado_por_usuario_id, papel_sistema, nome, email, senha_hash, ativo FROM usuarios WHERE id = :id');
        $owner->execute(['id' => $active['owner_user_id']]);
        $ownerRow = $owner->fetch(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare('SELECT ator_usuario_id, ator_tipo, acao, entidade, entidade_id, antes_json, depois_json FROM auditoria_processos WHERE tenant_id = :tenant AND entidade_id = :entity');
        $audit->execute(['tenant' => $active['tenant_id'], 'entity' => $active['tenant_id']]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        $auditAfter = is_array($auditRow) ? json_decode((string) $auditRow['depois_json'], true) : null;
        $resolved = tenantResolverResolve($pdo, $slug . '.ieprcard.example', $configuration);
        $authenticated = $resolved === null ? null : tenantAuthAuthenticate($pdo, $resolved, $ownerEmail, 'tenant-password-123');
        if (!is_array($tenantRow) || $tenantRow['tipo'] !== 'cliente' || $tenantRow['parent_id'] !== null
            || (int) $tenantRow['plano_id'] !== $planId || $tenantRow['status_conta'] !== 'ativo'
            || !is_array($ownerRow) || (int) $ownerRow['tenant_id'] !== $active['tenant_id']
            || $ownerRow['subcliente_id'] !== null || (int) $ownerRow['criado_por_usuario_id'] !== $adminId
            || $ownerRow['papel_sistema'] !== 'titular_cliente' || $ownerRow['email'] !== $ownerEmail
            || (int) $ownerRow['ativo'] !== 1 || !password_verify('tenant-password-123', (string) $ownerRow['senha_hash'])
            || !is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $adminId
            || $auditRow['ator_tipo'] !== 'superadmin' || $auditRow['acao'] !== 'tenant.provisioned'
            || $auditRow['entidade'] !== 'tenants' || !is_array($auditAfter)
            || (int) ($auditAfter['plano_id'] ?? 0) !== $planId || ($auditAfter['status_conta'] ?? null) !== 'ativo'
            || str_contains((string) $auditRow['depois_json'], $ownerEmail)
            || str_contains((string) $auditRow['depois_json'], 'tenant-password-123')
            || $authenticated === null || $authenticated['id'] !== $active['owner_user_id']) {
            throw new RuntimeException($driver . ': active provisioning must atomically create an auditable Sede and authenticating titular without PII in audit.');
        }

        $pending = tenantProvisionCreate($pdo, $adminContext, $identity, [
            'nome_organizacao' => 'Conta pendente ' . $suffix,
            'slug_subdominio' => $pendingSlug,
            'nome_titular' => 'Titular pendente',
            'email_titular' => $ownerEmail,
            'senha_titular' => 'tenant-password-123',
            'plano_id' => $planId,
            'status_conta' => 'pendente',
        ], $configuration);
        $pendingTenant = $pdo->prepare('SELECT status_conta FROM tenants WHERE id = :id');
        $pendingTenant->execute(['id' => $pending['tenant_id']]);
        $pendingOwner = $pdo->prepare('SELECT ativo FROM usuarios WHERE id = :id');
        $pendingOwner->execute(['id' => $pending['owner_user_id']]);
        $pendingContext = new TenantContext('cliente', $pending['tenant_id'], null, []);
        if ($pendingTenant->fetchColumn() !== 'pendente' || (int) $pendingOwner->fetchColumn() !== 0
            || tenantAuthAuthenticate($pdo, $pendingContext, $ownerEmail, 'tenant-password-123') !== null
            || tenantResolverResolve($pdo, $pendingSlug . '.ieprcard.example', $configuration) !== null) {
            throw new RuntimeException($driver . ': pending provisioning must keep the tenant and titular inactive.');
        }

        $denied = ['id' => $active['owner_user_id'], 'tenant_id' => $active['tenant_id'], 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $rejected = false;
        try {
            tenantProvisionCreate($pdo, $adminContext, $denied, [
                'nome_organizacao' => 'Denied ' . $suffix,
                'slug_subdominio' => 'denied-' . $suffix,
                'nome_titular' => 'Denied', 'email_titular' => 'denied-' . $suffix . '@example.test',
                'senha_titular' => 'tenant-password-123', 'plano_id' => $planId, 'status_conta' => 'ativo',
            ], $configuration);
        } catch (DomainException $exception) {
            $rejected = $exception->getMessage() === 'TENANT_PROVISION_DENIED';
        }
        if (!$rejected || (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug_subdominio = 'denied-" . $suffix . "'")->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': tenant identities must not provision global accounts.');
        }

        $cases = [
            [['status_conta' => 'suspenso'], 'TENANT_PROVISION_INVALID'],
            [['slug_subdominio' => 'bad_slug'], 'TENANT_PROVISION_INVALID'],
            [['slug_subdominio' => 'admin'], 'TENANT_PROVISION_RESERVED_SLUG'],
            [['plano_id' => 99999999], 'TENANT_PLAN_UNAVAILABLE'],
            [['email_titular' => 'not-an-email'], 'TENANT_PROVISION_INVALID'],
            [['senha_titular' => 'short'], 'TENANT_PROVISION_INVALID'],
            [['nome_organizacao' => ''], 'TENANT_PROVISION_INVALID'],
        ];
        foreach ($cases as [$changes, $expectedCode]) {
            $input = array_replace([
                'nome_organizacao' => 'Invalid ' . $suffix,
                'slug_subdominio' => 'invalid-' . $suffix,
                'nome_titular' => 'Invalid', 'email_titular' => 'invalid-' . $suffix . '@example.test',
                'senha_titular' => 'tenant-password-123', 'plano_id' => $planId, 'status_conta' => 'ativo',
            ], $changes);
            $thrownCode = null;
            try {
                tenantProvisionCreate($pdo, $adminContext, $identity, $input, $configuration);
            } catch (DomainException $exception) {
                $thrownCode = $exception->getMessage();
            }
            if ($thrownCode !== $expectedCode) {
                throw new RuntimeException($driver . ': invalid provisioning input must be rejected with a stable domain error.');
            }
        }

        $inactivePlan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 0)');
        $inactivePlan->execute(['name' => 'Inactive plan ' . $suffix]);
        $inactivePlanId = (int) $pdo->lastInsertId();
        try {
            tenantProvisionCreate($pdo, $adminContext, $identity, [
                'nome_organizacao' => 'Inactive plan ' . $suffix,
                'slug_subdominio' => 'inactive-' . $suffix,
                'nome_titular' => 'Inactive', 'email_titular' => 'inactive-' . $suffix . '@example.test',
                'senha_titular' => 'tenant-password-123', 'plano_id' => $inactivePlanId, 'status_conta' => 'ativo',
            ], $configuration);
            throw new RuntimeException($driver . ': inactive plans must not provision tenants.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'TENANT_PLAN_UNAVAILABLE') {
                throw $exception;
            }
        }

        if ($driver === 'SQLite') {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON usuarios WHEN NEW.email = 'rollback@example.test' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        } else {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON usuarios FOR EACH ROW BEGIN IF NEW.email = 'rollback@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'; END IF; END");
        }
        try {
            tenantProvisionCreate($pdo, $adminContext, $identity, [
                'nome_organizacao' => 'Rollback ' . $suffix,
                'slug_subdominio' => $rollbackSlug,
                'nome_titular' => 'Rollback', 'email_titular' => 'rollback@example.test',
                'senha_titular' => 'tenant-password-123', 'plano_id' => $planId, 'status_conta' => 'ativo',
            ], $configuration);
            throw new RuntimeException($driver . ': owner insert failure must abort tenant provisioning.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'TENANT_PROVISION_FAILED') {
                throw $exception;
            }
        }
        $rollbackCount = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug_subdominio = :slug');
        $rollbackCount->execute(['slug' => $rollbackSlug]);
        if ((int) $rollbackCount->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': failed owner creation must roll back its tenant row.');
        }

        $duplicateRejected = false;
        try {
            tenantProvisionCreate($pdo, $adminContext, $identity, [
                'nome_organizacao' => 'Duplicate ' . $suffix,
                'slug_subdominio' => $slug,
                'nome_titular' => 'Duplicate', 'email_titular' => 'duplicate-' . $suffix . '@example.test',
                'senha_titular' => 'tenant-password-123', 'plano_id' => $planId, 'status_conta' => 'ativo',
            ], $configuration);
        } catch (DomainException $exception) {
            $duplicateRejected = $exception->getMessage() === 'TENANT_SLUG_CONFLICT';
        }
        if (!$duplicateRejected) {
            throw new RuntimeException($driver . ': duplicate tenant slugs must be rejected.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' tenant provisioning, activation, authorization and atomic audit.' . "\n");
    } finally {
        if ($driver === 'SQLite') {
            $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
        } else {
            $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
        }
        foreach ([$slug, $pendingSlug, $rollbackSlug, 'denied-' . $suffix, 'invalid-' . $suffix, 'inactive-' . $suffix] as $cleanupSlug) {
            $find = $pdo->prepare('SELECT id FROM tenants WHERE slug_subdominio = :slug');
            $find->execute(['slug' => $cleanupSlug]);
            $tenantId = $find->fetchColumn();
            if ($tenantId !== false) {
                $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
                $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :id')->execute(['id' => $tenantId]);
                $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
            }
        }
        if ($planId !== null) {
            $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :id')->execute(['id' => $planId]);
            $pdo->prepare('DELETE FROM planos WHERE id = :id')->execute(['id' => $planId]);
        }
        if ($inactivePlanId !== null) {
            $pdo->prepare('DELETE FROM planos WHERE id = :id')->execute(['id' => $inactivePlanId]);
        }
        if ($adminId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminId]);
        }
    }
}

return 'pass';
