<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-portal-artifact-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$dbPath = $directory . DIRECTORY_SEPARATOR . 'portal.sqlite';
$privateRoot = $directory . DIRECTORY_SEPARATOR . 'private';
$ratePath = $directory . DIRECTORY_SEPARATOR . 'rate.json';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'IEPR_PRIVATE_STORAGE_PATH', 'IEPRCARD_PORTAL_RATE_LIMIT_PATH', 'TENANT_BASE_DOMAIN'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
$oldSessionName = session_name();
$oldSessionId = session_id();
$oldSessionPath = session_save_path();
$oldCookieParams = session_get_cookie_params();
$oldSession = $_SESSION ?? [];
$hadPortalCookie = array_key_exists('IEPRCARDPORTAL', $_COOKIE);
$oldPortalCookie = $_COOKIE['IEPRCARDPORTAL'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($directory);
putenv('APP_ENV=dev');
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $privateRoot);
putenv('IEPRCARD_PORTAL_RATE_LIMIT_PATH=' . $ratePath);
putenv('TENANT_BASE_DOMAIN=ieprcard.test');

$testDbConfiguration = ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $dbPath];
$mysqlTestSettings = [
    'DB_HOST' => getenv('TEST_MYSQL_HOST'),
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE'),
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME'),
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD'),
];
if (in_array('mysql', PDO::getAvailableDrivers(), true)
    && count(array_filter($mysqlTestSettings, static fn (mixed $value): bool => $value !== false)) === count($mysqlTestSettings)) {
    $testDbConfiguration = ['DB_DRIVER' => 'mysql'] + $mysqlTestSettings;
}
foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    putenv($key);
}
foreach ($testDbConfiguration as $key => $value) putenv($key . '=' . $value);

$pdo = dbConnect($testDbConfiguration);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$suffix = bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')->execute(['name' => 'Portal artifact ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE tenants SET plano_id = :plan WHERE id = :tenant')->execute([
    'plan' => $planId,
    'tenant' => $fixture['tenant_id'],
]);
$pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)')
    ->execute(['plan' => $planId, 'key' => 'carteirinha.pdf']);
$pdo->prepare(
    'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) VALUES (:tenant, :name, :orientation, :created)'
)->execute(['tenant' => $fixture['tenant_id'], 'name' => 'Portal artifact ' . $suffix, 'orientation' => 'horizontal', 'created' => gmdate('Y-m-d H:i:s')]);
$designId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, data_nascimento, acesso_app_liberado, ativo, criado_em) '
    . 'VALUES (:tenant, :unit, :name, :birth, 1, 1, :created)'
)->execute([
    'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'name' => 'Portal Artifact Holder',
    'birth' => '1990-02-03', 'created' => gmdate('Y-m-d H:i:s'),
]);
$holderId = (int) $pdo->lastInsertId();
$foreignHolder = $pdo->prepare(
    'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, data_nascimento, acesso_app_liberado, ativo, criado_em) '
    . 'VALUES (:tenant, :unit, :name, :birth, 1, 1, :created)'
);
$foreignHolder->execute([
    'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'name' => 'Other Portal Holder',
    'birth' => '1990-02-03', 'created' => gmdate('Y-m-d H:i:s'),
]);
$foreignHolderId = (int) $pdo->lastInsertId();
$keys = [];
foreach ([$holderId, $foreignHolderId] as $id) {
    foreach (['front', 'back'] as $side) {
        $subdir = $privateRoot . DIRECTORY_SEPARATOR . $fixture['tenant_id'];
        if (!is_dir($subdir)) mkdir($subdir, 0700, true);
        $token = substr(hash('sha256', $id . ':' . $side . ':' . $suffix), 0, 48);
        $bytes = "RIFF" . pack('V', 4) . "WEBP";
        file_put_contents($subdir . DIRECTORY_SEPARATOR . $token . '.webp', $bytes);
        $keys[$id][$side] = 'private://' . $fixture['tenant_id'] . '/' . $token . '.webp';
    }
    $pdfToken = substr(hash('sha256', $id . ':pdf:' . $suffix), 0, 48);
    $pdfBytes = "%PDF-1.4\nprivate portal artifact\n%%EOF\n";
    file_put_contents($subdir . DIRECTORY_SEPARATOR . $pdfToken . '.pdf', $pdfBytes);
    $keys[$id]['pdf'] = 'private://' . $fixture['tenant_id'] . '/' . $pdfToken . '.pdf';
    $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas '
        . '(tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, data_validade, webp_frente_url, webp_verso_url, pdf_individual_url, emitida_em) '
        . 'VALUES (:tenant, :unit, :holder, :design, :qr, :status, :validity, :front, :back, :pdf, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'holder' => $id, 'design' => $designId,
        'qr' => bin2hex(random_bytes(32)), 'status' => 'ativa', 'validity' => gmdate('Y-m-d', strtotime('+1 year')),
        'front' => $keys[$id]['front'], 'back' => $keys[$id]['back'], 'pdf' => $keys[$id]['pdf'],
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $cardIds[$id] = (int) $pdo->lastInsertId();
}
unset($pdo);

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!portalAuthStartSession(false)) throw new RuntimeException('Could not establish the portal artifact test session.');
$sessionCookie = session_name() . '=' . session_id();
$_SESSION = [
    'portal_holder_id' => $holderId,
    'portal_tenant_id' => $fixture['tenant_id'],
    'portal_subcliente_id' => $fixture['subcliente_id'],
    'portal_authenticated_at' => time(),
    'last_activity_at' => time(),
];
session_write_close();

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $directory, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'router.php'],
    [0 => ['file', $nullDevice, 'r'], 1 => ['file', $directory . DIRECTORY_SEPARATOR . 'out.log', 'a'], 2 => ['file', $directory . DIRECTORY_SEPARATOR . 'err.log', 'a']],
    $pipes,
    $root
);
if (!is_resource($process)) throw new RuntimeException('Could not start portal artifact HTTP test server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $request = static function (string $path, ?string $cookie = null, string $method = 'GET') use ($baseUrl, $fixture): array {
        $headers = ['Host: ' . $fixture['slug'] . '.ieprcard.test'];
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 3,
        ]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $responseHeaders];
    };
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if (@fsockopen('127.0.0.1', $port, $probeCode, $probeMessage, 0.1)) { $ready = true; break; }
        usleep(100_000);
    }
    if (!$ready) throw new RuntimeException('Portal artifact HTTP test server did not become ready.');

    [$status, $body, $headers] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/frente', $sessionCookie);
    if ($status !== 200 || $body !== "RIFF" . pack('V', 4) . "WEBP"
        || portalArtifactHeaderValue($headers, 'Content-Type') !== 'image/webp'
        || !str_contains((string) portalArtifactHeaderValue($headers, 'Cache-Control'), 'private')
        || !str_contains((string) portalArtifactHeaderValue($headers, 'Cache-Control'), 'no-store')
        || portalArtifactHeaderValue($headers, 'X-Content-Type-Options') !== 'nosniff'
        || portalArtifactHeaderValue($headers, 'Referrer-Policy') !== 'no-referrer') {
        throw new RuntimeException('An authenticated holder must receive only their private WebP bytes with privacy headers.');
    }
    [$status, $body] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/verso', $sessionCookie);
    if ($status !== 200 || $body !== "RIFF" . pack('V', 4) . "WEBP") {
        throw new RuntimeException('The authenticated holder must be able to retrieve the card back.');
    }
    [$status] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/pdf', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('Titular PDF access must be denied by default.');
    $pdo = dbConnect($testDbConfiguration);
    $pdo->prepare('UPDATE tenants SET pdf_portal_habilitado = 1, permissoes_unidade_json = :permissions WHERE id = :tenant')
        ->execute(['permissions' => '[]', 'tenant' => $fixture['tenant_id']]);
    unset($pdo);
    [$status] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/pdf', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('A unit without explicit PDF delegation must remain denied.');
    $pdo = dbConnect($testDbConfiguration);
    $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')->execute([
        'permissions' => '["carteirinha.pdf"]',
        'tenant' => $fixture['tenant_id'],
    ]);
    unset($pdo);
    [$status, $body, $headers] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/pdf', $sessionCookie);
    if ($status !== 200 || $body !== "%PDF-1.4\nprivate portal artifact\n%%EOF\n"
        || portalArtifactHeaderValue($headers, 'Content-Type') !== 'application/pdf'
        || portalArtifactHeaderValue($headers, 'Content-Disposition') !== 'attachment; filename="carteirinha.pdf"'
        || !str_contains((string) portalArtifactHeaderValue($headers, 'Cache-Control'), 'no-store')
        || portalArtifactHeaderValue($headers, 'X-Content-Type-Options') !== 'nosniff') {
        throw new RuntimeException('An enabled and delegated holder must receive only their private PDF with safe headers.');
    }
    [$status] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/pdf');
    if ($status !== 404) throw new RuntimeException('An unauthenticated request must not retrieve a PDF artifact.');
    [$status] = $request('/portal/carteirinha/' . $cardIds[$foreignHolderId] . '/pdf', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('A holder must not retrieve another holder PDF by changing the card ID.');
    [$status] = $request('/portal/carteirinha/' . $cardIds[$foreignHolderId] . '/frente', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('A card belonging to another holder must remain undiscoverable.');
    [$status, , $headers] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/frente');
    if ($status !== 404 || !str_contains((string) portalArtifactHeaderValue($headers, 'Cache-Control'), 'private')
        || !str_contains((string) portalArtifactHeaderValue($headers, 'Cache-Control'), 'no-store')) {
        throw new RuntimeException('An unauthenticated request must fail privately without caching a card artifact.');
    }
    [$status] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/download', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('Only the explicit front and back artifact sides may be routed.');
    [$status] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/frente', $sessionCookie, 'POST');
    if ($status !== 405) throw new RuntimeException('The private artifact endpoint must accept GET only.');
    [$status] = $request('/portal/carteirinha/999999999/frente', $sessionCookie);
    if ($status !== 404) throw new RuntimeException('An unknown card ID must fail closed.');
    $pdo = dbConnect($testDbConfiguration);
    $pdo->prepare('UPDATE carteirinhas_emitidas SET webp_frente_url = :key WHERE id = :id')
        ->execute(['key' => 'private://' . $fixture['tenant_id'] . '/' . str_repeat('a', 48) . '.webp', 'id' => $cardIds[$holderId]]);
    unset($pdo);
    [$status, $body] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/frente', $sessionCookie);
    if ($status !== 503 || $body !== '') throw new RuntimeException('Missing private storage must fail generically without file details.');
    $pdo = dbConnect($testDbConfiguration);
    $pdo->prepare('UPDATE carteirinhas_emitidas SET pdf_individual_url = :key WHERE id = :id')
        ->execute(['key' => 'private://' . $fixture['tenant_id'] . '/' . str_repeat('b', 48) . '.pdf', 'id' => $cardIds[$holderId]]);
    unset($pdo);
    [$status, $body] = $request('/portal/carteirinha/' . $cardIds[$holderId] . '/pdf', $sessionCookie);
    if ($status !== 503 || $body !== '') throw new RuntimeException('Missing private PDF storage must fail generically without file details.');
    fwrite(STDOUT, 'PASS: private titular WebP artifacts are served through authenticated HTTP (' . $testDbConfiguration['DB_DRIVER'] . ") with no-store headers.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($oldSessionPath);
    session_name($oldSessionName);
    if ($oldSessionId !== '') session_id($oldSessionId);
    session_set_cookie_params($oldCookieParams);
    $_SESSION = $oldSession;
    if ($hadPortalCookie) $_COOKIE['IEPRCARDPORTAL'] = $oldPortalCookie;
    else unset($_COOKIE['IEPRCARDPORTAL']);
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) putenv($key);
        else putenv($key . '=' . $value);
    }
    $cleanupPdo = dbConnect($testDbConfiguration);
    $cleanupPdo->prepare('DELETE FROM carteirinhas_emitidas WHERE id IN (:card_a, :card_b)')
        ->execute(['card_a' => $cardIds[$holderId], 'card_b' => $cardIds[$foreignHolderId]]);
    $cleanupPdo->prepare('DELETE FROM titulares WHERE id IN (:holder_a, :holder_b)')
        ->execute(['holder_a' => $holderId, 'holder_b' => $foreignHolderId]);
    $cleanupPdo->prepare('DELETE FROM designs_carteirinha WHERE id = :id')->execute(['id' => $designId]);
    $cleanupPdo->prepare('UPDATE tenants SET plano_id = NULL WHERE id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
    $cleanupPdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
    $cleanupPdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
    tenantTestCleanup($cleanupPdo, $fixture);
    unset($cleanupPdo);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
        elseif (is_dir($path)) {
            foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) if (is_file($child)) @unlink($child);
            @rmdir($path);
        }
    }
    @rmdir($directory);
}

function portalArtifactHeaderValue(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
    }
    return null;
}

return 'pass';
