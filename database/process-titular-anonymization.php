<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/titular_anonymization.php';

$limit = isset($argv[1]) && preg_match('/^[0-9]{1,3}$/D', $argv[1]) === 1 ? (int) $argv[1] : 100;
if ($limit < 1 || $limit > 200 || isset($argv[2])) {
    fwrite(STDERR, "Uso: php database/process-titular-anonymization.php [lote:1-200]\n");
    exit(2);
}

try {
    $result = titularAnonymizationProcessDue(dbConnect(), null, $limit);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(($result['failed'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "ANONYMIZATION_WORKER_FAILED\n");
    exit(1);
}
