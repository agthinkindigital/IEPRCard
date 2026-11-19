<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $slug = 'quota-' . $suffix;
    $childSlug = 'unit-' . $suffix;
    $adminId = null;
    $ownerId = null;
    $planId = null;
    $tenantId = null;
    $childId = null;

    try {
        $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
        $plan->execute(['name' => 'Quota plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
        $flag->execute(['plan' => $planId, 'key' => 'cota.distribuir']);

        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'Quota admin',
            'email' => 'quota-admin-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $adminId = (int) $pdo->lastInsertId();

        $root = $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, permissoes_unidade_json) "
            . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', '[]')"
        );
        $root->execute(['plan' => $planId, 'name' => 'Quota Sede', 'slug' => $slug]);
        $tenantId = (int) $pdo->lastInsertId();

        $owner = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        );
        $owner->execute([
            'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Quota owner',
            'email' => 'quota-owner-' . $suffix . '@example.test', 'password' => 'not-used',
        ]);
        $ownerId = (int) $pdo->lastInsertId();

        $child = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
        );
        $child->execute(['parent' => $tenantId, 'name' => 'Quota unit', 'slug' => $childSlug]);
        $childId = (int) $pdo->lastInsertId();

        $adminContext = new TenantContext('admin', null, null, []);
        $adminIdentity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];
        tenantQuotaSetRootLimits($pdo, $adminContext, $adminIdentity, $tenantId, [
            'limite_subclientes' => 5,
            'limite_usuarios_equipe' => 20,
            'limite_titulares' => 100,
            'limite_carteirinhas' => 1000,
        ]);

        $ownerContext = new TenantContext('cliente', $tenantId, null, []);
        $ownerIdentity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, [
            'limite_usuarios_equipe' => 8,
            'limite_titulares' => 30,
            'limite_carteirinhas' => 300,
        ]);

        $rootRow = $pdo->prepare(
            'SELECT limite_subclientes, limite_usuarios_equipe, limite_titulares, limite_carteirinhas '
            . 'FROM tenants WHERE id = :id'
        );
        $rootRow->execute(['id' => $tenantId]);
        $childRow = $pdo->prepare(
            'SELECT limite_usuarios_equipe, limite_titulares, limite_carteirinhas FROM tenants WHERE id = :id'
        );
        $childRow->execute(['id' => $childId]);
        $rootValues = $rootRow->fetch(PDO::FETCH_ASSOC);
        $childValues = $childRow->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rootValues) || !is_array($childValues)
            || array_map('intval', array_values($rootValues)) !== [5, 20, 100, 1000]
            || array_map('intval', array_values($childValues)) !== [8, 30, 300]) {
            throw new RuntimeException($driver . ': authorized quota configuration must persist the root ceilings and fixed unit allocations.');
        }

        tenantQuotaSetSubclientAllocation($pdo, $adminContext, $adminIdentity, $tenantId, $childId, [
            'limite_usuarios_equipe' => 7,
            'limite_titulares' => 25,
            'limite_carteirinhas' => 250,
        ]);
        $childRow->execute(['id' => $childId]);
        $adminOverride = $childRow->fetch(PDO::FETCH_ASSOC);
        $overrideAudit = $pdo->prepare(
            "SELECT ator_usuario_id, acao FROM auditoria_processos WHERE tenant_id = :tenant AND entidade_id = :entity "
            . "AND acao = 'quota.subclient_override_changed' ORDER BY id DESC LIMIT 1"
        );
        $overrideAudit->execute(['tenant' => $tenantId, 'entity' => $childId]);
        $overrideAuditRow = $overrideAudit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($adminOverride) || array_map('intval', array_values($adminOverride)) !== [7, 25, 250]
            || !is_array($overrideAuditRow) || (int) $overrideAuditRow['ator_usuario_id'] !== $adminId) {
            throw new RuntimeException($driver . ': an authorized global admin must be able to override a unit allocation with an audit record.');
        }
        tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, [
            'limite_usuarios_equipe' => 8,
            'limite_titulares' => 30,
            'limite_carteirinhas' => 300,
        ]);

        $auditCountBeforeOverflow = $pdo->prepare(
            "SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao LIKE 'quota.%'"
        );
        $auditCountBeforeOverflow->execute(['tenant' => $tenantId]);
        $auditCount = (int) $auditCountBeforeOverflow->fetchColumn();
        $overflowRejected = false;
        try {
            tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, [
                'limite_usuarios_equipe' => 8,
                'limite_titulares' => 101,
                'limite_carteirinhas' => 300,
            ]);
        } catch (DomainException $exception) {
            $overflowRejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
        }
        $childRow->execute(['id' => $childId]);
        $childAfterOverflow = $childRow->fetch(PDO::FETCH_ASSOC);
        $auditCountBeforeOverflow->execute(['tenant' => $tenantId]);
        if (!$overflowRejected || !is_array($childAfterOverflow)
            || array_map('intval', array_values($childAfterOverflow)) !== [8, 30, 300]
            || (int) $auditCountBeforeOverflow->fetchColumn() !== $auditCount) {
            throw new RuntimeException($driver . ': an allocation above the Sede ceiling must roll back without audit side effects.');
        }

        $rootReductionRejected = false;
        try {
            tenantQuotaSetRootLimits($pdo, $adminContext, $adminIdentity, $tenantId, [
                'limite_subclientes' => 5,
                'limite_usuarios_equipe' => 20,
                'limite_titulares' => 29,
                'limite_carteirinhas' => 1000,
            ]);
        } catch (DomainException $exception) {
            $rootReductionRejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
        }
        $rootRow->execute(['id' => $tenantId]);
        $rootAfterReduction = $rootRow->fetch(PDO::FETCH_ASSOC);
        if (!$rootReductionRejected || !is_array($rootAfterReduction)
            || array_map('intval', array_values($rootAfterReduction)) !== [5, 20, 100, 1000]) {
            throw new RuntimeException($driver . ': root limits must not fall below existing child allocations.');
        }

        $rootChildCountRejected = false;
        try {
            tenantQuotaSetRootLimits($pdo, $adminContext, $adminIdentity, $tenantId, [
                'limite_subclientes' => 0,
                'limite_usuarios_equipe' => 20,
                'limite_titulares' => 100,
                'limite_carteirinhas' => 1000,
            ]);
        } catch (DomainException $exception) {
            $rootChildCountRejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
        }
        if (!$rootChildCountRejected) {
            throw new RuntimeException($driver . ': root subclient ceilings must not fall below the current unit count.');
        }

        $invalidRootLimitRejected = false;
        try {
            tenantQuotaSetRootLimits($pdo, $adminContext, $adminIdentity, $tenantId, [
                'limite_subclientes' => 5,
                'limite_usuarios_equipe' => 20,
                'limite_titulares' => -1,
                'limite_carteirinhas' => 1000,
            ]);
        } catch (DomainException $exception) {
            $invalidRootLimitRejected = $exception->getMessage() === 'TENANT_QUOTA_INVALID';
        }
        if (!$invalidRootLimitRejected) {
            throw new RuntimeException($driver . ': negative quota values must be rejected before any write.');
        }
        foreach ([true, 1.0, '01', '2147483648'] as $invalidInteger) {
            $invalidTypeRejected = false;
            try {
                tenantQuotaSetRootLimits($pdo, $adminContext, $adminIdentity, $tenantId, [
                    'limite_subclientes' => 5,
                    'limite_usuarios_equipe' => 20,
                    'limite_titulares' => $invalidInteger,
                    'limite_carteirinhas' => 1000,
                ]);
            } catch (DomainException $exception) {
                $invalidTypeRejected = $exception->getMessage() === 'TENANT_QUOTA_INVALID';
            }
            if (!$invalidTypeRejected) {
                throw new RuntimeException($driver . ': non-canonical quota integer values must be rejected.');
            }
        }

        $forgedIdentityRejected = false;
        try {
            tenantQuotaSetRootLimits($pdo, $ownerContext, $ownerIdentity, $tenantId, [
                'limite_subclientes' => 5,
                'limite_usuarios_equipe' => 20,
                'limite_titulares' => 100,
                'limite_carteirinhas' => 1000,
            ]);
        } catch (DomainException $exception) {
            $forgedIdentityRejected = $exception->getMessage() === 'TENANT_QUOTA_DENIED';
        }
        if (!$forgedIdentityRejected) {
            throw new RuntimeException($driver . ': tenant identities must not change global Sede ceilings.');
        }

        $normalizedPool = [
            'limite_usuarios_equipe' => null,
            'limite_titulares' => null,
            'limite_carteirinhas' => null,
        ];
        tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, $normalizedPool);
        $childRow->execute(['id' => $childId]);
        $childAfterPool = $childRow->fetch(PDO::FETCH_ASSOC);
        if (!is_array($childAfterPool) || array_values($childAfterPool) !== [null, null, null]) {
            throw new RuntimeException($driver . ': NULL allocations must preserve shared-pool semantics.');
        }

        $childConsumption = $pdo->prepare('UPDATE tenants SET consumo_carteirinhas = :consumption WHERE id = :id');
        $childConsumption->execute(['consumption' => 12, 'id' => $childId]);
        $reductionBelowUsageRejected = false;
        try {
            tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, [
                'limite_usuarios_equipe' => null,
                'limite_titulares' => null,
                'limite_carteirinhas' => 11,
            ]);
        } catch (DomainException $exception) {
            $reductionBelowUsageRejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
        }
        $childRow->execute(['id' => $childId]);
        $childAfterUsageReduction = $childRow->fetch(PDO::FETCH_ASSOC);
        if (!$reductionBelowUsageRejected || !is_array($childAfterUsageReduction)
            || array_values($childAfterUsageReduction) !== [null, null, null]) {
            throw new RuntimeException($driver . ': a fixed allocation cannot be reduced below current usage.');
        }
        $childConsumption->execute(['consumption' => 0, 'id' => $childId]);

        $allocationAudit = $pdo->prepare(
            "SELECT subcliente_id, ator_usuario_id, ator_tipo, antes_json, depois_json FROM auditoria_processos "
            . "WHERE tenant_id = :tenant AND entidade_id = :entity AND acao = 'quota.subclient_allocation_changed' "
            . 'ORDER BY id DESC LIMIT 1'
        );
        $allocationAudit->execute(['tenant' => $tenantId, 'entity' => $childId]);
        $auditRow = $allocationAudit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($auditRow) || (int) $auditRow['subcliente_id'] !== $childId
            || (int) $auditRow['ator_usuario_id'] !== $ownerId
            || $auditRow['ator_tipo'] !== 'titular_cliente'
            || json_decode((string) $auditRow['antes_json'], true) !== [
                'limite_usuarios_equipe' => 8, 'limite_titulares' => 30, 'limite_carteirinhas' => 300,
            ]
            || json_decode((string) $auditRow['depois_json'], true) !== $normalizedPool
            || str_contains((string) $auditRow['antes_json'], '@example.test')
            || str_contains((string) $auditRow['depois_json'], '@example.test')) {
            throw new RuntimeException($driver . ': quota audit must record only actor and before/after limits.');
        }

        $triggerName = 'ieprcard_fail_quota_audit_' . substr($suffix, 0, 8);
        if ($driver === 'SQLite') {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos WHEN NEW.acao = 'quota.subclient_allocation_changed' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        } else {
            $pdo->exec("CREATE TRIGGER {$triggerName} BEFORE INSERT ON auditoria_processos FOR EACH ROW BEGIN IF NEW.acao = 'quota.subclient_allocation_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'; END IF; END");
        }
        $auditFailureRejected = false;
        try {
            tenantQuotaSetSubclientAllocation($pdo, $ownerContext, $ownerIdentity, $tenantId, $childId, [
                'limite_usuarios_equipe' => 8,
                'limite_titulares' => 30,
                'limite_carteirinhas' => 300,
            ]);
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TENANT_QUOTA_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
        }
        $childRow->execute(['id' => $childId]);
        $childAfterAuditFailure = $childRow->fetch(PDO::FETCH_ASSOC);
        if (!$auditFailureRejected || !is_array($childAfterAuditFailure)
            || array_values($childAfterAuditFailure) !== [null, null, null]) {
            throw new RuntimeException($driver . ': quota updates must roll back when the audit write fails.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' authorized root quota configuration and unit allocation.' . "\n");
    } finally {
        tenantQuotaTestCleanup($pdo, $tenantId, [$childId], $ownerId, $planId, $adminId);
    }
}

return 'pass';
