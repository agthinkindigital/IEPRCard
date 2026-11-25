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
    if ($status !== 200) throw new RuntimeException('The authenticated superadmin must access global admin routes before choosing a Sede or installation profile.');
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
        'tenant_local_id' => '', 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($savedHeaders, 'Location') !== '/admin/instalacao.php?salvo=1') {
        throw new RuntimeException('A valid Local Host profile must be saveable before any Sede exists.');
    }
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?salvo=1', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Local Host ativo')
        || !str_contains((string) $page, 'Nenhuma Sede selecionada')
        || !str_contains((string) $page, 'O superadmin continua no painel global')
        || !str_contains((string) $page, 'id="hosted-fields" hidden')) {
        throw new RuntimeException('Saved Local Host without a Sede must keep global administration available.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/migracao-banco.php', 'GET', null, $cookie);
    if ($status !== 200) throw new RuntimeException('Saving an installation profile must release the protected superadmin routes.');

    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'GET', null, $cookie);
    if ($status !== 200 || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $hostedToken)) {
        throw new RuntimeException('The saved profile must remain editable by the authenticated superadmin.');
    }
    [$status, , $confirmationHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => $hostedToken[1], 'modo' => 'hospedado',
        'hostname_plataforma' => 'admin.ieprcard.example', 'dominio_base' => 'ieprcard.example',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($confirmationHeaders, 'Location') !== '/admin/instalacao.php?erro=1') {
        throw new RuntimeException('Changing installation mode must require explicit impact confirmation.');
    }
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?erro=1', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Confirme que compreendeu')
        || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $retryToken)) {
        throw new RuntimeException('A missing impact confirmation must be announced and remain retryable.');
    }
    [$status, , $invalidUpdateHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => $retryToken[1], 'modo' => 'hospedado',
        'hostname_plataforma' => 'bad"><script>alert(1)</script>', 'dominio_base' => 'ieprcard.example', 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($invalidUpdateHeaders, 'Location') !== '/admin/instalacao.php?erro=1') {
        throw new RuntimeException('An invalid reconfiguration must return to the form without replacing the active profile.');
    }
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?erro=1', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, '&lt;script&gt;alert(1)&lt;/script&gt;')
        || str_contains((string) $page, '<script>alert(1)</script>')
        || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $invalidRetryToken)) {
        throw new RuntimeException('An invalid reconfiguration must preserve its value and remain retryable.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $currentProfile = $pdo->query('SELECT modo, tenant_local_id FROM configuracoes_instalacao ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($currentProfile) || $currentProfile['modo'] !== 'local_host' || $currentProfile['tenant_local_id'] !== null
        || (int) $pdo->query('SELECT COUNT(*) FROM configuracoes_instalacao')->fetchColumn() !== 1) {
        throw new RuntimeException('A failed reconfiguration must preserve the Local Host profile without a Sede.');
    }
    unset($pdo);

    [$status, , $hostedHeaders] = installationProfileHttpRequest($base . '/admin/instalacao.php', 'POST', http_build_query([
        'action' => 'save', 'csrf_token' => $invalidRetryToken[1], 'modo' => 'hospedado',
        'hostname_plataforma' => 'admin.ieprcard.example', 'dominio_base' => 'ieprcard.example', 'confirm_impact' => '1',
    ]), $cookie);
    if ($status !== 303 || installationProfileHttpHeader($hostedHeaders, 'Location') !== '/admin/instalacao.php?salvo=1') {
        throw new RuntimeException('A valid Hosted profile must be saveable through the HTTP form.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/instalacao.php?salvo=1', 'GET', null, $cookie);
    if ($status !== 404) throw new RuntimeException('The Hosted profile must stop serving the administrative pages on an unconfigured Host.');
    [$status, $page] = installationProfileHttpRequest($base . '/admin/instalacao.php?salvo=1', 'GET', null, $cookie, 'admin.ieprcard.example');
    if ($status !== 200 || !str_contains((string) $page, 'Hospedado ativo')
        || !str_contains((string) $page, 'value="admin.ieprcard.example"')
        || !str_contains((string) $page, 'value="ieprcard.example"')) {
        throw new RuntimeException('A saved Hosted profile must show its normalized platform and base hostnames on the configured Host.');
    }
    [$status] = installationProfileHttpRequest($base . '/admin/migracao-banco.php', 'GET', null, $cookie, 'admin.ieprcard.example');
    if ($status !== 200) throw new RuntimeException('Hosted mode must release protected admin routes on the configured platform Host.');

    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $record = $pdo->query('SELECT modo, tenant_local_id, ator_usuario_id FROM configuracoes_instalacao ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($record) || $record['modo'] !== 'hospedado' || $record['tenant_local_id'] !== null
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

fwrite(STDOUT, "PASS: installation mode setup is authenticated and auditable; global admin access works without a Sede.\n");
return 'pass';

/** @return array{int,string|null,list<string>} */
function installationProfileHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null): array
{
    $headers = ['Accept: text/html'];
    if ($host !== null) $headers[] = 'Host: ' . $host;
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
