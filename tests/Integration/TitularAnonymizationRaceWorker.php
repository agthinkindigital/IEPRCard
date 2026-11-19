<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

$startFile = $argv[1] ?? '';
$requestId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$now = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
if ($startFile === '' || $requestId === false || $now === false) {
    fwrite(STDERR, "Invalid anonymization race worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, "Anonymization race start signal timed out.\n");
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
$completed = titularAnonymizationProcessOne(
    $pdo,
    (int) $requestId,
    gmdate('Y-m-d H:i:s', (int) $now),
    titularAnonymizationEncryptionKey()
);
if (!$completed) {
    fwrite(STDERR, "Anonymization request failed.\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
