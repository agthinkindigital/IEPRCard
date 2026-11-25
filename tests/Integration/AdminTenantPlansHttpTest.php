<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once __DIR__ . '/AdminHttpProfileFixture.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-admin-plans-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'admin.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'TENANT_BASE_DOMAIN'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('TENANT_BASE_DOMAIN=tenant.example.test');

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
    . "VALUES ('superadmin', 'Plans Admin', 'plans-admin@example.test', :password, 1)"
)->execute(['password' => password_hash('plans-http-password', PASSWORD_DEFAULT)]);
$adminId = (int) $pdo->lastInsertId();
adminHttpSeedHostedProfile($pdo, $adminId);
unset($pdo);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) throw new RuntimeException('Could not allocate a test port.');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($server)) throw new RuntimeException('Could not start the admin plans HTTP server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = adminTenantPlansHttpRequest($baseUrl . '/admin/planos.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) throw new RuntimeException('Plan administration must require a global superadmin.');
    [$tenantHostStatus] = adminTenantPlansHttpRequest($baseUrl . '/admin/planos.php', 'GET', null, null, '127.0.0.1', 'tenant.example.test');
    if ($tenantHostStatus !== 404) throw new RuntimeException('Plan administration must stay hidden on tenant hosts.');

    [, , $loginHeaders] = adminTenantPlansHttpRequest($baseUrl . '/admin/login.php');
    $cookie = adminTenantPlansHttpCookie($loginHeaders);
    [, $loginPage] = adminTenantPlansHttpRequest($baseUrl . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $loginPage, $csrf)) {
        throw new RuntimeException('The admin login must provide a CSRF token.');
    }
    [$status, , $loginHeaders] = adminTenantPlansHttpRequest($baseUrl . '/admin/login.php', 'POST', http_build_query([
        'email' => 'plans-admin@example.test', 'password' => 'plans-http-password', 'csrf_token' => $csrf[1],
    ]), $cookie);
    $cookie = adminTenantPlansHttpCookie($loginHeaders);
    if ($status !== 303 || $cookie === null) throw new RuntimeException('The superadmin must be able to authenticate.');

    [$status, $page] = adminTenantPlansHttpRequest($baseUrl . '/admin/planos.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Planos de clientes')
        || !str_contains((string) $page, 'Nenhum plano de cliente cadastrado')
        || !str_contains((string) $page, 'limit_subclientes') && !str_contains((string) $page, 'limite_subclientes')
        || str_contains((string) $page, 'Asaas') || str_contains((string) $page, 'Mercado Pago')) {
        throw new RuntimeException('The plan page must show the empty state, quota fields, and no payment UI.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $pageCsrf)) {
        throw new RuntimeException('Plan mutations must use the authenticated CSRF token.');
    }
    [$badCsrf] = adminTenantPlansHttpRequest($baseUrl . '/admin/planos.php', 'POST', http_build_query([
        'action' => 'create', 'csrf_token' => str_repeat('0', 64),
    ]), $cookie);
    if ($badCsrf !== 403) throw new RuntimeException('Plan creation must reject an invalid CSRF token.');

    $form = [
        'action' => 'create', 'csrf_token' => $pageCsrf[1], 'nome' => 'Igreja piloto',
        'descricao' => 'Plano inicial para emissão local', 'ativo' => '1',
        'permissoes' => ['titular.ver', 'titular.criar', 'carteirinha.gerar', 'subcliente.criar', 'usuario.criar'],
        'cotas' => [
            'limite_subclientes' => '3', 'limite_usuarios_equipe' => '5',
            'limite_titulares' => '500', 'limite_carteirinhas' => '1000',
        ],
    ];
    [$status] = adminTenantPlansHttpRequest($baseUrl . '/admin/planos.php', 'POST', http_build_query($form), $cookie);
    if ($status !== 303) throw new RuntimeException('The authenticated superadmin must be able to create a customer plan.');
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    if ((int) $pdo->query("SELECT COUNT(*) FROM planos WHERE nome = 'Igreja piloto'")->fetchColumn() !== 1) {
        throw new RuntimeException('The submitted plan must be persisted.');
    }
    [$status, $sedesPage] = adminTenantPlansHttpRequest($baseUrl . '/admin/sedes.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $sedesPage, 'Sedes e acessos')
        || !str_contains((string) $sedesPage, 'Criar Sede e primeiro responsável')
        || !str_contains((string) $sedesPage, 'Convidar para criar uma Sede')
        || !str_contains((string) $sedesPage, '72 horas')
        || str_contains((string) $sedesPage, 'checkout')) {
        throw new RuntimeException('The global Sede screen must show approved provision paths without payment implementation.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $sedesPage, $sedesCsrf)) {
        throw new RuntimeException('Sede and access mutations must use CSRF protection.');
    }
    [$badSedeCsrf] = adminTenantPlansHttpRequest($baseUrl . '/admin/sedes.php', 'POST', http_build_query([
        'action' => 'create_invite', 'csrf_token' => str_repeat('0', 64), 'plano_id' => '1', 'email_convite' => 'owner@example.test',
    ]), $cookie);
    if ($badSedeCsrf !== 403) throw new RuntimeException('Sede provisioning must reject an invalid CSRF token.');
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ($oldEnvironment as $key => $value) putenv($value === null ? $key : $key . '=' . $value);
}

fwrite(STDOUT, "PASS: superadmin plan UI is authenticated, CSRF-protected, and creates plans with no payment flow.\n");

/** @return array{0:int,1:?string,2:list<string>} */
function adminTenantPlansHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, string $connectHost = '127.0.0.1', string $httpHost = '127.0.0.1'): array
{
    $parts = parse_url($url);
    $socket = stream_socket_client('tcp://' . $connectHost . ':' . (int) $parts['port'], $errorCode, $errorMessage, 2);
    if (!is_resource($socket)) return [0, null, []];
    $requestPath = (string) ($parts['path'] ?? '/');
    $headers = ['Host: ' . $httpHost, 'Connection: close'];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body ?? '');
    }
    fwrite($socket, $method . ' ' . $requestPath . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? ''));
    $raw = stream_get_contents($socket);
    fclose($socket);
    $chunks = explode("\r\n\r\n", (string) $raw, 2);
    $lines = explode("\r\n", $chunks[0] ?? '');
    $status = isset($lines[0]) && preg_match('/\s(\d{3})\s/', $lines[0], $match) ? (int) $match[1] : 0;

    return [$status, $chunks[1] ?? null, array_slice($lines, 1)];
}

/** @param list<string> $headers */
function adminTenantPlansHttpCookie(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) return $match[1];
    }

    return null;
}
