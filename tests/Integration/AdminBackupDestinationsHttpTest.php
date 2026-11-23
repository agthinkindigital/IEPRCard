<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/backup_destinations.php';
require_once __DIR__ . '/AdminHttpProfileFixture.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-backup-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPR_INTEGRATION_KEY', 'IEPR_BACKUP_HOSTING_PROFILE', 'IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_BACKUP_HOSTING_PROFILE=shared');
putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$pdo->prepare(
    'INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (\'superadmin\', :name, :email, :password, 1)'
)->execute([
    'name' => 'Backup Admin', 'email' => 'backup-admin@example.test',
    'password' => password_hash('backup-http-password', PASSWORD_DEFAULT),
]);
$adminId = (int) $pdo->lastInsertId();
adminHttpSeedHostedProfile($pdo, $adminId);
$identity = ['id' => $adminId, 'papel_sistema' => 'superadmin'];
$saved = backupDestinationSave($pdo, $identity, [
    'name' => 'Synthetic destination', 'bucket' => 'synthetic-backups', 'region' => 'us-east-1',
    'endpoint' => '', 'path_style' => false, 'access_key' => 'synthetic-access', 'secret_key' => 'synthetic-secret',
]);
$pdo->prepare(
    "UPDATE destinos_backup SET ultimo_teste_status = 'success', ultimo_sucesso_em = :success, "
    . 'retencao_ultima_execucao_em = :retention, retencao_itens_removidos = 3 WHERE id = :id'
)->execute([
    'success' => gmdate('Y-m-d H:i:s', time() - 300),
    'retention' => gmdate('Y-m-d H:i:s', time() - 600),
    'id' => $saved['id'],
]);
$protected = backupDestinationSave($pdo, $identity, [
    'name' => 'Protected destination', 'bucket' => 'protected-backups', 'region' => 'us-east-1',
    'endpoint' => '', 'path_style' => false, 'access_key' => 'protected-access', 'secret_key' => 'protected-secret',
]);
$pdo->prepare(
    "INSERT INTO titular_chaves_backup (destino_id, referencia_hmac, estado, criado_em, atualizado_em) "
    . "VALUES (:destination, :reference, 'ativa', :created, :updated)"
)->execute([
    'destination' => $protected['id'], 'reference' => hash('sha256', 'synthetic-holder'),
    'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
]);
unset($pdo);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) throw new RuntimeException('Could not allocate an HTTP test port.');
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
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
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Could not start the PHP test server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) throw new RuntimeException('Unauthenticated access must redirect to admin login.');
    [$tenantHostStatus] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'GET', null, null, 'tenant.example.test');
    if ($tenantHostStatus !== 404) throw new RuntimeException('The destination admin page must be hidden on tenant hosts.');

    [, , $headers] = backupDestHttpRequest($baseUrl . '/admin/login.php');
    $cookie = backupDestHttpCookie($headers);
    [, $loginPage] = backupDestHttpRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage, $loginCsrf)) {
        throw new RuntimeException('Admin login must provide a session-bound CSRF token.');
    }
    [$status, , $loginHeaders] = backupDestHttpRequest($baseUrl . '/admin/login.php', 'POST', http_build_query([
        'email' => 'backup-admin@example.test', 'password' => 'backup-http-password', 'csrf_token' => $loginCsrf[1],
    ]), $cookie);
    $cookie = backupDestHttpCookie($loginHeaders);
    if ($status !== 303 || $cookie === null) throw new RuntimeException('A valid superadmin must establish an authenticated session.');

    [$status, $page] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Destinos externos')
        || !str_contains((string) $page, 'Protected destination') || !str_contains((string) $page, 'envelopes de chaves')
        || !str_contains((string) $page, 'Retenção de snapshots') || !str_contains((string) $page, '3 snapshots removidos')
        || !str_contains((string) $page, 'Último snapshot') || !str_contains((string) $page, 'há 5 minutos')
        || str_contains((string) $page, 'synthetic-secret') || str_contains((string) $page, 'protected-secret')
        || str_contains((string) $page, 'credentials_cifradas')) {
        throw new RuntimeException('Admin page must render safe destination status without credentials.');
    }
    if (!str_contains((string) $page, '/admin/backup-destinos.php?edit=' . $saved['id'])
        || str_contains((string) $page, '/admin/backup-destinos.php?edit=' . $protected['id'])) {
        throw new RuntimeException('Destinations with holder key envelopes must not expose edit actions.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $pageCsrf)) {
        throw new RuntimeException('Destination page must include CSRF tokens for every operation.');
    }
    $csrf = $pageCsrf[1];
    $badCsrf = http_build_query(['action' => 'save', 'csrf_token' => str_repeat('0', 64), 'name' => 'Rejected']);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $badCsrf, $cookie);
    if ($status !== 403) throw new RuntimeException('Destination changes must reject invalid CSRF tokens.');

    $newForm = http_build_query([
        'csrf_token' => $csrf, 'action' => 'save', 'destination_id' => '0', 'name' => 'Created through HTTP',
        'bucket' => 'created-backups', 'region' => 'us-east-1', 'endpoint' => '', 'path_style' => '0',
        'access_key' => 'http-synthetic-access', 'secret_key' => 'http-synthetic-secret', 'retention_days' => '45',
    ]);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $newForm, $cookie);
    if ($status !== 303) throw new RuntimeException('Superadmin must be able to create an encrypted destination.');
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $createdId = (int) $pdo->query("SELECT id FROM destinos_backup WHERE nome = 'Created through HTTP'")->fetchColumn();
    $created = $pdo->query('SELECT credentials_cifradas, retencao_dias FROM destinos_backup WHERE id = ' . $createdId)->fetch(PDO::FETCH_ASSOC);
    $ciphertext = is_array($created) ? (string) $created['credentials_cifradas'] : '';
    if ($createdId < 1 || $ciphertext === 'http-synthetic-secret' || $ciphertext === ''
        || !is_array($created) || (int) $created['retencao_dias'] !== 45) {
        throw new RuntimeException('Destination secrets must be encrypted and configured retention must persist.');
    }
    $pdo->exec("UPDATE destinos_backup SET ultimo_teste_status = 'success' WHERE id = " . $createdId);
    unset($pdo);

    [$status, $editPage] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php?edit=' . $createdId, 'GET', null, $cookie);
    if ($status !== 200 || preg_match('/name="secret_key"[^>]*value=/', (string) $editPage) === 1
        || str_contains((string) $editPage, 'http-synthetic-secret')) {
        throw new RuntimeException('Saved credentials must never be returned or prefilled.');
    }
    $updateForm = http_build_query([
        'csrf_token' => $csrf, 'action' => 'save', 'destination_id' => (string) $createdId, 'name' => 'Renamed destination',
        'bucket' => 'created-backups', 'region' => 'us-east-1', 'endpoint' => '', 'path_style' => '0',
        'access_key' => '', 'secret_key' => '', 'retention_days' => '45',
    ]);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $updateForm, $cookie);
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $updated = $pdo->query('SELECT credentials_cifradas, ativo FROM destinos_backup WHERE id = ' . $createdId)->fetch(PDO::FETCH_ASSOC);
    if ($status !== 303 || !is_array($updated) || $updated['credentials_cifradas'] !== $ciphertext || (int) $updated['ativo'] !== 0) {
        throw new RuntimeException('Empty secret fields must preserve the existing encrypted credentials and deactivate edited config.');
    }
    $pdo->exec("UPDATE destinos_backup SET ultimo_teste_status = 'success' WHERE id = " . $createdId);

    $toggleOn = http_build_query(['csrf_token' => $csrf, 'action' => 'toggle', 'destination_id' => (string) $createdId, 'enabled' => '1']);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $toggleOn, $cookie);
    if ($status !== 303 || (int) $pdo->query('SELECT ativo FROM destinos_backup WHERE id = ' . $createdId)->fetchColumn() !== 1) {
        throw new RuntimeException('A successfully tested destination must be activatable.');
    }
    $pdo->exec("UPDATE destinos_backup SET ultimo_teste_status = 'success' WHERE id = " . (int) $protected['id']);
    $overLimitToggle = http_build_query([
        'csrf_token' => $csrf, 'action' => 'toggle', 'destination_id' => (string) $protected['id'], 'enabled' => '1',
    ]);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $overLimitToggle, $cookie);
    [$noticeStatus, $limitPage] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'GET', null, $cookie);
    if ($status !== 303 || $noticeStatus !== 200
        || !str_contains((string) $limitPage, 'Hospedagem compartilhada permite um')
        || (int) $pdo->query('SELECT ativo FROM destinos_backup WHERE id = ' . (int) $protected['id'])->fetchColumn() !== 0) {
        throw new RuntimeException('The admin screen must explain that the shared-host active destination limit was reached.');
    }
    $badDelete = http_build_query(['csrf_token' => $csrf, 'action' => 'delete', 'destination_id' => (string) $createdId, 'confirm_name' => 'wrong']);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $badDelete, $cookie);
    if ($status !== 303 || $pdo->query('SELECT 1 FROM destinos_backup WHERE id = ' . $createdId)->fetchColumn() === false) {
        throw new RuntimeException('Destination removal must require exact name confirmation.');
    }
    $protectedDelete = http_build_query([
        'csrf_token' => $csrf, 'action' => 'delete', 'destination_id' => (string) $protected['id'],
        'confirm_name' => 'Protected destination',
    ]);
    [$status] = backupDestHttpRequest($baseUrl . '/admin/backup-destinos.php', 'POST', $protectedDelete, $cookie);
    if ($status !== 303 || $pdo->query('SELECT 1 FROM destinos_backup WHERE id = ' . $protected['id'])->fetchColumn() === false) {
        throw new RuntimeException('A destination containing holder key envelopes must remain protected from removal.');
    }
    unset($pdo);
    fwrite(STDOUT, "PASS: backup destinations admin UI enforces superadmin, CSRF, secret redaction, and protected key envelopes.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    unset($pdo);
    foreach ($oldEnvironment as $key => $value) $value === null ? putenv($key) : putenv($key . '=' . $value);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($directory);
}

return 'pass';

/** @return array{int,string|null,list<string>} */
function backupDestHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null): array
{
    if ($host !== null) {
        $parts = parse_url($url);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) ($parts['port'] ?? 80), $errorCode, $errorMessage, 4);
        if (!is_resource($socket)) return [0, null, []];
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) $path .= '?' . $parts['query'];
        $headers = ['Host: ' . $host, 'Connection: close'];
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($method === 'POST') $headers[] = 'Content-Length: ' . strlen($body ?? '');
        fwrite($socket, $method . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? ''));
        $raw = stream_get_contents($socket);
        fclose($socket);
        $chunks = explode("\r\n\r\n", (string) $raw, 2);
        $lines = explode("\r\n", $chunks[0] ?? '');
        $status = isset($lines[0]) && preg_match('/\s(\d{3})\s/', $lines[0], $match) ? (int) $match[1] : 0;
        return [$status, $chunks[1] ?? null, array_slice($lines, 1)];
    }
    $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 4,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function backupDestHttpCookie(array $headers): ?string
{
    foreach ($headers as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) return $match[1];
    return null;
}
