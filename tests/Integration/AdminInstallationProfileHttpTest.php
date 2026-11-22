<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-install-profile-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'installation.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPRCARD_ADMIN_RATE_LIMIT_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH=' . $directory . DIRECTORY_SEPARATOR . 'rate-limit.json');

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$tenant = $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', 'Sede de teste', 'sede-teste', 'ativo')");
$tenant->execute();
$tenantId = (int) $pdo->lastInsertId();
$unit = $pdo->prepare("INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) VALUES (:parent, 'subcliente', 'Unidade de teste', 'unidade-teste', 'ativo')");
$unit->execute(['parent' => $tenantId]);
$admin = $pdo->prepare("INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES ('superadmin', 'Admin de teste', 'admin-install@example.test', :hash, 1)");
$admin->execute(['hash' => password_hash('admin-profile-test-password', PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();
unset($pdo);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) throw new RuntimeException('Could not allocate an HTTP test port.');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Could not start the PHP test server.');

try {
    $base = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = installationProfileHttpRequest($base . '/admin/login.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 200) throw new RuntimeException('The existing superadmin must be able to sign in before first installation setup.');
    [$status, , $anonymousHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php');
    if ($status !== 303 || installationProfileHttpHeader($anonymousHeaders, 'Location') !== '/admin/login.php') {
        throw new RuntimeException('Installation settings must require an authenticated global superadmin.');
    }
    [$status, $loginPage, $headers] = installationProfileHttpRequest($base . '/admin/login.php');
    $cookie = installationProfileHttpCookie($headers);
    if ($status !== 200 || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage, $loginToken)) {
        throw new RuntimeException('Admin login must expose a CSRF token for installation setup.');
    }
    [$status, , $loginHeaders] = installationProfileHttpRequest($base . '/admin/login.php', 'POST', http_build_query([
        'email' => 'admin-install@example.test', 'password' => 'admin-profile-test-password', 'csrf_token' => $loginToken[1],
    ]), $cookie);
    $cookie = installationProfileHttpCookie($loginHeaders) ?? $cookie;
    if ($status !== 303 || installationProfileHttpHeader($loginHeaders, 'Location') !== '/admin/instalacao.php') {
        throw new RuntimeException('A newly authenticated superadmin must land on installation setup when no profile exists.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 303) throw new RuntimeException('Protected admin routes must remain blocked until the installation profile is saved.');
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Modo de hospedagem')
        || !str_contains((string) $page, 'Hospedado') || !str_contains((string) $page, 'Local Host')
        || !str_contains((string) $page, 'Sede de teste')
        || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $formToken)) {
        throw new RuntimeException('First setup must render the approved mode choices, active Sedes, and CSRF protection.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => str_repeat('0', 64), 'modo' => 'local_host',
        'tenant_local_id' => (string) $tenantId, 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 403) throw new RuntimeException('Installation profile changes must reject invalid CSRF tokens.');

    [$status, , $invalidHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => $formToken[1], 'modo' => 'hospedado',
        'hostname_plataforma' => 'bad/path', 'dominio_base' => 'ieprcard.example', 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($invalidHeaders, 'Location') !== '/admin/instalacao.php?erro=1') {
        throw new RuntimeException('Invalid profile submissions must return to the form with an accessible error.');
    }
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?erro=1', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'bad/path') || !str_contains((string) $page, 'Não foi possível salvar')) {
        throw new RuntimeException('The form must preserve submitted values and announce validation errors.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM configuracoes_instalacao')->fetchColumn() !== 0) {
        throw new RuntimeException('Invalid profile submissions must leave the active configuration unchanged.');
    }
    unset($pdo);

    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $savedToken)) {
        throw new RuntimeException('The installation form must keep a CSRF token after validation errors.');
    }
    [$status, , $savedHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => $savedToken[1], 'modo' => 'local_host',
        'tenant_local_id' => (string) $tenantId, 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($savedHeaders, 'Location') !== '/admin/migracao-banco.php?salvo=1') {
        throw new RuntimeException('A valid Local Host profile must be saved through the HTTP form.');
    }
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?salvo=1', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Local Host ativo')
        || !str_contains((string) $page, '1 unidade ativa')
        || !str_contains((string) $page, 'id="hosted-fields" hidden')) {
        throw new RuntimeException('Saved Local Host must show its selected Sede and unit count without hosted hostname fields.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 200) throw new RuntimeException('Saving an installation profile must release the protected superadmin routes.');

    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $record = $pdo->query('SELECT modo, tenant_local_id, ator_usuario_id FROM configuracoes_instalacao ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($record) || $record['modo'] !== 'local_host' || (int) $record['tenant_local_id'] !== $tenantId
        || (int) $record['ator_usuario_id'] !== $adminId) {
        throw new RuntimeException('The HTTP form must persist the authenticated actor and the validated profile.');
    }
    unset($pdo);
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($directory);
    foreach ($oldEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
}

fwrite(STDOUT, "PASS: installation mode setup is authenticated, CSRF-protected, auditable, and blocks routes until saved.\n");
return 'pass';

/** @return array{int,string|null,list<string>} */
function installationProfileHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null): array
{
    $headers = ['Accept: text/html'];
    if ($body !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s([0-9]{3})\s/', $responseHeaders[0], $matches)) $status = (int) $matches[1];
    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @param list<string> $headers */
function installationProfileHttpHeader(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
    }
    return null;
}

/** @param list<string> $headers */
function installationProfileHttpCookie(array $headers): ?string
{
    $header = installationProfileHttpHeader($headers, 'Set-Cookie');
    if ($header === null || !preg_match('/^(IEPRCARDADMIN=[^;]+)/', $header, $matches)) return null;
    return $matches[1];
}
