<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_grants.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $suffix = bin2hex(random_bytes(5));
    $admin = $pdo->prepare(
        "INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES (NULL, NULL, 'superadmin', 'MCP Grant Admin', :email, :password, 1)"
    );
    $admin->execute([
        'email' => 'mcp-grant-' . $suffix . '@example.test',
        'password' => password_hash('mcp-grant-password', PASSWORD_DEFAULT),
    ]);
    $identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
    $laterUnitId = null;
    $auditTrigger = null;

    try {
        $created = mcpSupportGrantCreate($pdo, $identity, [
            'label' => 'Diagnóstico Sede',
            'duration' => '1h',
            'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
            'capabilities' => ['system.read', 'telemetry.read'],
        ]);

        $grant = $pdo->prepare(
            'SELECT segredo_hash, rotulo, capacidades_json, expira_em, revogado_em FROM mcp_support_grants WHERE id = :id'
        );
        $grant->execute(['id' => $created['id']]);
        $stored = $grant->fetch(PDO::FETCH_ASSOC);
        $targets = $pdo->prepare(
            'SELECT tenant_id, subcliente_id FROM mcp_support_grant_targets WHERE grant_id = :grant '
            . 'ORDER BY CASE WHEN subcliente_id IS NULL THEN 0 ELSE 1 END, subcliente_id'
        );
        $targets->execute(['grant' => $created['id']]);
        $targetRows = $targets->fetchAll(PDO::FETCH_ASSOC);
        $capabilities = is_array($stored) ? json_decode((string) $stored['capacidades_json'], true) : null;

        $laterUnit = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', 'Unidade posterior', :slug, 'ativo')"
        );
        $laterUnit->execute(['parent' => $fixture['tenant_id'], 'slug' => 'later-' . $suffix]);
        $laterUnitId = (int) $pdo->lastInsertId();
        $targets->execute(['grant' => $created['id']]);
        $frozenAfterNewUnit = $targets->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($stored) || !is_string($created['secret'] ?? null) || strlen($created['secret']) < 40
            || !password_verify($created['secret'], (string) $stored['segredo_hash'])
            || $stored['segredo_hash'] === $created['secret'] || str_contains((string) $stored['segredo_hash'], $created['secret'])
            || $stored['rotulo'] !== 'Diagnóstico Sede' || !is_array($capabilities)
            || $capabilities !== ['system.read', 'telemetry.read'] || $stored['revogado_em'] !== null
            || count($targetRows) !== 2
            || (int) $targetRows[0]['tenant_id'] !== $fixture['tenant_id']
            || $targetRows[0]['subcliente_id'] !== null
            || (int) $targetRows[1]['tenant_id'] !== $fixture['tenant_id']
            || (int) $targetRows[1]['subcliente_id'] !== $fixture['subcliente_id']
            || count($frozenAfterNewUnit) !== 2
            || array_filter($frozenAfterNewUnit, static fn(array $row): bool => (int) ($row['subcliente_id'] ?? 0) === $laterUnitId) !== []) {
            throw new RuntimeException($driver . ': support grant must hash its one-time secret and freeze the selected Sede plus its existing unit targets.');
        }

        $expires = strtotime((string) $stored['expira_em'] . ' UTC');
        if (!is_int($expires) || $expires < time() + 3500 || $expires > time() + 3700) {
            throw new RuntimeException($driver . ': support grant expiry must match its selected duration.');
        }

        $listed = mcpSupportGrantList($pdo, $identity);
        if (count($listed) !== 1 || ($listed[0]['id'] ?? null) !== $created['id']
            || ($listed[0]['state'] ?? null) !== 'active' || isset($listed[0]['secret'])
            || isset($listed[0]['segredo_hash']) || isset($listed[0]['public_id'])) {
            throw new RuntimeException($driver . ': support grant listing must expose safe metadata only.');
        }
        if (mcpSupportGrantRevoke($pdo, $identity, $created['id']) !== true
            || mcpSupportGrantRevoke($pdo, $identity, $created['id']) !== false) {
            throw new RuntimeException($driver . ': grant revocation must immediately change state and be idempotent.');
        }
        $revoked = mcpSupportGrantList($pdo, $identity);
        if (count($revoked) !== 1 || ($revoked[0]['state'] ?? null) !== 'revoked') {
            throw new RuntimeException($driver . ': revoked support grant must be visible as revoked.');
        }

        try {
            mcpSupportGrantCreate($pdo, ['id' => $identity['id'], 'papel_sistema' => 'titular_cliente'], [
                'label' => 'Grant negado', 'duration' => '1h',
                'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
                'capabilities' => ['system.read'],
            ]);
            throw new RuntimeException($driver . ': tenant identity must not create superadmin support grants.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_SUPPORT_GRANT_DENIED') {
                throw $exception;
            }
        }

        try {
            mcpSupportGrantCreate($pdo, $identity, [
                'label' => 'Capability inválida', 'duration' => '1h',
                'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
                'capabilities' => ['sql.execute'],
            ]);
            throw new RuntimeException($driver . ': arbitrary SQL capability must never be grantable.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_SUPPORT_GRANT_INVALID') {
                throw $exception;
            }
        }
        try {
            mcpSupportGrantCreate($pdo, $identity, [
                'label' => 'Acesso para pessoa@example.test', 'duration' => '30m',
                'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
                'capabilities' => ['system.read'],
            ]);
            throw new RuntimeException($driver . ': grant labels must reject structured personal identifiers.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_SUPPORT_GRANT_INVALID') throw $exception;
        }

        if ($driver === 'SQLite') {
            $auditTrigger = 'ieprcard_fail_support_grant_audit_' . substr($suffix, 0, 8);
            $pdo->exec(
                'CREATE TRIGGER ' . $auditTrigger . ' BEFORE INSERT ON mcp_support_audit_logs '
                . "WHEN NEW.tool = 'grant.create' BEGIN SELECT RAISE(ABORT, 'audit failure'); END"
            );
            $before = (int) $pdo->query('SELECT COUNT(*) FROM mcp_support_grants')->fetchColumn();
            try {
                mcpSupportGrantCreate($pdo, $identity, [
                    'label' => 'Falha de auditoria', 'duration' => '30m',
                    'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
                    'capabilities' => ['system.read'],
                ]);
                throw new RuntimeException('Grant creation must fail when its audit row cannot be persisted.');
            } catch (PDOException) {
            }
            $after = (int) $pdo->query('SELECT COUNT(*) FROM mcp_support_grants')->fetchColumn();
            if ($after !== $before) {
                throw new RuntimeException('Failed support grant audit must roll back the grant and frozen targets.');
            }
        }
    } finally {
        if ($auditTrigger !== null) {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . $auditTrigger);
        }
        if (isset($created['id'])) {
            $pdo->prepare('DELETE FROM mcp_support_grants WHERE id = :id')->execute(['id' => $created['id']]);
        }
        if ($laterUnitId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $laterUnitId]);
        }
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $identity['id']]);
        tenantTestCleanup($pdo, $fixture);
    }
}

fwrite(STDOUT, "PASS: superadmin support grant stores a one-time secret hash and freezes a Sede's current units.\n");
