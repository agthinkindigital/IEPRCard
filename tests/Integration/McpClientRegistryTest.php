<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/mcp_client_registry.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$legacy = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaAplicarCatalogoExpandOnly($legacy, \IEPRCard\Database\Schema\catalogoVersao('0001'));
$legacyTenant = $legacy->prepare("INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'Legacy MCP', 'ativo')");
$legacyTenant->execute();
$legacyTenantId = (int) $legacy->lastInsertId();
$legacyKey = $legacy->prepare(
    'INSERT INTO mcp_api_keys (tenant_id, nome, chave_hash, escopos_json, ativo) '
    . 'VALUES (:tenant, :name, :hash, :scopes, 1)'
);
$legacyKey->execute([
    'tenant' => $legacyTenantId,
    'name' => 'Legacy token row',
    'hash' => password_hash('legacy-mcp-secret', PASSWORD_DEFAULT),
    'scopes' => '["system.read"]',
]);
$legacyKeyId = (int) $legacy->lastInsertId();
schemaAplicarMigracoes($legacy, 'test');
$legacyRow = $legacy->prepare('SELECT tenant_id, client_id, nome FROM mcp_api_keys WHERE id = :id');
$legacyRow->execute(['id' => $legacyKeyId]);
$legacyRowValue = $legacyRow->fetch(PDO::FETCH_ASSOC);
if (!is_array($legacyRowValue) || (int) $legacyRowValue['tenant_id'] !== $legacyTenantId
    || $legacyRowValue['client_id'] !== null || $legacyRowValue['nome'] !== 'Legacy token row') {
    throw new RuntimeException('MCP client registry migration must preserve existing credential rows.');
}

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $suffix = bin2hex(random_bytes(5));
    $adminId = null;
    try {
        $admin = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, :role, :name, :email, :password, 1)'
        );
        $admin->execute([
            'role' => 'superadmin', 'name' => 'MCP registry admin',
            'email' => 'mcp-admin-' . $suffix . '@example.test',
            'password' => password_hash('mcp-admin-password', PASSWORD_DEFAULT),
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $context = new TenantContext('admin', null, null, []);
        $identity = ['id' => $adminId, 'tenant_id' => null, 'subcliente_id' => null, 'papel_sistema' => 'superadmin'];

        $created = mcpClientCreate($pdo, $context, $identity, $fixture['tenant_id'], [
            'nome' => 'Agente leitura',
            'escopos' => ['system.read', 'telemetry.read'],
            'limite_requisicoes_minuto' => 30,
            'expira_em' => null,
        ]);
        $client = $pdo->prepare('SELECT tenant_id, usuario_id, nome, client_id, chave_hash, escopos_json, limite_requisicoes_minuto, ativo FROM mcp_api_keys WHERE id = :id');
        $client->execute(['id' => $created['id']]);
        $clientRow = $client->fetch(PDO::FETCH_ASSOC);
        $audit = $pdo->prepare('SELECT ator_usuario_id, ator_tipo, acao, entidade_id, depois_json FROM auditoria_processos WHERE tenant_id = :tenant AND acao = :action AND entidade_id = :id');
        $audit->execute(['tenant' => $fixture['tenant_id'], 'action' => 'mcp.client_created', 'id' => $created['id']]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($clientRow) || $created['id'] < 1 || $created['tenant_id'] !== $fixture['tenant_id']
            || $created['client_id'] !== $clientRow['client_id'] || strlen($created['client_secret']) < 64
            || (int) $clientRow['tenant_id'] !== $fixture['tenant_id'] || (int) $clientRow['usuario_id'] !== $adminId
            || !password_verify($created['client_secret'], (string) $clientRow['chave_hash'])
            || $clientRow['chave_hash'] === $created['client_secret']
            || json_decode((string) $clientRow['escopos_json'], true) !== ['system.read', 'telemetry.read']
            || (int) $clientRow['limite_requisicoes_minuto'] !== 30 || (int) $clientRow['ativo'] !== 1
            || !is_array($auditRow) || (int) $auditRow['ator_usuario_id'] !== $adminId
            || $auditRow['ator_tipo'] !== 'superadmin' || str_contains((string) $auditRow['depois_json'], $created['client_secret'])) {
            throw new RuntimeException($driver . ': MCP client credentials must be tenant-bound, hashed, one-time and auditable without secrets.');
        }

        $listed = mcpClientList($pdo, $context, $identity, $fixture['tenant_id']);
        if (count($listed) !== 1 || $listed[0]['client_id'] !== $created['client_id']
            || isset($listed[0]['client_secret']) || isset($listed[0]['chave_hash'])) {
            throw new RuntimeException($driver . ': client metadata listing must never reveal credentials or hashes.');
        }
        if (mcpClientRevoke($pdo, $context, $identity, $created['id']) !== true
            || mcpClientRevoke($pdo, $context, $identity, $created['id']) !== false) {
            throw new RuntimeException($driver . ': client revocation must be immediate and idempotent.');
        }
        $client->execute(['id' => $created['id']]);
        if ((int) $client->fetchColumn(7) !== 0) {
            throw new RuntimeException($driver . ': revoked OAuth client must be inactive in the database.');
        }

        $invalidCases = [
            [['escopos' => ['code.patch']], 'MCP_CLIENT_INVALID'],
            [['limite_requisicoes_minuto' => 0], 'MCP_CLIENT_INVALID'],
            [['expira_em' => '2000-01-01 00:00:00'], 'MCP_CLIENT_INVALID'],
            [['nome' => '  '], 'MCP_CLIENT_INVALID'],
        ];
        foreach ($invalidCases as [$changes, $expectedCode]) {
            $input = array_replace([
                'nome' => 'Validação MCP',
                'escopos' => ['system.read'],
                'limite_requisicoes_minuto' => 60,
                'expira_em' => null,
            ], $changes);
            try {
                mcpClientCreate($pdo, $context, $identity, $fixture['tenant_id'], $input);
                throw new RuntimeException($driver . ': invalid MCP client input was accepted.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== $expectedCode) {
                    throw $exception;
                }
            }
        }

        $childTargetDenied = false;
        try {
            mcpClientCreate($pdo, $context, $identity, $fixture['subcliente_id'], [
                'nome' => 'Não deve criar', 'escopos' => ['system.read'],
                'limite_requisicoes_minuto' => 60, 'expira_em' => null,
            ]);
        } catch (DomainException $exception) {
            $childTargetDenied = $exception->getMessage() === 'MCP_CLIENT_TARGET_INVALID';
        }
        if (!$childTargetDenied) {
            throw new RuntimeException($driver . ': MCP clients must bind only to active root tenants.');
        }

        $tenantIdentity = ['id' => $adminId, 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
        $permissionDenied = false;
        try {
            mcpClientCreate($pdo, $context, $tenantIdentity, $fixture['tenant_id'], [
                'nome' => 'Não autorizado', 'escopos' => ['system.read'],
                'limite_requisicoes_minuto' => 60, 'expira_em' => null,
            ]);
        } catch (DomainException $exception) {
            $permissionDenied = $exception->getMessage() === 'MCP_CLIENT_DENIED';
        }
        if (!$permissionDenied) {
            throw new RuntimeException($driver . ': only the global MCP-token permission may create MCP clients.');
        }

        $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
        $inactiveTenantDenied = false;
        try {
            mcpClientCreate($pdo, $context, $identity, $fixture['tenant_id'], [
                'nome' => 'Tenant inativo', 'escopos' => ['system.read'],
                'limite_requisicoes_minuto' => 60, 'expira_em' => null,
            ]);
        } catch (DomainException $exception) {
            $inactiveTenantDenied = $exception->getMessage() === 'MCP_CLIENT_TARGET_INVALID';
        }
        $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
        if (!$inactiveTenantDenied) {
            throw new RuntimeException($driver . ': suspended tenants must not receive MCP clients.');
        }

        if ($driver === 'SQLite') {
            $triggerName = 'ieprcard_fail_mcp_client_audit_' . substr($suffix, 0, 8);
            $pdo->exec(
                'CREATE TRIGGER ' . $triggerName . ' BEFORE INSERT ON auditoria_processos '
                . "WHEN NEW.acao = 'mcp.client_created' BEGIN SELECT RAISE(ABORT, 'audit failure'); END"
            );
            $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM mcp_api_keys')->fetchColumn();
            try {
                mcpClientCreate($pdo, $context, $identity, $fixture['tenant_id'], [
                    'nome' => 'Rollback MCP', 'escopos' => ['system.read'],
                    'limite_requisicoes_minuto' => 60, 'expira_em' => null,
                ]);
                throw new RuntimeException('MCP client creation must roll back when audit persistence fails.');
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'MCP_CLIENT_CREATE_FAILED') {
                    throw $exception;
                }
            } finally {
                $pdo->exec('DROP TRIGGER IF EXISTS ' . $triggerName);
            }
            $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM mcp_api_keys')->fetchColumn();
            if ($afterCount !== $beforeCount) {
                throw new RuntimeException('MCP client and audit must commit or roll back together.');
            }
        }
    } finally {
        $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        $pdo->prepare('DELETE FROM mcp_api_keys WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        if ($adminId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminId]);
        }
        tenantTestCleanup($pdo, $fixture);
    }
}

fwrite(STDOUT, "PASS: MCP OAuth clients are tenant-bound and secrets are only stored as hashes.\n");
