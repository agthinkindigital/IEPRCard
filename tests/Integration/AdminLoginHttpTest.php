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
    if (!extension_loaded('pdo_sqlite') && is_file($sqliteExtension)) {
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
    $page = null;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status, $page, $headers] = adminHttpRequest($baseUrl . '/admin/login.php');
        if ($status !== 0) {
            break;
        }
        usleep(100_000);
    }
    $location = adminHttpHeaderValue($headers, 'Location');
    if ($status !== 303 || !is_string($location)
        || preg_match('~^/admin/login\.php\?token=([a-f0-9]{64})$~D', $location, $setupTokenMatch) !== 1) {
        throw new RuntimeException('An empty installation must redirect to a session-bound setup URL with a random token.');
    }
    if (adminHttpHeaderValue($headers, 'Cache-Control') === null
        || adminHttpHeaderValue($headers, 'Referrer-Policy') !== 'no-referrer'
        || adminHttpHeaderValue($headers, 'X-Robots-Tag') !== 'noindex, nofollow') {
        throw new RuntimeException('Initial setup responses must disable caching, referrers, and indexing.');
    }
    $cookie = adminHttpCookie($headers);
    if ($cookie === null || !str_contains(implode("\n", $headers), 'HttpOnly') || !str_contains(implode("\n", $headers), 'SameSite=Lax')) {
        throw new RuntimeException('Initial setup must use a protected session cookie.');
    }
    $setupUrl = $baseUrl . $location;
    [$foreignSessionStatus, , $foreignSessionHeaders] = adminHttpRequest($setupUrl);
    $foreignLocation = adminHttpHeaderValue($foreignSessionHeaders, 'Location');
    if ($foreignSessionStatus !== 303 || !is_string($foreignLocation)
        || $foreignLocation === $location
        || !preg_match('~^/admin/login\.php\?token=[a-f0-9]{64}$~D', $foreignLocation)) {
        throw new RuntimeException('A setup token from another session must be rejected and replaced with that session’s token.');
    }
    [$status, $page, $setupHeaders] = adminHttpRequest($setupUrl, 'GET', null, $cookie);
    if ($status !== 200 || !is_string($page) || !str_contains($page, 'Configurar administrador')
        || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $tokenMatch)) {
        throw new RuntimeException('A valid setup token in its session must show the initial administrator form.');
    }
    [$tenantHostStatus, $tenantHostBody] = adminHttpRequest($baseUrl . '/admin/login.php', 'GET', null, null, 'customer.example.test');
    if ($tenantHostStatus !== 404 || str_contains((string) $tenantHostBody, 'Entrar na administração')) {
        throw new RuntimeException('Administrative login must not be exposed through a tenant or unknown Host.');
    }

    $setupForm = http_build_query([
        'setup_token' => $setupTokenMatch[1],
        'csrf_token' => substr($tokenMatch[1], 1),
        'name' => 'Administrador HTTP',
        'email' => 'http-admin@example.test',
        'password' => 'http-test-password-123',
        'password_confirm' => 'http-test-password-123',
    ]);
    [$status] = adminHttpRequest($setupUrl, 'POST', $setupForm, $cookie);
    if ($status !== 403) {
        throw new RuntimeException('Initial setup must reject a request without the session-bound CSRF token.');
    }

    $setupForm = http_build_query([
        'setup_token' => $setupTokenMatch[1],
        'csrf_token' => $tokenMatch[1],
        'name' => 'Administrador HTTP',
        'email' => 'http-admin@example.test',
        'password' => 'éééééé',
        'password_confirm' => 'éééééé',
    ]);
    [$status] = adminHttpRequest($setupUrl, 'POST', $setupForm, $cookie);
    if ($status !== 422) {
        throw new RuntimeException('A password shorter than 12 Unicode characters must be rejected even if it has 12 bytes.');
    }

    $setupForm = http_build_query([
        'setup_token' => $setupTokenMatch[1],
        'csrf_token' => $tokenMatch[1],
        'name' => 'Administrador HTTP',
        'email' => 'http-admin@example.test',
        'password' => 'http-test-password-123',
        'password_confirm' => 'different-password-123',
    ]);
    [$status] = adminHttpRequest($setupUrl, 'POST', $setupForm, $cookie);
    if ($status !== 422) {
        throw new RuntimeException('Mismatched initial passwords must be rejected without creating an account.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() !== 0) {
        throw new RuntimeException('Rejected initial setup submissions must not create an account.');
    }
    unset($pdo);

    $setupForm = http_build_query([
        'setup_token' => $setupTokenMatch[1],
        'csrf_token' => $tokenMatch[1],
        'name' => 'Administrador HTTP',
        'email' => 'http-admin@example.test',
        'password' => 'http-test-password-123',
        'password_confirm' => 'http-test-password-123',
    ]);
    [$status, , $setupSuccessHeaders] = adminHttpRequest($setupUrl, 'POST', $setupForm, $cookie);
    $cookie = adminHttpCookie($setupSuccessHeaders) ?? $cookie;
    if ($status !== 303 || adminHttpHeaderValue($setupSuccessHeaders, 'Location') !== '/admin/login.php') {
        throw new RuntimeException('Successful initial setup must consume the token and redirect to the clean login URL.');
    }
    [$status] = adminHttpRequest($setupUrl, 'GET', null, $cookie);
    if ($status !== 303) {
        throw new RuntimeException('A consumed setup URL must no longer display the initial setup form.');
    }
    [$status, $page, $headers] = adminHttpRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if ($status !== 200 || !is_string($page) || str_contains($page, 'Configurar administrador')
        || !str_contains($page, 'Entrar na administração')) {
        throw new RuntimeException('A populated installation must show only the regular administrator login.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $tokenMatch)) {
        throw new RuntimeException('Admin login page must include a session-bound CSRF token.');
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

    $form = http_build_query(['email' => 'http-admin@example.test', 'password' => 'http-test-password-123', 'csrf_token' => $tokenMatch[1]]);
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
    if ($host !== null) {
        $urlParts = parse_url($url);
        $port = (int) ($urlParts['port'] ?? 80);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 3);
        if ($socket === false) {
            return [0, null, []];
        }
        $path = (string) ($urlParts['path'] ?? '/');
        if (isset($urlParts['query'])) $path .= '?' . $urlParts['query'];
        $requestHeaders = ['Host: ' . $host, 'Accept: text/html', 'Connection: close'];
        if ($cookie !== null) $requestHeaders[] = 'Cookie: ' . $cookie;
        if ($method === 'POST') {
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $requestHeaders[] = 'Content-Length: ' . strlen($body ?? '');
        }
        fwrite($socket, $method . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $requestHeaders) . "\r\n\r\n" . ($body ?? ''));
        $rawResponse = stream_get_contents($socket);
        fclose($socket);
        $responseParts = explode("\r\n\r\n", (string) $rawResponse, 2);
        $responseLines = explode("\r\n", $responseParts[0] ?? '');
        $status = isset($responseLines[0]) && preg_match('/\s(\d{3})\s/', $responseLines[0], $match) ? (int) $match[1] : 0;

        return [$status, $responseParts[1] ?? null, array_slice($responseLines, 1)];
    }

    $headers = ['Accept: text/html'];
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

/** @param list<string> $headers */
function adminHttpHeaderValue(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return null;
}
