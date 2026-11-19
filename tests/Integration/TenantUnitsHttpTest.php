<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-tenant-units-' . bin2hex(random_bytes(6));
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
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(4));
$plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
$plan->execute(['name' => 'Tenant units HTTP ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
$flag->execute(['plan' => $planId, 'key' => 'subcliente.criar']);
$tenantSlug = 'tenant-' . $suffix;
$rootInsert = $pdo->prepare(
    "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_subclientes) "
    . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 3)"
);
$rootInsert->execute(['plan' => $planId, 'name' => 'Instituição HTTP ' . $suffix, 'slug' => $tenantSlug]);
$tenantId = (int) $pdo->lastInsertId();
$otherInsert = $pdo->prepare(
    "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', 'Outro cliente', :slug, 'ativo')"
);
$otherInsert->execute(['slug' => 'other-' . $suffix]);
$otherTenantId = (int) $pdo->lastInsertId();
$unitInsert = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, permissoes_unidade_json) "
    . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', '[]')"
);
$unitInsert->execute(['parent' => $tenantId, 'name' => 'Unidade Centro', 'slug' => 'center-' . $suffix]);
$unitInsert->execute(['parent' => $otherTenantId, 'name' => 'Unidade sigilosa', 'slug' => 'secret-' . $suffix]);
$ownerInsert = $pdo->prepare(
    'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
    . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
);
$ownerInsert->execute([
    'tenant' => $tenantId,
    'role' => 'titular_cliente',
    'name' => 'Cliente Master',
    'email' => 'owner-' . $suffix . '@example.test',
    'password' => password_hash('units-http-password', PASSWORD_DEFAULT),
]);
$ownerId = (int) $pdo->lastInsertId();
$ownerEmail = 'delegate-' . $suffix . '@example.test';
$ownerInsert->execute([
    'tenant' => $tenantId,
    'role' => 'subacesso',
    'name' => 'Operador delegado',
    'email' => $ownerEmail,
    'password' => password_hash('units-http-password', PASSWORD_DEFAULT),
]);
$delegateId = (int) $pdo->lastInsertId();
unset($pdo);

$previousSessionName = session_name();
$previousSessionId = session_id();
$previousSessionSavePath = session_save_path();
$previousSessionSettings = [];
foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_trans_sid', 'session.gc_maxlifetime'] as $setting) {
    $previousSessionSettings[$setting] = ini_get($setting);
}
$previousCookieParameters = session_get_cookie_params();
$hadTenantCookie = array_key_exists('IEPRCARDTENANT', $_COOKIE);
$previousTenantCookie = $_COOKIE['IEPRCARDTENANT'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_save_path($directory);
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) {
    throw new RuntimeException('Could not start the unit-owner test session.');
}
$ownerSessionId = session_id();
$ownerCsrf = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $ownerId,
    'tenant_id' => $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $ownerCsrf,
];
session_write_close();
session_id('');
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) {
    throw new RuntimeException('Could not start the delegated unit test session.');
}
$delegateSessionId = session_id();
$_SESSION = [
    'tenant_user_id' => $delegateId,
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
    throw new RuntimeException('Could not allocate a tenant-units HTTP test port.');
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
    if (is_file(rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll')) {
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
    throw new RuntimeException('Could not start the tenant-units HTTP test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port . '/tenant/unidades.php';
    $host = $tenantSlug . '.ieprcard.test';
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = tenantUnitsHttpRequest($baseUrl, 'GET', null, null, 'unknown.ieprcard.test');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 404) {
        throw new RuntimeException('Unknown tenant hosts must not reach the units screen.');
    }
    [$status] = tenantUnitsHttpRequest($baseUrl, 'GET', null, null, $host, 'invalid-session');
    if ($status !== 401) {
        throw new RuntimeException('An unauthenticated tenant must not see its units screen.');
    }
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, null, $host, $delegateSessionId);
    if ($status !== 403) {
        throw new RuntimeException('Delegated tenant users must not manage units.');
    }

    $ownerCookie = 'IEPRCARDTENANT=' . $ownerSessionId;
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, $ownerCookie, $host);
    if ($status !== 200 || !str_contains((string) $body, 'Unidades')
        || !str_contains((string) $body, 'Unidade Centro')
        || str_contains((string) $body, 'Unidade sigilosa')
        || !str_contains((string) $body, '1') || !str_contains((string) $body, '2')) {
        throw new RuntimeException('The units screen must show only the current tenant and its quota.');
    }

    $invalidCsrf = http_build_query([
        'action' => 'create', 'nome_organizacao' => 'Unidade Norte', 'slug_subdominio' => 'north-' . $suffix,
        'tenant_id' => $otherTenantId, 'csrf_token' => substr($ownerCsrf, 1),
    ]);
    [$status] = tenantUnitsHttpRequest($baseUrl, 'POST', $invalidCsrf, $ownerCookie, $host);
    if ($status !== 403) {
        throw new RuntimeException('Unit creation must reject invalid CSRF.');
    }

    $create = static function (string $name, string $slug, ?int $targetTenant = null) use ($baseUrl, $ownerCookie, $host, $ownerCsrf): array {
        $form = [
            'action' => 'create', 'nome_organizacao' => $name, 'slug_subdominio' => $slug, 'csrf_token' => $ownerCsrf,
        ];
        if ($targetTenant !== null) $form['tenant_id'] = (string) $targetTenant;
        return tenantUnitsHttpRequest($baseUrl, 'POST', http_build_query($form), $ownerCookie, $host);
    };

    [$status, $createBody] = $create('Unidade Norte', 'north-' . $suffix, $otherTenantId);
    if ($status !== 303) {
        throw new RuntimeException('A valid unit creation must use post-redirect-get and ignore a forged tenant ID: '
            . json_encode(['status' => $status, 'body' => $createBody], JSON_THROW_ON_ERROR));
    }
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, $ownerCookie, $host);
    if ($status !== 200 || !str_contains((string) $body, 'Unidade Norte')
        || !str_contains((string) $body, 'north-' . $suffix . '.' . $tenantSlug . '.ieprcard.test')
        || !str_contains((string) $body, 'Nenhum responsável')) {
        throw new RuntimeException('The created unit must be listed under the authenticated Sede: '
            . json_encode(['status' => $status, 'has_name' => str_contains((string) $body, 'Unidade Norte'),
                'has_host' => str_contains((string) $body, 'north-' . $tenantSlug . '.ieprcard.test'),
                'has_no_responsible' => str_contains((string) $body, 'Nenhum responsável')], JSON_THROW_ON_ERROR));
    }

    [$status] = $create('Unidade duplicada', 'north-' . $suffix);
    if ($status !== 303) {
        throw new RuntimeException('Duplicate slugs must return a safe field-level notice.');
    }
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, $ownerCookie, $host);
    if ($status !== 200 || !str_contains((string) $body, 'Subdomínio indisponível')
        || str_contains((string) $body, 'Outro cliente')) {
        throw new RuntimeException('Duplicate slug errors must not disclose another tenant.');
    }

    [$status] = $create('Unidade Sul', 'south-' . $suffix);
    if ($status !== 303) {
        throw new RuntimeException('A second unit must be created before the configured limit is reached.');
    }
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, $ownerCookie, $host);
    if ($status !== 200 || !str_contains((string) $body, '3')
        || !str_contains((string) $body, 'Limite de unidades atingido')) {
        throw new RuntimeException('The units screen must update counts and disable creation at the quota: '
            . json_encode(['status' => $status, 'has_three' => str_contains((string) $body, '3'),
                'has_limit_message' => str_contains((string) $body, 'Limite de unidades atingido')], JSON_THROW_ON_ERROR));
    }
    [$status] = $create('Unidade Leste', 'east-' . $suffix);
    if ($status !== 303) {
        throw new RuntimeException('A direct request must not bypass the unit quota.');
    }
    [$status, $body] = tenantUnitsHttpRequest($baseUrl, 'GET', null, $ownerCookie, $host);
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $unitCount = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente'");
    $unitCount->execute(['tenant' => $tenantId]);
    if ($status !== 200 || !str_contains((string) $body, 'Limite de unidades atingido')
        || (int) $unitCount->fetchColumn() !== 3) {
        throw new RuntimeException('The backend must keep the unit count at the configured limit.');
    }

    fwrite(STDOUT, "PASS: tenant units screen enforces session scope, CSRF, duplicate slug handling and quota limits.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    unset($pdo);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($previousSessionSavePath);
    session_name($previousSessionName);
    session_id($previousSessionId);
    foreach ($previousSessionSettings as $setting => $value) {
        if (is_string($value)) ini_set($setting, $value);
    }
    session_set_cookie_params($previousCookieParameters);
    if ($hadTenantCookie) $_COOKIE['IEPRCARDTENANT'] = $previousTenantCookie;
    else unset($_COOKIE['IEPRCARDTENANT']);
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
function tenantUnitsHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null, ?string $host = null, ?string $session = null): array
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
