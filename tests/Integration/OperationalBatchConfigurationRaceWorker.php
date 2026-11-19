<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/operational_configuration.php';

$startFile = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$value = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
$driver = strtolower($argv[4] ?? 'mysql');
$sqlitePath = $argv[5] ?? '';
if ($startFile === '' || !in_array($mode, ['save', 'activate'], true) || $value === false
    || !in_array($driver, ['mysql', 'sqlite'], true) || ($driver === 'sqlite' && $sqlitePath === '')) {
    fwrite(STDERR, "Invalid operational configuration race worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, "Operational configuration race start signal timed out.\n");
    exit(2);
}

$pdo = $driver === 'sqlite'
    ? dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sqlitePath])
    : dbConnect([
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
        'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
        'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '',
        'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
    ]);

if ($mode === 'save') {
    $result = operationalConfigSavePending($pdo, [
        'lote_maximo_registros' => $value,
        'pico_previsto_diario' => 100,
        'janela_previsao_dias' => 30,
    ], null, 1767355200);
} else {
    $result = operationalConfigWorkerStart($pdo, 1767355200);
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
