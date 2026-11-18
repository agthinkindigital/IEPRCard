<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-migration-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'MIGRATION_SOURCE_SQLITE_PATH', 'MIGRATION_TARGET_DATABASE', 'MIGRATION_TARGET_USERNAME', 'MIGRATION_TARGET_PASSWORD', 'IEPRCARD_ADMIN_RATE_LIMIT_PATH', 'IEPRCARD_MIGRATION_STATE_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('MIGRATION_SOURCE_SQLITE_PATH=' . $databasePath);
putenv('MIGRATION_TARGET_DATABASE=target_db');
putenv('MIGRATION_TARGET_USERNAME=migration_user');
putenv('MIGRATION_TARGET_PASSWORD=do-not-display-this-secret');
putenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH=' . $directory . DIRECTORY_SEPARATOR . 'rate-limit.json');
putenv('IEPRCARD_MIGRATION_STATE_PATH=' . $statePath);

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$pdo->exec("INSERT INTO planos (nome, preco) VALUES ('Conta confidencial', 10.00)");
$insert = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 1)');
$insert->execute([
    'role' => 'superadmin',
    'name' => 'Admin Migração',
    'email' => 'migration-admin@example.test',
    'password_hash' => password_hash('migration-http-password', PASSWORD_DEFAULT),
]);
unset($insert, $pdo);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$extensionArguments = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (is_file($sqliteExtension)) {
        $extensionArguments = ['-d', 'extension=pdo_sqlite'];
    }
}
$process = proc_open(array_merge([PHP_BINARY], $extensionArguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']), [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = migrationHttpRequest($baseUrl . '/admin/migracao-banco.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) {
        throw new RuntimeException('Unauthenticated access to migration admin must redirect to login.');
    }
    [$status] = migrationHttpRequest($baseUrl . '/admin/migracao-status.php');
    if ($status !== 401) {
        throw new RuntimeException('The migration status endpoint must reject unauthenticated requests.');
    }
    [, , $headers] = migrationHttpRequest($baseUrl . '/admin/login.php');
    $cookie = migrationHttpCookie($headers);
    if ($cookie === null) {
        throw new RuntimeException('Login must establish a session before authenticating.');
    }
    [, $loginBody] = migrationHttpRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginBody, $loginToken)) {
        throw new RuntimeException('Login must provide a session-bound CSRF token.');
    }
    $loginForm = http_build_query([
        'email' => 'migration-admin@example.test',
        'password' => 'migration-http-password',
        'csrf_token' => $loginToken[1],
    ]);
    [$status, , $loginHeaders] = migrationHttpRequest($baseUrl . '/admin/login.php', 'POST', $loginForm, $cookie);
    $cookie = migrationHttpCookie($loginHeaders);
    if ($status !== 303 || $cookie === null) {
        throw new RuntimeException('A valid global administrator must establish an authenticated session.');
    }

    [$status, $page, $headers] = migrationHttpRequest($baseUrl . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Migração de banco')
        || !str_contains((string) $page, 'source.sqlite')
        || str_contains((string) $page, $databasePath)
        || str_contains((string) $page, 'Conta confidencial')
        || str_contains((string) $page, 'do-not-display-this-secret')
        || str_contains((string) $page, 'migration_user')) {
        throw new RuntimeException('Authenticated migration UI must render preflight without paths, credentials, or row data.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $pageToken)) {
        throw new RuntimeException('Migration page must include CSRF tokens for its POST actions.');
    }
    $form = http_build_query(['action' => 'queue', 'confirm_copy' => 'yes', 'csrf_token' => substr($pageToken[1], 1)]);
    [$status] = migrationHttpRequest($baseUrl . '/admin/migracao-banco.php', 'POST', $form, $cookie);
    if ($status !== 403 || is_file($statePath)) {
        throw new RuntimeException('Migration queue must reject invalid CSRF without writing a job.');
    }

    [$status, $statusBody] = migrationHttpRequest($baseUrl . '/admin/migracao-status.php', 'GET', null, $cookie);
    $statusData = json_decode((string) $statusBody, true);
    if ($status !== 200 || !is_array($statusData) || ($statusData['status'] ?? null) !== 'idle') {
        throw new RuntimeException('Authenticated status endpoint must expose only the current operational state.');
    }

    fwrite(STDOUT, "PASS: migration UI requires admin auth/CSRF and hides source paths, row data, and credentials.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach ($oldEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    $cleanupDeadline = microtime(true) + 5;
    do {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        if (count(glob($directory . DIRECTORY_SEPARATOR . '*') ?: []) === 0) {
            @rmdir($directory);
            break;
        }
        usleep(100_000);
    } while (microtime(true) < $cleanupDeadline);
}

return 'pass';

/** @return array{int, string|null, list<string>} */
function migrationHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null): array
{
    $headers = ['Accept: text/html, application/json'];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 4,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function migrationHttpCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) return $match[1];
    }
    return null;
}
