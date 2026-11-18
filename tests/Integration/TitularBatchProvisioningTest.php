<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once __DIR__ . '/TitularBatchProvisioningTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/titular_provisioning.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $planId = null;
    $tenantId = null;
    $childId = null;
    $ownerId = null;
    $operatorId = null;

    try {
        $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')
            ->execute(['name' => 'Titular batch plan ' . $suffix]);
        $planId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)')
            ->execute(['plan' => $planId, 'key' => 'titular.criar']);
        $pdo->prepare(
            "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_titulares) "
            . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 5)"
        )->execute(['plan' => $planId, 'name' => 'Titular batch Sede', 'slug' => 'titular-batch-' . $suffix]);
        $tenantId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_titulares) "
            . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', 3)"
        )->execute([
            'parent' => $tenantId, 'name' => 'Titular batch unit', 'slug' => 'titular-batch-unit-' . $suffix,
        ]);
        $childId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $tenantId,
            'role' => 'titular_cliente',
            'name' => 'Titular batch owner',
            'email' => 'titular-batch-owner-' . $suffix . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $ownerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :child, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $tenantId,
            'child' => $childId,
            'role' => 'responsavel_sub',
            'name' => 'Titular batch unit operator',
            'email' => 'titular-batch-operator-' . $suffix . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $operatorId = (int) $pdo->lastInsertId();

        $context = new TenantContext('cliente', $tenantId, null, []);
        $identity = [
            'id' => $ownerId,
            'tenant_id' => $tenantId,
            'subcliente_id' => null,
            'papel_sistema' => 'titular_cliente',
        ];
        $records = [
            ['nome_completo' => 'Ana batch', 'cpf' => titularBatchTestCpf(0), 'matricula' => 'B-001', 'whatsapp' => '(11) 90000-0000'],
            ['nome_completo' => 'Bruno batch', 'cpf' => titularBatchTestCpf(1), 'matricula' => 'B-002', 'whatsapp' => ''],
        ];
        $ids = titularCreateBatch($pdo, $context, $identity, $tenantId, $childId, $records);
        $holders = $pdo->prepare(
            'SELECT id, nome_completo, cpf, whatsapp, subcliente_id FROM titulares WHERE tenant_id = :tenant ORDER BY id'
        );
        $holders->execute(['tenant' => $tenantId]);
        $saved = $holders->fetchAll(PDO::FETCH_ASSOC);
        $audits = $pdo->prepare(
            "SELECT entidade_id, depois_json FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'titular.created' ORDER BY id"
        );
        $audits->execute(['tenant' => $tenantId]);
        $auditRows = $audits->fetchAll(PDO::FETCH_ASSOC);
        if (count($ids) !== 2 || count($saved) !== 2 || count($auditRows) !== 2
            || $ids !== [(int) $saved[0]['id'], (int) $saved[1]['id']]
            || $saved[0]['nome_completo'] !== 'Ana batch' || $saved[0]['cpf'] !== titularBatchTestCpf(0)
            || $saved[0]['whatsapp'] !== '+5511900000000' || $saved[1]['whatsapp'] !== null
            || (int) $saved[0]['subcliente_id'] !== $childId
            || (int) $auditRows[0]['entidade_id'] !== $ids[0]
            || str_contains((string) $auditRows[0]['depois_json'], 'Ana batch')
            || str_contains((string) $auditRows[0]['depois_json'], titularBatchTestCpf(0))) {
            throw new RuntimeException($driver . ': batch creation must preserve order, scope, and PII-free audit.');
        }

        titularBatchAssertFailure($pdo, $context, $identity, $tenantId, $childId, [], 'TITULAR_CREATE_INVALID');
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            array_fill(0, 26, ['nome_completo' => 'Over limit']),
            'TITULAR_CREATE_INVALID'
        );
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Would otherwise create'],
                ['nome_completo' => 'Invalid phone', 'whatsapp' => '(11) 80000-0000'],
            ],
            'TITULAR_CREATE_INVALID'
        );
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Duplicate one', 'cpf' => titularBatchTestCpf(5)],
                ['nome_completo' => 'Duplicate two', 'cpf' => titularBatchTestCpf(5)],
            ],
            'TITULAR_CREATE_CPF_CONFLICT'
        );
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Would otherwise create'],
                ['nome_completo' => 'Invalid CPF', 'cpf' => '111.111.111-11'],
            ],
            'TITULAR_CREATE_INVALID'
        );
        $wrongContext = new TenantContext('cliente', $tenantId + 1000, null, []);
        titularBatchAssertFailure(
            $pdo,
            $wrongContext,
            $identity,
            $tenantId,
            $childId,
            [['nome_completo' => 'Wrong scope']],
            'TITULAR_CREATE_DENIED'
        );
        $pdo->prepare('UPDATE tenants SET limite_titulares = 3 WHERE id = :tenant')->execute(['tenant' => $tenantId]);
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Root quota one'],
                ['nome_completo' => 'Root quota two'],
            ],
            'TITULAR_CREATE_QUOTA_EXCEEDED'
        );
        $pdo->prepare('UPDATE tenants SET limite_titulares = 5 WHERE id = :tenant')->execute(['tenant' => $tenantId]);
        $pdo->prepare('UPDATE tenants SET limite_titulares = 5 WHERE id = :child')->execute(['child' => $childId]);
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Existing duplicate', 'cpf' => titularBatchTestCpf(0)],
                ['nome_completo' => 'Would otherwise create'],
            ],
            'TITULAR_CREATE_CPF_CONFLICT'
        );
        $pdo->prepare('UPDATE tenants SET limite_titulares = 3 WHERE id = :child')->execute(['child' => $childId]);
        titularBatchAssertFailure(
            $pdo,
            $context,
            $identity,
            $tenantId,
            $childId,
            [
                ['nome_completo' => 'Child quota one'],
                ['nome_completo' => 'Child quota two'],
            ],
            'TITULAR_CREATE_QUOTA_EXCEEDED'
        );
        $pdo->prepare('UPDATE tenants SET limite_titulares = 5 WHERE id = :child')->execute(['child' => $childId]);

        $triggerName = 'titular_batch_audit_fail';
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
            titularCreateBatch($pdo, $context, $identity, $tenantId, $childId, [
                ['nome_completo' => 'Rollback one'],
                ['nome_completo' => 'Rollback two'],
            ]);
        } catch (RuntimeException $exception) {
            $auditFailureRejected = $exception->getMessage() === 'TITULAR_CREATE_FAILED';
        } finally {
            $pdo->exec("DROP TRIGGER {$triggerName}");
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
        $count->execute(['tenant' => $tenantId]);
        if (!$auditFailureRejected || (int) $count->fetchColumn() !== 2) {
            throw new RuntimeException($driver . ': batch audit failure must roll back every holder.');
        }

        $pdo->prepare('UPDATE tenants SET limite_titulares = 30 WHERE id IN (:tenant, :child)')
            ->execute(['tenant' => $tenantId, 'child' => $childId]);
        $maximumChunk = array_map(
            static fn(int $index): array => ['nome_completo' => 'Maximum chunk ' . $index],
            range(1, 25)
        );
        $maximumChunkIds = titularCreateBatch($pdo, $context, $identity, $tenantId, $childId, $maximumChunk);
        $count->execute(['tenant' => $tenantId]);
        if (count($maximumChunkIds) !== 25 || (int) $count->fetchColumn() !== 27) {
            throw new RuntimeException($driver . ': a 25-holder chunk must be accepted within configured quotas.');
        }
        $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')->execute([
            'permissions' => json_encode(['titular.criar'], JSON_THROW_ON_ERROR),
            'tenant' => $tenantId,
        ]);
        $unitContext = new TenantContext('subcliente', $tenantId, $childId, []);
        $operatorIdentity = [
            'id' => $operatorId,
            'tenant_id' => $tenantId,
            'subcliente_id' => $childId,
            'papel_sistema' => 'responsavel_sub',
        ];
        $operatorIds = titularCreateBatch($pdo, $unitContext, $operatorIdentity, $tenantId, $childId, [
            ['nome_completo' => 'Unit operator batch'],
        ]);
        $operatorHolder = $pdo->prepare('SELECT subcliente_id FROM titulares WHERE id = :id');
        $operatorHolder->execute(['id' => $operatorIds[0] ?? 0]);
        if (count($operatorIds) !== 1 || (int) $operatorHolder->fetchColumn() !== $childId) {
            throw new RuntimeException($driver . ': a delegated unit operator must create holders inside its own unit.');
        }

        fwrite(STDOUT, 'PASS: ' . $driver . ' atomic titular chunks enforce role scope, quota, CPF conflicts, and audit rollback.' . "\n");
    } finally {
        if ($tenantId !== null) {
            $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
            $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
        }
        if ($operatorId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :operator')->execute(['operator' => $operatorId]);
        }
        tenantQuotaTestCleanup($pdo, $tenantId, $childId === null ? [] : [$childId], $ownerId, $planId, null);
    }
}

return 'pass';
