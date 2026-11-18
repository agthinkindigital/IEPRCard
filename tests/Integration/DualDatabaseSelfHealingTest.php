<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';

$pdo = dbConnect([
    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => ':memory:',
]);

if (!$pdo instanceof PDO) {
    throw new RuntimeException('dbConnect() must return a PDO instance.');
}

$foreignKeysEnabled = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
if ($foreignKeysEnabled !== 1) {
    throw new RuntimeException('SQLite foreign-key enforcement must be enabled.');
}

fwrite(STDOUT, "PASS: SQLite connection enables foreign keys.\n");

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=:memory:');
$dbValue = "bound value'); DROP TABLE connection_contract_items;--";
dbExecute('CREATE TABLE connection_contract_items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');
$insertedRows = dbExecute('INSERT INTO connection_contract_items (value) VALUES (:value)', ['value' => $dbValue]);
$insertId = dbInsertId();
$item = dbFetchOne('SELECT id, value FROM connection_contract_items WHERE value = :value', ['value' => $dbValue]);

if ($insertedRows !== 1 || $insertId !== '1' || $item === null || $item['value'] !== $dbValue || $item['id'] !== 1) {
    throw new RuntimeException('Database helpers must bind values and return inserted rows consistently.');
}

fwrite(STDOUT, "PASS: query helpers bind values and return rows and insert IDs.\n");

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Asia/Tokyo');
try {
    $timestamp = dbAgora();
} finally {
    date_default_timezone_set($previousTimezone);
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));
if ($date === false || $timestamp !== gmdate('Y-m-d H:i:s')) {
    throw new RuntimeException('dbAgora() must return an ISO timestamp in UTC.');
}

fwrite(STDOUT, "PASS: dbAgora() returns a UTC ISO timestamp.\n");

$sqliteFile = tempnam(sys_get_temp_dir(), 'ieprcard-sqlite-');
if ($sqliteFile === false) {
    throw new RuntimeException('Could not create a temporary SQLite file.');
}

try {
    $filePdo = dbConnect([
        'DB_DRIVER' => 'sqlite',
        'DB_SQLITE_PATH' => $sqliteFile,
    ]);
    $journalMode = strtolower((string) $filePdo->query('PRAGMA journal_mode')->fetchColumn());
    if ($journalMode !== 'wal') {
        throw new RuntimeException('SQLite file connections must use WAL journal mode.');
    }
} finally {
    unset($filePdo);
    foreach ([$sqliteFile, $sqliteFile . '-wal', $sqliteFile . '-shm'] as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

fwrite(STDOUT, "PASS: SQLite file connections use WAL journal mode.\n");

$invalidConfigurations = [
    ['DB_DRIVER' => 'postgres'],
    ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ''],
    ['DB_DRIVER' => 'mysql', 'DB_DATABASE' => 'iepr;dbname=other', 'DB_USERNAME' => 'tester'],
    ['DB_DRIVER' => 'mysql', 'DB_DATABASE' => 'ieprcard', 'DB_USERNAME' => 'tester', 'DB_PORT' => '70000'],
];

foreach ($invalidConfigurations as $configuration) {
    try {
        dbConnect($configuration);
        throw new RuntimeException('Invalid database configuration was accepted.');
    } catch (InvalidArgumentException) {
        continue;
    }
}

fwrite(STDOUT, "PASS: unsupported drivers and unsafe database settings are rejected.\n");

return 'pass';
