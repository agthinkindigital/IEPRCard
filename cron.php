<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (count($argv) > 1 && $argv[1] !== '--once' || count($argv) > 2) {
    fwrite(STDERR, "Uso: php cron.php [--once]\n");
    exit(2);
}

require_once __DIR__ . '/config/conn.php';
require_once __DIR__ . '/config/batch_import_staging.php';
require_once __DIR__ . '/config/card_issuance_worker.php';
require_once __DIR__ . '/config/whatsapp_conversation.php';
require_once __DIR__ . '/config/titular_anonymization.php';
require_once __DIR__ . '/config/scheduled_tasks.php';

$lock = null;
try {
    $pdo = dbConnect();
    $lockPath = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . 'scheduled-tasks.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('SCHEDULED_TASK_LOCK_UNAVAILABLE');
    }
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $lock = null;
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'skipped' => true,
            'reason' => 'SCHEDULED_TASKS_ALREADY_RUNNING',
        ], JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    try {
        $result = scheduledTasksRun(scheduledTasksBuild($pdo));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
    }

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit($result['ok'] ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, json_encode(['ok' => false, 'error_code' => 'SCHEDULED_TASKS_FAILED'], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
