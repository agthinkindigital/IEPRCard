<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_lifecycle.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$configuration = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $suffix = bin2hex(random_bytes(5));
    try {
        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Lifecycle admin',
            'email' => 'lifecycle-' . $suffix . '@example.test',
            'password' => password_hash('lifecycle-admin-password', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Lifecycle plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $feature = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
        $feature->execute(['plan' => $planId, 'key' => 'admin.clientes']);

        $adminContext = new TenantContext('admin', null, null, []);
        $identity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];
        $root = $pdo->prepare('UPDATE tenants SET plano_id = :plan, slug_subdominio = :slug WHERE id = :id');
        $root->execute(['plan' => $planId, 'slug' => $fixture['slug'], 'id' => $fixture['tenant_id']]);

        $changed = tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'suspenso');
        $storedRootStatus = $pdo->prepare('SELECT status_conta FROM tenants WHERE id = :id');
        $storedRootStatus->execute(['id' => $fixture['tenant_id']]);
        $storedChildStatus = $pdo->prepare('SELECT status_conta FROM tenants WHERE id = :id');
        $storedChildStatus->execute(['id' => $fixture['subcliente_id']]);
        $rootContext = new TenantContext('cliente', $fixture['tenant_id'], null, []);
        $childContext = new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []);
        if ($changed !== true || $storedRootStatus->fetchColumn() !== 'suspenso'
            || $storedChildStatus->fetchColumn() !== 'ativo'
            || tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $configuration) !== null
            || tenantAuthContextIsActive($pdo, $rootContext)
            || tenantAuthContextIsActive($pdo, $childContext)) {
            throw new RuntimeException($driver . ': suspending the root must block the full hierarchy without overwriting child state.');
        }

        $audit = $pdo->prepare(
            'SELECT ator_usuario_id, ator_tipo, acao, entidade_id, antes_json, depois_json FROM auditoria_processos '
            . 'WHERE tenant_id = :tenant AND acao = :action AND entidade_id = :entity ORDER BY id DESC LIMIT 1'
        );
        $audit->execute(['tenant' => $fixture['tenant_id'], 'action' => 'tenant.status_changed', 'entity' => $fixture['tenant_id']]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $adminId
            || $auditRow['ator_tipo'] !== 'superadmin'
            || json_decode((string) $auditRow['antes_json'], true) !== ['status_conta' => 'ativo']
            || json_decode((string) $auditRow['depois_json'], true) !== ['status_conta' => 'suspenso']) {
            throw new RuntimeException($driver . ': lifecycle transition must be audited with only before/after states.');
        }

        if (tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'suspenso') !== false) {
            throw new RuntimeException($driver . ': repeating the current state must be idempotent.');
        }
        $auditCount = $pdo->prepare(
            'SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao = :action AND entidade_id = :entity'
        );
        $auditCount->execute(['tenant' => $fixture['tenant_id'], 'action' => 'tenant.status_changed', 'entity' => $fixture['tenant_id']]);
        if ((int) $auditCount->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': idempotent lifecycle requests must not create duplicate audit entries.');
        }
        if (tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'ativo') !== true
            || tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $configuration) === null
            || !tenantAuthContextIsActive($pdo, $childContext)) {
            throw new RuntimeException($driver . ': reactivating the root must restore access when the child itself remains active.');
        }
        $storedChildStatus->execute(['id' => $fixture['subcliente_id']]);
        if ($storedChildStatus->fetchColumn() !== 'ativo') {
            throw new RuntimeException($driver . ': reactivation must preserve the independently stored child status.');
        }

        if ($driver === 'SQLite') {
            $triggerName = 'ieprcard_fail_lifecycle_audit_' . substr($suffix, 0, 8);
            $pdo->exec(
                'CREATE TRIGGER ' . $triggerName . ' BEFORE INSERT ON auditoria_processos '
                . "BEGIN SELECT RAISE(ABORT, 'lifecycle audit failure'); END"
            );
            try {
                tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'suspenso');
                throw new RuntimeException('A failed audit insert must abort the lifecycle transition.');
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'TENANT_LIFECYCLE_FAILED') {
                    throw $exception;
                }
            } finally {
                $pdo->exec('DROP TRIGGER IF EXISTS ' . $triggerName);
            }
            $storedRootStatus->execute(['id' => $fixture['tenant_id']]);
            if ($storedRootStatus->fetchColumn() !== 'ativo') {
                throw new RuntimeException('A failed audit insert must roll back the tenant state change.');
            }
        }

        if (tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'encerrado') !== true) {
            throw new RuntimeException($driver . ': an active tenant must be closable.');
        }
        try {
            tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['tenant_id'], 'ativo');
            throw new RuntimeException($driver . ': a closed tenant must not be reactivated.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'TENANT_LIFECYCLE_TRANSITION_INVALID') {
                throw $exception;
            }
        }

        $childTargetRejected = false;
        try {
            tenantLifecycleSetRootStatus($pdo, $adminContext, $identity, $fixture['subcliente_id'], 'suspenso');
        } catch (DomainException $exception) {
            $childTargetRejected = $exception->getMessage() === 'TENANT_LIFECYCLE_TARGET_INVALID';
        }
        if (!$childTargetRejected) {
            throw new RuntimeException($driver . ': lifecycle service must only target root tenants.');
        }

        $tenantIdentity = ['id' => $fixture['tenant_id'], 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $permissionRejected = false;
        try {
            tenantLifecycleSetRootStatus($pdo, $adminContext, $tenantIdentity, $fixture['tenant_id'], 'suspenso');
        } catch (DomainException $exception) {
            $permissionRejected = $exception->getMessage() === 'TENANT_LIFECYCLE_DENIED';
        }
        if (!$permissionRejected) {
            throw new RuntimeException($driver . ': tenant users must not alter global lifecycle state.');
        }

    } finally {
        $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        tenantTestCleanup($pdo, $fixture);
    }
}

fwrite(STDOUT, "PASS: suspending a root blocks the tenant tree and preserves child status.\n");
