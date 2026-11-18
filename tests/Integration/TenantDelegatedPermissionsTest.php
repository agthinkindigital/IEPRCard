<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';
require_once dirname(__DIR__, 2) . '/config/tenant_delegated_permissions.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $adminId = null;
    $adminContext = new TenantContext('admin', null, null, []);
    $adminIdentity = null;
    $ownerId = null;
    $operatorId = null;
    $planId = null;
    $tenantId = null;
    $childId = null;
    $foreignTenantId = null;
    $foreignProfileId = null;
    $subaccessId = null;

    try {
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Delegation plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $feature = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, :enabled)');
        foreach ([
            'usuario.permissoes', 'titular.ver', 'titular.criar', 'design.ver',
            'usuario.criar', 'financeiro.ver', 'telemetria.rede',
        ] as $key) {
            $feature->execute(['plan' => $planId, 'key' => $key, 'enabled' => 1]);
        }

        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Delegation admin',
            'email' => 'delegation-admin-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $adminIdentity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];

        $root = $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, permissoes_unidade_json) "
            . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', '[]')"
        );
        $root->execute(['plan' => $planId, 'name' => 'Delegation Sede', 'slug' => 'delegate-' . $suffix]);
        $tenantId = (int) $pdo->lastInsertId();
        $child = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
        );
        $child->execute(['parent' => $tenantId, 'name' => 'Delegation unit', 'slug' => 'delegate-unit-' . $suffix]);
        $childId = (int) $pdo->lastInsertId();

        $foreignRoot = $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES ('cliente', :name, :slug, 'ativo')"
        );
        $foreignRoot->execute(['name' => 'Foreign delegation Sede', 'slug' => 'foreign-delegate-' . $suffix]);
        $foreignTenantId = (int) $pdo->lastInsertId();
        $foreignProfile = $pdo->prepare(
            'INSERT INTO perfis_acesso (tenant_id, nome_perfil, escopo_permitido, permissoes_acoes_json) '
            . 'VALUES (:tenant, :name, :scope, :permissions)'
        );
        $foreignProfile->execute([
            'tenant' => $foreignTenantId,
            'name' => 'Foreign delegated profile',
            'scope' => 'rede',
            'permissions' => json_encode(['titular.ver'], JSON_THROW_ON_ERROR),
        ]);
        $foreignProfileId = (int) $pdo->lastInsertId();

        $owner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $owner->execute([
            'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Delegation owner',
            'email' => 'delegation-owner-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $ownerId = (int) $pdo->lastInsertId();
        $operator = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :child, :role, :name, :email, :password, 1)'
        );
        $operator->execute([
            'tenant' => $tenantId, 'child' => $childId, 'role' => 'responsavel_sub', 'name' => 'Delegation operator',
            'email' => 'delegation-operator-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $operatorId = (int) $pdo->lastInsertId();
        $subaccess = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :profile, :role, :name, :email, :password, 1)'
        );
        $subaccess->execute([
            'tenant' => $tenantId, 'profile' => $foreignProfileId, 'role' => 'subacesso',
            'name' => 'Foreign profile access', 'email' => 'foreign-profile-' . $suffix . '@example.test',
            'password' => 'not-used',
        ]);
        $subaccessId = (int) $pdo->lastInsertId();

        $ownerContext = new TenantContext('cliente', $tenantId, null, []);
        $ownerIdentity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $unitContext = new TenantContext('subcliente', $tenantId, $childId, []);
        $operatorIdentity = ['id' => $operatorId, 'tenant_id' => $tenantId, 'subcliente_id' => $childId, 'papel_sistema' => 'responsavel_sub'];
        if (pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId)) {
            throw new RuntimeException($driver . ': a unit operator must start with delegated business permissions denied.');
        }

        $delegated = tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, [
            'titular.criar', 'titular.ver', 'titular.ver',
        ]);
        motorPermissoesCacheLimpar($pdo);
        if ($delegated !== ['titular.criar', 'titular.ver']
            || !pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId)
            || !pode($pdo, $unitContext, $operatorIdentity, 'titular.criar', $childId)) {
            throw new RuntimeException($driver . ': enabled business permissions must be normalized and become available only inside the unit: ' . json_encode([
                'delegated' => $delegated,
                'view' => pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId),
                'create' => pode($pdo, $unitContext, $operatorIdentity, 'titular.criar', $childId),
            ]));
        }
        $subaccessIdentity = [
            'id' => $subaccessId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'subacesso',
        ];
        if (pode($pdo, $ownerContext, $subaccessIdentity, 'titular.ver', $childId)) {
            throw new RuntimeException($driver . ': a profile belonging to another Sede must not grant a delegated unit permission.');
        }

        $audit = $pdo->prepare(
            "SELECT ator_usuario_id, acao, antes_json, depois_json FROM auditoria_processos "
            . "WHERE tenant_id = :tenant AND acao = 'tenant.unit_permissions_changed' ORDER BY id DESC LIMIT 1"
        );
        $audit->execute(['tenant' => $tenantId]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $ownerId
            || json_decode((string) $auditRow['antes_json'], true) !== ['permissoes_unidade' => []]
            || json_decode((string) $auditRow['depois_json'], true) !== ['permissoes_unidade' => $delegated]
            || str_contains((string) $auditRow['antes_json'], '@example.test')
            || str_contains((string) $auditRow['depois_json'], '@example.test')) {
            throw new RuntimeException($driver . ': delegation audit must include only permission keys and the persisted actor.');
        }

        $triggerName = 'tenant_delegation_audit_fail';
        if (strtolower($driver) === 'sqlite') {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos "
                . "WHEN NEW.acao = 'tenant.unit_permissions_changed' BEGIN "
                . "SELECT RAISE(ABORT, 'test audit failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos FOR EACH ROW "
                . "BEGIN IF NEW.acao = 'tenant.unit_permissions_changed' THEN "
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test audit failure'; END IF; END"
            );
        }
        $auditFailureRejected = false;
        try {
            tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, ['titular.ver']);
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TENANT_DELEGATION_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$triggerName}");
        }
        $permissionsAfterAuditFailure = $pdo->prepare('SELECT permissoes_unidade_json FROM tenants WHERE id = :id');
        $permissionsAfterAuditFailure->execute(['id' => $tenantId]);
        if (!$auditFailureRejected
            || json_decode((string) $permissionsAfterAuditFailure->fetchColumn(), true) !== $delegated) {
            throw new RuntimeException($driver . ': an audit failure must roll back the permission JSON update.');
        }

        $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :id')->execute([
            'permissions' => json_encode(
                ['titular.ver', 'usuario.criar', 'financeiro.ver', 'telemetria.rede'],
                JSON_THROW_ON_ERROR
            ),
            'id' => $tenantId,
        ]);
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId)
            || pode($pdo, $unitContext, $operatorIdentity, 'usuario.criar', $childId)
            || pode($pdo, $unitContext, $operatorIdentity, 'financeiro.ver', $childId)
            || pode($pdo, $unitContext, $operatorIdentity, 'telemetria.rede', $childId)) {
            throw new RuntimeException($driver . ': direct database tampering must not bypass the hard-coded delegation ceiling.');
        }

        tenantDelegatedPermissionsSet($pdo, $adminContext, $adminIdentity, $tenantId, ['design.ver']);
        motorPermissoesCacheLimpar($pdo);
        $audit->execute(['tenant' => $tenantId]);
        $adminAudit = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($adminAudit) || (int) $adminAudit['ator_usuario_id'] !== $adminId
            || !pode($pdo, $unitContext, $operatorIdentity, 'design.ver', $childId)) {
            throw new RuntimeException($driver . ': global admin must be able to override a target Sede with an audit record.');
        }
        tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, ['titular.criar', 'titular.ver']);
        motorPermissoesCacheLimpar($pdo);

        $disabledFlag = $pdo->prepare(
            'UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :key'
        );
        $disabledFlag->execute(['plan' => $planId, 'key' => 'titular.criar']);
        $disabledPlanPermissionRejected = false;
        try {
            tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, ['titular.criar']);
        } catch (DomainException $exception) {
            $disabledPlanPermissionRejected = $exception->getMessage() === 'TENANT_DELEGATION_PERMISSION_UNAVAILABLE';
        }
        $currentPermissions = $pdo->prepare('SELECT permissoes_unidade_json FROM tenants WHERE id = :id');
        $currentPermissions->execute(['id' => $tenantId]);
        if (!$disabledPlanPermissionRejected
            || json_decode((string) $currentPermissions->fetchColumn(), true) !== ['titular.criar', 'titular.ver']) {
            throw new RuntimeException($driver . ': a disabled plan permission must be rejected without replacing current delegation.');
        }
        $pdo->prepare(
            'UPDATE plano_feature_flags SET habilitado = 1 WHERE plano_id = :plan AND feature_key = :key'
        )->execute(['plan' => $planId, 'key' => 'titular.criar']);

        $blockedRejected = false;
        try {
            tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, ['usuario.criar']);
        } catch (DomainException $exception) {
            $blockedRejected = $exception->getMessage() === 'TENANT_DELEGATION_PERMISSION_FORBIDDEN';
        }
        motorPermissoesCacheLimpar($pdo);
        if (!$blockedRejected || !pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId))
            throw new RuntimeException($driver . ': protected permission groups must not replace the current delegation.');

        $unknownRejected = false;
        try {
            tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, ['titular.nao_catalogada']);
        } catch (DomainException $exception) {
            $unknownRejected = $exception->getMessage() === 'TENANT_DELEGATION_PERMISSION_UNKNOWN';
        }
        if (!$unknownRejected || !pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId)) {
            throw new RuntimeException($driver . ': unknown permission keys must be rejected without changing delegation.');
        }

        tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId, []);
        motorPermissoesCacheLimpar($pdo);
        if (pode($pdo, $unitContext, $operatorIdentity, 'titular.ver', $childId)) {
            throw new RuntimeException($driver . ': an empty delegation list must revoke all unit permissions.');
        }

        $forgedTargetRejected = false;
        try {
            tenantDelegatedPermissionsSet($pdo, $ownerContext, $ownerIdentity, $tenantId + 100000, ['titular.ver']);
        } catch (DomainException $exception) {
            $forgedTargetRejected = $exception->getMessage() === 'TENANT_DELEGATION_DENIED';
        }
        if (!$forgedTargetRejected) {
            throw new RuntimeException($driver . ': a tenant identity must not delegate permissions to another Sede.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' delegated unit permissions and RBAC enforcement.' . "\n");
    } finally {
        if ($tenantId !== null) {
            $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
        }
        if ($operatorId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $operatorId]);
        }
        if ($subaccessId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $subaccessId]);
        }
        if ($foreignProfileId !== null) {
            $pdo->prepare('DELETE FROM perfis_acesso WHERE id = :id')->execute(['id' => $foreignProfileId]);
        }
        if ($foreignTenantId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignTenantId]);
        }
        tenantQuotaTestCleanup($pdo, $tenantId, [$childId], $ownerId, $planId, $adminId);
    }
}

return 'pass';
