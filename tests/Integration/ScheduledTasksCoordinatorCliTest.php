<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/conn.php';

function scheduledTasksTestRemoveTree(string $path): void
{
    $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $normalized = rtrim($path, DIRECTORY_SEPARATOR);
    if (!str_starts_with($normalized, $temporaryRoot . 'ieprcard-scheduled-tasks-')
        || !is_dir($normalized) || is_link($normalized)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($normalized);
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-scheduled-tasks-' . bin2hex(random_bytes(6));
$storagePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'private';
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'scheduled.sqlite';
if (!mkdir($storagePath, 0700, true)) {
    throw new RuntimeException('Could not create scheduled task test storage.');
}

$keyNames = ['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'IEPR_PRIVATE_STORAGE_PATH', 'IEPR_ANONYMIZATION_KEY'];
$oldValues = [];
foreach ($keyNames as $name) {
    $value = getenv($name);
    $oldValues[$name] = $value === false ? null : $value;
}

try {
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    schemaAplicarMigracoes($pdo, 'test');
    unset($pdo);

    putenv('APP_ENV=test');
    putenv('DB_DRIVER=sqlite');
    putenv('DB_SQLITE_PATH=' . $databasePath);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(32)));
    $root = dirname(__DIR__, 2);
    $phpArguments = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDirectory = ini_get('extension_dir');
        if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
            $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
        }
        $phpArguments = ['-n', '-d', 'extension_dir=' . $extensionDirectory];
        foreach (['sqlite3', 'pdo_sqlite', 'sodium', 'gd', 'mbstring', 'zip'] as $extension) {
            $phpArguments[] = '-d';
            $phpArguments[] = 'extension=' . $extension;
        }
    }
    $command = array_merge([PHP_BINARY], $phpArguments, [$root . DIRECTORY_SEPARATOR . 'cron.php', '--once']);
    $runCoordinator = static function () use ($command, $root): array {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the scheduled task CLI.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    };
    $execution = $runCoordinator();
    $stdout = $execution['stdout'];
    $stderr = $execution['stderr'];
    $exitCode = $execution['exit_code'];
    $summary = json_decode((string) $stdout, true);
    if ($exitCode !== 0 || !is_array($summary) || ($summary['ok'] ?? null) !== true
        || array_keys($summary['tasks'] ?? []) !== [
            'card_issuance', 'whatsapp_delivery', 'import_staging_cleanup', 'titular_anonymization',
            'privacy_subject_retention', 'whatsapp_conversation_cleanup', 'privacy_backup', 'privacy_snapshot_retention',
        ]
        || str_contains((string) $stdout, $storagePath) || str_contains((string) $stderr, $databasePath)) {
        throw new RuntimeException('The scheduled task CLI must run bounded tasks and emit a private-data-free summary: ' . $stderr . $stdout);
    }

    fwrite(STDOUT, "PASS: scheduled task CLI summary and output redaction.\n");

    require_once dirname(__DIR__, 2) . '/config/card_issuance_worker.php';
    $workerLockPath = $storagePath . DIRECTORY_SEPARATOR . 'card-issuance-worker.lock';
    $workerLock = fopen($workerLockPath, 'c');
    if (!is_resource($workerLock) || !flock($workerLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Could not acquire the issuance lock for its regression check.');
    }
    try {
        $workerResult = cardIssuanceWorkerRunBatch(dbConnect(), 1);
        if (!$workerResult['skipped']) {
            throw new RuntimeException('The shared-hosting coordinator must preserve the standalone issuance worker lock.');
        }
    } finally {
        flock($workerLock, LOCK_UN);
        fclose($workerLock);
    }

    $cronLock = fopen($storagePath . DIRECTORY_SEPARATOR . 'scheduled-tasks.lock', 'c');
    if (!is_resource($cronLock) || !flock($cronLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Could not acquire the coordinator lock for its regression check.');
    }
    try {
        $skippedRun = $runCoordinator();
        $skippedSummary = json_decode((string) $skippedRun['stdout'], true);
        if ($skippedRun['exit_code'] !== 0 || !is_array($skippedSummary)
            || ($skippedSummary['skipped'] ?? null) !== true
            || ($skippedSummary['reason'] ?? null) !== 'SCHEDULED_TASKS_ALREADY_RUNNING') {
            throw new RuntimeException('A concurrent scheduled invocation must exit successfully without overlapping tasks.');
        }
    } finally {
        flock($cronLock, LOCK_UN);
        fclose($cronLock);
    }

    fwrite(STDOUT, "PASS: scheduled task coordinator and issuance locks.\n");

    $anonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
    putenv('IEPR_ANONYMIZATION_KEY');
    try {
        $keylessRun = $runCoordinator();
    } finally {
        putenv('IEPR_ANONYMIZATION_KEY=' . (is_string($anonymizationKey) ? $anonymizationKey : ''));
    }
    $keylessSummary = json_decode((string) $keylessRun['stdout'], true);
    if ($keylessRun['exit_code'] !== 1 || !is_array($keylessSummary)
        || ($keylessSummary['ok'] ?? null) !== false
        || ($keylessSummary['tasks']['titular_anonymization']['error_code'] ?? null) !== 'ANONYMIZATION_KEY_UNAVAILABLE'
        || str_contains((string) $keylessRun['stdout'], $storagePath)
        || str_contains((string) $keylessRun['stderr'], $databasePath)) {
        throw new RuntimeException('The scheduled task CLI must report key failure safely and continue independent tasks.');
    }

    fwrite(STDOUT, "PASS: scheduled task failure handling without the anonymization key.\n");

    $portSocket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorText);
    if (!is_resource($portSocket)) {
        throw new RuntimeException('Could not reserve a local port for the HTTP guard test.');
    }
    $socketName = stream_socket_get_name($portSocket, false);
    fclose($portSocket);
    $port = (int) substr((string) $socketName, strrpos((string) $socketName, ':') + 1);
    $serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . 'http-server.log';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $serverPipes,
        $root
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the local HTTP guard test server.');
    }
    fclose($serverPipes[0]);
    $response = '';
    try {
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectErrorText, 0.2);
            if (is_resource($client)) {
                fwrite($client, "GET /cron.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                stream_set_timeout($client, 2);
                $response = stream_get_contents($client) ?: '';
                fclose($client);
                break;
            }
            usleep(100_000);
        }
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
    if (!preg_match('/^HTTP\/1\.[01] 404\b/m', $response)) {
        throw new RuntimeException('The scheduled task entrypoint must reject HTTP requests.');
    }

    fwrite(STDOUT, "PASS: scheduled task HTTP requests are rejected.\n");
} finally {
    foreach ($keyNames as $name) {
        $oldValues[$name] === null ? putenv($name) : putenv($name . '=' . $oldValues[$name]);
    }
    scheduledTasksTestRemoveTree($temporaryRoot);
}
