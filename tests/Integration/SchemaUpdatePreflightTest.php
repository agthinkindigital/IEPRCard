<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/schema_update.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-schema-preflight-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not prepare an isolated schema preflight database.');
}
$databasePath = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';

try {
    $configuration = ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath];
    $pdo = dbConnect($configuration);
    schemaGarantirEstrutura($pdo, 'test');
    schemaAplicarMigracoes($pdo, 'test');

    $report = schemaUpdatePreflight($pdo, $configuration, 'dev', $statePath);
    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (($report['ready'] ?? false) !== true
        || ($report['environment'] ?? null) !== 'dev'
        || ($report['driver'] ?? null) !== 'sqlite'
        || ($report['database_name'] ?? null) !== 'source.sqlite'
        || ($report['schema']['expected_tables'] ?? null) !== count(\IEPRCard\Database\Schema\catalogoCanonico())
        || ($report['schema']['drift_count'] ?? null) !== 0
        || !is_array($report['migrations'] ?? null)
        || count($report['migrations']) !== count(schemaUpdateReleaseManifest()['migrations'])
        || str_contains($encoded, $directory)
        || str_contains($encoded, 'password')
        || str_contains($encoded, 'username')) {
        throw new RuntimeException('Preflight must report a clean canonical schema and trusted release while hiding local paths and credentials.');
    }

    fwrite(STDOUT, "PASS: schema update preflight reports the active release and canonical schema without exposing connection details.\n");
} finally {
    unset($pdo);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

return 'pass';
