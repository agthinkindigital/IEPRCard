<?php
declare(strict_types=1);

$testFiles = glob(__DIR__ . '/Integration/*Test.php') ?: [];
sort($testFiles, SORT_STRING);

if ($testFiles === []) {
    fwrite(STDERR, "No integration tests were found.\n");
    exit(1);
}

foreach ($testFiles as $testFile) {
    fwrite(STDOUT, 'Running ' . basename($testFile) . "...\n");

    try {
        $result = require $testFile;
        if ($result === 'skip') {
            $skipped[] = basename($testFile);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: ' . basename($testFile) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

if (!empty($skipped)) {
    fwrite(STDERR, 'Incomplete integration suite; skipped: ' . implode(', ', $skipped) . "\n");
    exit(2);
}

fwrite(STDOUT, "All integration tests passed.\n");
