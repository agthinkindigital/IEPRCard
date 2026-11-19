<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-tenant-domains-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$databasePath = $directory . DIRECTORY_SEPARATOR . 'tenant.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
$suffix = bin2hex(random_bytes(4));
$slug = 'tenant-' . $suffix;
$tenant = $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', :name, :slug, 'ativo')");
$tenant->execute(['name' => 'Instituição HTTP ' . $suffix, 'slug' => $slug]);
$tenantId = (int) $pdo->lastInsertId();
$otherTenant = $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', 'Outro cliente', :slug, 'ativo')");
$otherTenant->execute(['slug' => 'other-' . $suffix]);
$otherTenantId = (int) $pdo->lastInsertId();
$userInsert = $pdo->prepare('INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (:tenant, :role, :name, :email, :password, 1)');
$userInsert->execute([
    'tenant' => $tenantId,
    'role' => 'titular_cliente',
    'name' => 'Cliente Master',
    'email' => 'owner-' . $suffix . '@example.test',
    'password' => password_hash('domain-http-password', PASSWORD_DEFAULT),
]);
$userId = (int) $pdo->lastInsertId();
$userInsert->execute([
    'tenant' => $tenantId,
    'role' => 'subacesso',
    'name' => 'Acesso delegado',
    'email' => 'delegate-' . $suffix . '@example.test',
    'password' => password_hash('domain-http-password', PASSWORD_DEFAULT),
]);
$delegateUserId = (int) $pdo->lastInsertId();
$otherDomain = $pdo->prepare("INSERT INTO dominios_tenant (tenant_id, hostname, estado) VALUES (:tenant, :hostname, 'tls_pendente')");
$otherDomain->execute(['tenant' => $otherTenantId, 'hostname' => 'private-' . $suffix . '.example.test']);
unset($pdo);

$previousSessionName = session_name();
$previousSessionId = session_id();
$previousSessionSavePath = session_save_path();
$previousSessionSettings = [];
foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_trans_sid', 'session.gc_maxlifetime'] as $setting) {
    $previousSessionSettings[$setting] = ini_get($setting);
}
$previousCookieParameters = session_get_cookie_params();
$previousTenantCookie = $_COOKIE['IEPRCARDTENANT'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$hadTenantCookie = array_key_exists('IEPRCARDTENANT', $_COOKIE);
$csrf = bin2hex(random_bytes(32));
session_save_path($directory);
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) {
    throw new RuntimeException('Could not start the owner test session.');
}
$sessionId = session_id();
$_SESSION = [
    'tenant_user_id' => $userId,
    'tenant_id' => $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $csrf,
];
session_write_close();
session_id('');
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) {
    throw new RuntimeException('Could not start the delegated test session.');
}
$delegateSessionId = session_id();
$_SESSION = [
    'tenant_user_id' => $delegateUserId,
    'tenant_id' => $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'subacesso',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => bin2hex(random_bytes(32)),
];
session_write_close();

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate a tenant-domain HTTP test port.');
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
    if (!extension_loaded('pdo_sqlite') && is_file(rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll')) {
        $extensionArguments = ['-d', 'extension=pdo_sqlite'];
    }
}
$process = proc_open(array_merge(
    [PHP_BINARY],
    $extensionArguments,
    ['-d', 'session.save_path=' . $directory, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public']
), [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the tenant-domain HTTP test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, null, 'unknown.ieprcard.test');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 404) {
        throw new RuntimeException('Unknown tenant hosts must not reach the domain management screen.');
    }

    [$status] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, null, $slug . '.ieprcard.test', 'invalid-session');
    if ($status !== 401) {
        throw new RuntimeException('A tenant without an authenticated session must not see its domain screen.');
    }

    [$status, $delegateBody] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, null, $slug . '.ieprcard.test', $delegateSessionId);
    if ($status !== 403) {
        throw new RuntimeException('Delegated tenant users must not manage custom domains: '
            . json_encode(['status' => $status, 'body' => $delegateBody], JSON_THROW_ON_ERROR));
    }

    $cookie = 'IEPRCARDTENANT=' . $sessionId;
    [$status, $body] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, $cookie, $slug . '.ieprcard.test');
    if ($status !== 200 || !str_contains((string) $body, 'Domínios próprios')
        || str_contains((string) $body, 'private-' . $suffix . '.example.test')
        || str_contains((string) $body, 'Outro cliente')) {
        throw new RuntimeException('The domain screen must render only domains scoped to the authenticated tenant.');
    }

    $form = http_build_query(['action' => 'create', 'hostname' => 'Carteira.Exemplo.Test', 'csrf_token' => substr($csrf, 1)]);
    [$status] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'POST', $form, $cookie, $slug . '.ieprcard.test');
    if ($status !== 403) {
        throw new RuntimeException('Domain creation must reject an invalid CSRF token.');
    }

    $form = http_build_query(['action' => 'create', 'hostname' => 'Carteira.Exemplo.Test', 'csrf_token' => $csrf]);
    [$status, , $headers] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'POST', $form, $cookie, $slug . '.ieprcard.test');
    if ($status !== 303 || !str_contains(implode("\n", $headers), '/tenant/dominios.php')) {
        throw new RuntimeException('A valid domain creation must use post-redirect-get.');
    }

    [$status, $body, $headers] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, $cookie, $slug . '.ieprcard.test');
    if ($status !== 200 || !str_contains((string) $body, 'Aguardando DNS')
        || !str_contains((string) $body, '_ieprcard-verification.carteira.exemplo.test')
        || !str_contains((string) $body, 'data-copy-target="txt-name"')
        || !str_contains((string) $body, 'data-copy-target="txt-value"')
        || !str_contains(implode("\n", $headers), 'Cache-Control: no-store, private')
        || !preg_match('/ieprcard-verification=([a-f0-9]{64})/', (string) $body, $tokenMatch)) {
        throw new RuntimeException('The newly created domain must show its one-time TXT challenge.');
    }
    $token = $tokenMatch[1];
    [$status, $reloadedBody] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, $cookie, $slug . '.ieprcard.test');
    if ($status !== 200 || str_contains((string) $reloadedBody, $token)
        || str_contains((string) $reloadedBody, 'data-copy-target="txt-value"')) {
        throw new RuntimeException('The TXT secret must not be rendered again after its one-time display.');
    }

    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $domain = $pdo->query("SELECT id, tenant_id, estado, token_verificacao_hash FROM dominios_tenant WHERE hostname = 'carteira.exemplo.test'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($domain) || (int) $domain['tenant_id'] !== $tenantId
        || $domain['estado'] !== 'pendente_verificacao'
        || !hash_equals((string) $domain['token_verificacao_hash'], hash('sha256', $token))) {
        throw new RuntimeException('Domain creation must persist only a tenant-bound token hash.');
    }
    $verifyForm = http_build_query(['action' => 'verify', 'domain_id' => (string) $domain['id'], 'csrf_token' => $csrf]);
    [$status, $verifyBody] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'POST', $verifyForm, $cookie, $slug . '.ieprcard.test');
    if ($status !== 303) {
        throw new RuntimeException('DNS verification must be handled through the authenticated POST endpoint.');
    }
    [$status, $verifyBody] = tenantDomainHttpRequest($baseUrl . '/tenant/dominios.php', 'GET', null, $cookie, $slug . '.ieprcard.test');
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $pendingState = $pdo->query("SELECT estado FROM dominios_tenant WHERE id = " . (int) $domain['id'])->fetchColumn();
    if ($status !== 200 || !str_contains((string) $verifyBody, 'Aguardando DNS') || $pendingState !== 'pendente_verificacao') {
        throw new RuntimeException('A failed DNS lookup must remain pending and must not activate the domain.');
    }

    fwrite(STDOUT, "PASS: tenant domain screen enforces host/session/CSRF scope and one-time TXT display.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    unset($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_save_path($previousSessionSavePath);
    session_name($previousSessionName);
    session_id($previousSessionId);
    foreach ($previousSessionSettings as $setting => $value) {
        if (is_string($value)) ini_set($setting, $value);
    }
    session_set_cookie_params($previousCookieParameters);
    if ($hadTenantCookie) {
        $_COOKIE['IEPRCARDTENANT'] = $previousTenantCookie;
    } else {
        unset($_COOKIE['IEPRCARDTENANT']);
    }
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

/** @return array{int,string|null,list<string>} */
function tenantDomainHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null, ?string $session = null): array
{
    $urlParts = parse_url($url);
    $port = (int) ($urlParts['port'] ?? 80);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null, []];
    $path = (string) ($urlParts['path'] ?? '/');
    $headers = [
        $method . ' ' . $path . " HTTP/1.1",
        'Host: ' . ($host ?? '127.0.0.1'),
        'Connection: close',
        'Accept: text/html',
    ];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($session !== null) $headers[] = 'Cookie: IEPRCARDTENANT=' . $session;
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen((string) $body);
    }
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? ''));
    $response = stream_get_contents($socket);
    fclose($socket);
    $responseParts = explode("\r\n\r\n", (string) $response, 2);
    $responseLines = explode("\r\n", $responseParts[0] ?? '');
    $status = isset($responseLines[0]) && preg_match('/\s(\d{3})\s/', $responseLines[0], $match) ? (int) $match[1] : 0;
    return [$status, $responseParts[1] ?? null, array_slice($responseLines, 1)];
}
