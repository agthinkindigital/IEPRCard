<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';
require_once dirname(__DIR__, 2) . '/config/mcp_server.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once __DIR__ . '/McpServerHttpTestSupport.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-server-http-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create MCP server HTTP test storage.');
}
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'MCP_PUBLIC_BASE_URL', 'MCP_OAUTH_SIGNING_KEY', 'MCP_ORIGIN_ALLOWLIST', 'MCP_RATE_LIMIT_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an MCP server HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$baseUrl = 'http://127.0.0.1:' . $port;
$databasePath = $directory . DIRECTORY_SEPARATOR . 'mcp-server.sqlite';
$ratePath = $directory . DIRECTORY_SEPARATOR . 'rate';
$signingKey = base64_encode(random_bytes(32));
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('MCP_PUBLIC_BASE_URL=' . $baseUrl);
putenv('MCP_OAUTH_SIGNING_KEY=' . $signingKey);
putenv('MCP_ORIGIN_ALLOWLIST=https://client.example.test');
putenv('MCP_RATE_LIMIT_PATH=' . $ratePath);

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$clientId = 'mcp_' . bin2hex(random_bytes(20));
$clientSecret = 'mcpsec_' . bin2hex(random_bytes(32));
$insert = $pdo->prepare(
    'INSERT INTO mcp_api_keys (tenant_id, nome, client_id, chave_hash, escopos_json, limite_requisicoes_minuto, ativo) '
    . 'VALUES (:tenant, :name, :client, :hash, :scopes, 30, 1)'
);
$insert->execute([
    'tenant' => $fixture['tenant_id'], 'name' => 'MCP transport client', 'client' => $clientId,
    'hash' => password_hash($clientSecret, PASSWORD_DEFAULT), 'scopes' => '["system.read"]',
]);
$configuration = ['APP_ENV' => 'dev', 'MCP_PUBLIC_BASE_URL' => $baseUrl, 'MCP_OAUTH_SIGNING_KEY' => $signingKey];
$token = mcpOauthIssueClientCredentialsToken($pdo, [
    'grant_type' => 'client_credentials', 'resource' => $baseUrl . '/mcp', 'scope' => 'system.read',
    '_basic_credentials' => ['client_id' => $clientId, 'client_secret' => $clientSecret],
], $configuration, $ratePath)['access_token'];

$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$arguments = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (is_file($sqliteExtension)) {
        $arguments = ['-d', 'extension=pdo_sqlite'];
    }
}
$process = proc_open(array_merge([PHP_BINARY], $arguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']), [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start MCP transport test server.');
}

$factories = mcpServerHttpRpcFactories();
$rpcHeaders = $factories['headers'];
$meta = $factories['meta'];
$rpc = $factories['request'];

try {
    $url = $baseUrl . '/mcp.php';
    $status = 0;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        [$status] = mcpServerHttpRequest($url, 'GET');
        if ($status !== 0) {
            break;
        }
        usleep(100_000);
    }
    if ($status !== 405) {
        throw new RuntimeException('MCP transport must reject GET and remain POST-only.');
    }
    $body = $rpc('server/discover');
    [$status, $response, $headers] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('server/discover', null, '2026-07-28', $token));
    $decoded = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($decoded) || ($decoded['result']['supportedVersions'] ?? null) !== ['2026-07-28']
        || !isset($decoded['result']['capabilities']['tools'])
        || !in_array('content-type: application/json; charset=utf-8', array_map('strtolower', $headers), true)
        || !in_array('cache-control: no-store', array_map('strtolower', $headers), true)) {
        throw new RuntimeException('MCP server/discover must return versioned stateless capability metadata: ' . $status . ' ' . (string) $response . ' ' . json_encode($headers));
    }
    $body = $rpc('ping');
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $token));
    if ($status !== 200 || !str_contains((string) $response, '"result":{}')) {
        throw new RuntimeException('MCP ping must return an empty JSON-RPC result.');
    }
    mcpServerHttpToolAssertions($url, $token, $meta, $rpc, $rpcHeaders);
    [$status, $response, $authHeaders] = mcpServerHttpRequest($url, 'POST', $body, ['Accept: application/json, text/event-stream', 'Content-Type: application/json', 'Origin: https://client.example.test']);
    if ($status !== 401 || !in_array('www-authenticate: bearer resource_metadata="' . $baseUrl . '/.well-known/oauth-protected-resource/mcp", error="invalid_token"', array_map('strtolower', $authHeaders), true)) {
        throw new RuntimeException('MCP authentication challenge must reference Protected Resource Metadata.');
    }
    $badOriginHeaders = $rpcHeaders('ping', null, '2026-07-28', $token);
    $originHeaderIndex = array_search('Origin: https://client.example.test', $badOriginHeaders, true);
    $badOriginHeaders[$originHeaderIndex] = 'Origin: https://attacker.example.test';
    [$status] = mcpServerHttpRequest($url, 'POST', $body, $badOriginHeaders);
    if ($status !== 403) {
        throw new RuntimeException('MCP transport must reject untrusted Origin values.');
    }
    $sessionHeaders = [...$rpcHeaders('ping', null, '2026-07-28', $token), 'Mcp-Session-Id: legacy-session'];
    [$status] = mcpServerHttpRequest($url, 'POST', $body, $sessionHeaders);
    if ($status !== 400) {
        throw new RuntimeException('MCP transport must reject legacy session and resumable stream headers.');
    }
    $body = $rpc('ping', '1900-01-01');
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '1900-01-01', $token));
    if ($status !== 400 || !str_contains((string) $response, '"code":-32022')) {
        throw new RuntimeException('MCP transport must report unsupported protocol versions.');
    }
    $body = $rpc('ping');
    $mismatchedVersionHeaders = $rpcHeaders('ping', null, '1900-01-01', $token);
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $mismatchedVersionHeaders);
    if ($status !== 400 || !str_contains((string) $response, '"code":-32020')) {
        throw new RuntimeException('MCP transport must reject protocol version header/body mismatches.');
    }
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('server/discover', null, '2026-07-28', $token));
    if ($status !== 400 || !str_contains((string) $response, '"code":-32020')) {
        throw new RuntimeException('MCP transport must reject mirrored method header mismatches.');
    }
    $body = $rpc('tools/call', '2026-07-28', ['name' => 'private-tool']);
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('tools/call', 'different-tool', '2026-07-28', $token));
    if ($status !== 400 || !str_contains((string) $response, '"code":-32020')) {
        throw new RuntimeException('MCP transport must reject mirrored tool name mismatches.');
    }
    $unicodeName = 'ação';
    $body = $rpc('tools/call', '2026-07-28', ['name' => $unicodeName]);
    $encodedNameHeaders = $rpcHeaders('tools/call', '=?base64?' . base64_encode($unicodeName) . '?=', '2026-07-28', $token);
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $body, $encodedNameHeaders);
    if ($status !== 400 || !str_contains((string) $response, '"code":-32602')) {
        throw new RuntimeException('MCP transport must decode the standard Base64 sentinel in Mcp-Name before tool lookup.');
    }
    $body = $rpc('ping');
    $tokenSeparator = strpos($token, '.');
    $payloadFirstCharacter = $tokenSeparator + 1;
    $tamperedToken = substr_replace($token, $token[$payloadFirstCharacter] === 'A' ? 'B' : 'A', $payloadFirstCharacter, 1);
    [$status] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $tamperedToken));
    if ($status !== 401) {
        throw new RuntimeException('MCP transport must reject an invalid token signature.');
    }
    foreach ([
        ['aud' => 'https://other.example.test/mcp'],
        ['exp' => time() - 1],
        ['tenant_id' => $fixture['tenant_id'] + 1000],
    ] as $invalidClaims) {
        $invalidToken = mcpServerHttpResignToken($token, base64_decode($signingKey, true), $invalidClaims);
        [$status] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $invalidToken));
        if ($status !== 401) {
            throw new RuntimeException('MCP transport must reject wrong audience, expired token and tenant claims.');
        }
    }
    $suspend = $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :tenant");
    $suspend->execute(['tenant' => $fixture['tenant_id']]);
    [$status] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $token));
    $restore = $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :tenant");
    $restore->execute(['tenant' => $fixture['tenant_id']]);
    if ($status !== 401) {
        throw new RuntimeException('MCP transport must revalidate tenant suspension on every request.');
    }
    $revoke = $pdo->prepare('UPDATE mcp_api_keys SET ativo = 0 WHERE client_id = :client');
    $revoke->execute(['client' => $clientId]);
    [$status] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $token));
    if ($status !== 401) {
        throw new RuntimeException('MCP transport must revalidate client revocation on every request.');
    }
    $activate = $pdo->prepare('UPDATE mcp_api_keys SET ativo = 1 WHERE client_id = :client');
    $activate->execute(['client' => $clientId]);
    $body = $rpc('ping');
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        [$status] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $token));
        if ($status !== 200) {
            throw new RuntimeException('MCP client rate limit must allow requests within the configured window.');
        }
    }
    [$status, , $rateHeaders] = mcpServerHttpRequest($url, 'POST', $body, $rpcHeaders('ping', null, '2026-07-28', $token));
    if ($status !== 429 || !in_array('retry-after: 60', array_map('strtolower', $rateHeaders), true)) {
        throw new RuntimeException('MCP client rate limit must return HTTP 429 and Retry-After.');
    }
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM mcp_audit_logs')->fetchColumn();
    if ($auditCount !== 30) {
        throw new RuntimeException('Authenticated MCP protocol calls must produce sanitized audit records.');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    unset($pdo);
    foreach ($oldEnvironment as $key => $value) {
        putenv($value === null ? $key : $key . '=' . $value);
    }
    $cleanupDeadline = microtime(true) + 5;
    do {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) {
                    if (is_file($child)) {
                        @unlink($child);
                    }
                }
                @rmdir($path);
            }
        }
        if ((glob($directory . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
            @rmdir($directory);
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $cleanupDeadline);
}

fwrite(STDOUT, "PASS: MCP Streamable HTTP auth, Origin, protocol headers, discovery, ping, revocation and audit.\n");
