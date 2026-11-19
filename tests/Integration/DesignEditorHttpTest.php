<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$designEditorHttpRoot = dirname(__DIR__, 2);
$designEditorHttpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-design-editor-' . bin2hex(random_bytes(6));
mkdir($designEditorHttpDirectory, 0700, true);
$designEditorHttpDatabase = $designEditorHttpDirectory . DIRECTORY_SEPARATOR . 'editor.sqlite';
$designEditorHttpPrivateRoot = $designEditorHttpDirectory . DIRECTORY_SEPARATOR . 'private';
$designEditorHttpMysql = getenv('TEST_MYSQL_HOST') !== false && getenv('TEST_MYSQL_DATABASE') !== false
    && getenv('TEST_MYSQL_USERNAME') !== false && extension_loaded('pdo_mysql');
$designEditorHttpOldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME', 'IEPR_PRIVATE_STORAGE_PATH'] as $key) {
    $value = getenv($key);
    $designEditorHttpOldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=' . ($designEditorHttpMysql ? 'mysql' : 'sqlite'));
if ($designEditorHttpMysql) {
    putenv('DB_HOST=' . (getenv('TEST_MYSQL_HOST') ?: '127.0.0.1'));
    putenv('DB_PORT=' . (getenv('TEST_MYSQL_PORT') ?: '3306'));
    putenv('DB_DATABASE=' . (getenv('TEST_MYSQL_DATABASE') ?: ''));
    putenv('DB_USERNAME=' . (getenv('TEST_MYSQL_USERNAME') ?: ''));
    putenv('DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
} else {
    putenv('DB_SQLITE_PATH=' . $designEditorHttpDatabase);
}
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $designEditorHttpPrivateRoot);

$designEditorHttpConfiguration = $designEditorHttpMysql ? [
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => getenv('DB_HOST'),
    'DB_PORT' => getenv('DB_PORT'),
    'DB_DATABASE' => getenv('DB_DATABASE'),
    'DB_USERNAME' => getenv('DB_USERNAME'),
    'DB_PASSWORD' => getenv('DB_PASSWORD'),
] : ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $designEditorHttpDatabase];
$designEditorHttpPdo = dbConnect($designEditorHttpConfiguration);
schemaGarantirEstrutura($designEditorHttpPdo, 'test');
schemaAplicarMigracoes($designEditorHttpPdo, 'test');
$designEditorHttpSuffix = bin2hex(random_bytes(5));
$designEditorHttpPlan = $designEditorHttpPdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
$designEditorHttpPlan->execute(['name' => 'Design editor ' . $designEditorHttpSuffix]);
$designEditorHttpPlanId = (int) $designEditorHttpPdo->lastInsertId();
$designEditorHttpFlag = $designEditorHttpPdo->prepare(
    'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)'
);
foreach (['design.ver', 'design.criar', 'design.editar', 'design.mapear'] as $feature) {
    $designEditorHttpFlag->execute(['plan' => $designEditorHttpPlanId, 'feature' => $feature]);
}
$designEditorHttpSlug = 'design-' . $designEditorHttpSuffix;
$designEditorHttpTenantInsert = $designEditorHttpPdo->prepare(
    "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
    . "VALUES (:plan, 'cliente', :name, :slug, 'ativo')"
);
$designEditorHttpTenantInsert->execute([
    'plan' => $designEditorHttpPlanId,
    'name' => 'Sede design HTTP ' . $designEditorHttpSuffix,
    'slug' => $designEditorHttpSlug,
]);
$designEditorHttpTenantId = (int) $designEditorHttpPdo->lastInsertId();
$designEditorHttpForeignSlug = 'foreign-' . $designEditorHttpSuffix;
$designEditorHttpTenantInsert->execute([
    'plan' => $designEditorHttpPlanId,
    'name' => 'Outra Sede ' . $designEditorHttpSuffix,
    'slug' => $designEditorHttpForeignSlug,
]);
$designEditorHttpForeignTenantId = (int) $designEditorHttpPdo->lastInsertId();
$designEditorHttpUserInsert = $designEditorHttpPdo->prepare(
    'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) '
    . 'VALUES (:tenant, :role, :name, :email, :password, 1)'
);
$designEditorHttpUserInsert->execute([
    'tenant' => $designEditorHttpTenantId,
    'role' => 'titular_cliente',
    'name' => 'Design owner',
    'email' => 'design-' . $designEditorHttpSuffix . '@example.test',
    'password' => password_hash('design-editor-test-password', PASSWORD_DEFAULT),
]);
$designEditorHttpUserId = (int) $designEditorHttpPdo->lastInsertId();
unset($designEditorHttpPdo);

$designEditorHttpOldSessionName = session_name();
$designEditorHttpOldSessionId = session_id();
$designEditorHttpOldSessionPath = session_save_path();
$designEditorHttpOldCookieParams = session_get_cookie_params();
$designEditorHttpOldSettings = [];
foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_trans_sid', 'session.gc_maxlifetime'] as $setting) {
    $designEditorHttpOldSettings[$setting] = ini_get($setting);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($designEditorHttpDirectory);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start the design editor test session.');
$designEditorHttpSessionId = session_id();
$designEditorHttpCsrf = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $designEditorHttpUserId,
    'tenant_id' => $designEditorHttpTenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $designEditorHttpCsrf,
];
session_write_close();

$designEditorHttpProbe = stream_socket_server('tcp://127.0.0.1:0', $designEditorHttpErrorCode, $designEditorHttpErrorMessage);
if ($designEditorHttpProbe === false) throw new RuntimeException('Could not allocate a design editor HTTP port.');
$designEditorHttpAddress = stream_socket_get_name($designEditorHttpProbe, false);
fclose($designEditorHttpProbe);
$designEditorHttpPort = (int) substr((string) $designEditorHttpAddress, strrpos((string) $designEditorHttpAddress, ':') + 1);
$designEditorHttpNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$designEditorHttpServer = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $designEditorHttpDirectory, '-S', '127.0.0.1:' . $designEditorHttpPort, '-t', $designEditorHttpRoot . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', $designEditorHttpNull, 'r'],
    1 => ['file', $designEditorHttpDirectory . DIRECTORY_SEPARATOR . 'server.out.log', 'a'],
    2 => ['file', $designEditorHttpDirectory . DIRECTORY_SEPARATOR . 'server.err.log', 'a'],
], $designEditorHttpPipes, $designEditorHttpRoot);
if (!is_resource($designEditorHttpServer)) throw new RuntimeException('Could not start the design editor HTTP server.');

try {
    $designEditorHttpUrl = 'http://127.0.0.1:' . $designEditorHttpPort . '/tenant/designs.php';
    $designEditorHttpHost = $designEditorHttpSlug . '.ieprcard.test';
    $designEditorHttpStatus = 0;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl, null, 'unknown.ieprcard.test');
        if ($designEditorHttpStatus !== 0) break;
        usleep(100_000);
    }
    if ($designEditorHttpStatus !== 404) throw new RuntimeException('Unknown hosts must not reach design management.');

    [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl, null, $designEditorHttpHost);
    if ($designEditorHttpStatus !== 401) {
        throw new RuntimeException('Design management must reject a tenant without an authenticated session; received HTTP ' . $designEditorHttpStatus . '.');
    }

    $designEditorHttpCookie = 'IEPRCARDTENANT=' . rawurlencode($designEditorHttpSessionId);
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost);
    if ($designEditorHttpStatus !== 200 || !str_contains((string) $designEditorHttpBody, 'Designs da carteirinha')) {
        throw new RuntimeException('Authenticated Sede operators with design view permission must reach the catalogue; received HTTP ' . $designEditorHttpStatus . ': ' . (string) $designEditorHttpBody);
    }
    preg_match_all('/<img\b[^>]*>/i', (string) $designEditorHttpBody, $designEditorHttpImages);
    foreach ($designEditorHttpImages[0] as $designEditorHttpImage) {
        if (preg_match('/\bsrc\s*=/i', $designEditorHttpImage) !== 1) {
            throw new RuntimeException('An empty design canvas must not render a broken background image placeholder.');
        }
    }
    $designEditorHttpPermissionDb = dbConnect($designEditorHttpConfiguration);
    $designEditorHttpPermissionDb->prepare('UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :feature')
        ->execute(['plan' => $designEditorHttpPlanId, 'feature' => 'design.ver']);
    [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost);
    if ($designEditorHttpStatus !== 403) throw new RuntimeException('The catalogue must enforce the current plan and user permission on the server.');
    $designEditorHttpPermissionDb->prepare('UPDATE plano_feature_flags SET habilitado = 1 WHERE plano_id = :plan AND feature_key = :feature')
        ->execute(['plan' => $designEditorHttpPlanId, 'feature' => 'design.ver']);
    unset($designEditorHttpPermissionDb);

    $designEditorHttpDefaults = json_encode([
        'versao_schema' => '1.0',
        'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'],
        'elementos' => [],
    ], JSON_THROW_ON_ERROR);
    $designEditorHttpMapping = json_encode(['versao_schema' => '1.0', 'mapeamentos' => new stdClass()], JSON_THROW_ON_ERROR);
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, 'POST', http_build_query([
        'action' => 'preview', 'csrf_token' => $designEditorHttpCsrf, 'mapa_campos_json' => $designEditorHttpDefaults,
    ]));
    $designEditorHttpPreview = json_decode((string) $designEditorHttpBody, true);
    if ($designEditorHttpStatus !== 200 || !isset($designEditorHttpPreview['images']['frente'], $designEditorHttpPreview['images']['verso'])) {
        throw new RuntimeException('Preview must use the renderer and return both synthetic sides without saving; received HTTP ' . $designEditorHttpStatus . ': ' . (string) $designEditorHttpBody);
    }
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, 'POST', http_build_query([
        'csrf_token' => $designEditorHttpCsrf, 'nome_layout' => 'Mapa inválido',
        'mapa_campos_json' => '{invalid', 'mapeamento_fixo_json' => $designEditorHttpMapping,
    ]));
    if ($designEditorHttpStatus !== 200 || !str_contains((string) $designEditorHttpBody, 'invalid_json')) {
        throw new RuntimeException('Invalid maps must return a field-addressable validation message.');
    }
    [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, 'POST', http_build_query([
        'csrf_token' => str_repeat('0', 64), 'nome_layout' => 'No CSRF',
        'mapa_campos_json' => $designEditorHttpDefaults, 'mapeamento_fixo_json' => $designEditorHttpMapping,
    ]));
    if ($designEditorHttpStatus !== 403) throw new RuntimeException('Design mutation must require CSRF.');

    $designEditorHttpBackground = imagecreatetruecolor(32, 32);
    ob_start();
    imagepng($designEditorHttpBackground);
    $designEditorHttpBackgroundBytes = (string) ob_get_clean();
    imagedestroy($designEditorHttpBackground);
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpMultipartRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, [
        'csrf_token' => $designEditorHttpCsrf, 'nome_layout' => 'Rascunho validado',
        'mapa_campos_json' => $designEditorHttpDefaults, 'mapeamento_fixo_json' => $designEditorHttpMapping,
    ], ['fundo_frente' => ['name' => 'card.png', 'mime' => 'image/png', 'bytes' => $designEditorHttpBackgroundBytes]]);
    if ($designEditorHttpStatus !== 303) throw new RuntimeException('Valid design draft should save and redirect; received HTTP ' . $designEditorHttpStatus . '.');
    $designEditorHttpCheck = dbConnect($designEditorHttpConfiguration);
    $designEditorHttpDrafts = $designEditorHttpCheck->prepare('SELECT COUNT(*) FROM designs_carteirinha WHERE tenant_id = :tenant AND nome_layout = :name AND ativo = 0');
    $designEditorHttpDrafts->execute(['tenant' => $designEditorHttpTenantId, 'name' => 'Rascunho validado']);
    if ((int) $designEditorHttpDrafts->fetchColumn() !== 1) throw new RuntimeException('A valid save must persist exactly one inactive draft in the current tenant.');
    $designEditorHttpFindDraft = $designEditorHttpCheck->prepare('SELECT id FROM designs_carteirinha WHERE tenant_id = :tenant AND nome_layout = :name LIMIT 1');
    $designEditorHttpFindDraft->execute(['tenant' => $designEditorHttpTenantId, 'name' => 'Rascunho validado']);
    $designEditorHttpDraftId = (int) $designEditorHttpFindDraft->fetchColumn();
    $designEditorHttpAssetQuery = $designEditorHttpCheck->prepare('SELECT bg_frente_url, ativo FROM designs_carteirinha WHERE id = :id AND tenant_id = :tenant');
    $designEditorHttpAssetQuery->execute(['id' => $designEditorHttpDraftId, 'tenant' => $designEditorHttpTenantId]);
    $designEditorHttpAsset = $designEditorHttpAssetQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($designEditorHttpAsset)
        || preg_match('/^private:\/\/' . $designEditorHttpTenantId . '\/[a-f0-9]{48}\.png$/D', (string) $designEditorHttpAsset['bg_frente_url']) !== 1
        || !is_file($designEditorHttpPrivateRoot . DIRECTORY_SEPARATOR . $designEditorHttpTenantId . DIRECTORY_SEPARATOR . substr((string) $designEditorHttpAsset['bg_frente_url'], strrpos((string) $designEditorHttpAsset['bg_frente_url'], '/') + 1))
        || (int) $designEditorHttpAsset['ativo'] !== 0) {
        throw new RuntimeException('Backgrounds must be MIME-validated, private, tenant-keyed and saved with inactive drafts.');
    }
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpRequest(
        $designEditorHttpUrl . '?id=' . $designEditorHttpDraftId . '&asset=frente',
        $designEditorHttpCookie,
        $designEditorHttpHost
    );
    if ($designEditorHttpStatus !== 200 || !str_starts_with((string) $designEditorHttpBody, "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException('Private backgrounds must stream through an authenticated tenant-scoped route.');
    }
    unset($designEditorHttpCheck);
    [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl . '?id=' . $designEditorHttpDraftId, $designEditorHttpCookie, $designEditorHttpHost, 'POST', http_build_query([
        'csrf_token' => $designEditorHttpCsrf, 'design_id' => $designEditorHttpDraftId,
        'nome_layout' => 'Rascunho validado', 'mapa_campos_json' => $designEditorHttpDefaults,
        'mapeamento_fixo_json' => $designEditorHttpMapping,
    ]));
    if ($designEditorHttpStatus !== 303) throw new RuntimeException('An unchanged inactive design should remain safely editable.');
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpMultipartRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, [
        'csrf_token' => $designEditorHttpCsrf, 'nome_layout' => 'Invalid upload',
        'mapa_campos_json' => $designEditorHttpDefaults, 'mapeamento_fixo_json' => $designEditorHttpMapping,
    ], ['fundo_frente' => ['name' => 'bad.txt', 'mime' => 'text/plain', 'bytes' => 'not an image']]);
    if ($designEditorHttpStatus !== 200 || !str_contains((string) $designEditorHttpBody, 'da frente deve ser PNG')) {
        throw new RuntimeException('Invalid background uploads must be rejected before a draft is persisted.');
    }
    [$designEditorHttpStatus, $designEditorHttpBody] = designEditorHttpMultipartRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpHost, [
        'csrf_token' => $designEditorHttpCsrf, 'nome_layout' => 'Partial upload',
        'mapa_campos_json' => $designEditorHttpDefaults, 'mapeamento_fixo_json' => $designEditorHttpMapping,
    ], [
        'fundo_frente' => ['name' => 'card.png', 'mime' => 'image/png', 'bytes' => $designEditorHttpBackgroundBytes],
        'fundo_verso' => ['name' => 'bad.txt', 'mime' => 'text/plain', 'bytes' => 'not an image'],
    ]);
    $designEditorHttpStoredFiles = glob($designEditorHttpPrivateRoot . DIRECTORY_SEPARATOR . $designEditorHttpTenantId . DIRECTORY_SEPARATOR . '*') ?: [];
    if ($designEditorHttpStatus !== 200 || !str_contains((string) $designEditorHttpBody, 'do verso deve ser PNG') || count($designEditorHttpStoredFiles) !== 1) {
        throw new RuntimeException('A valid front upload must not be orphaned if the back upload is invalid.');
    }
    $designEditorHttpForeignHost = $designEditorHttpForeignSlug . '.ieprcard.test';
    [$designEditorHttpStatus] = designEditorHttpRequest($designEditorHttpUrl, $designEditorHttpCookie, $designEditorHttpForeignHost);
    if ($designEditorHttpStatus !== 401) throw new RuntimeException('A valid tenant session must not cross tenant host boundaries.');

    fwrite(STDOUT, "PASS: design editor protects scope and CSRF, previews synthetically, validates maps and saves inactive drafts.\n");
} finally {
    proc_terminate($designEditorHttpServer);
    proc_close($designEditorHttpServer);
    foreach (glob($designEditorHttpPrivateRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $privateFile) {
        if (is_file($privateFile)) @unlink($privateFile);
    }
    foreach (glob($designEditorHttpPrivateRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $privateTenantDirectory) {
        if (is_dir($privateTenantDirectory) && !is_link($privateTenantDirectory)) @rmdir($privateTenantDirectory);
    }
    if (is_dir($designEditorHttpPrivateRoot)) @rmdir($designEditorHttpPrivateRoot);
    if ($designEditorHttpMysql) {
        $cleanupPdo = dbConnect($designEditorHttpConfiguration);
        $cleanupPdo->prepare('DELETE FROM designs_carteirinha WHERE tenant_id IN (:tenant, :foreign)')
            ->execute(['tenant' => $designEditorHttpTenantId, 'foreign' => $designEditorHttpForeignTenantId]);
        $cleanupPdo->prepare('DELETE FROM usuarios WHERE tenant_id = :tenant')->execute(['tenant' => $designEditorHttpTenantId]);
        $cleanupPdo->prepare('DELETE FROM tenants WHERE id IN (:tenant, :foreign)')
            ->execute(['tenant' => $designEditorHttpTenantId, 'foreign' => $designEditorHttpForeignTenantId]);
        $cleanupPdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $designEditorHttpPlanId]);
        $cleanupPdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $designEditorHttpPlanId]);
        unset($cleanupPdo);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($designEditorHttpOldSessionPath);
    session_name($designEditorHttpOldSessionName);
    session_id($designEditorHttpOldSessionId);
    foreach ($designEditorHttpOldSettings as $setting => $value) if (is_string($value)) ini_set($setting, $value);
    session_set_cookie_params($designEditorHttpOldCookieParams);
    foreach ($designEditorHttpOldEnvironment as $key => $value) $value === null ? putenv($key) : putenv($key . '=' . $value);
    foreach (glob($designEditorHttpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($designEditorHttpDirectory);
}

return 'pass';

/** @return array{int,string|null} */
function designEditorHttpRequest(string $url, ?string $cookie, string $host, string $method = 'GET', string $body = ''): array
{
    $parts = parse_url($url);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) ($parts['port'] ?? 80), $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $headers = [strtoupper($method) . ' ' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') . " HTTP/1.1", 'Host: ' . $host, 'Connection: close'];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (strtoupper($method) === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);
    [$head, $body] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
    $status = preg_match('/\s(\d{3})\s/', explode("\r\n", $head)[0] ?? '', $match) === 1 ? (int) $match[1] : 0;
    return [$status, $body];
}

/** @param array<string,string> $fields @param array<string,array{name:string,mime:string,bytes:string}> $files @return array{int,string|null} */
function designEditorHttpMultipartRequest(string $url, string $cookie, string $host, array $fields, array $files): array
{
    $boundary = '----IEPR' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
    }
    foreach ($files as $field => $file) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . "\"; filename=\"" . $file['name'] . "\"\r\n";
        $body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n" . $file['bytes'] . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";
    $parts = parse_url($url);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) ($parts['port'] ?? 80), $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $headers = [
        'POST ' . $path . " HTTP/1.1", 'Host: ' . $host, 'Connection: close',
        'Cookie: ' . $cookie, 'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Content-Length: ' . strlen($body),
    ];
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);
    [$head, $responseBody] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
    $status = preg_match('/\s(\d{3})\s/', explode("\r\n", $head)[0] ?? '', $match) === 1 ? (int) $match[1] : 0;
    return [$status, $responseBody];
}
