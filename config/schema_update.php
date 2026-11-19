<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/schema_self_healing.php';
require_once dirname(__DIR__) . '/database/migrations/runner.php';

/** @return array{release_id: string, migrations: list<array{id: string, sha256: string}>} */
function schemaUpdateReleaseManifest(): array
{
    $directory = dirname(__DIR__) . '/database/migrations';
    $files = glob($directory . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]_*.php') ?: [];
    sort($files, SORT_STRING);
    $migrations = [];

    foreach ($files as $file) {
        if (!is_file($file) || is_link($file)) {
            continue;
        }
        $id = basename($file, '.php');
        $hash = hash_file('sha256', $file);
        if (!preg_match('/^\d{4}_[a-z0-9_]+$/D', $id) || !is_string($hash)) {
            throw new RuntimeException('Não foi possível verificar o conjunto de migrations da release.');
        }
        $migrations[] = ['id' => $id, 'sha256' => $hash];
    }

    if ($migrations === []) {
        throw new RuntimeException('A release não contém migrations versionadas.');
    }

    return [
        'release_id' => hash('sha256', json_encode($migrations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'migrations' => $migrations,
    ];
}

function schemaUpdateStatePath(): string
{
    return (string) (getenv('IEPRCARD_SCHEMA_UPDATE_STATE_PATH') ?: dirname(__DIR__) . '/var/schema-update-job.json');
}

function schemaUpdateAuditPath(?string $statePath = null): string
{
    $configured = getenv('IEPRCARD_SCHEMA_UPDATE_AUDIT_PATH');
    return $configured !== false && $configured !== ''
        ? $configured
        : ($statePath ?? schemaUpdateStatePath()) . '.audit.jsonl';
}

/** @param array<string, mixed> $job */
function schemaUpdateAuditAppend(array $job, string $event, ?int $timestamp = null, ?string $path = null): void
{
    if (!in_array($event, ['queued', 'running', 'completed', 'failed'], true)) {
        throw new InvalidArgumentException('Evento de auditoria inválido.');
    }
    $path ??= schemaUpdateAuditPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o histórico da atualização de schema.');
    }
    $lockPath = $path . '.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível bloquear o histórico da atualização de schema.');
    }
    try {
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o histórico da atualização de schema.');
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível gravar o histórico da atualização de schema.');
        }
        try {
            @chmod($path, 0600);
            $entry = [
                'job_id' => (string) ($job['job_id'] ?? ''),
                'event' => $event,
                'actor_user_id' => (int) ($job['requested_by'] ?? 0),
                'release_id' => (string) ($job['release_id'] ?? ''),
                'migration_ids' => array_values(array_map(
                    static fn(array $migration): string => (string) ($migration['id'] ?? ''),
                    is_array($job['migrations'] ?? null) ? $job['migrations'] : []
                )),
                'created_at' => gmdate('Y-m-d H:i:s', $timestamp ?? time()),
            ];
            if (is_string($job['error'] ?? null)) {
                $entry['error'] = $job['error'];
            }
            if (is_array($job['result'] ?? null)) {
                $entry['result'] = [
                    'migration_count' => (int) ($job['result']['migration_count'] ?? 0),
                    'schema_drift_count' => (int) ($job['result']['schema_drift_count'] ?? -1),
                ];
            }
            $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Não foi possível gravar o histórico da atualização de schema.');
            }
        } finally {
            fclose($handle);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return list<array<string, mixed>> */
function schemaUpdateAuditRead(?string $path = null, int $limit = 50): array
{
    $path ??= schemaUpdateAuditPath();
    if (!is_file($path)) {
        return [];
    }
    $contents = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($contents)) {
        return [];
    }
    $entries = [];
    foreach (array_slice($contents, -max(1, min(200, $limit))) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && in_array($entry['event'] ?? null, ['queued', 'running', 'completed', 'failed'], true)) {
            $entries[] = $entry;
        }
    }
    return array_reverse($entries);
}

/** @return list<array<string, mixed>> */
function schemaUpdateAuditHistory(?string $path = null, int $limit = 50): array
{
    $events = array_reverse(schemaUpdateAuditRead($path, max(1, min(200, $limit * 4))));
    $jobs = [];
    foreach ($events as $event) {
        $jobId = (string) ($event['job_id'] ?? '');
        if ($jobId === '') {
            continue;
        }
        if (!isset($jobs[$jobId])) {
            $jobs[$jobId] = [
                'release_id' => (string) ($event['release_id'] ?? ''),
                'actor_user_id' => (int) ($event['actor_user_id'] ?? 0),
                'created_at' => (string) ($event['created_at'] ?? ''),
                'status' => (string) ($event['event'] ?? 'unknown'),
                'schema_drift_count' => null,
            ];
        }
        $jobs[$jobId]['status'] = (string) ($event['event'] ?? 'unknown');
        if (($event['event'] ?? null) === 'completed') {
            $jobs[$jobId]['schema_drift_count'] = (int) ($event['result']['schema_drift_count'] ?? -1);
        }
    }
    return array_slice(array_reverse(array_values($jobs)), 0, max(1, min(100, $limit)));
}

/** @return array<string, mixed> */
function schemaUpdateStateRead(?string $path = null): array
{
    $path ??= schemaUpdateStatePath();
    return schemaUpdateWithStateLock($path, static fn(): array => schemaUpdateStateReadUnlocked($path), LOCK_SH);
}

/** @return array<string, mixed>|null */
function schemaUpdateQueueCreate(int $requestedBy, bool $backupConfirmed, ?int $timestamp = null, ?string $path = null): ?array
{
    if ($requestedBy < 1 || !$backupConfirmed) {
        return null;
    }
    $path ??= schemaUpdateStatePath();
    $timestamp ??= time();
    $manifest = schemaUpdateReleaseManifest();

    return schemaUpdateWithStateLock($path, static function () use ($path, $requestedBy, $backupConfirmed, $timestamp, $manifest): ?array {
        $existing = schemaUpdateStateReadUnlocked($path);
        if (!in_array($existing['status'] ?? 'unknown', ['idle', 'completed', 'failed'], true)) {
            return null;
        }
        $state = [
            'job_id' => bin2hex(random_bytes(16)),
            'status' => 'queued',
            'requested_by' => $requestedBy,
            'created_at' => $timestamp,
            'backup_confirmed' => $backupConfirmed,
            'release_id' => $manifest['release_id'],
            'migrations' => $manifest['migrations'],
            'completed_migrations' => 0,
        ];
        schemaUpdateStateWriteUnlocked($path, $state);
        try {
            schemaUpdateAuditAppend($state, 'queued', $timestamp, schemaUpdateAuditPath($path));
        } catch (Throwable $error) {
            $state['status'] = 'failed';
            $state['completed_at'] = $timestamp;
            $state['error'] = 'O pedido não foi aceito porque o histórico operacional não pôde ser gravado.';
            schemaUpdateStateWriteUnlocked($path, $state);
            throw $error;
        }
        return $state;
    });
}

/** @param array<string, mixed> $changes */
function schemaUpdateStateTransition(string $jobId, string $expectedStatus, array $changes, ?string $path = null): bool
{
    $path ??= schemaUpdateStatePath();
    $allowedFields = ['status', 'started_at', 'completed_at', 'updated_at', 'completed_migrations', 'current_migration', 'result', 'error'];
    $changes = array_intersect_key($changes, array_flip($allowedFields));

    return schemaUpdateWithStateLock($path, static function () use ($path, $jobId, $expectedStatus, $changes): bool {
        $state = schemaUpdateStateReadUnlocked($path);
        if (!hash_equals((string) ($state['job_id'] ?? ''), $jobId)
            || ($state['status'] ?? '') !== $expectedStatus
            || !schemaUpdateTransitionAllowed($expectedStatus, (string) ($changes['status'] ?? $expectedStatus))) {
            return false;
        }
        $updated = array_merge($state, $changes);
        schemaUpdateStateWriteUnlocked($path, $updated);
        if (isset($changes['status']) && $changes['status'] !== $expectedStatus) {
            schemaUpdateAuditAppend($updated, (string) $changes['status'], null, schemaUpdateAuditPath($path));
        }
        return true;
    });
}

function schemaUpdateTransitionAllowed(string $from, string $to): bool
{
    return match ($from) {
        'queued' => in_array($to, ['running', 'failed'], true),
        'running' => in_array($to, ['running', 'completed', 'failed'], true),
        default => false,
    };
}

/** @return array<string, mixed> */
function schemaUpdatePreflight(?PDO $pdo = null, ?array $configuration = null, ?string $environment = null, ?string $statePath = null): array
{
    $environment = strtolower(trim($environment ?? (getenv('APP_ENV') ?: 'production')));
    $configuration ??= dbReadEnvironment();
    $driver = strtolower(trim($configuration['DB_DRIVER'] ?? 'sqlite'));
    $databaseName = $driver === 'sqlite'
        ? basename((string) ($configuration['DB_SQLITE_PATH'] ?? 'ieprcard.sqlite'))
        : (string) ($configuration['DB_DATABASE'] ?? '');
    $manifest = null;
    $connectionError = false;
    $schema = null;

    try {
        $manifest = schemaUpdateReleaseManifest();
    } catch (Throwable) {
        $manifest = null;
    }

    try {
        $pdo ??= dbConnect($configuration);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $schema = schemaInspecionar($pdo);
    } catch (Throwable) {
        $connectionError = true;
    }

    $expectedTables = count(\IEPRCard\Database\Schema\catalogoCanonico());
    $driftCounts = [];
    foreach (['missing_tables', 'missing_columns', 'mismatched_columns', 'missing_indexes', 'missing_foreign_keys'] as $key) {
        $driftCounts[$key] = is_array($schema[$key] ?? null) ? count($schema[$key]) : 0;
    }
    $driftCount = array_sum($driftCounts);
    $mismatchedColumns = $driftCounts['mismatched_columns'] > 0;
    $validEnvironment = in_array($environment, ['dev', 'production'], true);
    $state = schemaUpdateStateRead($statePath);
    $validJobState = in_array($state['status'] ?? '', ['idle', 'queued', 'running', 'completed', 'failed'], true);
    $ready = !$connectionError && $manifest !== null && $validEnvironment && !$mismatchedColumns && $validJobState;
    $activeJob = in_array($state['status'] ?? '', ['queued', 'running'], true);
    $alreadyApplied = ($state['status'] ?? '') === 'completed'
        && ($state['release_id'] ?? null) === ($manifest['release_id'] ?? null)
        && $driftCount === 0;

    $checks = [
        [
            'label' => 'Estado operacional do job',
            'status' => !$validJobState ? 'Bloqueado' : ($activeJob ? 'Atenção' : 'Aprovado'),
            'detail' => !$validJobState
                ? 'O estado operacional está inválido; preserve o arquivo e revise o histórico antes de continuar.'
                : ($activeJob ? 'Já existe uma atualização na fila ou em execução.' : 'Estado verificado.'),
        ],
        [
            'label' => 'Ambiente compatível',
            'status' => $validEnvironment ? 'Aprovado' : 'Bloqueado',
            'detail' => $validEnvironment ? ucfirst($environment) : 'A atualização não pode ser executada neste ambiente.',
        ],
        [
            'label' => 'Banco configurado',
            'status' => $connectionError ? 'Bloqueado' : 'Aprovado',
            'detail' => $connectionError ? 'Não foi possível conectar ao banco configurado.' : 'Conexão verificada; credenciais ocultas.',
        ],
        [
            'label' => 'Migrations versionadas da release',
            'status' => $manifest === null ? 'Bloqueado' : 'Aprovado',
            'detail' => $manifest === null ? 'Não foi possível verificar a release instalada.' : count($manifest['migrations']) . ' arquivo(s) numerado(s) verificado(s).',
        ],
        [
            'label' => 'Schema canônico',
            'status' => $connectionError || $mismatchedColumns ? 'Bloqueado' : ($driftCount > 0 ? 'Atenção' : 'Aprovado'),
            'detail' => $connectionError
                ? 'A inspeção será possível após conectar ao banco.'
                : ($mismatchedColumns
                    ? 'Há colunas com definição incompatível; a atualização automática foi bloqueada.'
                    : ($driftCount > 0 ? $driftCount . ' diferença(s) estrutural(is) serão conferidas após a execução.' : 'Sem divergência estrutural.')),
        ],
    ];

    return [
        'ready' => $ready,
        'can_queue' => $ready && !$activeJob && !$alreadyApplied,
        'environment' => $environment,
        'driver' => $driver,
        'database_name' => $databaseName,
        'expected_tables' => $expectedTables,
        'release_id' => $manifest['release_id'] ?? null,
        'migrations' => $manifest['migrations'] ?? [],
        'schema' => [
            'expected_tables' => $expectedTables,
            'drift_count' => $driftCount,
            'drift' => $driftCounts,
        ],
        'checks' => $checks,
        'job' => $state,
        'connection_error' => $connectionError,
    ];
}

/** @template T @param callable():T $operation @return T */
function schemaUpdateWithStateLock(string $path, callable $operation, int $lockType = LOCK_EX): mixed
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o estado da atualização de schema.');
    }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível bloquear o estado da atualização de schema.');
    }
    try {
        @chmod($path . '.lock', 0600);
        if (!flock($lock, $lockType)) {
            throw new RuntimeException('Não foi possível bloquear o estado da atualização de schema.');
        }
        return $operation();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array<string, mixed> */
function schemaUpdateStateReadUnlocked(string $path): array
{
    if (!is_file($path)) {
        return ['status' => 'idle'];
    }
    $contents = @file_get_contents($path);
    $state = is_string($contents) ? json_decode($contents, true) : null;
    return is_array($state) && isset($state['status']) ? $state : ['status' => 'unknown'];
}

/** @param array<string, mixed> $state */
function schemaUpdateStateWriteUnlocked(string $path, array $state): void
{
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível gravar o estado da atualização de schema.');
    }
    try {
        @chmod($path, 0600);
        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('Não foi possível gravar o estado da atualização de schema.');
        }
    } finally {
        fclose($handle);
    }
}
