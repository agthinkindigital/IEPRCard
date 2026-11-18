<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_migration.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-migration-state-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';
try {
    $initial = adminMigrationStateRead($statePath);
    if (($initial['status'] ?? null) !== 'idle') {
        throw new RuntimeException('A missing job state must be idle.');
    }

    $job = adminMigrationQueueCreate(42, 1_800_000_000, $statePath);
    if (!is_array($job) || ($job['status'] ?? null) !== 'queued'
        || ($job['requested_by'] ?? null) !== 42 || ($job['created_at'] ?? null) !== 1_800_000_000
        || !preg_match('/^[a-f0-9]{32}$/D', (string) ($job['job_id'] ?? ''))) {
        throw new RuntimeException('Queueing must persist a private, traceable job without form data.');
    }
    if (adminMigrationQueueCreate(84, 1_800_000_001, $statePath) !== null) {
        throw new RuntimeException('A queued or running job must not be duplicated.');
    }
    if (adminMigrationStateTransition('wrong-job', 'queued', ['status' => 'running'], $statePath)) {
        throw new RuntimeException('A state transition must match the active job ID.');
    }
    if (!adminMigrationStateTransition($job['job_id'], 'queued', [
        'status' => 'running',
        'started_at' => 1_800_000_002,
        'completed_tables' => 0,
        'completed_records' => 0,
    ], $statePath)) {
        throw new RuntimeException('The worker must be able to claim its queued job exactly once.');
    }
    if (!adminMigrationStateTransition($job['job_id'], 'running', [
        'completed_tables' => 2,
        'completed_records' => 14,
    ], $statePath)) {
        throw new RuntimeException('The worker must be able to publish verified progress.');
    }
    $running = adminMigrationStateRead($statePath);
    if (($running['completed_tables'] ?? null) !== 2 || ($running['completed_records'] ?? null) !== 14
        || isset($running['email'], $running['password'], $running['credentials'])) {
        throw new RuntimeException('Migration state must expose verified counts and never store credentials or personal records.');
    }
    if (!adminMigrationStateTransition($job['job_id'], 'running', [
        'status' => 'completed',
        'completed_at' => 1_800_000_003,
    ], $statePath)) {
        throw new RuntimeException('The worker must be able to finalize its job.');
    }
    if (adminMigrationStateTransition($job['job_id'], 'running', ['status' => 'failed'], $statePath)) {
        throw new RuntimeException('A completed job must not transition back to running or failed.');
    }

    $nextJob = adminMigrationQueueCreate(42, 1_800_000_004, $statePath);
    if (!is_array($nextJob)) {
        throw new RuntimeException('A new migration may be queued after a previous job reaches a terminal state.');
    }
    $environment = [];
    foreach (['IEPRCARD_MIGRATION_STATE_PATH', 'MIGRATION_SOURCE_SQLITE_PATH', 'MIGRATION_TARGET_DATABASE', 'MIGRATION_TARGET_USERNAME', 'MIGRATION_TARGET_PASSWORD', 'DB_DRIVER'] as $key) {
        $value = getenv($key);
        $environment[$key] = $value === false ? null : $value;
    }
    putenv('IEPRCARD_MIGRATION_STATE_PATH=' . $statePath);
    putenv('MIGRATION_SOURCE_SQLITE_PATH=' . $directory . DIRECTORY_SEPARATOR . 'missing.sqlite');
    putenv('MIGRATION_TARGET_DATABASE=');
    putenv('MIGRATION_TARGET_USERNAME=');
    putenv('MIGRATION_TARGET_PASSWORD=private-secret');
    putenv('DB_DRIVER=sqlite');
    $root = dirname(__DIR__, 2);
    $process = proc_open([PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'process-admin-migration.php'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('The CLI migration worker must start as a process.');
    }
    fclose($pipes[0]);
    $workerOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    foreach ($environment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    $failed = adminMigrationStateRead($statePath);
    if ($exitCode !== 1 || ($failed['status'] ?? null) !== 'failed'
        || ($failed['completed_records'] ?? null) !== 0
        || str_contains($workerOutput, 'private-secret')
        || str_contains((string) ($failed['error'] ?? ''), 'missing.sqlite')) {
        throw new RuntimeException('The CLI worker must persist a safe failure when preflight blocks the queued job.');
    }

    fwrite(STDOUT, "PASS: migration queue state is single-flight, locked, and contains only operational metadata.\n");
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

return 'pass';
