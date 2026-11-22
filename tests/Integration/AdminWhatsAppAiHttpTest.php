<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';

$root = dirname(__DIR__, 2);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-ai-admin-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$database = $dir . DIRECTORY_SEPARATOR . 'app.sqlite';
$old = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'PLATFORM_HOSTNAME', 'IEPR_INTEGRATION_KEY'] as $key) {
    $value = getenv($key); $old[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev'); putenv('DB_DRIVER=sqlite'); putenv('DB_SQLITE_PATH=' . $database);
putenv('PLATFORM_HOSTNAME=127.0.0.1');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $database]);
schemaAplicarMigracoes($pdo, 'test'); schemaGarantirEstrutura($pdo, 'test');
$password = 'admin-http-synthetic-password';
$pdo->prepare("INSERT INTO usuarios (papel_sistema,nome,email,senha_hash,ativo) VALUES ('superadmin','AI Admin','ai-admin@example.test',:hash,1)")
    ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT)]);
$identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
whatsappGatewayConfigSave($pdo, $identity, 'https://gowa.example.test', 'synthetic-gowa-secret');
aiGlobalConfigSave($pdo, $identity, 'openai', 'synthetic-ai-secret', 'gpt-test-model');
unset($pdo);
$probe = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if (!is_resource($probe)) throw new RuntimeException('Could not allocate AI admin HTTP test port.');
$address = stream_socket_get_name($probe, false); fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public'], [
    0 => ['file', $null, 'r'], 1 => ['file', $dir . DIRECTORY_SEPARATOR . 'out.log', 'a'], 2 => ['file', $dir . DIRECTORY_SEPARATOR . 'err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) throw new RuntimeException('Could not start AI admin HTTP server.');
try {
    $base = 'http://127.0.0.1:' . $port;
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = aiAdminHttpRequest($base . '/admin/atendimento-ia.php');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 303) throw new RuntimeException('AI settings must require an authenticated superadmin session.');
    [, , $headers] = aiAdminHttpRequest($base . '/admin/login.php');
    $cookie = aiAdminCookie($headers);
    [, $login] = aiAdminHttpRequest($base . '/admin/login.php', 'GET', null, $cookie);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $login, $match)) throw new RuntimeException('Login form did not expose a CSRF token.');
    [$status, , $headers] = aiAdminHttpRequest($base . '/admin/login.php', 'POST', http_build_query([
        'email' => 'ai-admin@example.test', 'password' => $password, 'csrf_token' => $match[1],
    ]), $cookie);
    $cookie = aiAdminCookie($headers) ?? $cookie;
    if ($status !== 303 || $cookie === null) throw new RuntimeException('Superadmin login did not create a session.');
    [$status, $page] = aiAdminHttpRequest($base . '/admin/atendimento-ia.php', 'GET', null, $cookie);
    if ($status !== 200 || !str_contains((string) $page, 'Atendimento e IA')
        || !str_contains((string) $page, 'Habilitar cadastro e emissão neste fluxo')
        || str_contains((string) $page, 'synthetic-gowa-secret') || str_contains((string) $page, 'synthetic-ai-secret')
        || str_contains((string) $page, 'chave_cifrada') || str_contains((string) $page, 'api_token_cifrado')) {
        throw new RuntimeException('Admin settings must render controls while keeping credential values and storage fields private.');
    }
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', (string) $page, $match)) throw new RuntimeException('Settings page did not expose session-bound CSRF tokens.');
    [$status] = aiAdminHttpRequest($base . '/admin/atendimento-ia.php', 'POST', http_build_query([
        'action' => 'save_ai', 'provider' => 'openai', 'model' => 'gpt-test-model', 'csrf_token' => str_repeat('0', 64),
    ]), $cookie);
    if ($status !== 403) throw new RuntimeException('AI settings must reject invalid CSRF tokens.');
    fwrite(STDOUT, "PASS: AI admin HTTP screen requires superadmin, CSRF and never renders stored secrets.\n");
} finally {
    proc_terminate($process); proc_close($process);
    foreach ($old as $key => $value) $value === null ? putenv($key) : putenv($key . '=' . $value);
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($dir);
}
return 'pass';

/** @return array{int,string|null,list<string>} */
function aiAdminHttpRequest(string $url, string $method = 'GET', ?string $body = null, ?string $cookie = null): array
{
    $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 4,
    ]]);
    $response = @file_get_contents($url, false, $context); $responseHeaders = $http_response_header ?? [];
    $status = isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match) ? (int) $match[1] : 0;
    return [$status, is_string($response) ? $response : null, $responseHeaders];
}

/** @param list<string> $headers */
function aiAdminCookie(array $headers): ?string
{
    foreach ($headers as $header) if (stripos($header, 'Set-Cookie: IEPRCARDADMIN=') === 0) return explode(';', substr($header, 12), 2)[0];
    return null;
}
