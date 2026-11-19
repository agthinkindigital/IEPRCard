<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-http-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create MCP OAuth HTTP test storage.');
}
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'MCP_PUBLIC_BASE_URL', 'MCP_OAUTH_SIGNING_KEY', 'MCP_RATE_LIMIT_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an MCP OAuth HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$baseUrl = 'http://127.0.0.1:' . $port;
$databasePath = $directory . DIRECTORY_SEPARATOR . 'mcp.sqlite';
$ratePath = $directory . DIRECTORY_SEPARATOR . 'rate';
$signingKey = base64_encode(random_bytes(32));
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('MCP_PUBLIC_BASE_URL=' . $baseUrl);
putenv('MCP_OAUTH_SIGNING_KEY=' . $signingKey);
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
    'tenant' => $fixture['tenant_id'], 'name' => 'MCP HTTP client', 'client' => $clientId,
    'hash' => password_hash($clientSecret, PASSWORD_DEFAULT), 'scopes' => '["system.read"]',
]);
unset($pdo);

$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$arguments = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (!extension_loaded('pdo_sqlite') && is_file($sqliteExtension)) {
        $arguments = ['-d', 'extension=pdo_sqlite'];
    }
}
$process = proc_open(array_merge([PHP_BINARY], $arguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']), [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start MCP OAuth HTTP test server.');
}

try {
    $tokenUrl = $baseUrl . '/oauth/token.php';
    $status = 0;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        [$status] = mcpOAuthHttpRequest($tokenUrl, 'GET');
        if ($status !== 0) {
            break;
        }
        usleep(100_000);
    }
    if ($status !== 405) {
        throw new RuntimeException('MCP OAuth token endpoint must reject non-POST methods.');
    }
    $headers = ['Authorization: Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret)), 'Content-Type: application/x-www-form-urlencoded'];
    $body = http_build_query(['grant_type' => 'client_credentials', 'resource' => $baseUrl . '/mcp', 'scope' => 'system.read']);
    [$status, $response, $responseHeaders] = mcpOAuthHttpRequest($tokenUrl, 'POST', $body, $headers);
    $decoded = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($decoded) || $decoded['token_type'] !== 'Bearer'
        || !isset($decoded['access_token']) || !in_array('cache-control: no-store', array_map('strtolower', $responseHeaders), true)) {
        throw new RuntimeException('MCP OAuth HTTP token response must be JSON, bearer and non-cacheable.');
    }
    [$status, $response] = mcpOAuthHttpRequest($tokenUrl, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
    if ($status !== 401 || !str_contains((string) $response, '"error":"invalid_client"')) {
        throw new RuntimeException('MCP OAuth HTTP endpoint must reject missing client authentication generically.');
    }
    [$status, $response] = mcpOAuthHttpRequest($tokenUrl, 'POST', http_build_query([
        'grant_type' => 'client_credentials', 'resource' => $baseUrl . '/mcp', 'scope' => 'system.write',
    ]), $headers);
    if ($status !== 400 || !str_contains((string) $response, '"error":"invalid_scope"')) {
        throw new RuntimeException('MCP OAuth HTTP endpoint must deny ungranted scopes.');
    }
    [$status, $response] = mcpOAuthHttpRequest($tokenUrl, 'POST', $body . '&scope=system.read', $headers);
    if ($status !== 400 || !str_contains((string) $response, '"error":"invalid_request"')) {
        throw new RuntimeException('MCP OAuth HTTP endpoint must reject duplicate form parameters.');
    }
    [$status, $response] = mcpOAuthHttpRequest($baseUrl . '/.well-known/oauth-protected-resource-mcp.php', 'GET', null, ['Host: attacker.example.test']);
    $metadata = json_decode((string) $response, true);
    if ($status !== 200 || !is_array($metadata) || $metadata['resource'] !== $baseUrl . '/mcp') {
        throw new RuntimeException('MCP OAuth resource metadata must use the configured canonical URL, not Host.');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
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

fwrite(STDOUT, "PASS: MCP OAuth HTTP methods, Basic auth, token responses, scope denial and canonical metadata host.\n");

/** @return array{int,string|null,list<string>} */
function mcpOAuthHttpRequest(string $url, string $method, ?string $body = null, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s([0-9]{3})\s/', $responseHeaders[0], $matches) === 1) {
        $status = (int) $matches[1];
    }
    return [$status, $response === false ? null : $response, $responseHeaders];
}
