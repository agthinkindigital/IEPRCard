<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_migration.php';

$mysqlHost = getenv('TEST_MYSQL_HOST');
$mysqlDatabase = getenv('TEST_MYSQL_DATABASE');
$mysqlUsername = getenv('TEST_MYSQL_USERNAME');
if (!in_array('mysql', PDO::getAvailableDrivers(), true)
    || $mysqlHost === false || $mysqlDatabase === false || $mysqlUsername === false) {
    if (filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('CI requires MySQL/MariaDB to execute the admin migration end-to-end test.');
    }
    fwrite(STDOUT, "SKIP: admin migration end-to-end test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-migration-e2e-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$sourcePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$authPath = $directory . DIRECTORY_SEPARATOR . 'auth.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
$mysqlConfiguration = [
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => $mysqlHost,
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => $mysqlDatabase,
    'DB_USERNAME' => $mysqlUsername,
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
];

$source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sourcePath]);
schemaGarantirEstrutura($source, 'test');
unset($source);
$auth = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $authPath]);
schemaGarantirEstrutura($auth, 'test');
$insertAdmin = $auth->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 1)');
$insertAdmin->execute([
    'role' => 'superadmin',
    'name' => 'Admin de integração',
    'email' => 'migration-e2e@example.test',
    'password_hash' => password_hash('integration-test-password', PASSWORD_DEFAULT),
]);
unset($insertAdmin, $auth);
$target = dbConnect($mysqlConfiguration);
schemaGarantirEstrutura($target, 'test');
$targetRows = 0;
foreach (array_keys(\IEPRCard\Database\Schema\catalogoCanonico()) as $table) {
    $targetRows += (int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
unset($target);
if ($targetRows !== 0) {
    throw new RuntimeException('Admin migration end-to-end test requires the disposable CI target database to be empty.');
}

$environmentKeys = [
    'APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'MIGRATION_SOURCE_SQLITE_PATH', 'MIGRATION_TARGET_HOST', 'MIGRATION_TARGET_PORT',
    'MIGRATION_TARGET_DATABASE', 'MIGRATION_TARGET_USERNAME', 'MIGRATION_TARGET_PASSWORD',
    'IEPRCARD_ADMIN_RATE_LIMIT_PATH', 'IEPRCARD_MIGRATION_STATE_PATH',
];
$oldEnvironment = [];
foreach ($environmentKeys as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=test');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $authPath);
putenv('MIGRATION_SOURCE_SQLITE_PATH=' . $sourcePath);
putenv('MIGRATION_TARGET_HOST=' . $mysqlConfiguration['DB_HOST']);
putenv('MIGRATION_TARGET_PORT=' . $mysqlConfiguration['DB_PORT']);
putenv('MIGRATION_TARGET_DATABASE=' . $mysqlConfiguration['DB_DATABASE']);
putenv('MIGRATION_TARGET_USERNAME=' . $mysqlConfiguration['DB_USERNAME']);
putenv('MIGRATION_TARGET_PASSWORD=' . $mysqlConfiguration['DB_PASSWORD']);
putenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH=' . $directory . DIRECTORY_SEPARATOR . 'rate-limit.json');
putenv('IEPRCARD_MIGRATION_STATE_PATH=' . $statePath);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'server.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'server.err.log', 'a'],
], $serverPipes, $root);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start the admin migration HTTP server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = migrationE2eRequest($baseUrl . '/admin/migracao-banco.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) {
        throw new RuntimeException('Migration UI must require an authenticated global superadmin.');
    }
    [, , $headers] = migrationE2eRequest($baseUrl . '/admin/login.php');
    $cookie = migrationE2eCookie($headers);
    if ($cookie === null) {
        throw new RuntimeException('Login must issue a protected PHP session before authentication.');
    }
    [, $loginPage] = migrationE2eRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage, $loginToken)) {
        throw new RuntimeException('Login must provide its session-bound CSRF token.');
    }
    $form = http_build_query([
        'email' => 'migration-e2e@example.test',
        'password' => 'integration-test-password',
        'csrf_token' => $loginToken[1],
    ]);
    [$status, , $headers] = migrationE2eRequest($baseUrl . '/admin/login.php', 'POST', $form, $cookie);
    $cookie = migrationE2eCookie($headers);
    if ($status !== 303 || $cookie === null) {
        throw new RuntimeException('Valid admin credentials must establish an authenticated migration session.');
    }

    [$status, $page] = migrationE2eRequest($baseUrl . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Válido')
        || !str_contains((string) $page, '0 · pronto para receber')
        || ($mysqlConfiguration['DB_PASSWORD'] !== '' && str_contains((string) $page, $mysqlConfiguration['DB_PASSWORD']))) {
        throw new RuntimeException('Ready source/destination prechecks must allow the approved migration flow without exposing credentials.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $pageToken)) {
        throw new RuntimeException('Migration queue action must include a CSRF token.');
    }
    $form = http_build_query(['action' => 'queue', 'confirm_copy' => 'yes', 'csrf_token' => $pageToken[1]]);
    [$status] = migrationE2eRequest($baseUrl . '/admin/migracao-banco.php', 'POST', $form, $cookie);
    if ($status !== 303 || (adminMigrationStateRead($statePath)['status'] ?? null) !== 'queued') {
        throw new RuntimeException('A reviewed copy must enqueue one asynchronous job instead of running in the HTTP request.');
    }

    $worker = proc_open([PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'process-admin-migration.php'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $workerPipes, $root);
    if (!is_resource($worker)) {
        throw new RuntimeException('Could not start the migration CLI worker.');
    }
    fclose($workerPipes[0]);
    $workerOutput = stream_get_contents($workerPipes[1]) . stream_get_contents($workerPipes[2]);
    fclose($workerPipes[1]);
    fclose($workerPipes[2]);
    $workerExit = proc_close($worker);
    $state = adminMigrationStateRead($statePath);
    if ($workerExit !== 0 || ($state['status'] ?? null) !== 'completed'
        || ($state['completed_tables'] ?? null) !== 27 || ($state['completed_records'] ?? null) !== 0
        || count($state['result']['tables'] ?? []) !== 27
        || ($mysqlConfiguration['DB_PASSWORD'] !== '' && str_contains($workerOutput, $mysqlConfiguration['DB_PASSWORD']))) {
        throw new RuntimeException('CLI worker must execute and verify all tables without blocking the HTTP request or exposing secrets.');
    }

    [$status, $statusBody] = migrationE2eRequest($baseUrl . '/admin/migracao-status.php', 'GET', null, $cookie);
    $statusData = json_decode((string) $statusBody, true);
    [$pageStatus, $resultPage] = migrationE2eRequest($baseUrl . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 200 || ($statusData['status'] ?? null) !== 'completed'
        || $pageStatus !== 200 || !str_contains((string) $resultPage, 'Resultado da cópia')
        || !str_contains((string) $resultPage, 'Checksum SHA-256')) {
        throw new RuntimeException('Completed status and checksum summary must be available to the authenticated administrator.');
    }
    fwrite(STDOUT, "PASS: admin migration flow authenticated, validated, queued, processed by CLI, and displayed verified results.\n");
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ($oldEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    unset($source, $auth, $target);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($directory);
}

return 'pass';

/** @return array{int, string|null, list<string>} */
function migrationE2eRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null): array
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
        'timeout' => 10,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function migrationE2eCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) return $match[1];
    }
    return null;
}
