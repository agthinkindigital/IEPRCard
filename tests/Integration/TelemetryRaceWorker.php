<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/business_telemetry.php';

[$script, $driver, $sqlitePath, $barrierPath, $userId, $iterations, $timestamp] = $argv;
$pdo = dbConnect($driver === 'sqlite'
    ? ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sqlitePath]
    : [
        'DB_DRIVER' => 'mysql', 'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '',
        'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306', 'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
        'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '', 'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
    ]);
$query = $pdo->prepare('SELECT id, papel_sistema, tenant_id, subcliente_id FROM usuarios WHERE id = :id');
$query->execute(['id' => (int) $userId]);
$identity = $query->fetch(PDO::FETCH_ASSOC);
$query->closeCursor();
unset($query);
if (!is_array($identity)) {
    fwrite(STDERR, "Telemetry race identity missing.\n");
    exit(1);
}
$identity['id'] = (int) $identity['id'];
$identity['tenant_id'] = $identity['tenant_id'] === null ? null : (int) $identity['tenant_id'];
$identity['subcliente_id'] = $identity['subcliente_id'] === null ? null : (int) $identity['subcliente_id'];
$deadline = microtime(true) + 15;
while (!is_file($barrierPath) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($barrierPath)) {
    fwrite(STDERR, "Telemetry race barrier timed out.\n");
    exit(1);
}
for ($index = 0; $index < (int) $iterations; $index++) {
    telemetryBusinessRecord($pdo, $identity, 'issuance', 'card.generated', 1, (int) $timestamp);
}
fwrite(STDOUT, "OK\n");
