<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$mysqlSettings = [
    'DB_HOST' => getenv('TEST_MYSQL_HOST'),
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE'),
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME'),
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD'),
];
if (!in_array('mysql', PDO::getAvailableDrivers(), true)
    || count(array_filter($mysqlSettings, static fn(mixed $value): bool => $value !== false)) !== count($mysqlSettings)) {
    if (getenv('CI') === 'true') {
        throw new RuntimeException('CI must provide MySQL/MariaDB for the GOWA duplicate-delivery race test.');
    }
    fwrite(STDOUT, "SKIP: GOWA webhook race test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x75", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$pdo = dbConnect(['DB_DRIVER' => 'mysql'] + $mysqlSettings);
schemaAplicarMigracoes($pdo, 'test');
schemaGarantirEstrutura($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$callbackTable = 'ieprcard_gowa_cb_' . $suffix;
$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-gowa-race-' . $suffix;
if (!mkdir($tempDirectory, 0700, true) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create temporary GOWA race test storage.');
}
$inputPath = $tempDirectory . DIRECTORY_SEPARATOR . 'input.json';
$barrierPath = $tempDirectory . DIRECTORY_SEPARATOR . 'start';
$processes = [];
$pipesByWorker = [];
$tenantId = null;
$instanceId = null;
$pdo->exec('CREATE TABLE `' . $callbackTable . '` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, called TINYINT NOT NULL) ENGINE=InnoDB');

try {
    $pdo->prepare(
        "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', :name, 'ativo')"
    )->execute(['name' => 'GOWA Race ' . $suffix]);
    $tenantId = (int) $pdo->lastInsertId();
    $token = bin2hex(random_bytes(32));
    $instanceName = 'ieprcard-race-' . $suffix;
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO whatsapp_instancias "
        . "(tenant_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, estado, criado_em, atualizado_em) "
        . "VALUES (:tenant, :scope, :name, :device, :token_hash, 'ativa', :created, :updated)"
    )->execute([
        'tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'name' => $instanceName,
        'device' => 'gowa-race-device-' . $suffix, 'token_hash' => hash('sha256', $token),
        'created' => $now, 'updated' => $now,
    ]);
    $instanceId = (int) $pdo->lastInsertId();
    file_put_contents($inputPath, json_encode([
        'token' => $token,
        'payload' => [
            'usuario' => $instanceName,
            'id_mensagem' => 'concurrent-provider-message-' . $suffix,
            'telefone' => '5511000000000',
            'texto' => 'synthetic race payload',
        ],
    ], JSON_THROW_ON_ERROR));

    for ($worker = 0; $worker < 2; $worker++) {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/WhatsAppGatewayRaceWorker.php', $inputPath, $barrierPath, $callbackTable],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $workerPipes,
            dirname(__DIR__, 2)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the GOWA webhook race worker.');
        }
        fclose($workerPipes[0]);
        $processes[] = $process;
        $pipesByWorker[] = [$workerPipes[1], $workerPipes[2]];
    }
    file_put_contents($barrierPath, 'go');
    $results = [];
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($pipesByWorker[$index][0]);
        $stderr = stream_get_contents($pipesByWorker[$index][1]);
        fclose($pipesByWorker[$index][0]);
        fclose($pipesByWorker[$index][1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Concurrent GOWA webhook worker failed: ' . $stdout . ' ' . $stderr);
        }
        $result = json_decode(trim($stdout), true);
        if (!is_array($result)) {
            throw new RuntimeException('Concurrent GOWA webhook worker returned invalid status.');
        }
        $results[] = $result;
    }

    $callbackCount = (int) $pdo->query('SELECT COUNT(*) FROM `' . $callbackTable . '`')->fetchColumn();
    $eventQuery = $pdo->prepare(
        'SELECT estado FROM webhook_gowa_eventos WHERE instancia_id = :instance LIMIT 2'
    );
    $eventQuery->execute(['instance' => $instanceId]);
    $eventStates = $eventQuery->fetchAll(PDO::FETCH_COLUMN);
    $processed = count(array_filter($results, static fn(array $result): bool => ($result['processed'] ?? false) === true));
    $duplicates = count(array_filter($results, static fn(array $result): bool => ($result['duplicate'] ?? false) === true));
    if ($callbackCount !== 1 || count($eventStates) !== 1 || $eventStates[0] !== 'processado'
        || $processed !== 1 || $duplicates < 1) {
        throw new RuntimeException('Concurrent duplicate GOWA deliveries must invoke the consumer exactly once: '
            . json_encode(['callbacks' => $callbackCount, 'events' => $eventStates, 'results' => $results], JSON_THROW_ON_ERROR));
    }
    fwrite(STDOUT, "PASS: concurrent MySQL GOWA deliveries claim a message exactly once.\n");
} finally {
    foreach ($processes as $index => $process) {
        if (is_resource($process)) {
            foreach ($pipesByWorker[$index] ?? [] as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
    }
    $pdo->exec('DROP TABLE IF EXISTS `' . $callbackTable . '`');
    unset($pdo);
    foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    @rmdir($tempDirectory);
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
}

return 'pass';
