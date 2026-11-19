<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-qr-http-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create temporary QR HTTP test storage.');
}
$databasePath = $tempDirectory . DIRECTORY_SEPARATOR . 'qr.sqlite';
$rateLimitPath = $tempDirectory . DIRECTORY_SEPARATOR . 'rate-limit.json';
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('IEPRCARD_QR_RATE_LIMIT_PATH=' . $rateLimitPath);
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$pdo->prepare(
    'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) VALUES (:tenant, :name, :orientation, :created)'
)->execute(['tenant' => $fixture['tenant_id'], 'name' => 'HTTP QR', 'orientation' => 'horizontal', 'created' => gmdate('Y-m-d H:i:s')]);
$designId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, ativo, criado_em) VALUES (:tenant, :unit, :name, 1, :created)'
)->execute(['tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'name' => 'HTTP QR Holder', 'created' => gmdate('Y-m-d H:i:s')]);
$holderId = (int) $pdo->lastInsertId();
$token = bin2hex(random_bytes(32));
$pdo->prepare(
    'INSERT INTO carteirinhas_emitidas (tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, emitida_em) '
    . 'VALUES (:tenant, :unit, :holder, :design, :token, :status, :created)'
)->execute([
    'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'holder' => $holderId,
    'design' => $designId, 'token' => $token, 'status' => 'ativa', 'created' => gmdate('Y-m-d H:i:s'),
]);
unset($pdo);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($socket === false) {
    throw new RuntimeException('Could not allocate a QR HTTP test port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'router.php'];
$process = proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => ['file', $tempDirectory . DIRECTORY_SEPARATOR . 'out.log', 'a'], 2 => ['file', $tempDirectory . DIRECTORY_SEPARATOR . 'err.log', 'a']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the QR HTTP test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $request = static function (string $path) use ($baseUrl): array {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $match);
        return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $headers];
    };
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeCode, $probeMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    if (!$ready) throw new RuntimeException('The QR HTTP test server did not become ready.');
    [$status, $body, $headers] = $request('/validar/not-a-token');
    $invalid = json_decode($body, true);
    if ($status !== 200 || $invalid !== ['status' => 'invalida', 'motivo_codigo' => 'invalida']
        || !str_contains(implode("\n", $headers), 'application/json')
        || !str_contains(implode("\n", $headers), 'no-store') || str_contains($body, '<html')) {
        throw new RuntimeException('The public route must return the generic no-store JSON contract, not the local diagnostic page.');
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 200 || json_decode($body, true) !== ['status' => 'ativa', 'motivo_codigo' => 'ativa']) {
        throw new RuntimeException('The routed QR URL must resolve and return only the approved public card state.');
    }
    for ($attempt = 0; $attempt < 58; $attempt++) {
        [$status] = $request('/validar/' . $token);
        if ($status !== 200) {
            throw new RuntimeException('The per-IP rate limit must permit the first 60 requests in one minute.');
        }
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 429 || json_decode($body, true) !== ['erro' => 'limite_de_leituras']) {
        throw new RuntimeException('The 61st request in a minute must be rate limited.');
    }
    $rateData = (string) file_get_contents($rateLimitPath);
    if (str_contains($rateData, '127.0.0.1') || preg_match('/"[a-f0-9]{64}"\s*:\s*\{"window":\d+,"count":\d+\}/', $rateData) !== 1) {
        throw new RuntimeException('The QR limiter must retain only a one-minute IP hash and request count, never the raw address.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $audit = $pdo->query("SELECT COUNT(*) FROM auditoria_processos WHERE acao = 'carteirinha.qr_lida'")->fetchColumn();
    if ((int) $audit !== 59) {
        throw new RuntimeException('Rate-limited attempts must not increment card reads or write audit rows.');
    }
    unset($pdo);
    file_put_contents($rateLimitPath, '{}', LOCK_EX);
    if (file_put_contents($databasePath, 'invalid sqlite file', LOCK_EX) === false) {
        throw new RuntimeException('Could not prepare the controlled database failure case.');
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 503 || json_decode($body, true) !== ['erro' => 'validacao_indisponivel']
        || str_contains($body, 'sqlite') || str_contains($body, $databasePath)) {
        throw new RuntimeException('Database failures must return a generic service error without storage details.');
    }
    fwrite(STDOUT, "PASS: QR validation routes through local PHP with generic JSON, no-store headers and ephemeral-IP rate limiting.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($tempDirectory);
}

return 'pass';
