<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_operator_provisioning.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $adminId = null;
    $ownerId = null;
    $existingTeamUserId = null;
    $planId = null;
    $tenantId = null;
    $childIds = [];
    $foreignTenantId = null;
    $foreignChildId = null;
    $operatorIds = [];

    try {
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Operator plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
        )->execute(['plan' => $planId, 'key' => 'usuario.criar']);

        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Operator admin',
            'email' => 'operator-admin-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();

        $root = $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, "
            . 'limite_usuarios_equipe) VALUES (:plan, \'cliente\', :name, :slug, \'ativo\', 4)'
        );
        $root->execute(['plan' => $planId, 'name' => 'Operator Sede', 'slug' => 'operator-' . $suffix]);
        $tenantId = (int) $pdo->lastInsertId();

        $owner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $owner->execute([
            'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Operator owner',
            'email' => 'operator-owner-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $ownerId = (int) $pdo->lastInsertId();

        $makeChild = static function (string $slug, ?int $limit) use ($pdo, $tenantId): int {
            $statement = $pdo->prepare(
                "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe) "
                . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', :limit)"
            );
            $statement->execute(['parent' => $tenantId, 'name' => 'Operator ' . $slug, 'slug' => $slug, 'limit' => $limit]);
            return (int) $pdo->lastInsertId();
        };
        $fixedChildId = $makeChild('operator-fixed-' . $suffix, 1);
        $poolChildId = $makeChild('operator-pool-' . $suffix, null);
        $childIds = [$fixedChildId, $poolChildId];
        $foreignRoot = $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES ('cliente', :name, :slug, 'ativo')"
        );
        $foreignRoot->execute(['name' => 'Foreign operator Sede', 'slug' => 'foreign-operator-' . $suffix]);
        $foreignTenantId = (int) $pdo->lastInsertId();
        $foreignChild = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
        );
        $foreignChild->execute([
            'parent' => $foreignTenantId, 'name' => 'Foreign operator unit', 'slug' => 'foreign-unit-' . $suffix,
        ]);
        $foreignChildId = (int) $pdo->lastInsertId();

        $existingTeamUser = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $existingTeamUser->execute([
            'tenant' => $tenantId, 'role' => 'subacesso', 'name' => 'Existing network access',
            'email' => 'operator-existing-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $existingTeamUserId = (int) $pdo->lastInsertId();

        $ownerContext = new TenantContext('cliente', $tenantId, null, []);
        $ownerIdentity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $adminContext = new TenantContext('admin', null, null, []);
        $adminIdentity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];
        $create = static function (TenantContext $context, array $identity, int $childId, string $suffix) use (
            $pdo,
            $tenantId
        ): int {
            return tenantSubclientOperatorCreate($pdo, $context, $identity, $tenantId, $childId, [
                'nome' => 'Unit operator ' . $suffix,
                'email' => 'unit-operator-' . $suffix . '@example.test',
                'senha' => 'correct horse battery staple',
            ]);
        };

        $fixedOperatorId = $create($ownerContext, $ownerIdentity, $fixedChildId, $suffix . '-fixed');
        $operatorIds[] = $fixedOperatorId;
        $created = $pdo->prepare(
            'SELECT tenant_id, subcliente_id, papel_sistema, criado_por_usuario_id, senha_hash, email FROM usuarios WHERE id = :id'
        );
        $created->execute(['id' => $fixedOperatorId]);
        $row = $created->fetch(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare(
            "SELECT ator_usuario_id, acao, entidade, entidade_id, depois_json FROM auditoria_processos "
            . "WHERE tenant_id = :tenant AND acao = 'tenant.subclient_operator_created' ORDER BY id DESC LIMIT 1"
        );
        $audit->execute(['tenant' => $tenantId]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['tenant_id'] !== $tenantId || (int) $row['subcliente_id'] !== $fixedChildId
            || $row['papel_sistema'] !== 'responsavel_sub' || (int) $row['criado_por_usuario_id'] !== $ownerId
            || !password_verify('correct horse battery staple', (string) $row['senha_hash'])
            || $row['email'] !== 'unit-operator-' . $suffix . '-fixed@example.test'
            || !is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $ownerId
            || $auditRow['entidade'] !== 'usuarios' || (int) $auditRow['entidade_id'] !== $fixedOperatorId
            || str_contains((string) $auditRow['depois_json'], '@example.test')
            || str_contains((string) $auditRow['depois_json'], 'senha')
            || str_contains((string) $auditRow['depois_json'], (string) $row['senha_hash'])) {
            throw new RuntimeException($driver . ': operator provisioning must hash credentials, bind the unit and audit no personal data.');
        }

        $triggerName = 'tenant_operator_audit_fail';
        if (strtolower($driver) === 'sqlite') {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos "
                . "WHEN NEW.acao = 'tenant.subclient_operator_created' BEGIN "
                . "SELECT RAISE(ABORT, 'test audit failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos FOR EACH ROW "
                . "BEGIN IF NEW.acao = 'tenant.subclient_operator_created' THEN "
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test audit failure'; END IF; END"
            );
        }
        $auditFailureRejected = false;
        try {
            $create($ownerContext, $ownerIdentity, $poolChildId, $suffix . '-audit-failure');
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TENANT_OPERATOR_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$triggerName}");
        }
        $auditFailureEmail = 'unit-operator-' . $suffix . '-audit-failure@example.test';
        $auditFailureQuery = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
        $auditFailureQuery->execute(['email' => $auditFailureEmail]);
        if (!$auditFailureRejected || (int) $auditFailureQuery->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': an audit failure must roll back the operator account.');
        }

        $operatorContext = new TenantContext('subcliente', $tenantId, $fixedChildId, []);
        $operatorIdentity = [
            'id' => $fixedOperatorId, 'tenant_id' => $tenantId, 'subcliente_id' => $fixedChildId,
            'papel_sistema' => 'responsavel_sub',
        ];
        $operatorDenied = false;
        try {
            $create($operatorContext, $operatorIdentity, $fixedChildId, $suffix . '-forged');
        } catch (DomainException $exception) {
            $operatorDenied = $exception->getMessage() === 'TENANT_OPERATOR_DENIED';
        }
        if (!$operatorDenied) {
            throw new RuntimeException($driver . ': a unit operator must not provision accounts.');
        }

        $invalidCredentialRejected = false;
        try {
            tenantSubclientOperatorCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome' => 'Invalid password operator',
                'email' => 'invalid-password-' . $suffix . '@example.test',
                'senha' => 'short',
            ]);
        } catch (DomainException $exception) {
            $invalidCredentialRejected = $exception->getMessage() === 'TENANT_OPERATOR_INVALID';
        }
        if (!$invalidCredentialRejected) {
            throw new RuntimeException($driver . ': invalid credentials must be rejected before writing.');
        }

        $duplicateEmailRejected = false;
        try {
            tenantSubclientOperatorCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome' => 'Duplicate email operator',
                'email' => 'OPERATOR-OWNER-' . $suffix . '@example.test',
                'senha' => 'correct horse battery staple',
            ]);
        } catch (DomainException $exception) {
            $duplicateEmailRejected = $exception->getMessage() === 'TENANT_OPERATOR_EMAIL_CONFLICT';
        }
        if (!$duplicateEmailRejected) {
            throw new RuntimeException($driver . ': duplicate email addresses must be rejected.');
        }

        $wrongUnitRejected = false;
        try {
            $create($ownerContext, $ownerIdentity, $foreignChildId, $suffix . '-wrong-unit');
        } catch (DomainException $exception) {
            $wrongUnitRejected = $exception->getMessage() === 'TENANT_OPERATOR_TARGET_INVALID';
        }
        if (!$wrongUnitRejected) {
            throw new RuntimeException($driver . ': a forged unit id must not create an operator under another target.');
        }

        $fixedQuotaRejected = false;
        try {
            $create($ownerContext, $ownerIdentity, $fixedChildId, $suffix . '-fixed-overflow');
        } catch (DomainException $exception) {
            $fixedQuotaRejected = $exception->getMessage() === 'TENANT_OPERATOR_QUOTA_EXCEEDED';
        }
        if (!$fixedQuotaRejected) {
            throw new RuntimeException($driver . ': a fixed unit team allocation must be enforced.');
        }

        $poolOperatorOne = $create($ownerContext, $ownerIdentity, $poolChildId, $suffix . '-pool-1');
        $operatorIds[] = $poolOperatorOne;
        $poolOperatorTwo = $create($adminContext, $adminIdentity, $poolChildId, $suffix . '-pool-2');
        $operatorIds[] = $poolOperatorTwo;
        $rootQuotaRejected = false;
        try {
            $create($ownerContext, $ownerIdentity, $poolChildId, $suffix . '-pool-overflow');
        } catch (DomainException $exception) {
            $rootQuotaRejected = $exception->getMessage() === 'TENANT_OPERATOR_QUOTA_EXCEEDED';
        }
        if (!$rootQuotaRejected) {
            throw new RuntimeException($driver . ': NULL unit allocation must use, but never exceed, the shared root pool.');
        }

        $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $tenantId]);
        $inactiveRootRejected = false;
        try {
            $create($adminContext, $adminIdentity, $poolChildId, $suffix . '-inactive-root');
        } catch (DomainException $exception) {
            $inactiveRootRejected = $exception->getMessage() === 'TENANT_OPERATOR_ROOT_INACTIVE';
        }
        $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $tenantId]);
        if (!$inactiveRootRejected) {
            throw new RuntimeException($driver . ': a suspended root must block global admin provisioning.');
        }

        $pdo->prepare('UPDATE planos SET ativo = 0 WHERE id = :id')->execute(['id' => $planId]);
        $inactivePlanRejected = false;
        try {
            $create($adminContext, $adminIdentity, $poolChildId, $suffix . '-inactive-plan');
        } catch (DomainException $exception) {
            $inactivePlanRejected = $exception->getMessage() === 'TENANT_OPERATOR_PLAN_UNAVAILABLE';
        }
        $pdo->prepare('UPDATE planos SET ativo = 1 WHERE id = :id')->execute(['id' => $planId]);
        if (!$inactivePlanRejected) {
            throw new RuntimeException($driver . ': an inactive plan must block global admin provisioning.');
        }

        $pdo->prepare('UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :key')
            ->execute(['plan' => $planId, 'key' => 'usuario.criar']);
        $planFlagRejected = false;
        try {
            $create($adminContext, $adminIdentity, $fixedChildId, $suffix . '-plan-disabled');
        } catch (DomainException $exception) {
            $planFlagRejected = $exception->getMessage() === 'TENANT_OPERATOR_PLAN_UNAVAILABLE';
        }
        if (!$planFlagRejected) {
            throw new RuntimeException($driver . ': the plan must bound global admin overrides as well.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' quota-aware Subcliente operator provisioning, RBAC and audit.' . "\n");
    } finally {
        if ($tenantId !== null) {
            $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
            $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :id')->execute(['id' => $tenantId]);
        }
        tenantQuotaTestCleanup($pdo, $tenantId, $childIds, $ownerId, $planId, $adminId);
        if ($foreignChildId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignChildId]);
        }
        if ($foreignTenantId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignTenantId]);
        }
    }
}

return 'pass';
