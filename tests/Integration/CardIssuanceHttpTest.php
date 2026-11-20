<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$issuanceHttpRoot = dirname(__DIR__, 2);
$issuanceHttpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-issuance-http-' . bin2hex(random_bytes(5));
mkdir($issuanceHttpDirectory, 0700, true);
$issuanceHttpDatabase = $issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'issuance.sqlite';
$issuanceHttpOld = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME', 'IEPR_PRIVATE_STORAGE_PATH'] as $key) {
    $value = getenv($key);
    $issuanceHttpOld[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $issuanceHttpDatabase);
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'private');
$configuration = ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $issuanceHttpDatabase];
$pdo = dbConnect($configuration);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')->execute(['name' => 'Issuance ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
foreach (['carteirinha.gerar', 'titular.ver'] as $key) $flag->execute(['plan' => $planId, 'key' => $key]);
$slug = 'issue-' . $suffix;
$pdo->prepare("INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_carteirinhas) VALUES (:plan, 'cliente', :name, :slug, 'ativo', 20)")
    ->execute(['plan' => $planId, 'name' => 'Issuance test', 'slug' => $slug]);
$tenantId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')
    ->execute(['permissions' => json_encode(['carteirinha.gerar'], JSON_THROW_ON_ERROR), 'tenant' => $tenantId]);
$unitSlug = 'unit-' . $suffix;
$pdo->prepare("INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) VALUES (:parent, 'subcliente', :name, :slug, 'ativo')")
    ->execute(['parent' => $tenantId, 'name' => 'Unit scope', 'slug' => $unitSlug]);
$unitId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', :name, :slug, 'ativo')")
    ->execute(['name' => 'Foreign test', 'slug' => 'foreign-' . $suffix]);
$foreignTenantId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (:tenant, :role, :name, :email, :password, 1)')
    ->execute(['tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Issuer', 'email' => 'issuer-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (:tenant, :unit, :role, :name, :email, :password, 1)')
    ->execute([
        'tenant' => $tenantId, 'unit' => $unitId, 'role' => 'responsavel_sub', 'name' => 'Unit issuer',
        'email' => 'unit-issuer-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
    ]);
$unitUserId = (int) $pdo->lastInsertId();
$designMap = ['versao_schema' => '1.0', 'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'], 'elementos' => [[
    'id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
    'rotulo_amigavel' => 'Nome do titular', 'obrigatorio' => true, 'x' => 25, 'y' => 20,
    'largura_max' => 800, 'fonte_arquivo' => 'Inter-Bold.otf', 'tamanho_pt' => 18, 'cor_hex' => '#000000',
    'alinhamento' => 'left', 'transformacao' => 'none', 'auto_reduzir_fonte_se_longo' => true,
]]];
$background = imagecreatetruecolor(2, 2);
if (!$background instanceof GdImage) throw new RuntimeException('Could not allocate an issuance preview background.');
ob_start();
imagepng($background);
$png = ob_get_clean();
imagedestroy($background);
if (!is_string($png)) throw new RuntimeException('Could not encode an issuance preview background.');
$assetDirectory = $issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . (string) $tenantId;
mkdir($assetDirectory, 0700, true);
$frontKey = str_repeat('a', 48) . '.png';
$backKey = str_repeat('b', 48) . '.png';
file_put_contents($assetDirectory . DIRECTORY_SEPARATOR . $frontKey, $png);
file_put_contents($assetDirectory . DIRECTORY_SEPARATOR . $backKey, $png);
$pdo->prepare('INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, bg_frente_url, bg_verso_url, mapa_campos_json, mapeamento_fixo_json, ativo, compartilhado_subclientes, criado_em) VALUES (:tenant, :name, :orientation, :front, :back, :map, :mapping, 1, 1, :created)')
    ->execute([
        'tenant' => $tenantId, 'name' => 'Design ativo', 'orientation' => 'horizontal',
        'front' => 'private://' . $tenantId . '/' . $frontKey, 'back' => 'private://' . $tenantId . '/' . $backKey,
        'map' => json_encode($designMap, JSON_THROW_ON_ERROR),
        'mapping' => json_encode(['versao_schema' => '1.0', 'mapeamentos' => ['nome' => ['tipo' => 'campo', 'origem' => 'titular.nome_completo']]], JSON_THROW_ON_ERROR),
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
$designId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO lotes_importacao (tenant_id, usuario_id, origem_tipo, total_registros, status_lote, criado_em, finalizado_em) VALUES (:tenant, :user, :origin, 1, :status, :created, :finished)')
    ->execute(['tenant' => $tenantId, 'user' => $userId, 'origin' => 'csv', 'status' => 'concluido', 'created' => gmdate('Y-m-d H:i:s'), 'finished' => gmdate('Y-m-d H:i:s')]);
$sourceLotId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO titulares (tenant_id, nome_completo, lote_origem_id, ativo, criado_em) VALUES (:tenant, :name, :lot, 1, :created)')
    ->execute(['tenant' => $tenantId, 'name' => 'Titular importado', 'lot' => $sourceLotId, 'created' => gmdate('Y-m-d H:i:s')]);
$pdo->prepare('INSERT INTO titulares (tenant_id, nome_completo, dados_extras_json, ativo, criado_em) VALUES (:tenant, :name, :extra, 1, :created)')
    ->execute(['tenant' => $tenantId, 'name' => 'Titular manual', 'extra' => json_encode(['ministerio' => 'Áudio'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created' => gmdate('Y-m-d H:i:s')]);
$holderId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, ativo, criado_em) VALUES (:tenant, :unit, :name, 1, :created)')
    ->execute(['tenant' => $tenantId, 'unit' => $unitId, 'name' => 'Titular da Unidade', 'created' => gmdate('Y-m-d H:i:s')]);
$unitHolderId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO titulares (tenant_id, nome_completo, ativo, criado_em) VALUES (:tenant, :name, 1, :created)')
    ->execute(['tenant' => $foreignTenantId, 'name' => 'Titular externo secreto', 'created' => gmdate('Y-m-d H:i:s')]);
$foreignHolderId = (int) $pdo->lastInsertId();
unset($pdo);

$oldSessionName = session_name();
$oldSessionId = session_id();
$oldSessionPath = session_save_path();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($issuanceHttpDirectory);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start the issuance test session.');
$sessionId = session_id();
$csrfToken = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $userId, 'tenant_id' => $tenantId, 'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente', 'tenant_authenticated_at' => time(),
    'last_activity_at' => time(), 'csrf_token' => $csrfToken,
];
session_write_close();
$unitCsrfToken = bin2hex(random_bytes(32));
session_id('');
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start the unit issuance test session.');
$unitSessionId = session_id();
$_SESSION = [
    'tenant_user_id' => $unitUserId, 'tenant_id' => $tenantId, 'subcliente_id' => $unitId,
    'papel_sistema' => 'responsavel_sub', 'tenant_authenticated_at' => time(),
    'last_activity_at' => time(), 'csrf_token' => $unitCsrfToken,
];
session_write_close();
$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($probe === false) throw new RuntimeException('Could not allocate an issuance HTTP port.');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $issuanceHttpDirectory, '-S', '127.0.0.1:' . $port, '-t', $issuanceHttpRoot . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', $null, 'r'], 1 => ['file', $issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'server.out.log', 'a'],
    2 => ['file', $issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'server.err.log', 'a'],
], $pipes, $issuanceHttpRoot);
if (!is_resource($server)) throw new RuntimeException('Could not start the issuance HTTP server.');

try {
    $url = 'http://127.0.0.1:' . $port . '/tenant/emitir.php';
    $host = $slug . '.ieprcard.test';
    $status = 0;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        [$status] = issuanceHttpRequest($url, null, $host);
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 401) throw new RuntimeException('Emission wizard must require an authenticated tenant session.');
    [$status, $body] = issuanceHttpRequest($url, 'IEPRCARDTENANT=' . rawurlencode($sessionId), $host);
    if ($status !== 200 || !str_contains((string) $body, 'Emitir carteirinhas')
        || !str_contains((string) $body, 'Titular manual') || !str_contains((string) $body, 'Design ativo')
        || !str_contains((string) $body, 'Lote #') || !str_contains((string) $body, 'Titular importado')
        || !str_contains((string) $body, 'titular.dados_extras.ministerio')
        || str_contains((string) $body, 'Titular externo secreto')) {
        throw new RuntimeException('Authorized tenant must see the issuance wizard and only its eligible sources/designs; HTTP ' . $status . '.');
    }
    $unitHost = $unitSlug . '.' . $slug . '.ieprcard.test';
    [$status, $unitBody] = issuanceHttpRequest($url, 'IEPRCARDTENANT=' . rawurlencode($unitSessionId), $unitHost);
    if ($status !== 200 || !str_contains((string) $unitBody, 'Titular da Unidade')
        || str_contains((string) $unitBody, 'Titular manual') || str_contains((string) $unitBody, 'Titular importado')) {
        throw new RuntimeException('Unit operator must see only sources from the authorized unit.');
    }
    $cookie = 'IEPRCARDTENANT=' . rawurlencode($sessionId);
    [$status] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query(['csrf_token' => 'invalid', 'action' => 'enqueue']));
    if ($status !== 403) throw new RuntimeException('The wizard must reject requests without a valid CSRF token.');
    $previewPayload = [
        'csrf_token' => $csrfToken, 'action' => 'preview', 'source_type' => 'holder', 'source_id' => (string) $holderId,
        'design_id' => (string) $designId, 'output_mode' => 'webp', 'mapping_mode' => 'fixed',
        'mapping_json' => '', 'idempotency_key' => str_repeat('c', 64),
    ];
    [$status, $previewBody] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($previewPayload));
    $previewResult = json_decode((string) $previewBody, true);
    if ($status !== 200 || !is_array($previewResult) || ($previewResult['valid'] ?? false) !== true
        || !isset($previewResult['images']['frente'], $previewResult['images']['verso'])) {
        throw new RuntimeException('Authorized preview must render the selected source without creating a job; HTTP ' . $status . ': ' . (string) $previewBody);
    }
    $invalidMapping = $previewPayload;
    $invalidMapping['mapping_mode'] = 'custom';
    $invalidMapping['mapping_json'] = json_encode(['versao_schema' => '1.0', 'mapeamentos' => (object) []], JSON_THROW_ON_ERROR);
    [$status, $invalidMappingBody] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($invalidMapping));
    if ($status !== 422 || str_contains((string) $invalidMappingBody, 'Titular manual')) {
        throw new RuntimeException('Preview must reject a custom mapping that omits a required field without exposing sample data.');
    }
    $faultDb = dbConnect($configuration);
    $faultDb->prepare('UPDATE designs_carteirinha SET mapa_campos_json = :map WHERE id = :design AND tenant_id = :tenant')
        ->execute(['map' => '{invalid-json', 'design' => $designId, 'tenant' => $tenantId]);
    unset($faultDb);
    [$status, $invalidDesignBody] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($previewPayload));
    if ($status !== 422 || !str_contains((string) $invalidDesignBody, 'Não foi possível validar esta prévia.')
        || str_contains((string) $invalidDesignBody, 'Titular manual')) {
        throw new RuntimeException('Preview must return a safe client error when stored design data is malformed; HTTP ' . $status . '.');
    }
    $restoreDb = dbConnect($configuration);
    $restoreDb->prepare('UPDATE designs_carteirinha SET mapa_campos_json = :map WHERE id = :design AND tenant_id = :tenant')
        ->execute(['map' => json_encode($designMap, JSON_THROW_ON_ERROR), 'design' => $designId, 'tenant' => $tenantId]);
    unset($restoreDb);
    $validCustomMapping = $invalidMapping;
    $validCustomMapping['mapping_json'] = json_encode(['versao_schema' => '1.0', 'mapeamentos' => ['nome' => ['tipo' => 'valor', 'valor' => 'Prévia autorizada']]], JSON_THROW_ON_ERROR);
    [$status, $validCustomBody] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($validCustomMapping));
    $validCustomResult = json_decode((string) $validCustomBody, true);
    if ($status !== 200 || !is_array($validCustomResult) || ($validCustomResult['valid'] ?? false) !== true) {
        throw new RuntimeException('Preview must validate and render a job-specific literal mapping; HTTP ' . $status . ': ' . (string) $validCustomBody);
    }
    $foreignPreview = $previewPayload;
    $foreignPreview['source_id'] = (string) $foreignHolderId;
    [$status, $foreignBody] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($foreignPreview));
    if ($status !== 422 || str_contains((string) $foreignBody, 'Titular externo secreto')) {
        throw new RuntimeException('Preview must reject and redact a holder outside the current tenant.');
    }
    $unitPreview = $previewPayload;
    $unitPreview['csrf_token'] = $unitCsrfToken;
    $unitPreview['source_id'] = (string) $holderId;
    [$status, $unitDeniedBody] = issuanceHttpRequest($url, 'IEPRCARDTENANT=' . rawurlencode($unitSessionId), $unitHost, 'POST', http_build_query($unitPreview));
    if ($status !== 422 || str_contains((string) $unitDeniedBody, 'Titular manual')) {
        throw new RuntimeException('Unit operator must not preview a holder from the Sede.');
    }
    $unitPreview['source_id'] = (string) $unitHolderId;
    [$status, $unitPreviewBody] = issuanceHttpRequest($url, 'IEPRCARDTENANT=' . rawurlencode($unitSessionId), $unitHost, 'POST', http_build_query($unitPreview));
    $unitPreviewResult = json_decode((string) $unitPreviewBody, true);
    if ($status !== 200 || !is_array($unitPreviewResult) || ($unitPreviewResult['valid'] ?? false) !== true) {
        throw new RuntimeException('Unit operator must preview the selected authorized source.');
    }
    $check = dbConnect($configuration);
    $jobCount = (int) $check->query("SELECT COUNT(*) FROM lotes_importacao WHERE origem_tipo = 'emissao'")->fetchColumn();
    if ($jobCount !== 0 || (int) $check->query('SELECT consumo_carteirinhas FROM tenants WHERE id = ' . $tenantId)->fetchColumn() !== 0) {
        throw new RuntimeException('Preview must not create a job or reserve quota.');
    }
    $enqueuePayload = $previewPayload;
    $enqueuePayload['action'] = 'enqueue';
    [$status] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($enqueuePayload));
    if ($status !== 303) throw new RuntimeException('A valid issuance request must redirect after enqueue; HTTP ' . $status . '.');
    [$status] = issuanceHttpRequest($url, $cookie, $host, 'POST', http_build_query($enqueuePayload));
    $jobCount = (int) $check->query("SELECT COUNT(*) FROM lotes_importacao WHERE origem_tipo = 'emissao'")->fetchColumn();
    $jobQuantity = (int) $check->query("SELECT total_registros FROM lotes_importacao WHERE origem_tipo = 'emissao' AND tenant_id = " . $tenantId)->fetchColumn();
    $consumed = (int) $check->query('SELECT consumo_carteirinhas FROM tenants WHERE id = ' . $tenantId)->fetchColumn();
    if ($status !== 303 || $jobCount !== 1 || $jobQuantity !== 1 || $consumed !== 1) {
        throw new RuntimeException('Repeated confirmation must reuse one individual job and reserve exactly one unit of quota.');
    }
    unset($check);
    fwrite(STDOUT, "PASS: authenticated tenant sees the authorized issuance wizard sources and designs.\n");
} finally {
    proc_terminate($server);
    proc_close($server);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($oldSessionPath);
    session_name($oldSessionName);
    session_id($oldSessionId);
    foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME', 'IEPR_PRIVATE_STORAGE_PATH'] as $key) {
        $value = $issuanceHttpOld[$key] ?? null;
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    foreach (glob($issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $asset) if (is_file($asset)) @unlink($asset);
    foreach (glob($issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . '*') ?: [] as $directory) if (is_dir($directory)) @rmdir($directory);
    @rmdir($issuanceHttpDirectory . DIRECTORY_SEPARATOR . 'private');
    foreach (glob($issuanceHttpDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($issuanceHttpDirectory);
}

return 'pass';

/** @return array{int,string|null} */
function issuanceHttpRequest(string $url, ?string $cookie, string $host, string $method = 'GET', string $body = ''): array
{
    $parts = parse_url($url);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . (int) ($parts['port'] ?? 80), $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $headers = [strtoupper($method) . ' ' . ($parts['path'] ?? '/') . " HTTP/1.1", 'Host: ' . $host, 'Connection: close'];
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
