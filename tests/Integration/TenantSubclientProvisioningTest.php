<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';
require_once dirname(__DIR__, 2) . '/config/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_provisioning.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $adminId = null;
    $ownerId = null;
    $planId = null;
    $tenantId = null;
    $childIds = [];

    try {
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Subclient plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
        $flag->execute(['plan' => $planId, 'key' => 'subcliente.criar']);

        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Subclient admin',
            'email' => 'sub-admin-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $adminId = (int) $pdo->lastInsertId();

        $root = $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_subclientes) "
            . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 2)"
        );
        $root->execute(['plan' => $planId, 'name' => 'Subclient Sede', 'slug' => 'sub-root-' . $suffix]);
        $tenantId = (int) $pdo->lastInsertId();

        $owner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $owner->execute([
            'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Subclient owner',
            'email' => 'sub-owner-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $ownerId = (int) $pdo->lastInsertId();

        $adminContext = new TenantContext('admin', null, null, []);
        $adminIdentity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];
        $ownerContext = new TenantContext('cliente', $tenantId, null, []);
        $ownerIdentity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $first = tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, [
            'nome_organizacao' => 'Unidade Alpha',
            'slug_subdominio' => 'alpha-' . $suffix,
        ]);
        $childIds[] = $first['subcliente_id'];

        $unit = $pdo->prepare(
            'SELECT parent_id, tipo, nome_organizacao, slug_subdominio, status_conta FROM tenants WHERE id = :id'
        );
        $unit->execute(['id' => $first['subcliente_id']]);
        $unitRow = $unit->fetch(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare(
            "SELECT subcliente_id, ator_usuario_id, acao, antes_json, depois_json FROM auditoria_processos "
            . "WHERE tenant_id = :tenant AND entidade_id = :entity AND acao = 'subclient.created' LIMIT 1"
        );
        $audit->execute(['tenant' => $tenantId, 'entity' => $first['subcliente_id']]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unitRow) || (int) $unitRow['parent_id'] !== $tenantId
            || $unitRow['tipo'] !== 'subcliente' || $unitRow['nome_organizacao'] !== 'Unidade Alpha'
            || $unitRow['slug_subdominio'] !== 'alpha-' . $suffix || $unitRow['status_conta'] !== 'ativo'
            || !is_array($auditRow) || (int) $auditRow['subcliente_id'] !== $first['subcliente_id']
            || (int) $auditRow['ator_usuario_id'] !== $ownerId
            || str_contains((string) $auditRow['depois_json'], 'Unidade Alpha')
            || str_contains((string) $auditRow['depois_json'], '@example.test')) {
            throw new RuntimeException($driver . ': unit creation must be scoped and auditable without personal or organization data in the audit payload.');
        }

        $duplicateRejected = false;
        try {
            tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade duplicada',
                'slug_subdominio' => 'alpha-' . $suffix,
            ]);
        } catch (DomainException $exception) {
            $duplicateRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_SLUG_CONFLICT';
        }
        if (!$duplicateRejected) {
            throw new RuntimeException($driver . ': duplicate unit slugs must be rejected.');
        }

        $invalidRejected = false;
        try {
            tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade inválida',
                'slug_subdominio' => 'invalid_slug',
            ]);
        } catch (DomainException $exception) {
            $invalidRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_INVALID';
        }
        $forgedTargetRejected = false;
        try {
            tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId + 1, [
                'nome_organizacao' => 'Unidade fora do escopo',
                'slug_subdominio' => 'forged-' . $suffix,
            ]);
        } catch (DomainException $exception) {
            $forgedTargetRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_DENIED';
        }
        if (!$invalidRejected || !$forgedTargetRejected) {
            throw new RuntimeException($driver . ': invalid input and cross-Sede target IDs must be rejected.');
        }

        $triggerName = 'ieprcard_fail_subclient_audit_' . substr($suffix, 0, 8);
        if ($driver === 'SQLite') {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos WHEN NEW.acao = 'subclient.created' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        } else {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos FOR EACH ROW BEGIN IF NEW.acao = 'subclient.created' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'; END IF; END");
        }
        $auditFailureRejected = false;
        try {
            tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade rollback',
                'slug_subdominio' => 'rollback-' . $suffix,
            ]);
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
        }
        $countBeforeSecond = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :parent AND tipo = 'subcliente'");
        $countBeforeSecond->execute(['parent' => $tenantId]);
        if (!$auditFailureRejected || (int) $countBeforeSecond->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': an audit failure must roll back the new Subcliente.');
        }

        $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $tenantId]);
        $inactiveRootRejected = false;
        try {
            tenantSubclientCreate($pdo, $adminContext, $adminIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade de conta suspensa',
                'slug_subdominio' => 'suspended-' . $suffix,
            ]);
        } catch (DomainException $exception) {
            $inactiveRootRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_ROOT_INACTIVE';
        }
        $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $tenantId]);
        $pdo->prepare('UPDATE tenants SET limite_subclientes = NULL WHERE id = :id')->execute(['id' => $tenantId]);
        $unconfiguredLimitRejected = false;
        try {
            tenantSubclientCreate($pdo, $adminContext, $adminIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade sem cota',
                'slug_subdominio' => 'unconfigured-' . $suffix,
            ]);
        } catch (DomainException $exception) {
            $unconfiguredLimitRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_QUOTA_UNCONFIGURED';
        }
        $pdo->prepare('UPDATE tenants SET limite_subclientes = 2 WHERE id = :id')->execute(['id' => $tenantId]);
        if (!$inactiveRootRejected || !$unconfiguredLimitRejected) {
            throw new RuntimeException($driver . ': inactive roots and unconfigured quotas must fail closed.');
        }

        $second = tenantSubclientCreate($pdo, $adminContext, $adminIdentity, $tenantId, [
            'nome_organizacao' => 'Unidade Beta',
            'slug_subdominio' => 'beta-' . $suffix,
        ]);
        $childIds[] = $second['subcliente_id'];
        $quotaRejected = false;
        try {
            tenantSubclientCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, [
                'nome_organizacao' => 'Unidade Gamma',
                'slug_subdominio' => 'gamma-' . $suffix,
            ]);
        } catch (DomainException $exception) {
            $quotaRejected = $exception->getMessage() === 'TENANT_SUBCLIENT_QUOTA_EXCEEDED';
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :parent AND tipo = 'subcliente'");
        $count->execute(['parent' => $tenantId]);
        if (!$quotaRejected || (int) $count->fetchColumn() !== 2) {
            throw new RuntimeException($driver . ': configured subclient quota must reject any creation once its ceiling is reached.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' Subcliente creation, authorization, audit and quota ceiling.' . "\n");
    } finally {
        tenantQuotaTestCleanup($pdo, $tenantId, $childIds, $ownerId, $planId, $adminId);
    }
}

return 'pass';
