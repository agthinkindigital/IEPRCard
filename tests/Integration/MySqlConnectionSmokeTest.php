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

$pdo = dbConnect([
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => $host,
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $username,
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
]);
$statement = $pdo->prepare('SELECT :answer AS answer');
$statement->execute(['answer' => 42]);
$answer = $statement->fetchColumn();

if ((int) $answer !== 42 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('MySQL PDO connection or prepared-statement smoke test failed.');
}

fwrite(STDOUT, "PASS: MySQL PDO connection and prepared statements.\n");

return 'pass';
