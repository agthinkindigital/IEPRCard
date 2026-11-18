<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/batch_import_staging.php';

try {
    $expired = batchImportStagingCleanupExpired(dbConnect(), time());
    fwrite(STDOUT, 'Expired import lots: ' . $expired . "\n");
} catch (Throwable) {
    fwrite(STDERR, "Import staging cleanup failed.\n");
    exit(1);
}
