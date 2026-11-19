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
    $result = cardIssuanceWorkerRunBatch(dbConnect(), $maxJobs);
    if ($result['skipped']) {
        fwrite(STDOUT, "Outro worker de emissão já está em execução.\n");
        exit(0);
    }
    fwrite(STDOUT, 'Jobs processados: ' . $result['processed'] . "\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Worker de emissão falhou: " . preg_replace('/[^A-Z0-9_]/', '', $exception->getMessage()) . "\n");
    exit(1);
}
