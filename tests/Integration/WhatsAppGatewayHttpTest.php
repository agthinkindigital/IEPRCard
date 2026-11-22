<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway.php';

$root = dirname(__DIR__, 2);
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-gowa-http-' . bin2hex(random_bytes(6));
if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create temporary GOWA HTTP test storage.');
}
$databasePath = $tempDirectory . DIRECTORY_SEPARATOR . 'gowa.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'IEPR_INTEGRATION_KEY', 'IEPR_ANONYMIZATION_KEY', 'IEPRCARD_GOWA_RATE_LIMIT_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
$integrationKey = base64_encode(str_repeat("\x74", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
putenv('APP_ENV=test');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $databasePath);
putenv('IEPR_INTEGRATION_KEY=' . $integrationKey);
putenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPRCARD_GOWA_RATE_LIMIT_PATH=' . $tempDirectory . DIRECTORY_SEPARATOR . 'gowa-rate.json');
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaAplicarMigracoes($pdo, 'test');
schemaGarantirEstrutura($pdo, 'test');
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
    . "VALUES ('superadmin', 'GOWA HTTP Test', 'gowa-http@example.test', :password, 1)"
)->execute(['password' => password_hash('synthetic-http-password', PASSWORD_DEFAULT)]);
$identity = [
    'id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null,
];
$globalToken = 'synthetic-gowa-http-token';
whatsappGatewayConfigSave($pdo, $identity, 'https://gowa.example.test', $globalToken);
$pdo->prepare(
    "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'GOWA HTTP Tenant', 'ativo')"
)->execute();
$tenantId = (int) $pdo->lastInsertId();
$webhookToken = str_repeat('b', 64);
$instanceName = 'ieprcard-http-' . bin2hex(random_bytes(4));
$pdo->prepare(
    "INSERT INTO whatsapp_instancias "
    . "(tenant_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, estado, criado_em, atualizado_em) "
    . "VALUES (:tenant, :scope, :name, 'gowa-http-device', :token_hash, 'ativa', :created, :updated)"
)->execute([
    'tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'name' => $instanceName,
    'token_hash' => hash('sha256', $webhookToken), 'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
]);
unset($pdo);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($portProbe)) {
    throw new RuntimeException('Could not allocate a GOWA HTTP test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$stdoutPath = $tempDirectory . DIRECTORY_SEPARATOR . 'php.out.log';
$stderrPath = $tempDirectory . DIRECTORY_SEPARATOR . 'php.err.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public'],
    [0 => ['file', $nullDevice, 'r'], 1 => ['file', $stdoutPath, 'a'], 2 => ['file', $stderrPath, 'a']],
    $pipes,
    $root
);
if (!is_resource($server)) {
    throw new RuntimeException('Could not start the GOWA HTTP test server.');
}

$request = static function (string $path, string $method, ?string $body = null, string $contentType = 'application/json') use ($port): int {
    $options = [
        'http' => [
            'method' => $method,
            'header' => 'Content-Type: ' . $contentType . "\r\n",
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 3,
        ],
    ];
    @file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create($options));
    $line = $http_response_header[0] ?? '';
    return preg_match('/\s([1-5][0-9]{2})\s/', $line, $match) === 1 ? (int) $match[1] : 0;
};

try {
    $base = '/webhook-gowa-validate.php';
    $status = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $status = $request($base, 'GET');
        if ($status !== 0) break;
        usleep(100_000);
    }
    if ($status !== 405) {
        throw new RuntimeException('The GOWA validation endpoint did not start with a POST-only response.');
    }
    if ($request($base, 'POST', json_encode(['token' => $globalToken, 'usuarios' => []])) !== 200
        || $request($base, 'POST', json_encode(['token' => 'wrong-token', 'usuarios' => []])) !== 401
        || $request($base, 'POST', '{malformed') !== 400
        || $request($base, 'POST', '{}', 'text/plain') !== 415) {
        throw new RuntimeException('GOWA license ping endpoint returned an unexpected status for valid or invalid requests.');
    }
    if ($request('/webhook-gowa.php', 'POST', '{}') !== 401
        || $request('/webhook-gowa.php?token=' . str_repeat('0', 64), 'POST', '{}') !== 401
        || $request('/webhook-gowa.php?token=' . $webhookToken, 'POST', '{}') !== 400) {
        throw new RuntimeException('GOWA message endpoint did not conceal invalid tokens or reject malformed payloads.');
    }
    $message = ['usuario' => $instanceName, 'id_mensagem' => 'http-unprocessed-event', 'telefone' => '5511999999999', 'texto' => 'private payload'];
    if ($request('/webhook-gowa.php?token=' . $webhookToken, 'POST', json_encode($message)) !== 503) {
        throw new RuntimeException('GOWA message endpoint must retry until the consent-aware consumer is installed.');
    }
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    if ((int) $pdo->query('SELECT COUNT(*) FROM webhook_gowa_eventos')->fetchColumn() !== 0) {
        throw new RuntimeException('A request received before the consent-aware handler must not persist message events.');
    }
    fwrite(STDOUT, "PASS: GOWA HTTP endpoints enforce method, media type, auth and pre-consent no-persistence.\n");
} finally {
    proc_terminate($server);
    proc_close($server);
    unset($pdo);
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) putenv($key);
        else putenv($key . '=' . $value);
    }
    foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    @rmdir($tempDirectory);
}

return 'pass';
