<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-tenant-team-' . bin2hex(random_bytes(6));
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
$plan->execute(['name' => 'Tenant team HTTP ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
foreach (['usuario.criar', 'usuario.permissoes', 'titular.ver', 'titular.criar'] as $feature) {
    $flag->execute(['plan' => $planId, 'key' => $feature]);
}
$rootInsert = $pdo->prepare(
    "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe) "
    . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 10)"
);
$rootInsert->execute(['plan' => $planId, 'name' => 'Sede HTTP ' . $suffix, 'slug' => 'team-' . $suffix]);
$tenantId = (int) $pdo->lastInsertId();
$host = 'team-' . $suffix . '.ieprcard.test';
if (tenantResolverResolve($pdo, $host)?->tenantId !== $tenantId) {
    throw new RuntimeException('The tenant-team fixture must resolve its root host before starting HTTP tests.');
}
$unitInsert = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, permissoes_unidade_json) "
    . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', '[\"titular.ver\"]')"
);
$unitInsert->execute(['parent' => $tenantId, 'name' => 'Unidade Centro', 'slug' => 'center-' . $suffix]);
$unitId = (int) $pdo->lastInsertId();
$foreignRoot = $pdo->prepare(
    "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', :name, :slug, 'ativo')"
);
$foreignRoot->execute(['name' => 'Outra Sede HTTP', 'slug' => 'foreign-team-' . $suffix]);
$foreignTenantId = (int) $pdo->lastInsertId();
$foreignUnit = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
    . "VALUES (:parent, 'subcliente', 'Unidade alheia', :slug, 'ativo')"
);
$foreignUnit->execute(['parent' => $foreignTenantId, 'slug' => 'foreign-unit-' . $suffix]);
$foreignUnitId = (int) $pdo->lastInsertId();
$userInsert = $pdo->prepare(
    'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
    . 'VALUES (:tenant, :unit, :role, :name, :email, :password, 1)'
);
$userInsert->execute([
    'tenant' => $tenantId, 'unit' => null, 'role' => 'titular_cliente', 'name' => 'Sede Titular',
    'email' => 'owner-' . $suffix . '@example.test', 'password' => password_hash('team-test-password', PASSWORD_DEFAULT),
]);
$ownerId = (int) $pdo->lastInsertId();
$userInsert->execute([
    'tenant' => $tenantId, 'unit' => null, 'role' => 'subacesso', 'name' => 'Acesso delegado da Sede',
    'email' => 'delegate-' . $suffix . '@example.test', 'password' => password_hash('team-test-password', PASSWORD_DEFAULT),
]);
$delegateId = (int) $pdo->lastInsertId();
$userInsert->execute([
    'tenant' => $tenantId, 'unit' => $unitId, 'role' => 'responsavel_sub', 'name' => 'Operadora Centro',
    'email' => 'operator-' . $suffix . '@example.test', 'password' => password_hash('team-test-password', PASSWORD_DEFAULT),
]);
$userInsert->execute([
    'tenant' => $foreignTenantId, 'unit' => $foreignUnitId, 'role' => 'responsavel_sub', 'name' => 'Operador alheio',
    'email' => 'foreign-' . $suffix . '@example.test', 'password' => password_hash('team-test-password', PASSWORD_DEFAULT),
]);
$foreignOperatorId = (int) $pdo->lastInsertId();
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
    throw new RuntimeException('Could not start the tenant-team owner test session.');
}
$sessionId = session_id();
$csrfToken = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $ownerId,
    'tenant_id' => $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $csrfToken,
];
session_write_close();
session_id('');
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) {
    throw new RuntimeException('Could not start the delegated tenant-team test session.');
}
$delegateSessionId = session_id();
$delegateCsrf = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $delegateId,
    'tenant_id' => $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'subacesso',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $delegateCsrf,
];
session_write_close();

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate a tenant-team HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$extensions = [];
if (PHP_OS_FAMILY === 'Windows') {
    $extensionDirectory = ini_get('extension_dir');
    if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
        $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
    }
    if (is_file(rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll')) {
        $extensions = ['-d', 'extension=pdo_sqlite'];
    }
}
$process = proc_open(array_merge(
    [PHP_BINARY], $extensions, ['-d', 'session.save_path=' . $directory, '-S', '127.0.0.1:' . $port,
        '-t', $root . DIRECTORY_SEPARATOR . 'public']
), [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the tenant-team HTTP test server.');
}

try {
    $url = 'http://127.0.0.1:' . $port . '/tenant/equipe.php';
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status, $body] = tenantTeamHttpRequest($url, $host);
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 401) {
        throw new RuntimeException('An unauthenticated tenant must receive 401 from the team screen; got '
            . $status . ' with body ' . (string) $body . '; server log: '
            . (string) @file_get_contents($directory . DIRECTORY_SEPARATOR . 'php.err.log') . '.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Operadores das unidades')
        || !str_contains((string) $body, 'Operadora Centro')
        || !str_contains((string) $body, 'operator-' . $suffix . '@example.test')
        || !str_contains((string) $body, 'Unidade Centro')
        || !str_contains((string) $body, 'Ver acesso')
        || str_contains((string) $body, 'Operador alheio')
        || str_contains((string) $body, 'foreign-' . $suffix . '@example.test')) {
        throw new RuntimeException('The team screen must list only unit operators of the authenticated Sede.');
    }
    [$status] = tenantTeamHttpRequest($url, $host, $delegateSessionId);
    if ($status !== 403) {
        throw new RuntimeException('A delegated Sede access must not manage the team screen; got ' . $status . '.');
    }
    foreach (['create_operator', 'update_permissions'] as $action) {
        [$status] = tenantTeamHttpRequest($url, $host, $delegateSessionId, 'POST', http_build_query([
            'action' => $action,
            'permissions' => ['titular.ver'],
            'nome' => 'Ataque delegado',
            'email' => 'attack-' . $suffix . '@example.test',
            'senha' => 'a-secure-test-password',
            'subcliente_id' => $unitId,
            'csrf_token' => $delegateCsrf,
        ]));
        if ($status !== 403) {
            throw new RuntimeException('A delegated Sede access must not perform ' . $action . '; got ' . $status . '.');
        }
    }

    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'create_operator',
        'nome' => 'Nova Responsável',
        'email' => 'new-' . $suffix . '@example.test',
        'senha' => 'a-secure-test-password',
        'subcliente_id' => $unitId,
        'csrf_token' => substr($csrfToken, 1),
    ]));
    if ($status !== 403) {
        throw new RuntimeException('The team screen must reject operator creation with invalid CSRF; got ' . $status . '.');
    }

    $createOperator = http_build_query([
        'action' => 'create_operator',
        'nome' => 'Nova Responsável',
        'email' => 'new-' . $suffix . '@example.test',
        'senha' => 'a-secure-test-password',
        'subcliente_id' => $unitId,
        'tenant_id' => $foreignTenantId,
        'csrf_token' => $csrfToken,
    ]);
    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', $createOperator);
    if ($status !== 303) {
        throw new RuntimeException('Creating a unit operator must redirect after saving and ignore a forged tenant ID; got '
            . $status . '.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Nova Responsável')
        || !str_contains((string) $body, 'new-' . $suffix . '@example.test')
        || str_contains((string) $body, 'a-secure-test-password')) {
        throw new RuntimeException('The newly created operator must appear only in the authenticated Sede team.');
    }
    $checkPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $createdOperator = $checkPdo->prepare(
        'SELECT id, tenant_id, subcliente_id, papel_sistema, senha_hash FROM usuarios WHERE email = :email LIMIT 1'
    );
    $createdOperator->execute(['email' => 'new-' . $suffix . '@example.test']);
    $createdRow = $createdOperator->fetch(PDO::FETCH_ASSOC);
    if (!is_array($createdRow) || (int) $createdRow['tenant_id'] !== $tenantId
        || (int) $createdRow['subcliente_id'] !== $unitId || $createdRow['papel_sistema'] !== 'responsavel_sub'
        || !password_verify('a-secure-test-password', (string) $createdRow['senha_hash'])) {
        throw new RuntimeException('Operator creation must persist the unit scope and a password hash.');
    }
    unset($checkPdo);

    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'create_operator',
        'nome' => 'E-mail duplicado',
        'email' => 'foreign-' . $suffix . '@example.test',
        'senha' => 'a-secure-test-password',
        'subcliente_id' => $unitId,
        'csrf_token' => $csrfToken,
    ]));
    if ($status !== 303) {
        throw new RuntimeException('A duplicate operator e-mail must return a safe field-level notice.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'E-mail indisponível')
        || str_contains((string) $body, 'Operador alheio')
        || str_contains((string) $body, 'foreign-' . $suffix . '@example.test')) {
        throw new RuntimeException('An e-mail conflict must not disclose the account or Sede that owns it.');
    }

    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'update_permissions',
        'permissions' => ['titular.criar', 'titular.ver'],
        'tenant_id' => $foreignTenantId,
        'csrf_token' => $csrfToken,
    ]));
    if ($status !== 303) {
        throw new RuntimeException('Saving the unit delegated-permission allowlist must redirect after saving; got ' . $status . '.');
    }
    $checkPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $savedPermissions = $checkPdo->prepare('SELECT permissoes_unidade_json FROM tenants WHERE id = :tenant');
    $savedPermissions->execute(['tenant' => $tenantId]);
    if (json_decode((string) $savedPermissions->fetchColumn(), true) !== ['titular.criar', 'titular.ver']) {
        throw new RuntimeException('The delegated-permission service must persist normalized keys for the session Sede.');
    }
    unset($checkPdo);
    $accessUrl = $url . '?operador=' . (int) $createdRow['id'];
    [$status, $body] = tenantTeamHttpRequest($accessUrl, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Acesso efetivo')
        || !str_contains((string) $body, 'titular.ver') || !str_contains((string) $body, 'titular.criar')) {
        throw new RuntimeException('The read-only operator access view must show only that user\'s effective permissions.');
    }
    [$status, $body] = tenantTeamHttpRequest($url . '?operador=' . $foreignOperatorId, $host, $sessionId);
    if ($status !== 200 || str_contains((string) $body, 'Operador alheio')
        || str_contains((string) $body, 'foreign-' . $suffix . '@example.test')) {
        throw new RuntimeException('A forged operator ID must not reveal another Sede access details.');
    }
    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'update_permissions',
        'permissions' => ['financeiro.ver'],
        'csrf_token' => $csrfToken,
    ]));
    if ($status !== 303) {
        throw new RuntimeException('Forbidden permission requests must return a safe validation notice.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'não podem ser delegadas')) {
        throw new RuntimeException('Forbidden permission requests must not be silently accepted.');
    }
    $checkPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $savedPermissions = $checkPdo->prepare('SELECT permissoes_unidade_json FROM tenants WHERE id = :tenant');
    $savedPermissions->execute(['tenant' => $tenantId]);
    if (json_decode((string) $savedPermissions->fetchColumn(), true) !== ['titular.criar', 'titular.ver']) {
        throw new RuntimeException('A forbidden permission must leave the stored allowlist unchanged.');
    }
    $updateRootLimit = $checkPdo->prepare('UPDATE tenants SET limite_usuarios_equipe = 3 WHERE id = :tenant');
    $updateRootLimit->execute(['tenant' => $tenantId]);
    unset($checkPdo);
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Limite de operadores da Sede atingido')
        || str_contains((string) $body, 'id="novo-operador"')) {
        throw new RuntimeException('The team screen must explain and disable creation at the Sede quota.');
    }
    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'create_operator',
        'nome' => 'Operador acima do teto global',
        'email' => 'over-root-' . $suffix . '@example.test',
        'senha' => 'a-secure-test-password',
        'subcliente_id' => $unitId,
        'csrf_token' => $csrfToken,
    ]));
    if ($status !== 303) {
        throw new RuntimeException('A direct request must not exceed the team quota defined for the Sede.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Limite de operadores atingido')
        || str_contains((string) $body, 'over-root-' . $suffix)) {
        throw new RuntimeException('The screen must report the global team quota without creating an extra operator.');
    }
    $checkPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $updateLimits = $checkPdo->prepare(
        'UPDATE tenants SET limite_usuarios_equipe = :limit WHERE id = :id'
    );
    $updateLimits->execute(['limit' => 10, 'id' => $tenantId]);
    $updateUnitLimit = $checkPdo->prepare('UPDATE tenants SET limite_usuarios_equipe = 2 WHERE id = :unit');
    $updateUnitLimit->execute(['unit' => $unitId]);
    unset($checkPdo);
    [$status] = tenantTeamHttpRequest($url, $host, $sessionId, 'POST', http_build_query([
        'action' => 'create_operator',
        'nome' => 'Operador acima do teto local',
        'email' => 'over-unit-' . $suffix . '@example.test',
        'senha' => 'a-secure-test-password',
        'subcliente_id' => $unitId,
        'csrf_token' => $csrfToken,
    ]));
    if ($status !== 303) {
        throw new RuntimeException('A direct request must not exceed the team quota defined for the unit.');
    }
    [$status, $body] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 200 || !str_contains((string) $body, 'Limite de operadores atingido')
        || str_contains((string) $body, 'over-unit-' . $suffix)) {
        throw new RuntimeException('The screen must enforce the unit team quota.');
    }

    $checkPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $disableManagement = $checkPdo->prepare(
        'UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :key'
    );
    foreach (['usuario.criar', 'usuario.permissoes'] as $feature) {
        $disableManagement->execute(['plan' => $planId, 'key' => $feature]);
    }
    unset($checkPdo);
    [$status] = tenantTeamHttpRequest($url, $host, $sessionId);
    if ($status !== 403) {
        throw new RuntimeException('A Sede without team permissions must not consult the team screen; got ' . $status . '.');
    }

    fwrite(STDOUT, "PASS: tenant team screen enforces tenant isolation, authorization, CSRF, delegated permissions and quotas.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
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

/** @return array{int,string|null} */
function tenantTeamHttpRequest(
    string $url,
    string $host,
    ?string $session = null,
    string $method = 'GET',
    ?string $body = null
): array
{
    $parts = parse_url($url);
    $port = (int) ($parts['port'] ?? 80);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $requestTarget = (string) ($parts['path'] ?? '/');
    if (isset($parts['query'])) $requestTarget .= '?' . $parts['query'];
    $headers = [
        strtoupper($method) . ' ' . $requestTarget . " HTTP/1.1",
        'Host: ' . $host,
        'Connection: close',
        'Accept: text/html',
    ];
    if ($session !== null) $headers[] = 'Cookie: IEPRCARDTENANT=' . $session;
    if (strtoupper($method) === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen((string) $body);
    }
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . ($body ?? ''));
    $response = stream_get_contents($socket);
    fclose($socket);
    [$head, $body] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, null);
    $status = preg_match('/\s(\d{3})\s/', explode("\r\n", (string) $head)[0] ?? '', $match) === 1
        ? (int) $match[1]
        : 0;

    return [$status, $body];
}
