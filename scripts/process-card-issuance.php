<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/card_issuance_worker.php';

$maxJobs = 1;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--max-jobs=([1-9][0-9]?)$/D', $argument, $match) === 1) {
        $maxJobs = min(20, (int) $match[1]);
    } elseif ($argument !== '--once') {
        fwrite(STDERR, "Uso: php scripts/process-card-issuance.php [--once|--max-jobs=1..20]\n");
        exit(2);
    }
}

try {
    $pdo = dbConnect();
    $lockPath = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . 'card-issuance-worker.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('ISSUANCE_WORKER_LOCK_UNAVAILABLE');
    }
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, "Outro worker de emissão já está em execução.\n");
        fclose($lock);
        exit(0);
    }
    $processed = 0;
    while ($processed < $maxJobs && cardIssuanceWorkerRunOne($pdo)) {
        $processed++;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    fwrite(STDOUT, "Jobs processados: {$processed}\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Worker de emissão falhou: " . preg_replace('/[^A-Z0-9_]/', '', $exception->getMessage()) . "\n");
    exit(1);
}
