<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/schema_update.php';

$statePath = schemaUpdateStatePath();
$directory = dirname($statePath);
if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Não foi possível preparar o worker de atualização de schema.\n");
    exit(1);
}
$workerLockPath = $statePath . '.worker.lock';
$workerLock = @fopen($workerLockPath, 'c');
if ($workerLock === false) {
    fwrite(STDERR, "Não foi possível preparar o worker de atualização de schema.\n");
    exit(1);
}
@chmod($workerLockPath, 0600);
if (!flock($workerLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => true, 'reason' => 'WORKER_ALREADY_RUNNING'], JSON_THROW_ON_ERROR) . "\n");
    fclose($workerLock);
    exit(0);
}

$state = schemaUpdateStateRead($statePath);
if (($state['status'] ?? null) === 'running' && is_string($state['job_id'] ?? null)) {
    schemaUpdateStateTransition($state['job_id'], 'running', [
        'status' => 'failed',
        'completed_at' => time(),
        'updated_at' => time(),
        'current_migration' => null,
        'error' => 'WORKER_INTERRUPTED',
    ], $statePath);
    fwrite(STDERR, json_encode(['ok' => false, 'error_code' => 'WORKER_INTERRUPTED'], JSON_THROW_ON_ERROR) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}
if (($state['status'] ?? null) !== 'queued' || !is_string($state['job_id'] ?? null)) {
    fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => true, 'reason' => 'NO_QUEUED_SCHEMA_UPDATE'], JSON_THROW_ON_ERROR) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(0);
}

$jobId = $state['job_id'];
$manifest = schemaUpdateReleaseManifest();
if (($state['backup_confirmed'] ?? false) !== true
    || !is_int($state['requested_by'] ?? null) || $state['requested_by'] < 1
    || !hash_equals((string) ($state['release_id'] ?? ''), $manifest['release_id'])
    || ($state['migrations'] ?? null) !== $manifest['migrations']) {
    schemaUpdateStateTransition($jobId, 'queued', [
        'status' => 'failed',
        'completed_at' => time(),
        'updated_at' => time(),
        'error' => 'RELEASE_CHANGED_OR_INVALID_JOB',
    ], $statePath);
    fwrite(STDERR, json_encode(['ok' => false, 'error_code' => 'RELEASE_CHANGED_OR_INVALID_JOB'], JSON_THROW_ON_ERROR) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}
if (!schemaUpdateStateTransition($jobId, 'queued', [
    'status' => 'running',
    'started_at' => time(),
    'updated_at' => time(),
    'completed_migrations' => 0,
])) {
    fwrite(STDERR, json_encode(['ok' => false, 'error_code' => 'JOB_STATE_CHANGED'], JSON_THROW_ON_ERROR) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}

$errorCode = 'SCHEMA_MIGRATION_FAILED';
try {
    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
    if (!in_array($environment, ['dev', 'production'], true)) {
        $errorCode = 'ENVIRONMENT_BLOCKED';
        throw new RuntimeException($errorCode);
    }

    $pdo = dbConnection();
    $preflight = schemaUpdatePreflight($pdo, null, $environment, $statePath);
    if (!$preflight['ready']) {
        $errorCode = 'SCHEMA_PREFLIGHT_BLOCKED';
        throw new RuntimeException($errorCode);
    }

    $applied = schemaAplicarMigracoes($pdo, 'production');
    $expectedIds = array_map(static fn(array $migration): string => $migration['id'], $manifest['migrations']);
    if ($applied !== $expectedIds) {
        $errorCode = 'RELEASE_MIGRATION_SET_MISMATCH';
        throw new RuntimeException($errorCode);
    }
    $drift = schemaInspecionar($pdo);
    $driftCount = 0;
    foreach ($drift as $items) {
        $driftCount += is_array($items) ? count($items) : 0;
    }
    if ($driftCount !== 0) {
        $errorCode = 'SCHEMA_DRIFT_AFTER_APPLY';
        throw new RuntimeException($errorCode);
    }

    $completedAt = time();
    if (!schemaUpdateStateTransition($jobId, 'running', [
        'status' => 'completed',
        'completed_at' => $completedAt,
        'updated_at' => $completedAt,
        'completed_migrations' => count($applied),
        'current_migration' => null,
        'result' => [
            'release_id' => $manifest['release_id'],
            'migration_count' => count($applied),
            'migrations' => $applied,
            'schema_drift_count' => 0,
        ],
        'error' => null,
    ], $statePath)) {
        $errorCode = 'JOB_FINALIZATION_FAILED';
        throw new RuntimeException($errorCode);
    }
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'status' => 'completed',
        'job_id' => $jobId,
        'release_id' => $manifest['release_id'],
        'migration_count' => count($applied),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(0);
} catch (Throwable) {
    schemaUpdateStateTransition($jobId, 'running', [
        'status' => 'failed',
        'completed_at' => time(),
        'updated_at' => time(),
        'current_migration' => null,
        'result' => null,
        'error' => $errorCode,
    ], $statePath);
    fwrite(STDERR, json_encode(['ok' => false, 'error_code' => $errorCode], JSON_THROW_ON_ERROR) . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}
