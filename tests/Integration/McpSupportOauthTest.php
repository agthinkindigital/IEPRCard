<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_grants.php';
require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';
require_once dirname(__DIR__, 2) . '/config/mcp_server.php';
require_once dirname(__DIR__, 2) . '/config/mcp_server_support.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $email = 'mcp-support-oauth-' . bin2hex(random_bytes(5)) . '@example.test';
    $pdo->prepare(
        "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES ('superadmin', 'MCP Support Admin', :email, :password, 1)"
    )->execute(['email' => $email, 'password' => password_hash('synthetic-password', PASSWORD_DEFAULT)]);
    $identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
    $grant = null;
    $laterUnitId = null;
    $rateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-support-oauth-' . bin2hex(random_bytes(8));
    $configuration = [
        'MCP_PUBLIC_BASE_URL' => 'https://mcp.example.test',
        'MCP_OAUTH_SIGNING_KEY' => base64_encode(str_repeat("\x62", 32)),
        'APP_ENV' => 'production',
    ];

    try {
        $grant = mcpSupportGrantCreate($pdo, $identity, [
            'label' => 'Suporte Sede', 'duration' => '1h',
            'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
            'capabilities' => ['system.read', 'telemetry.read'],
        ]);
        $token = mcpOauthIssueClientCredentialsToken($pdo, [
            'grant_type' => 'client_credentials',
            'resource' => 'https://mcp.example.test/mcp',
            'scope' => 'system.read telemetry.read',
            '_basic_credentials' => ['client_id' => $grant['public_id'], 'client_secret' => $grant['secret']],
        ], $configuration, $rateDirectory);
        $parts = explode('.', (string) ($token['access_token'] ?? ''));
        $claims = count($parts) === 3 ? json_decode((string) mcpServerBase64UrlDecode($parts[1]), true) : null;
        if (($token['token_type'] ?? null) !== 'Bearer' || ($token['expires_in'] ?? null) !== 300
            || !is_array($claims) || ($claims['support_grant_id'] ?? null) !== $grant['id']
            || isset($claims['tenant_id']) || ($claims['scope'] ?? null) !== 'system.read telemetry.read') {
            throw new RuntimeException($driver . ': support grant must exchange into a short-lived OAuth token without a single-tenant claim.');
        }

        $laterUnit = $pdo->prepare(
            "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
            . "VALUES (:parent, 'subcliente', 'Unidade pós-grant', :slug, 'ativo')"
        );
        $laterUnit->execute(['parent' => $fixture['tenant_id'], 'slug' => 'post-grant-' . bin2hex(random_bytes(4))]);
        $laterUnitId = (int) $pdo->lastInsertId();
        $principal = mcpServerAuthenticate($pdo, 'Bearer ' . $token['access_token'], $configuration);
        $targetIds = array_map(static fn(array $target): ?int => $target['subcliente_id'], $principal['targets']);
        if (($principal['kind'] ?? null) !== 'support' || ($principal['grant_id'] ?? null) !== $grant['id']
            || !in_array($fixture['subcliente_id'], $targetIds, true) || in_array($laterUnitId, $targetIds, true)) {
            throw new RuntimeException($driver . ': MCP authentication must attach the grant’s immutable target snapshot.');
        }
        $telemetry = mcpToolCall('telemetry.read', [
            'from' => '2026-11-22', 'to' => '2026-11-24', 'tenant_id' => $fixture['tenant_id'],
        ], $principal['scopes'], $pdo, $principal);
        if (!isset($telemetry['result']['structuredContent']['rows']) || !is_array($telemetry['result']['structuredContent']['rows'])) {
            throw new RuntimeException($driver . ': support telemetry must return only an explicitly authorized target.');
        }
        $outOfScope = mcpToolCall('telemetry.read', [
            'from' => '2026-11-22', 'to' => '2026-11-24', 'tenant_id' => $fixture['tenant_id'] + 100000,
        ], $principal['scopes'], $pdo, $principal);
        if (!isset($outOfScope['error'])) {
            throw new RuntimeException($driver . ': support telemetry must reject targets outside the frozen grant.');
        }

        try {
            mcpOauthIssueClientCredentialsToken($pdo, [
                'grant_type' => 'client_credentials', 'resource' => 'https://mcp.example.test/mcp',
                'scope' => 'system.read',
                '_basic_credentials' => ['client_id' => $grant['public_id'], 'client_secret' => 'wrong-secret'],
            ], $configuration, $rateDirectory);
            throw new RuntimeException($driver . ': invalid support grant secret must be rejected.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_OAUTH_INVALID_CLIENT') throw $exception;
        }

        mcpSupportGrantRevoke($pdo, $identity, $grant['id']);
        try {
            mcpServerAuthenticate($pdo, 'Bearer ' . $token['access_token'], $configuration);
            throw new RuntimeException($driver . ': revoking a grant must invalidate already-issued short OAuth tokens immediately.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_AUTH_INVALID') throw $exception;
        }
        try {
            mcpOauthIssueClientCredentialsToken($pdo, [
                'grant_type' => 'client_credentials', 'resource' => 'https://mcp.example.test/mcp',
                'scope' => 'system.read',
                '_basic_credentials' => ['client_id' => $grant['public_id'], 'client_secret' => $grant['secret']],
            ], $configuration, $rateDirectory);
            throw new RuntimeException($driver . ': revoked support grant must not issue OAuth tokens.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_OAUTH_INVALID_CLIENT') throw $exception;
        }
    } finally {
        if (is_array($grant)) {
            $pdo->prepare('DELETE FROM mcp_support_grants WHERE id = :id')->execute(['id' => $grant['id']]);
        }
        if ($laterUnitId !== null) {
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $laterUnitId]);
        }
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $identity['id']]);
        tenantTestCleanup($pdo, $fixture);
        foreach (glob($rateDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        if (is_dir($rateDirectory)) rmdir($rateDirectory);
    }
}

fwrite(STDOUT, "PASS: superadmin support grants exchange into short-lived, revocable OAuth tokens.\n");
