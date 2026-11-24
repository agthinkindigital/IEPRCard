<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once __DIR__ . '/AdminHttpProfileFixture.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-admin-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'admin.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPR_INTEGRATION_KEY'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev'); putenv('DB_DRIVER=sqlite'); putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$pdo->prepare("INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES ('superadmin', 'MCP Admin', 'mcp-admin@example.test', :password, 1)")
    ->execute(['password' => password_hash('mcp-admin-http-password', PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();
adminHttpSeedHostedProfile($pdo, $adminId);
unset($pdo);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) throw new RuntimeException('Could not allocate MCP admin HTTP test port.');
$address = stream_socket_get_name($probe, false); fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$extensionArguments = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (!extension_loaded('pdo_sqlite') && is_file($sqliteExtension)) $extensionArguments = ['-d', 'extension=pdo_sqlite'];
}
$process = proc_open(array_merge([PHP_BINARY], $extensionArguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']), [
    0 => ['file', $nullDevice, 'r'], 1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Could not start MCP admin HTTP test server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $request = static function (string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null): array {
        $headers = [];
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($host !== null) $headers[] = 'Host: ' . $host;
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 4,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
        return [$status, $response === false ? null : $response, $responseHeaders];
    };
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = $request($baseUrl . '/admin/acesso-mcp.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) throw new RuntimeException('Unauthenticated MCP administration must redirect to login.');
    [$tenantStatus] = $request($baseUrl . '/admin/acesso-mcp.php', 'GET', null, null, 'client.example.test');
    if ($tenantStatus !== 404) throw new RuntimeException('MCP superadmin screen must be hidden on tenant hosts.');
    [, , $loginHeaders] = $request($baseUrl . '/admin/login.php');
    $cookie = null;
    foreach ($loginHeaders as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookie = $match[1];
    [, $loginPage] = $request($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage, $loginCsrf)) {
        throw new RuntimeException('Admin login must provide a session-bound CSRF token.');
    }
    [$status, , $loginHeaders] = $request($baseUrl . '/admin/login.php', 'POST', http_build_query([
        'email' => 'mcp-admin@example.test', 'password' => 'mcp-admin-http-password', 'csrf_token' => $loginCsrf[1],
    ]), $cookie);
    foreach ($loginHeaders as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookie = $match[1];
    if ($status !== 303 || $cookie === null) throw new RuntimeException('Superadmin login must establish a session.');
    [$status, $page] = $request($baseUrl . '/admin/acesso-mcp.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Manutenção assistida')
        || !str_contains((string) $page, 'Fine-grained PAT restrito a este repositório')
        || !str_contains((string) $page, 'Criar grant temporário') || str_contains((string) $page, 'token_cifrado')
        || str_contains((string) $page, 'mcp_support_')) {
        throw new RuntimeException('Superadmin MCP screen must render the approved controls without stored secrets.');
    }
    [$status] = $request($baseUrl . '/admin/acesso-mcp.php', 'POST', http_build_query([
        'action' => 'grant.create', 'label' => 'Rejected without CSRF', 'duration' => '30m', 'csrf_token' => str_repeat('0', 64),
    ]), $cookie);
    if ($status !== 403) throw new RuntimeException('MCP grant mutations must require a valid CSRF token.');
} finally {
    proc_terminate($process); proc_close($process);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path) && !is_link($path)) @unlink($path);
    @rmdir($directory);
    foreach ($oldEnvironment as $key => $value) putenv($value === null ? $key : $key . '=' . $value);
}

fwrite(STDOUT, "PASS: MCP admin HTTP page enforces superadmin host, login, CSRF and secret redaction.\n");
