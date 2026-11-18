<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/titular_provisioning.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $adminId = null;
    $ownerId = null;
    $operatorId = null;
    $planId = null;
    $tenantId = null;
    $childIds = [];
    $foreignTenantId = null;
    $foreignCategoryId = null;
    $foreignChildId = null;

    try {
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Titular plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
        )->execute(['plan' => $planId, 'key' => 'titular.criar']);

        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Titular admin',
            'email' => 'titular-admin-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();

        $root = $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, "
            . 'limite_titulares, permissoes_unidade_json) VALUES (:plan, \'cliente\', :name, :slug, \'ativo\', 3, :permissions)'
        );
        $root->execute([
            'plan' => $planId,
            'name' => 'Titular Sede',
            'slug' => 'titular-' . $suffix,
            'permissions' => json_encode(['titular.criar'], JSON_THROW_ON_ERROR),
        ]);
        $tenantId = (int) $pdo->lastInsertId();

        $owner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $owner->execute([
            'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Titular owner',
            'email' => 'titular-owner-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $ownerId = (int) $pdo->lastInsertId();

        $makeChild = static function (string $slug, ?int $limit) use ($pdo, $tenantId): int {
            $statement = $pdo->prepare(
                "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_titulares) "
                . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', :limit)"
            );
            $statement->execute(['parent' => $tenantId, 'name' => 'Titular ' . $slug, 'slug' => $slug, 'limit' => $limit]);
            return (int) $pdo->lastInsertId();
        };
        $fixedChildId = $makeChild('titular-fixed-' . $suffix, 2);
        $poolChildId = $makeChild('titular-pool-' . $suffix, null);
        $childIds = [$fixedChildId, $poolChildId];

        $category = $pdo->prepare(
            'INSERT INTO categorias_titular (tenant_id, nome_categoria, ativo) VALUES (:tenant, :name, 1)'
        );
        $category->execute(['tenant' => $tenantId, 'name' => 'Titular category ' . $suffix]);
        $categoryId = (int) $pdo->lastInsertId();

        $foreignRoot = $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES ('cliente', :name, :slug, 'ativo')"
        );
        $foreignRoot->execute(['name' => 'Foreign titular Sede', 'slug' => 'foreign-titular-' . $suffix]);
        $foreignTenantId = (int) $pdo->lastInsertId();
        $foreignChild = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
        );
        $foreignChild->execute([
            'parent' => $foreignTenantId, 'name' => 'Foreign titular unit', 'slug' => 'foreign-unit-' . $suffix,
        ]);
        $foreignChildId = (int) $pdo->lastInsertId();
        $foreignCategory = $pdo->prepare(
            'INSERT INTO categorias_titular (tenant_id, nome_categoria, ativo) VALUES (:tenant, :name, 1)'
        );
        $foreignCategory->execute(['tenant' => $foreignTenantId, 'name' => 'Foreign titular category']);
        $foreignCategoryId = (int) $pdo->lastInsertId();

        $operator = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :child, :role, :name, :email, :password, 1)'
        );
        $operator->execute([
            'tenant' => $tenantId, 'child' => $fixedChildId, 'role' => 'responsavel_sub', 'name' => 'Unit operator',
            'email' => 'titular-operator-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $operatorId = (int) $pdo->lastInsertId();

        $ownerContext = new TenantContext('cliente', $tenantId, null, []);
        $ownerIdentity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $unitContext = new TenantContext('subcliente', $tenantId, $fixedChildId, []);
        $operatorIdentity = [
            'id' => $operatorId, 'tenant_id' => $tenantId, 'subcliente_id' => $fixedChildId, 'papel_sistema' => 'responsavel_sub',
        ];
        $adminContext = new TenantContext('admin', null, null, []);
        $adminIdentity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];

        $createdHolderId = titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $fixedChildId, [
            'nome_completo' => '  Ana da Silva  ',
            'cpf' => '529.982.247-25',
            'matricula' => 'A-001',
            'cargo_funcao' => 'Membro',
            'categoria_id' => $categoryId,
        ]);
        $created = $pdo->prepare(
            'SELECT tenant_id, subcliente_id, categoria_id, nome_completo, cpf, matricula, cargo_funcao, '
            . 'acesso_app_liberado, senha_portal_hash, token_unico_carteira, foto_url FROM titulares WHERE id = :id'
        );
        $created->execute(['id' => $createdHolderId]);
        $holder = $created->fetch(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare(
            "SELECT ator_usuario_id, acao, entidade, entidade_id, depois_json FROM auditoria_processos "
            . "WHERE tenant_id = :tenant AND acao = 'titular.created' ORDER BY id DESC LIMIT 1"
        );
        $audit->execute(['tenant' => $tenantId]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($holder) || (int) $holder['tenant_id'] !== $tenantId
            || (int) $holder['subcliente_id'] !== $fixedChildId || (int) $holder['categoria_id'] !== $categoryId
            || $holder['nome_completo'] !== 'Ana da Silva' || $holder['cpf'] !== '52998224725'
            || $holder['matricula'] !== 'A-001' || $holder['cargo_funcao'] !== 'Membro'
            || (int) $holder['acesso_app_liberado'] !== 0 || $holder['senha_portal_hash'] !== null
            || $holder['token_unico_carteira'] !== null || $holder['foto_url'] !== null
            || !is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $ownerId
            || $auditRow['entidade'] !== 'titulares' || (int) $auditRow['entidade_id'] !== $createdHolderId
            || str_contains((string) $auditRow['depois_json'], 'Ana')
            || str_contains((string) $auditRow['depois_json'], '52998224725')
            || str_contains((string) $auditRow['depois_json'], 'A-001')) {
            throw new RuntimeException($driver . ': individual holder creation must normalize data, stay offline, and audit without PII.');
        }

        $triggerName = 'titular_audit_fail';
        if (strtolower($driver) === 'sqlite') {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos "
                . "WHEN NEW.acao = 'titular.created' BEGIN SELECT RAISE(ABORT, 'test audit failure'); END"
            );
        } else {
            $pdo->exec(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos FOR EACH ROW "
                . "BEGIN IF NEW.acao = 'titular.created' THEN SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT = 'test audit failure'; END IF; END"
            );
        }
        $auditFailureRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Rolled back titular', 'cpf' => null,
            ]);
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TITULAR_CREATE_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$triggerName}");
        }
        $holderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
        $holderCount->execute(['tenant' => $tenantId]);
        if (!$auditFailureRejected || (int) $holderCount->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': audit failure must roll back holder creation.');
        }

        $operatorHolderId = titularCreate($pdo, $unitContext, $operatorIdentity, $tenantId, $fixedChildId, [
            'nome_completo' => 'Bruno unidade',
            'cpf' => '',
            'categoria_id' => null,
        ]);
        $optionalCpf = $pdo->prepare('SELECT cpf, acesso_app_liberado FROM titulares WHERE id = :id');
        $optionalCpf->execute(['id' => $operatorHolderId]);
        $optionalValues = $optionalCpf->fetch(PDO::FETCH_ASSOC);
        if (!is_array($optionalValues) || $optionalValues['cpf'] !== null || (int) $optionalValues['acesso_app_liberado'] !== 0) {
            throw new RuntimeException($driver . ': an absent CPF must remain NULL and portal access must stay disabled.');
        }

        $unitQuotaRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $fixedChildId, [
                'nome_completo' => 'Unit quota overflow', 'cpf' => null,
            ]);
        } catch (DomainException $exception) {
            $unitQuotaRejected = $exception->getMessage() === 'TITULAR_CREATE_QUOTA_EXCEEDED';
        }
        if (!$unitQuotaRejected) {
            throw new RuntimeException($driver . ': the fixed unit holder allocation must be enforced.');
        }

        $invalidCpfRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Invalid CPF', 'cpf' => '111.111.111-11',
            ]);
        } catch (DomainException $exception) {
            $invalidCpfRejected = $exception->getMessage() === 'TITULAR_CREATE_INVALID';
        }
        if (!$invalidCpfRejected) {
            throw new RuntimeException($driver . ': invalid CPF check digits must be rejected.');
        }

        $duplicateCpfRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Duplicate CPF', 'cpf' => '52998224725',
            ]);
        } catch (DomainException $exception) {
            $duplicateCpfRejected = $exception->getMessage() === 'TITULAR_CREATE_CPF_CONFLICT';
        }
        if (!$duplicateCpfRejected) {
            throw new RuntimeException($driver . ': CPF uniqueness must apply across all units in one Sede.');
        }

        $crossUnitDenied = false;
        try {
            titularCreate($pdo, $unitContext, $operatorIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Cross unit', 'cpf' => null,
            ]);
        } catch (DomainException $exception) {
            $crossUnitDenied = $exception->getMessage() === 'TITULAR_CREATE_DENIED';
        }
        if (!$crossUnitDenied) {
            throw new RuntimeException($driver . ': a unit operator must not create holders for another unit.');
        }

        $crossCategoryRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Foreign category', 'categoria_id' => $foreignCategoryId,
            ]);
        } catch (DomainException $exception) {
            $crossCategoryRejected = $exception->getMessage() === 'TITULAR_CREATE_TARGET_INVALID';
        }
        if (!$crossCategoryRejected) {
            throw new RuntimeException($driver . ': a category belonging to another tenant must be rejected.');
        }

        $adminHolderId = titularCreate($pdo, $adminContext, $adminIdentity, $tenantId, $poolChildId, [
            'nome_completo' => 'Carla shared pool', 'cpf' => null,
        ]);
        $rootQuotaRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Root quota overflow',
            ]);
        } catch (DomainException $exception) {
            $rootQuotaRejected = $exception->getMessage() === 'TITULAR_CREATE_QUOTA_EXCEEDED';
        }
        if ($adminHolderId < 1 || !$rootQuotaRejected) {
            throw new RuntimeException($driver . ': root quota must cover all active holders, including shared-pool units.');
        }

        $wrongTenantUnitRejected = false;
        try {
            titularCreate($pdo, $ownerContext, $ownerIdentity, $tenantId, $foreignChildId, [
                'nome_completo' => 'Cross tenant target',
            ]);
        } catch (DomainException $exception) {
            $wrongTenantUnitRejected = $exception->getMessage() === 'TITULAR_CREATE_DENIED';
        }
        if (!$wrongTenantUnitRejected) {
            throw new RuntimeException($driver . ': an ID from another tenant must not be accepted as a Subcliente target.');
        }

        $pdo->prepare('UPDATE tenants SET limite_titulares = NULL WHERE id = :id')->execute(['id' => $tenantId]);
        $unconfiguredQuotaRejected = false;
        try {
            titularCreate($pdo, $adminContext, $adminIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Unconfigured quota',
            ]);
        } catch (DomainException $exception) {
            $unconfiguredQuotaRejected = $exception->getMessage() === 'TITULAR_CREATE_QUOTA_UNCONFIGURED';
        }
        $pdo->prepare('UPDATE tenants SET limite_titulares = 3 WHERE id = :id')->execute(['id' => $tenantId]);
        if (!$unconfiguredQuotaRejected) {
            throw new RuntimeException($driver . ': an unset root holder quota must fail closed.');
        }

        $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $tenantId]);
        $inactiveRootRejected = false;
        try {
            titularCreate($pdo, $adminContext, $adminIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Suspended root',
            ]);
        } catch (DomainException $exception) {
            $inactiveRootRejected = $exception->getMessage() === 'TITULAR_CREATE_ROOT_INACTIVE';
        }
        $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $tenantId]);
        if (!$inactiveRootRejected) {
            throw new RuntimeException($driver . ': a suspended Sede must block global admin titular creation.');
        }

        $pdo->prepare('UPDATE planos SET ativo = 0 WHERE id = :id')->execute(['id' => $planId]);
        $inactivePlanRejected = false;
        try {
            titularCreate($pdo, $adminContext, $adminIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Inactive plan',
            ]);
        } catch (DomainException $exception) {
            $inactivePlanRejected = $exception->getMessage() === 'TITULAR_CREATE_PLAN_UNAVAILABLE';
        }
        $pdo->prepare('UPDATE planos SET ativo = 1 WHERE id = :id')->execute(['id' => $planId]);
        if (!$inactivePlanRejected) {
            throw new RuntimeException($driver . ': an inactive plan must block global admin titular creation.');
        }

        $pdo->prepare('UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :key')
            ->execute(['plan' => $planId, 'key' => 'titular.criar']);
        $featureRejected = false;
        try {
            titularCreate($pdo, $adminContext, $adminIdentity, $tenantId, $poolChildId, [
                'nome_completo' => 'Feature disabled',
            ]);
        } catch (DomainException $exception) {
            $featureRejected = $exception->getMessage() === 'TITULAR_CREATE_PLAN_UNAVAILABLE';
        }
        if (!$featureRejected) {
            throw new RuntimeException($driver . ': a disabled titular feature must also bind the global admin.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' individual titular creation, RBAC, privacy and quota.' . "\n");
    } finally {
        if ($tenantId !== null) {
            $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
            $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :id')->execute(['id' => $tenantId]);
            if ($operatorId !== null) {
                $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $operatorId]);
            }
            $pdo->prepare('DELETE FROM categorias_titular WHERE tenant_id = :id')->execute(['id' => $tenantId]);
        }
        tenantQuotaTestCleanup($pdo, $tenantId, $childIds, $ownerId, $planId, $adminId);
        if ($foreignCategoryId !== null) {
            $pdo->prepare('DELETE FROM categorias_titular WHERE id = :id')->execute(['id' => $foreignCategoryId]);
        }
        if ($foreignChildId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignChildId]);
        }
        if ($foreignTenantId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignTenantId]);
        }
    }
}

return 'pass';
