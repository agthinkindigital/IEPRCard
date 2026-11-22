<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/schema/catalog.php';

function adminMigrationStatePath(): string
{
    return (string) (getenv('IEPRCARD_MIGRATION_STATE_PATH') ?: dirname(__DIR__) . '/var/admin-migration-job.json');
}

function adminMigrationExpectedTableCount(): int
{
    return count(\IEPRCard\Database\Schema\catalogoCanonico());
}

/** @return array<string, mixed> */
function adminMigrationStateRead(?string $path = null): array
{
    $path ??= adminMigrationStatePath();
    return adminMigrationWithStateLock($path, static function () use ($path): array {
        return adminMigrationStateReadUnlocked($path);
    }, LOCK_SH);
}

/** @return array<string, mixed>|null */
function adminMigrationQueueCreate(int $requestedBy, ?int $timestamp = null, ?string $path = null, int $estimatedRecords = 0): ?array
{
    if ($requestedBy < 1) {
        return null;
    }
    $path ??= adminMigrationStatePath();
    $timestamp ??= time();

    return adminMigrationWithStateLock($path, static function () use ($path, $requestedBy, $timestamp, $estimatedRecords): ?array {
        $existing = adminMigrationStateReadUnlocked($path);
        if (in_array($existing['status'] ?? 'idle', ['queued', 'running'], true)) {
            return null;
        }
        $state = [
            'job_id' => bin2hex(random_bytes(16)),
            'status' => 'queued',
            'requested_by' => $requestedBy,
            'created_at' => $timestamp,
            'total_tables' => adminMigrationExpectedTableCount(),
            'total_records' => max(0, $estimatedRecords),
            'completed_tables' => 0,
            'completed_records' => 0,
        ];
        adminMigrationStateWriteUnlocked($path, $state);
        return $state;
    });
}

/** @param array<string, mixed> $changes */
function adminMigrationStateTransition(string $jobId, string $expectedStatus, array $changes, ?string $path = null): bool
{
    $path ??= adminMigrationStatePath();
    $allowedFields = [
        'status', 'started_at', 'completed_at', 'updated_at', 'completed_tables', 'total_tables',
        'completed_records', 'total_records', 'current_table', 'tables', 'result', 'error',
    ];
    $changes = array_intersect_key($changes, array_flip($allowedFields));

    return adminMigrationWithStateLock($path, static function () use ($path, $jobId, $expectedStatus, $changes): bool {
        $state = adminMigrationStateReadUnlocked($path);
        if (!hash_equals((string) ($state['job_id'] ?? ''), $jobId)
            || ($state['status'] ?? '') !== $expectedStatus
            || !adminMigrationTransitionAllowed($expectedStatus, (string) ($changes['status'] ?? $expectedStatus))) {
            return false;
        }
        adminMigrationStateWriteUnlocked($path, array_merge($state, $changes));
        return true;
    });
}

/** @return array<string, mixed> */
/** @template T @param callable():T $operation @return T */
function adminMigrationWithStateLock(string $path, callable $operation, int $lockType = LOCK_EX): mixed
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o estado da migração.');
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível bloquear o estado da migração.');
    }
    try {
        @chmod($path . '.lock', 0600);
        if (!flock($lock, $lockType)) {
            throw new RuntimeException('Não foi possível bloquear o estado da migração.');
        }
        return $operation();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array<string, mixed> */
function adminMigrationStateReadUnlocked(string $path): array
{
    if (!is_file($path)) {
        return ['status' => 'idle'];
    }
    $contents = @file_get_contents($path);
    $state = is_string($contents) ? json_decode($contents, true) : null;
    return is_array($state) && isset($state['status']) ? $state : ['status' => 'unknown'];
}

/** @param array<string, mixed> $state */
function adminMigrationStateWriteUnlocked(string $path, array $state): void
{
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível gravar o estado da migração.');
    }
    try {
        @chmod($path, 0600);
        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('Não foi possível gravar o estado da migração.');
        }
    } finally {
        fclose($handle);
    }
}

function adminMigrationTransitionAllowed(string $from, string $to): bool
{
    return match ($from) {
        'queued' => in_array($to, ['running', 'failed'], true),
        'running' => in_array($to, ['running', 'completed', 'failed'], true),
        default => false,
    };
}
