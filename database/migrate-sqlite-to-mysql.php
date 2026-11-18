<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once __DIR__ . '/schema/catalog.php';
require_once __DIR__ . '/schema/ddl.php';
require_once __DIR__ . '/schema/inspect.php';
require_once dirname(__DIR__) . '/config/schema_self_healing.php';
require_once __DIR__ . '/migration/sqlite_to_mysql.php';

$sourcePath = getenv('MIGRATION_SOURCE_SQLITE_PATH');
if ($sourcePath === false || $sourcePath === '' || !is_file($sourcePath)) {
    fwrite(STDERR, "Defina MIGRATION_SOURCE_SQLITE_PATH para um arquivo SQLite existente.\n");
    exit(2);
}

try {
    $source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sourcePath]);
    $target = dbConnection();
    $report = ieprMigrarSqliteParaMysql($source, $target);
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
