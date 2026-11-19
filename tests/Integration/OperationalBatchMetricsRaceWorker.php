<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/operational_batch_metrics.php';

$startFile = $argv[1] ?? '';
$now = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if ($startFile === '' || $now === false) {
    fwrite(STDERR, "Invalid operational metrics race worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, "Operational metrics race start signal timed out.\n");
    exit(2);
}

$pdo = dbConnect([
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '',
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
]);
operationalBatchMetricsRecord($pdo, [
    'demand' => 5,
    'processed' => 2,
    'duration_per_record_ms' => 100,
    'queue_depth' => 3,
    'failures' => 1,
], (int) $now);
fwrite(STDOUT, "OK\n");
