<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-portal-page-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create portal page HTTP test storage.');
}
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
foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) putenv($key);
foreach ($testDbConfiguration as $key => $value) putenv($key . '=' . $value);

$pdo = dbConnect($testDbConfiguration);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$suffix = bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')->execute(['name' => 'Portal PDF ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE tenants SET plano_id = :plan WHERE id = :tenant')->execute([
    'plan' => $planId,
    'tenant' => $fixture['tenant_id'],
]);
$pdo->prepare(
    'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)'
)->execute(['plan' => $planId, 'feature' => 'carteirinha.pdf']);
$pdo->prepare(
    'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) VALUES (:tenant, :name, :orientation, :created)'
)->execute(['tenant' => $fixture['tenant_id'], 'name' => 'Portal page ' . $suffix, 'orientation' => 'horizontal', 'created' => gmdate('Y-m-d H:i:s')]);
$designId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, cpf, data_nascimento, acesso_app_liberado, ativo, criado_em) '
    . 'VALUES (:tenant, :unit, :name, :cpf, :birth, 1, 1, :created)'
)->execute([
    'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'name' => 'Portal HTTP Holder',
    'cpf' => '52998224725', 'birth' => '1990-02-03', 'created' => gmdate('Y-m-d H:i:s'),
]);
$holderId = (int) $pdo->lastInsertId();
$token = portalAuthCreateAccessToken();
$pdo->prepare('UPDATE titulares SET token_unico_carteira = :token_hash WHERE id = :id')
    ->execute(['token_hash' => $token['token_hash'], 'id' => $holderId]);
$cardIds = [];
$cardFilePaths = [];
for ($index = 0; $index < 2; $index++) {
    $assetKeys = [];
    foreach (['frente', 'verso'] as $side) {
        $assetName = substr(hash('sha256', $suffix . '-' . $index . '-' . $side), 0, 48) . '.webp';
        $assetDirectory = $privateRoot . DIRECTORY_SEPARATOR . $fixture['tenant_id'];
        if (!is_dir($assetDirectory)) mkdir($assetDirectory, 0700, true);
        $assetPath = $assetDirectory . DIRECTORY_SEPARATOR . $assetName;
        file_put_contents($assetPath, "RIFF" . pack('V', 4) . 'WEBP');
        $cardFilePaths[] = $assetPath;
        $assetKeys[$side] = 'private://' . $fixture['tenant_id'] . '/' . $assetName;
    }
    $pdfName = substr(hash('sha256', $suffix . '-' . $index . '-pdf'), 0, 48) . '.pdf';
    $assetDirectory = $privateRoot . DIRECTORY_SEPARATOR . $fixture['tenant_id'];
    $pdfPath = $assetDirectory . DIRECTORY_SEPARATOR . $pdfName;
    file_put_contents($pdfPath, "%PDF-1.4\nportal page fixture\n%%EOF\n");
    $cardFilePaths[] = $pdfPath;
    $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas '
        . '(tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, data_validade, webp_frente_url, webp_verso_url, pdf_individual_url, emitida_em) '
        . 'VALUES (:tenant, :unit, :holder, :design, :qr, :status, :validity, :front, :back, :pdf, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'holder' => $holderId, 'design' => $designId,
        'qr' => bin2hex(random_bytes(32)), 'status' => 'ativa', 'validity' => gmdate('Y-m-d', strtotime('+1 year')),
        'front' => $assetKeys['frente'], 'back' => $assetKeys['verso'],
        'pdf' => 'private://' . $fixture['tenant_id'] . '/' . $pdfName, 'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $cardIds[] = (int) $pdo->lastInsertId();
}
unset($pdo);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($probe)) throw new RuntimeException('Could not reserve a portal page HTTP test port.');
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
if (!is_resource($process)) throw new RuntimeException('Could not start portal page HTTP test server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $request = static function (string $path, ?string $cookie = null, string $method = 'GET', array $form = []) use ($baseUrl, $fixture): array {
        $headers = ['Host: ' . $fixture['slug'] . '.ieprcard.test'];
        $content = '';
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $content = http_build_query($form);
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
            'ignore_errors' => true, 'timeout' => 4, 'follow_location' => 0,
        ]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        $setCookie = portalPageHeaderValue($responseHeaders, 'Set-Cookie');
        preg_match('/^IEPRCARDPORTAL=([^;]+)/', (string) $setCookie, $cookieMatch);
        $nextCookie = isset($cookieMatch[1]) ? 'IEPRCARDPORTAL=' . $cookieMatch[1] : $cookie;
        return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $responseHeaders, $nextCookie];
    };
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $probeCode, $probeMessage, 0.1);
        if (is_resource($socket)) { fclose($socket); $ready = true; break; }
        usleep(100_000);
    }
    if (!$ready) throw new RuntimeException('Portal page HTTP test server did not become ready.');

    [$status, $body, $headers, $cookie] = $request('/portal/');
    if ($status !== 200 || !str_contains($body, 'Acesse sua carteirinha') || !str_contains($body, 'name="csrf_token"')
        || !str_contains($body, 'name="cpf"') || !str_contains($body, 'name="data_nascimento"')
        || !str_contains((string) portalPageHeaderValue($headers, 'Cache-Control'), 'no-store')
        || portalPageHeaderValue($headers, 'Referrer-Policy') !== 'no-referrer'
        || portalPageHeaderValue($headers, 'X-Robots-Tag') !== 'noindex, nofollow'
        || portalPageHeaderValue($headers, 'X-Content-Type-Options') !== 'nosniff'
        || portalPageHeaderValue($headers, 'X-Frame-Options') !== 'DENY'
        || !str_contains((string) portalPageHeaderValue($headers, 'Content-Security-Policy'), "frame-ancestors 'none'")
        || stripos(implode("\n", $headers), 'Set-Cookie: IEPRCARDPORTAL=') === false) {
        throw new RuntimeException('Portal login must provide a no-store, session-bound CSRF form for the resolved tenant.');
    }
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $csrfMatch);
    $csrf = $csrfMatch[1] ?? '';
    if ($csrf === '') throw new RuntimeException('Portal login did not include a valid CSRF token.');

    [$status, $body] = $request('/portal/', $cookie, 'POST', [
        'action' => 'login', 'csrf_token' => $csrf, 'cpf' => '00000000000', 'data_nascimento' => '1990-02-03',
    ]);
    if ($status !== 401 || !str_contains($body, 'Não foi possível validar') || str_contains($body, '00000000000')) {
        throw new RuntimeException('Invalid holder credentials must return a generic error without reflecting the submitted CPF.');
    }

    [$status, $body, $headers, $authenticatedCookie] = $request('/portal/', $cookie, 'POST', [
        'action' => 'login', 'csrf_token' => $csrf, 'cpf' => '529.982.247-25', 'data_nascimento' => '1990-02-03',
    ]);
    if ($status !== 303 || portalPageHeaderValue($headers, 'Location') !== '/portal/'
        || $authenticatedCookie === $cookie || str_contains($body, 'token_unico')) {
        throw new RuntimeException('Successful CPF login must rotate the session and redirect to the clean portal URL.');
    }
    [$status, $body, $headers] = $request('/portal/', $authenticatedCookie);
    if ($status !== 200 || !str_contains($body, 'Portal HTTP Holder') || !str_contains($body, 'Unidade ')
        || !str_contains($body, 'Carteirinha de membro') || !str_contains($body, 'Selecione a carteirinha')
        || !str_contains($body, '/portal/carteirinha/' . $cardIds[1] . '/frente')
        || str_contains($body, '529.982.247-25') || str_contains($body, 'Baixar PDF')
        || str_contains($body, 'Other Portal Holder')) {
        throw new RuntimeException('Authenticated portal contract failed: ' . json_encode([
            'status' => $status, 'holder' => str_contains($body, 'Portal HTTP Holder'), 'unit' => str_contains($body, 'Unidade '),
            'option' => str_contains($body, 'Carteirinha de membro'), 'selector' => str_contains($body, 'Selecione a carteirinha'),
            'artifact' => str_contains($body, '/portal/carteirinha/' . $cardIds[0] . '/frente'),
            'cpf_leaked' => str_contains($body, '529.982.247-25'), 'pdf' => str_contains($body, 'Baixar PDF'),
            'other_holder' => str_contains($body, 'Other Portal Holder'), 'body_start' => substr($body, 0, 450),
        ], JSON_UNESCAPED_SLASHES));
    }
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $csrfMatch);
    $csrf = $csrfMatch[1] ?? '';
    [$status, , $headers, $authenticatedCookie] = $request('/portal/', $authenticatedCookie, 'POST', [
        'action' => 'select_card', 'csrf_token' => $csrf, 'carteirinha_id' => (string) $cardIds[0],
    ]);
    if ($status !== 303 || portalPageHeaderValue($headers, 'Location') !== '/portal/') {
        throw new RuntimeException('Card selection must be CSRF-protected and redirect after the server validates membership.');
    }
    [$status, $body] = $request('/portal/', $authenticatedCookie);
    if ($status !== 200 || !str_contains($body, '/portal/carteirinha/' . $cardIds[0] . '/frente')) {
        throw new RuntimeException('A selected card must be revalidated from the authenticated session.');
    }
    $policyPdo = dbConnect($testDbConfiguration);
    $policyPdo->prepare('UPDATE tenants SET pdf_portal_habilitado = 1, permissoes_unidade_json = :permissions WHERE id = :tenant')
        ->execute(['permissions' => '["carteirinha.pdf"]', 'tenant' => $fixture['tenant_id']]);
    [$status, $body] = $request('/portal/', $authenticatedCookie);
    if ($status !== 200 || !str_contains($body, 'Baixar PDF')
        || !str_contains($body, '/portal/carteirinha/' . $cardIds[0] . '/pdf')
        || str_contains($body, '/portal/carteirinha/' . $cardIds[1] . '/pdf')) {
        throw new RuntimeException('The authenticated portal must show a PDF action only for the selected card after Sede enablement and unit delegation.');
    }
    [$status, $body, $headers] = $request('/portal/carteirinha/' . $cardIds[0] . '/pdf', $authenticatedCookie);
    if ($status !== 200 || $body !== "%PDF-1.4\nportal page fixture\n%%EOF\n"
        || portalPageHeaderValue($headers, 'Content-Type') !== 'application/pdf') {
        throw new RuntimeException('The visible PDF action must resolve only through the private authorized route.');
    }
    $policyPdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')
        ->execute(['permissions' => '[]', 'tenant' => $fixture['tenant_id']]);
    unset($policyPdo);
    [$status, $body] = $request('/portal/', $authenticatedCookie);
    if ($status !== 200 || str_contains($body, 'Baixar PDF')) {
        throw new RuntimeException('The portal must hide PDF after explicit unit delegation is revoked.');
    }
    [$status] = $request('/portal/carteirinha/' . $cardIds[0] . '/pdf', $authenticatedCookie);
    if ($status !== 404) throw new RuntimeException('Revoking unit PDF delegation must also deny direct requests.');
    [$status, $body, $headers] = $request('/portal/carteirinha/' . $cardIds[0] . '/verso', $authenticatedCookie);
    if ($status !== 200 || $body !== "RIFF" . pack('V', 4) . 'WEBP'
        || portalPageHeaderValue($headers, 'Content-Type') !== 'image/webp') {
        throw new RuntimeException('The authenticated portal must load private WebP assets for the selected holder card.');
    }

    [$status, $body, $headers] = $request('/portal/?token=' . rawurlencode($token['token']));
    if ($status !== 303 || portalPageHeaderValue($headers, 'Location') !== '/portal/'
        || str_contains((string) portalPageHeaderValue($headers, 'Location'), $token['token'])) {
        throw new RuntimeException('Bearer-token login must redirect immediately to remove the token from the visible URL.');
    }
    $tokenCookieHeader = portalPageHeaderValue($headers, 'Set-Cookie');
    preg_match('/^IEPRCARDPORTAL=([^;]+)/', (string) $tokenCookieHeader, $tokenCookieMatch);
    $tokenCookie = isset($tokenCookieMatch[1]) ? 'IEPRCARDPORTAL=' . $tokenCookieMatch[1] : null;
    [$status, $body] = $request('/portal/', $tokenCookie);
    if ($status !== 200 || !str_contains($body, 'Portal HTTP Holder') || str_contains($body, $token['token'])) {
        throw new RuntimeException('A private access link must authenticate the holder without echoing the bearer token.');
    }
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $csrfMatch);
    [$status] = $request('/portal/', $tokenCookie, 'POST', ['action' => 'logout', 'csrf_token' => str_repeat('0', 64)]);
    if ($status !== 403) throw new RuntimeException('Portal logout must reject an invalid CSRF token.');
    [$status, $body] = $request('/portal/', $tokenCookie);
    if ($status !== 200 || !str_contains($body, 'Portal HTTP Holder')) {
        throw new RuntimeException('A rejected logout must preserve the authenticated portal session.');
    }

    preg_match('/^IEPRCARDPORTAL=([^;]+)/', (string) portalPageHeaderValue($headers, 'Set-Cookie'), $tokenSessionMatch);
    $sessionId = $tokenSessionMatch[1] ?? '';
    if ($sessionId === '') throw new RuntimeException('Bearer-token login did not establish a portal session.');
    $restoreSessionName = session_name();
    $restoreSessionId = session_id();
    $restoreSessionPath = session_save_path();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($directory);
    session_name('IEPRCARDPORTAL');
    session_id($sessionId);
    if (!session_start()) throw new RuntimeException('Could not prepare an expired portal session fixture.');
    $_SESSION['last_activity_at'] = time() - 1900;
    session_write_close();
    session_save_path($restoreSessionPath);
    session_name($restoreSessionName);
    session_id($restoreSessionId);
    [$status, $body] = $request('/portal/', $tokenCookie);
    if ($status !== 200 || !str_contains($body, 'Acesse sua carteirinha') || str_contains($body, 'Portal HTTP Holder')) {
        throw new RuntimeException('An expired portal session must return to the generic login page.');
    }

    [$status, , $headers] = $request('/portal/?token=' . rawurlencode($token['token']));
    if ($status !== 303 || portalPageHeaderValue($headers, 'Location') !== '/portal/') {
        throw new RuntimeException('A renewed private link must start a clean session without preserving its token in the URL.');
    }
    preg_match('/^IEPRCARDPORTAL=([^;]+)/', (string) portalPageHeaderValue($headers, 'Set-Cookie'), $revokedSessionMatch);
    $revokedCookie = isset($revokedSessionMatch[1]) ? 'IEPRCARDPORTAL=' . $revokedSessionMatch[1] : null;
    $revokePdo = dbConnect($testDbConfiguration);
    $revokePdo->prepare('UPDATE titulares SET acesso_app_liberado = 0 WHERE id = :holder')->execute(['holder' => $holderId]);
    unset($revokePdo);
    [$status, $body] = $request('/portal/', $revokedCookie);
    if ($status !== 200 || !str_contains($body, 'Acesse sua carteirinha') || str_contains($body, 'Portal HTTP Holder')) {
        throw new RuntimeException('Revoked titular access must return the authenticated session to login.');
    }
    $revokePdo = dbConnect($testDbConfiguration);
    $revokePdo->prepare('UPDATE titulares SET acesso_app_liberado = 1 WHERE id = :holder')->execute(['holder' => $holderId]);
    unset($revokePdo);

    [$status, , $headers] = $request('/portal/?token=' . rawurlencode($token['token']));
    preg_match('/^IEPRCARDPORTAL=([^;]+)/', (string) portalPageHeaderValue($headers, 'Set-Cookie'), $finalSessionMatch);
    $finalCookie = isset($finalSessionMatch[1]) ? 'IEPRCARDPORTAL=' . $finalSessionMatch[1] : null;
    [$status, $body] = $request('/portal/', $finalCookie);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $csrfMatch);
    [$status, , $headers] = $request('/portal/', $finalCookie, 'POST', ['action' => 'logout', 'csrf_token' => $csrfMatch[1] ?? '']);
    if ($status !== 303 || portalPageHeaderValue($headers, 'Location') !== '/portal/'
        || !str_contains((string) portalPageHeaderValue($headers, 'Set-Cookie'), 'expires=')) {
        throw new RuntimeException('Portal logout must be CSRF-protected and destroy the authenticated session.');
    }
    [$status, $body] = $request('/portal/', $finalCookie);
    if ($status !== 200 || !str_contains($body, 'Acesse sua carteirinha') || str_contains($body, 'Portal HTTP Holder')) {
        throw new RuntimeException('A logged-out session must no longer expose holder cards.');
    }
    [$status] = $request('/portal/', null, 'DELETE');
    if ($status !== 405) throw new RuntimeException('The titular portal must reject unsupported HTTP methods.');

    fwrite(STDOUT, 'PASS: titular portal HTML login, token cleanup, session rotation, scoped card selection, private WebP and logout (' . $testDbConfiguration['DB_DRIVER'] . ").\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($oldSessionPath);
    session_name($oldSessionName);
    session_id($oldSessionId);
    session_set_cookie_params($oldCookieParams);
    $_SESSION = $oldSession;
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) putenv($key);
        else putenv($key . '=' . $value);
    }
    $cleanupPdo = dbConnect($testDbConfiguration);
    $cleanupPdo->prepare('DELETE FROM carteirinhas_emitidas WHERE titular_id = :holder')->execute(['holder' => $holderId]);
    $cleanupPdo->prepare('DELETE FROM titulares WHERE id = :holder')->execute(['holder' => $holderId]);
    $cleanupPdo->prepare('DELETE FROM designs_carteirinha WHERE id = :design')->execute(['design' => $designId]);
    tenantTestCleanup($cleanupPdo, $fixture);
    unset($cleanupPdo);
    foreach ($cardFilePaths as $path) if (is_file($path)) @unlink($path);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
        elseif (is_dir($path)) {
            foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) if (is_file($child)) @unlink($child);
            @rmdir($path);
        }
    }
    @rmdir($directory);
}

function portalPageHeaderValue(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
    }

    return null;
}

return 'pass';
