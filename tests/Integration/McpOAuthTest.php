<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$clientId = 'mcp_' . bin2hex(random_bytes(20));
$clientSecret = 'mcpsec_' . bin2hex(random_bytes(32));
$insert = $pdo->prepare(
    'INSERT INTO mcp_api_keys (tenant_id, nome, client_id, chave_hash, escopos_json, limite_requisicoes_minuto, ativo) '
    . 'VALUES (:tenant, :name, :client, :hash, :scopes, :rate, 1)'
);
$insert->execute([
    'tenant' => $fixture['tenant_id'], 'name' => 'OAuth integration', 'client' => $clientId,
    'hash' => password_hash($clientSecret, PASSWORD_DEFAULT),
    'scopes' => '["system.read","telemetry.read"]', 'rate' => 4,
]);
$rateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-oauth-test-' . bin2hex(random_bytes(5));
$configuration = [
    'APP_ENV' => 'test',
    'MCP_PUBLIC_BASE_URL' => 'https://mcp.example.test',
    'MCP_OAUTH_SIGNING_KEY' => base64_encode(random_bytes(32)),
];
$credentials = mcpOauthReadBasicCredentials('Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret)));
if ($credentials === null || $credentials['client_id'] !== $clientId || $credentials['client_secret'] !== $clientSecret
    || mcpOauthReadBasicCredentials('Bearer token') !== null) {
    throw new RuntimeException('MCP OAuth must parse only Basic client credentials.');
}
$tokenResponse = mcpOauthIssueClientCredentialsToken($pdo, [
    'grant_type' => 'client_credentials',
    'resource' => 'https://mcp.example.test/mcp',
    'scope' => 'system.read telemetry.read',
    '_basic_credentials' => $credentials,
], $configuration, $rateDirectory);
$parts = explode('.', $tokenResponse['access_token']);
$payload = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true) : null;
$expectedSignature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], base64_decode($configuration['MCP_OAUTH_SIGNING_KEY'], true), true);
$signature = base64_decode(strtr($parts[2], '-_', '+/') . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4));
if ($tokenResponse['token_type'] !== 'Bearer' || $tokenResponse['expires_in'] !== 300
    || !is_array($payload) || $payload['iss'] !== 'https://mcp.example.test'
    || $payload['aud'] !== 'https://mcp.example.test/mcp' || $payload['sub'] !== $clientId
    || $payload['tenant_id'] !== $fixture['tenant_id'] || $payload['scope'] !== 'system.read telemetry.read'
    || !hash_equals($expectedSignature, $signature)) {
    throw new RuntimeException('MCP OAuth token must be signed, short-lived and bound to client, tenant, scope and resource.');
}

$metadata = mcpOauthProtectedResourceMetadata($configuration);
$authorizationMetadata = mcpOauthAuthorizationServerMetadata($configuration);
if ($metadata['resource'] !== 'https://mcp.example.test/mcp'
    || $metadata['authorization_servers'] !== ['https://mcp.example.test']
    || $authorizationMetadata['issuer'] !== 'https://mcp.example.test'
    || $authorizationMetadata['token_endpoint'] !== 'https://mcp.example.test/oauth/token'
    || $authorizationMetadata['token_endpoint_auth_methods_supported'] !== ['client_secret_basic']) {
    throw new RuntimeException('MCP OAuth metadata must expose canonical configured URLs.');
}
foreach ([
    static fn() => mcpOauthSigningKey(['MCP_OAUTH_SIGNING_KEY' => base64_encode(random_bytes(16))]),
    static fn() => mcpOauthSigningKey(['MCP_OAUTH_SIGNING_KEY' => '']),
    static fn() => mcpOauthPublicBaseUrl(['APP_ENV' => 'production', 'MCP_PUBLIC_BASE_URL' => 'http://mcp.example.test']),
] as $unsafeConfiguration) {
    try {
        $unsafeConfiguration();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'MCP_OAUTH_CONFIGURATION_INVALID') {
            continue;
        }
        throw $exception;
    }
    throw new RuntimeException('MCP OAuth must reject missing or weak signing keys and non-HTTPS production URLs.');
}

$rejected = static function (array $input, string $expectedCode) use ($pdo, $configuration, $rateDirectory): void {
    try {
        mcpOauthIssueClientCredentialsToken($pdo, $input, $configuration, $rateDirectory);
    } catch (DomainException $exception) {
        if ($exception->getMessage() === $expectedCode) {
            return;
        }
        throw $exception;
    }
    throw new RuntimeException('Invalid MCP OAuth request should have been rejected: ' . $expectedCode);
};
$baseInput = ['grant_type' => 'client_credentials', 'resource' => 'https://mcp.example.test/mcp', 'scope' => 'system.read', '_basic_credentials' => $credentials];
$rejected([...$baseInput, 'resource' => 'https://attacker.example/mcp'], 'MCP_OAUTH_INVALID_TARGET');
$rejected([...$baseInput, 'scope' => 'system.read admin.write'], 'MCP_OAUTH_INVALID_SCOPE');
$rejected([...$baseInput, 'grant_type' => 'password'], 'MCP_OAUTH_INVALID_REQUEST');
$rejected([...$baseInput, 'client_secret' => $clientSecret], 'MCP_OAUTH_INVALID_REQUEST');
$rejected([...$baseInput, '_basic_credentials' => ['client_id' => $clientId, 'client_secret' => 'wrong']], 'MCP_OAUTH_INVALID_CLIENT');
$retenant = $pdo->prepare('UPDATE mcp_api_keys SET tenant_id = :tenant WHERE client_id = :client');
$retenant->execute(['tenant' => $fixture['subcliente_id'], 'client' => $clientId]);
$rejected($baseInput, 'MCP_OAUTH_INVALID_CLIENT');
$retenant->execute(['tenant' => $fixture['tenant_id'], 'client' => $clientId]);
$tamperScopes = $pdo->prepare('UPDATE mcp_api_keys SET escopos_json = :scopes WHERE client_id = :client');
$tamperScopes->execute(['scopes' => '["code.patch"]', 'client' => $clientId]);
$rejected([...$baseInput, 'scope' => 'code.patch'], 'MCP_OAUTH_INVALID_SCOPE');
$tamperScopes->execute(['scopes' => '["system.read","telemetry.read"]', 'client' => $clientId]);

$expire = $pdo->prepare('UPDATE mcp_api_keys SET expira_em = :expires WHERE client_id = :client');
$expire->execute(['expires' => gmdate('Y-m-d H:i:s', time() - 60), 'client' => $clientId]);
$rejected($baseInput, 'MCP_OAUTH_INVALID_CLIENT');
$expire->execute(['expires' => null, 'client' => $clientId]);
$suspend = $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :tenant");
$suspend->execute(['tenant' => $fixture['tenant_id']]);
$rejected($baseInput, 'MCP_OAUTH_INVALID_CLIENT');
$restore = $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :tenant");
$restore->execute(['tenant' => $fixture['tenant_id']]);

$pdo->prepare('UPDATE mcp_api_keys SET limite_requisicoes_minuto = 1 WHERE client_id = :client')
    ->execute(['client' => $clientId]);
$limited = false;
for ($attempt = 0; $attempt < 5; ++$attempt) {
    try {
        mcpOauthIssueClientCredentialsToken($pdo, $baseInput, $configuration, $rateDirectory);
    } catch (DomainException $exception) {
        if ($exception->getMessage() === 'MCP_OAUTH_RATE_LIMITED') {
            $limited = true;
            break;
        }
        throw $exception;
    }
}
if (!$limited) {
    throw new RuntimeException('MCP OAuth must enforce the configured per-client issuance limit.');
}

$revoke = $pdo->prepare('UPDATE mcp_api_keys SET ativo = 0 WHERE client_id = :client');
$revoke->execute(['client' => $clientId]);
$rejected($baseInput, 'MCP_OAUTH_INVALID_CLIENT');

$counterDirectory = $rateDirectory . DIRECTORY_SEPARATOR . 'counter';
if (!mcpOauthConsumeRateLimit('case', 1, $counterDirectory, 120)
    || mcpOauthConsumeRateLimit('case', 1, $counterDirectory, 121)
    || !mcpOauthConsumeRateLimit('case', 1, $counterDirectory, 180)) {
    throw new RuntimeException('MCP OAuth fixed-window rate limiter must be atomic and reset per minute.');
}
tenantTestCleanup($pdo, $fixture);

fwrite(STDOUT, "PASS: MCP OAuth client credentials, token claims, discovery metadata, denial paths and rate limits.\n");
