<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$quotaHttpRoot = dirname(__DIR__, 2);
$quotaHttpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-tenant-quotas-' . bin2hex(random_bytes(6));
mkdir($quotaHttpDirectory, 0700, true);
$quotaHttpDatabase = $quotaHttpDirectory . DIRECTORY_SEPARATOR . 'tenant.sqlite';
$quotaHttpOldEnv = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME'] as $key) {
    $value = getenv($key);
    $quotaHttpOldEnv[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $quotaHttpDatabase);
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $quotaHttpDatabase]);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
$plan->execute(['name' => 'Quota HTTP ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
$flag->execute(['plan' => $planId, 'key' => 'cota.distribuir']);
$rootInsert = $pdo->prepare(
    "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_subclientes, "
    . 'limite_usuarios_equipe, limite_titulares, limite_carteirinhas) '
    . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 5, 20, 100, 1000)"
);
$slug = 'quotas-' . $suffix;
$rootInsert->execute(['plan' => $planId, 'name' => 'Sede Quotas ' . $suffix, 'slug' => $slug]);
$tenantId = (int) $pdo->lastInsertId();
$unitInsert = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe, "
    . 'limite_titulares, limite_carteirinhas, consumo_carteirinhas) '
    . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', 8, 40, 400, 20)"
);
$unitInsert->execute(['parent' => $tenantId, 'name' => 'Unidade Visível', 'slug' => 'visible-' . $suffix]);
$unitId = (int) $pdo->lastInsertId();
$foreignRoot = $pdo->prepare(
    "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', 'Outra Sede', :slug, 'ativo')"
);
$foreignRoot->execute(['slug' => 'foreign-' . $suffix]);
$foreignTenantId = (int) $pdo->lastInsertId();
$foreignUnit = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe, "
    . 'limite_titulares, limite_carteirinhas) '
    . "VALUES (:parent, 'subcliente', 'Unidade Segreda', :slug, 'ativo', 7, 30, 300)"
);
$foreignUnit->execute(['parent' => $foreignTenantId, 'slug' => 'secret-' . $suffix]);
$foreignUnitId = (int) $pdo->lastInsertId();
$user = $pdo->prepare(
    'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
    . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
);
$user->execute([
    'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Master Quotas',
    'email' => 'quota-owner-' . $suffix . '@example.test', 'password' => password_hash('quota-test-password', PASSWORD_DEFAULT),
]);
$ownerId = (int) $pdo->lastInsertId();
$user->execute([
    'tenant' => $tenantId, 'role' => 'subacesso', 'name' => 'Delegado',
    'email' => 'quota-delegate-' . $suffix . '@example.test', 'password' => password_hash('quota-test-password', PASSWORD_DEFAULT),
]);
$delegateId = (int) $pdo->lastInsertId();
$host = $slug . '.ieprcard.test';
unset($pdo);

$oldSessionName = session_name();
$oldSessionId = session_id();
$oldSessionPath = session_save_path();
$oldSessionSettings = [];
foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_trans_sid', 'session.gc_maxlifetime'] as $setting) {
    $oldSessionSettings[$setting] = ini_get($setting);
}
$oldCookieParams = session_get_cookie_params();
$hadTenantCookie = array_key_exists('IEPRCARDTENANT', $_COOKIE);
$oldTenantCookie = $_COOKIE['IEPRCARDTENANT'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($quotaHttpDirectory);
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start quota test owner session.');
$ownerSession = session_id();
$ownerCsrf = bin2hex(random_bytes(32));
$_SESSION = ['tenant_user_id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente', 'tenant_authenticated_at' => time(), 'last_activity_at' => time(), 'csrf_token' => $ownerCsrf];
session_write_close();
session_id('');
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start quota test delegate session.');
$delegateSession = session_id();
$_SESSION = ['tenant_user_id' => $delegateId, 'tenant_id' => $tenantId, 'subcliente_id' => null,
    'papel_sistema' => 'subacesso', 'tenant_authenticated_at' => time(), 'last_activity_at' => time(), 'csrf_token' => bin2hex(random_bytes(32))];
session_write_close();

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) throw new RuntimeException('Could not allocate quota HTTP test port.');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$extensions = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    if (is_file(rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll')) $extensions = ['-d', 'extension=pdo_sqlite'];
}
$process = proc_open(array_merge([PHP_BINARY], $extensions, ['-d', 'session.save_path=' . $quotaHttpDirectory,
    '-S', '127.0.0.1:' . $port, '-t', $quotaHttpRoot . DIRECTORY_SEPARATOR . 'public']), [
    0 => ['file', $nullDevice, 'r'], 1 => ['file', $quotaHttpDirectory . DIRECTORY_SEPARATOR . 'server.out', 'a'],
    2 => ['file', $quotaHttpDirectory . DIRECTORY_SEPARATOR . 'server.err', 'a'],
], $pipes, $quotaHttpRoot);
if (!is_resource($process)) throw new RuntimeException('Could not start quota HTTP test server.');

try {
    $url = 'http://127.0.0.1:' . $port . '/tenant/cotas.php';
    $request = static function (string $method = 'GET', ?array $form = null, ?string $session = null) use ($url, $host): array {
        $parts = parse_url($url);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $errorCode, $errorMessage, 4);
        if ($socket === false) return [0, ''];
        $body = $form === null ? '' : http_build_query($form);
        $headers = [strtoupper($method) . ' ' . $parts['path'] . " HTTP/1.1", 'Host: ' . $host, 'Connection: close', 'Accept: text/html'];
        if ($session !== null) $headers[] = 'Cookie: IEPRCARDTENANT=' . $session;
        if (strtoupper($method) === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body);
        $response = stream_get_contents($socket);
        fclose($socket);
        [$head, $content] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
        $status = preg_match('/\s(\d{3})\s/', explode("\r\n", $head)[0] ?? '', $match) ? (int) $match[1] : 0;
        return [$status, $content];
    };
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = $request('GET');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 401) throw new RuntimeException('Quota page must require tenant authentication.');
    [$status] = $request('GET', null, $delegateSession);
    if ($status !== 403) throw new RuntimeException('A delegated identity must not view quota management.');
    [$status, $body] = $request('GET', null, $ownerSession);
    if ($status !== 200 || !str_contains($body, 'Sede Quotas') || !str_contains($body, 'Unidade Visível')
        || str_contains($body, 'Unidade Segreda') || !str_contains($body, '0 / 1.000') || !str_contains($body, '20 / 400')) {
        throw new RuntimeException('Quota page must show the authenticated Sede usage without another tenant: '
            . json_encode(['status' => $status, 'has_root' => str_contains($body, 'Sede Quotas'),
                'has_unit' => str_contains($body, 'Unidade Visível'), 'has_foreign' => str_contains($body, 'Unidade Segreda'),
                'has_root_usage' => str_contains($body, '0 / 1.000'), 'has_unit_usage' => str_contains($body, '20 / 400')], JSON_THROW_ON_ERROR));
    }
    $post = static fn (array $values, ?string $csrf = null): array => $request('POST', array_merge([
        'action' => 'save_allocation', 'subcliente_id' => $unitId, 'csrf_token' => $csrf ?? $ownerCsrf,
    ], $values), $ownerSession);
    [$status] = $post(['limite_usuarios_equipe' => '', 'limite_titulares' => '', 'limite_carteirinhas' => ''], substr($ownerCsrf, 1));
    if ($status !== 403) throw new RuntimeException('Quota allocation must reject invalid CSRF.');
    [$status] = $post(['limite_usuarios_equipe' => '10', 'limite_titulares' => '40', 'limite_carteirinhas' => '500']);
    if ($status !== 303) throw new RuntimeException('A valid quota allocation must use post-redirect-get.');
    $check = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $quotaHttpDatabase]);
    $stored = $check->prepare('SELECT limite_usuarios_equipe, limite_titulares, limite_carteirinhas FROM tenants WHERE id = :id');
    $stored->execute(['id' => $unitId]);
    if (array_map('intval', $stored->fetch(PDO::FETCH_NUM)) !== [10, 40, 500]) throw new RuntimeException('Quota allocation must persist through the existing service.');
    unset($check);
    [$status] = $post(['limite_usuarios_equipe' => '11', 'limite_titulares' => '101', 'limite_carteirinhas' => '500']);
    if ($status !== 303) throw new RuntimeException('An over-ceiling allocation must return a safe validation notice.');
    [$status, $body] = $request('GET', null, $ownerSession);
    if (!str_contains($body, 'cotas fixas de titulares') || !str_contains($body, 'teto da Sede (100)')) throw new RuntimeException('Over-ceiling allocation must identify its dimension and ceiling safely.');
    [$status] = $request('POST', ['action' => 'save_allocation', 'subcliente_id' => $foreignUnitId,
        'limite_usuarios_equipe' => '5', 'limite_titulares' => '20', 'limite_carteirinhas' => '200', 'csrf_token' => $ownerCsrf], $ownerSession);
    if ($status !== 303) throw new RuntimeException('A foreign unit ID must be rejected without disclosing its tenant.');
    $check = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $quotaHttpDatabase]);
    $foreign = $check->prepare('SELECT limite_usuarios_equipe FROM tenants WHERE id = :id');
    $foreign->execute(['id' => $foreignUnitId]);
    if ((int) $foreign->fetchColumn() !== 7) throw new RuntimeException('A forged unit ID must not update another tenant.');
    unset($check);
    fwrite(STDOUT, "PASS: tenant quota screen enforces authentication, tenant scope, CSRF, fixed allocations and ceiling validation.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($oldSessionPath);
    session_name($oldSessionName);
    session_id($oldSessionId);
    session_set_cookie_params($oldCookieParams);
    foreach ($oldSessionSettings as $setting => $value) if (is_string($value)) ini_set($setting, $value);
    if ($hadTenantCookie) $_COOKIE['IEPRCARDTENANT'] = $oldTenantCookie;
    else unset($_COOKIE['IEPRCARDTENANT']);
    foreach ($quotaHttpOldEnv as $key => $value) $value === null ? putenv($key) : putenv($key . '=' . $value);
    foreach (glob($quotaHttpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($quotaHttpDirectory);
}

return 'pass';
