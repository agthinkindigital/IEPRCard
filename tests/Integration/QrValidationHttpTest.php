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
$statusTokens = [];
foreach (['suspensa', 'vencida', 'revogada'] as $cardStatus) {
    $statusToken = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas (tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, emitida_em) '
        . 'VALUES (:tenant, :unit, :holder, :design, :token, :status, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'holder' => $holderId,
        'design' => $designId, 'token' => $statusToken, 'status' => $cardStatus, 'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $statusTokens[$cardStatus] = $statusToken;
}
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
    $request = static function (string $path, string $method = 'GET') use ($baseUrl): array {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 3]]);
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
    if ($status !== 200 || !str_contains($body, 'Código inválido')
        || !str_contains(implode("\n", $headers), 'text/html')
        || !str_contains(implode("\n", $headers), 'no-store')
        || !str_contains(implode("\n", $headers), 'Referrer-Policy: no-referrer')
        || !str_contains(implode("\n", $headers), 'X-Robots-Tag: noindex, nofollow')
        || !str_contains(implode("\n", $headers), 'X-Content-Type-Options: nosniff')
        || !str_contains(implode("\n", $headers), "script-src 'none'")
        || !str_contains(implode("\n", $headers), 'X-Frame-Options: DENY')
        || stripos(implode("\n", $headers), 'Set-Cookie:') !== false
        || !str_contains($body, 'Validar outra carteirinha')) {
        throw new RuntimeException('The public route must render the generic HTML status and a path to validate another code.');
    }
    $xssProbe = '<script>alert(1)</script>';
    [$status, $body] = $request('/validar/' . rawurlencode($xssProbe));
    if ($status !== 200 || !str_contains($body, 'Código inválido')
        || str_contains($body, $xssProbe) || str_contains($body, '<script>')) {
        throw new RuntimeException('Untrusted QR input must remain unreflected in the public HTML page.');
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 200 || !str_contains($body, 'Carteirinha ativa')
        || str_contains($body, 'HTTP QR Holder') || str_contains($body, $token)) {
        throw new RuntimeException('The routed QR URL must render only the approved public card state.');
    }
    [$status, $body, $headers] = $request('/validar/' . $token, 'POST');
    if ($status !== 405 || !str_contains(implode("\n", $headers), 'Allow: GET')
        || str_contains($body, $token) || str_contains($body, 'HTTP QR Holder')) {
        throw new RuntimeException('The public validator must accept only stateless GET requests.');
    }
    [$status, $body] = $request('/validar/?codigo=' . $token);
    if ($status !== 200 || !str_contains($body, 'Carteirinha ativa')) {
        throw new RuntimeException('The public form must accept another code through the canonical route.');
    }
    foreach ([
        'suspensa' => 'Carteirinha suspensa',
        'vencida' => 'Carteirinha vencida',
        'revogada' => 'Carteirinha revogada',
    ] as $cardStatus => $expectedTitle) {
        [$status, $body] = $request('/validar/' . $statusTokens[$cardStatus]);
        if ($status !== 200 || !str_contains($body, $expectedTitle)
            || str_contains($body, 'HTTP QR Holder') || str_contains($body, $statusTokens[$cardStatus])) {
            throw new RuntimeException('The public page must render the generic ' . $cardStatus . ' state without holder data.');
        }
    }
    file_put_contents($rateLimitPath, '{}', LOCK_EX);
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $auditBeforeRateLimit = (int) $pdo->query("SELECT COUNT(*) FROM auditoria_processos WHERE acao = 'carteirinha.qr_lida'")->fetchColumn();
    unset($pdo);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        [$status] = $request('/validar/' . $token);
        if ($status !== 200) {
            throw new RuntimeException('The per-IP rate limit must permit the first 60 requests in one minute.');
        }
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 429 || !str_contains($body, 'Muitas consultas')
        || str_contains($body, $token) || str_contains($body, 'HTTP QR Holder')) {
        throw new RuntimeException('The 61st request in a minute must be rate limited.');
    }
    $rateData = (string) file_get_contents($rateLimitPath);
    if (str_contains($rateData, '127.0.0.1') || preg_match('/"[a-f0-9]{64}"\s*:\s*\{"window":\d+,"count":\d+\}/', $rateData) !== 1) {
        throw new RuntimeException('The QR limiter must retain only a one-minute IP hash and request count, never the raw address.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $audit = $pdo->query("SELECT COUNT(*) FROM auditoria_processos WHERE acao = 'carteirinha.qr_lida'")->fetchColumn();
    if ((int) $audit !== $auditBeforeRateLimit + 60) {
        throw new RuntimeException('Rate-limited attempts must not increment card reads or write audit rows.');
    }
    unset($pdo);
    file_put_contents($rateLimitPath, '{}', LOCK_EX);
    if (file_put_contents($databasePath, 'invalid sqlite file', LOCK_EX) === false) {
        throw new RuntimeException('Could not prepare the controlled database failure case.');
    }
    [$status, $body] = $request('/validar/' . $token);
    if ($status !== 503 || !str_contains($body, 'Validação indisponível')
        || str_contains($body, 'sqlite') || str_contains($body, $databasePath)
        || str_contains($body, $token) || str_contains($body, 'HTTP QR Holder')) {
        throw new RuntimeException('Database failures must return a generic service error without storage details.');
    }
    fwrite(STDOUT, "PASS: QR validation routes through local PHP with minimized HTML, no-store headers and ephemeral-IP rate limiting.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($tempDirectory);
}

return 'pass';
