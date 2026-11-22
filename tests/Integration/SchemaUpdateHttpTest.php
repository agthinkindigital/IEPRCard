<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/schema_update.php';
require_once __DIR__ . '/AdminHttpProfileFixture.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-schema-update-http-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not prepare an isolated schema update HTTP test.');
}
$databasePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
$auditPath = $directory . DIRECTORY_SEPARATOR . 'audit.jsonl';
$environmentKeys = ['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPRCARD_ADMIN_RATE_LIMIT_PATH', 'IEPRCARD_SCHEMA_UPDATE_STATE_PATH', 'IEPRCARD_SCHEMA_UPDATE_AUDIT_PATH'];
$previousEnvironment = [];
foreach ($environmentKeys as $key) {
    $value = getenv($key);
    $previousEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH=' . $directory . DIRECTORY_SEPARATOR . 'rate-limit.json');
putenv('IEPRCARD_SCHEMA_UPDATE_STATE_PATH=' . $statePath);
putenv('IEPRCARD_SCHEMA_UPDATE_AUDIT_PATH=' . $auditPath);

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$insert = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 1)');
$insert->execute([
    'role' => 'superadmin',
    'name' => 'Schema Admin',
    'email' => 'schema-admin@example.test',
    'password_hash' => password_hash('schema-update-http-password', PASSWORD_DEFAULT),
]);
$adminId = (int) $pdo->lastInsertId();
adminHttpSeedHostedProfile($pdo, $adminId);
unset($pdo, $insert);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) {
    throw new RuntimeException('Could not allocate an HTTP test port.');
}
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$phpArguments = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (!extension_loaded('pdo_sqlite') && is_file($sqliteExtension)) {
        $phpArguments = array_merge($phpArguments, ['-d', 'extension=pdo_sqlite']);
    }
}
$phpArguments = array_merge($phpArguments, ['-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']);
$process = proc_open($phpArguments, [
    0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP schema update test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) {
        throw new RuntimeException('Unauthenticated schema update page access must redirect to the admin login.');
    }
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema-status.php', 'GET', null, null, 'customer.example.test');
    if ($status !== 404) {
        throw new RuntimeException('Schema update status must not be exposed through a tenant host.');
    }
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema-status.php');
    if ($status !== 401) {
        throw new RuntimeException('Schema update status must reject unauthenticated requests.');
    }

    [, , $headers] = schemaUpdateHttpRequest($baseUrl . '/admin/login.php');
    $cookie = schemaUpdateHttpCookie($headers);
    [, $loginBody] = schemaUpdateHttpRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginBody, $loginToken)) {
        throw new RuntimeException('Admin login must provide the CSRF token needed for the schema update action.');
    }
    $loginForm = http_build_query([
        'email' => 'schema-admin@example.test',
        'password' => 'schema-update-http-password',
        'csrf_token' => $loginToken[1],
    ]);
    [$status, , $headers] = schemaUpdateHttpRequest($baseUrl . '/admin/login.php', 'POST', $loginForm, $cookie);
    $cookie = schemaUpdateHttpCookie($headers);
    if ($status !== 303 || $cookie === null) {
        throw new RuntimeException('A valid global superadmin must establish a session for schema updates.');
    }

    [$status, $page] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Atualização de schema')
        || !str_contains((string) $page, 'backup restaurável')
        || !str_contains((string) $page, '0001_mvp_schema')
        || str_contains((string) $page, $databasePath)
        || str_contains((string) $page, 'schema-update-http-password')) {
        throw new RuntimeException('The authenticated schema update UI must show the release and backup confirmation without secrets or paths.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $pageToken)) {
        throw new RuntimeException('The schema update page must include a session-bound CSRF token.');
    }

    $invalidCsrf = http_build_query(['action' => 'queue', 'confirm_backup' => 'yes', 'csrf_token' => substr($pageToken[1], 1)]);
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema.php', 'POST', $invalidCsrf, $cookie);
    if ($status !== 403 || is_file($statePath)) {
        throw new RuntimeException('The schema update action must reject invalid CSRF without queuing work.');
    }

    $missingBackup = http_build_query(['action' => 'queue', 'csrf_token' => $pageToken[1]]);
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema.php', 'POST', $missingBackup, $cookie);
    if ($status !== 400 || is_file($statePath)) {
        throw new RuntimeException('The schema update action must require a fresh backup confirmation.');
    }

    $queue = http_build_query([
        'action' => 'queue',
        'confirm_backup' => 'yes',
        'csrf_token' => $pageToken[1],
        'migration_files[]' => 'drop_everything.sql',
    ]);
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema.php', 'POST', $queue, $cookie);
    $state = schemaUpdateStateRead($statePath);
    if ($status !== 303 || ($state['status'] ?? null) !== 'queued'
        || ($state['requested_by'] ?? null) !== 1
        || array_column($state['migrations'] ?? [], 'id') !== array_column(schemaUpdateReleaseManifest()['migrations'], 'id')
        || in_array('drop_everything.sql', array_column($state['migrations'] ?? [], 'id'), true)) {
        throw new RuntimeException('The action must queue only the trusted release manifest, ignoring browser-supplied migration files.');
    }

    [$status, $statusBody] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema-status.php', 'GET', null, $cookie);
    $statusData = json_decode((string) $statusBody, true);
    $forbiddenStatusKeys = ['password', 'database_path', 'sql'];
    if ($status !== 200 || ($statusData['status'] ?? null) !== 'queued'
        || ($statusData['requested_by'] ?? null) !== 1
        || array_intersect($forbiddenStatusKeys, array_keys($statusData)) !== []) {
        throw new RuntimeException('The authenticated status endpoint must expose only safe job metadata.');
    }
    [$status] = schemaUpdateHttpRequest($baseUrl . '/admin/atualizar-schema-status.php', 'POST', '', $cookie);
    if ($status !== 405) {
        throw new RuntimeException('The schema update status endpoint must accept GET only.');
    }
    $audit = schemaUpdateAuditRead($auditPath);
    if (count(array_filter($audit, static fn(array $entry): bool => ($entry['event'] ?? null) === 'queued')) !== 1) {
        throw new RuntimeException('The queue action must append one actor- and release-attributed operational audit event.');
    }

    fwrite(STDOUT, "PASS: schema update UI enforces superadmin, CSRF and backup confirmation; it queues only the release manifest.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach ($environmentKeys as $key) {
        $value = $previousEnvironment[$key];
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
function schemaUpdateHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null): array
{
    if ($host !== null) {
        $parts = parse_url($url);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) ($parts['port'] ?? 80), $errorCode, $errorMessage, 4);
        if ($socket === false) return [0, null, []];
        $requestHeaders = ['Host: ' . $host, 'Accept: text/html, application/json', 'Connection: close'];
        if ($cookie !== null) $requestHeaders[] = 'Cookie: ' . $cookie;
        if ($method === 'POST') {
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $requestHeaders[] = 'Content-Length: ' . strlen($body ?? '');
        }
        fwrite($socket, $method . ' ' . (string) ($parts['path'] ?? '/') . " HTTP/1.1\r\n" . implode("\r\n", $requestHeaders) . "\r\n\r\n" . ($body ?? ''));
        $raw = stream_get_contents($socket);
        fclose($socket);
        $chunks = explode("\r\n\r\n", (string) $raw, 2);
        $headers = explode("\r\n", $chunks[0] ?? '');
        preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), $chunks[1] ?? null, array_slice($headers, 1)];
    }
    $headers = ['Accept: text/html, application/json'];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 4,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function schemaUpdateHttpCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) return $match[1];
    }
    return null;
}
