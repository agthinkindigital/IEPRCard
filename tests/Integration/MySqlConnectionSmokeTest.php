<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';

$ci = filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$driverAvailable = in_array('mysql', PDO::getAvailableDrivers(), true);
$host = getenv('TEST_MYSQL_HOST');
$database = getenv('TEST_MYSQL_DATABASE');
$username = getenv('TEST_MYSQL_USERNAME');

if ($driverAvailable) {
    $connectionFailure = null;
    try {
        dbConnect([
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'ieprcard',
            'DB_USERNAME' => 'probe',
            'DB_PASSWORD' => 'secret-that-must-not-appear',
        ]);
    } catch (RuntimeException $exception) {
        $connectionFailure = $exception;
    }

    if ($connectionFailure === null || str_contains($connectionFailure->getMessage(), 'secret-that-must-not-appear')) {
        throw new RuntimeException('MySQL connection failures must not expose credentials.');
    }

    fwrite(STDOUT, "PASS: MySQL connection failures hide credentials.\n");
}

if (!$driverAvailable || $host === false || $database === false || $username === false) {
    if ($ci) {
        throw new RuntimeException('CI requires pdo_mysql and TEST_MYSQL_HOST/DATABASE/USERNAME configuration.');
    }

    fwrite(STDOUT, "SKIP: MySQL smoke test needs pdo_mysql and TEST_MYSQL_* environment variables.\n");
    return 'skip';
}

putenv('DB_DRIVER=mysql');
putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . (getenv('TEST_MYSQL_PORT') ?: '3306'));
putenv('DB_DATABASE=' . $database);
putenv('DB_USERNAME=' . $username);
putenv('DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));

$connection = dbConnection();
if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('DB_DRIVER=mysql must select the MySQL PDO driver.');
}

dbExecute('CREATE TEMPORARY TABLE connection_contract_items (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255) NOT NULL)');
$dbValue = "bound value'); DROP TABLE connection_contract_items;--";
$insertedRows = dbExecute('INSERT INTO connection_contract_items (value) VALUES (:value)', ['value' => $dbValue]);
$insertId = dbInsertId();
$item = dbFetchOne('SELECT id, value FROM connection_contract_items WHERE value = :value', ['value' => $dbValue]);
$items = dbFetchAll('SELECT id, value FROM connection_contract_items');

if ($insertedRows !== 1 || $insertId !== '1' || $item === null || $item['value'] !== $dbValue || (int) $item['id'] !== 1 || count($items) !== 1) {
    throw new RuntimeException('MySQL query helpers must bind values and return rows consistently.');
}

fwrite(STDOUT, "PASS: MySQL PDO connection and query helpers.\n");

return 'pass';
