<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

$root = dirname(__DIR__, 2);
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-admin-http-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create temporary admin HTTP test storage.');
}
$databasePath = $tempDirectory . DIRECTORY_SEPARATOR . 'admin.sqlite';
$rateLimitPath = $tempDirectory . DIRECTORY_SEPARATOR . 'rate-limit.json';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPRCARD_ADMIN_RATE_LIMIT_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH=' . $rateLimitPath);

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$insert = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 1)');
$insert->execute([
    'role' => 'superadmin',
    'name' => 'Administrador HTTP',
    'email' => 'http-admin@example.test',
    'password_hash' => password_hash('http-test-password', PASSWORD_DEFAULT),
]);
unset($insert);
unset($pdo);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$stdoutPath = $tempDirectory . DIRECTORY_SEPARATOR . 'php.out.log';
$stderrPath = $tempDirectory . DIRECTORY_SEPARATOR . 'php.err.log';
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$phpArguments = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (is_file($sqliteExtension)) {
        $phpArguments = ['-d', 'extension=pdo_sqlite'];
    }
}
$command = array_merge([PHP_BINARY], $phpArguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']);
$process = proc_open($command, [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $stdoutPath, 'a'],
    2 => ['file', $stderrPath, 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    [$tenantHostStatus, $tenantHostBody] = adminHttpRequest($baseUrl . '/admin/login.php', 'GET', null, null, 'customer.example.test');
    if ($tenantHostStatus !== 404 || str_contains((string) $tenantHostBody, 'Entrar na administração')) {
        throw new RuntimeException('Administrative login must not be exposed through a tenant or unknown Host.');
    }

    $page = null;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status, $page, $headers] = adminHttpRequest($baseUrl . '/admin/login.php');
        if ($status !== 0) {
            break;
        }
        usleep(100_000);
    }
    if ($status !== 200 || !is_string($page)) {
        throw new RuntimeException('Admin login page must respond with HTTP 200.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $tokenMatch)) {
        throw new RuntimeException('Admin login page must include a session-bound CSRF token.');
    }
    $cookie = adminHttpCookie($headers);
    if ($cookie === null || !str_contains(implode("\n", $headers), 'HttpOnly') || !str_contains(implode("\n", $headers), 'SameSite=Lax')) {
        throw new RuntimeException('Admin login session cookie must be HttpOnly and SameSite=Lax.');
    }

    $form = http_build_query(['email' => 'http-admin@example.test', 'password' => 'wrong-password', 'csrf_token' => substr($tokenMatch[1], 1)]);
    [$status] = adminHttpRequest($baseUrl . '/admin/login.php', 'POST', $form, $cookie);
    if ($status !== 403) {
        throw new RuntimeException('A login POST with an invalid CSRF token must return HTTP 403.');
    }

    $form = http_build_query(['email' => 'unknown@example.test', 'password' => 'wrong-password', 'csrf_token' => $tokenMatch[1]]);
    [$status, $failureBody] = adminHttpRequest($baseUrl . '/admin/login.php', 'POST', $form, $cookie);
    if ($status !== 401 || !str_contains((string) $failureBody, 'E-mail ou senha inválidos.')
        || str_contains((string) $failureBody, 'unknown@example.test')) {
        throw new RuntimeException('Invalid login must return a generic denial without echoing the submitted email.');
    }

    $form = http_build_query(['email' => 'http-admin@example.test', 'password' => 'http-test-password', 'csrf_token' => $tokenMatch[1]]);
    [$status, , $successHeaders] = adminHttpRequest($baseUrl . '/admin/login.php', 'POST', $form, $cookie);
    $newCookie = adminHttpCookie($successHeaders);
    if ($status !== 303 || $newCookie === null || $newCookie === $cookie
        || !str_contains(implode("\n", $successHeaders), 'Location: /admin/migracao-banco.php')) {
        throw new RuntimeException('Valid login must rotate the session ID and redirect to the requested admin tool.');
    }

    [$status] = adminHttpRequest($baseUrl . '/admin/logout.php', 'GET', null, $newCookie);
    if ($status !== 405) {
        throw new RuntimeException('Logout must reject GET requests.');
    }
    $form = http_build_query(['csrf_token' => $tokenMatch[1]]);
    [$status] = adminHttpRequest($baseUrl . '/admin/logout.php', 'POST', $form, $newCookie);
    if ($status !== 403) {
        throw new RuntimeException('Logout must reject a missing or invalid session-bound CSRF token.');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
    $cleanupDeadline = microtime(true) + 5;
    do {
        foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (count(glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: []) === 0) {
            @rmdir($tempDirectory);
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $cleanupDeadline);
}

fwrite(STDOUT, "PASS: admin login enforces CSRF, generic denials, secure cookies, and session rotation over HTTP.\n");

return 'pass';

/** @return array{int, string|null, list<string>} */
function adminHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null): array
{
    $headers = ['Accept: text/html'];
    if ($host !== null) {
        $headers[] = 'Host: ' . $host;
    }
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)
        ? (int) $match[1]
        : 0;

    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function adminHttpCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) {
            return $match[1];
        }
    }
    return null;
}
