<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_migration_preflight.php';

$mysqlHost = getenv('TEST_MYSQL_HOST');
$mysqlDatabase = getenv('TEST_MYSQL_DATABASE');
$mysqlUsername = getenv('TEST_MYSQL_USERNAME');
if (!in_array('mysql', PDO::getAvailableDrivers(), true)
    || $mysqlHost === false || $mysqlDatabase === false || $mysqlUsername === false) {
    if (filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('CI requires MySQL/MariaDB to execute the CLI migration worker test.');
    }
    fwrite(STDOUT, "SKIP: migration worker integration needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-migration-worker-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$sourcePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
$mysqlConfiguration = [
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => $mysqlHost,
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => $mysqlDatabase,
    'DB_USERNAME' => $mysqlUsername,
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
];
$source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sourcePath]);
schemaGarantirEstrutura($source, 'test');
unset($source);
$target = dbConnect($mysqlConfiguration);
schemaGarantirEstrutura($target, 'test');
$targetRows = 0;
foreach (array_keys(\IEPRCard\Database\Schema\catalogoCanonico()) as $table) {
    $targetRows += (int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
unset($target);
if ($targetRows !== 0) {
    throw new RuntimeException('CLI worker test requires the disposable CI MySQL/MariaDB destination to be empty.');
}

$environmentKeys = [
    'APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'MIGRATION_SOURCE_SQLITE_PATH', 'MIGRATION_TARGET_HOST', 'MIGRATION_TARGET_PORT',
    'MIGRATION_TARGET_DATABASE', 'MIGRATION_TARGET_USERNAME', 'MIGRATION_TARGET_PASSWORD',
    'IEPRCARD_MIGRATION_STATE_PATH',
];
$oldEnvironment = [];
foreach ($environmentKeys as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=test');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $sourcePath);
putenv('MIGRATION_SOURCE_SQLITE_PATH=' . $sourcePath);
putenv('MIGRATION_TARGET_HOST=' . $mysqlConfiguration['DB_HOST']);
putenv('MIGRATION_TARGET_PORT=' . $mysqlConfiguration['DB_PORT']);
putenv('MIGRATION_TARGET_DATABASE=' . $mysqlConfiguration['DB_DATABASE']);
putenv('MIGRATION_TARGET_USERNAME=' . $mysqlConfiguration['DB_USERNAME']);
putenv('MIGRATION_TARGET_PASSWORD=' . $mysqlConfiguration['DB_PASSWORD']);
putenv('IEPRCARD_MIGRATION_STATE_PATH=' . $statePath);

try {
    $job = adminMigrationQueueCreate(1, time(), $statePath, 0);
    if (!is_array($job)) {
        throw new RuntimeException('The CLI worker test must be able to enqueue one job.');
    }
    $root = dirname(__DIR__, 2);
    $process = proc_open([PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'process-admin-migration.php'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the migration CLI worker.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $state = adminMigrationStateRead($statePath);
    if ($exitCode !== 0 || ($state['status'] ?? null) !== 'completed'
        || ($state['completed_tables'] ?? null) !== 27 || ($state['completed_records'] ?? null) !== 0
        || count($state['tables'] ?? []) !== 27 || count($state['result']['tables'] ?? []) !== 27
        || ($mysqlConfiguration['DB_PASSWORD'] !== '' && str_contains($output, $mysqlConfiguration['DB_PASSWORD']))) {
        throw new RuntimeException('The CLI worker must run the shared migration service, publish verified table progress, and complete without exposing credentials.');
    }
    fwrite(STDOUT, "PASS: CLI migration worker processed and checksummed all 27 tables on MySQL/MariaDB without rows.\n");
} finally {
    foreach ($oldEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    unset($source, $target);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($directory);
}

return 'pass';
