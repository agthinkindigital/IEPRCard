<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/schema_update.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-schema-worker-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not prepare an isolated schema update worker environment.');
}
$databasePath = $directory . DIRECTORY_SEPARATOR . 'empty.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
$auditPath = $directory . DIRECTORY_SEPARATOR . 'audit.jsonl';
$environmentKeys = ['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'IEPRCARD_SCHEMA_UPDATE_STATE_PATH', 'IEPRCARD_SCHEMA_UPDATE_AUDIT_PATH'];
$previousEnvironment = [];
foreach ($environmentKeys as $key) {
    $value = getenv($key);
    $previousEnvironment[$key] = $value === false ? null : $value;
}

try {
    putenv('APP_ENV=dev');
    putenv('DB_DRIVER=sqlite');
    putenv('DB_SQLITE_PATH=' . $databasePath);
    putenv('IEPRCARD_SCHEMA_UPDATE_STATE_PATH=' . $statePath);
    putenv('IEPRCARD_SCHEMA_UPDATE_AUDIT_PATH=' . $auditPath);
    $job = schemaUpdateQueueCreate(42, true, 1_800_000_100, $statePath);
    if (!is_array($job)) {
        throw new RuntimeException('The test must queue a production schema update before running its worker.');
    }
    $phpArguments = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDirectory = ini_get('extension_dir');
        if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
            $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
        }
        $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
    if (!extension_loaded('pdo_sqlite') && is_file($sqliteExtension)) {
            $phpArguments = array_merge($phpArguments, ['-d', 'extension=pdo_sqlite']);
        }
    }
    $phpArguments[] = dirname(__DIR__, 2) . '/database/process-schema-update.php';
    $workerLock = fopen($statePath . '.worker.lock', 'c');
    if ($workerLock === false || !flock($workerLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('The test must hold the schema worker lock before exercising concurrency.');
    }
    $contendingProcess = proc_open($phpArguments, [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $contendingPipes, dirname(__DIR__, 2));
    if (!is_resource($contendingProcess)) {
        flock($workerLock, LOCK_UN);
        fclose($workerLock);
        throw new RuntimeException('Could not start the contending schema update worker.');
    }
    $contendingStdout = stream_get_contents($contendingPipes[1]);
    $contendingStderr = stream_get_contents($contendingPipes[2]);
    fclose($contendingPipes[1]);
    fclose($contendingPipes[2]);
    $contendingExit = proc_close($contendingProcess);
    $contendingState = schemaUpdateStateRead($statePath);
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    if ($contendingExit !== 0 || ($contendingState['status'] ?? null) !== 'queued'
        || !str_contains((string) $contendingStdout, 'WORKER_ALREADY_RUNNING')
        || str_contains((string) $contendingStdout . (string) $contendingStderr, $directory)) {
        throw new RuntimeException('A concurrent scheduled worker must skip without changing the queued job or exposing local paths.');
    }

    $process = proc_open($phpArguments, [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the schema update CLI worker.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $state = schemaUpdateStateRead($statePath);
    $audit = is_file($auditPath) ? file_get_contents($auditPath) : '';
    if ($exitCode !== 0 || ($state['status'] ?? null) !== 'completed'
        || ($state['release_id'] ?? null) !== $job['release_id']
        || ($state['completed_migrations'] ?? null) !== count($job['migrations'])
        || ($state['result']['schema_drift_count'] ?? null) !== 0
        || !str_contains((string) $audit, 'completed')
        || str_contains((string) $stdout . (string) $stderr, $directory)) {
        throw new RuntimeException('The CLI worker must apply the pinned release, verify the canonical schema, and record a safe result: ' . json_encode([
            'exit' => $exitCode,
            'status' => $state['status'] ?? null,
            'completed_migrations' => $state['completed_migrations'] ?? null,
            'schema_drift_count' => $state['result']['schema_drift_count'] ?? null,
            'audit_recorded' => str_contains((string) $audit, 'completed'),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    fwrite(STDOUT, "PASS: schema update worker applies the pinned release asynchronously and verifies the canonical schema.\n");
} finally {
    foreach ($environmentKeys as $key) {
        $value = $previousEnvironment[$key];
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

return 'pass';
