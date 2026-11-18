<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

$ci = filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if (!in_array('mysql', PDO::getAvailableDrivers(), true)
    || getenv('TEST_MYSQL_HOST') === false
    || getenv('TEST_MYSQL_DATABASE') === false
    || getenv('TEST_MYSQL_USERNAME') === false) {
    if ($ci) {
        throw new RuntimeException('CI requires MySQL configuration for SQLite-to-MySQL migration tests.');
    }
    fwrite(STDOUT, "SKIP: SQLite-to-MySQL migration test needs pdo_mysql and TEST_MYSQL_* environment variables.\n");
    return 'skip';
}

putenv('DB_DRIVER=mysql');
putenv('DB_HOST=' . getenv('TEST_MYSQL_HOST'));
putenv('DB_PORT=' . (getenv('TEST_MYSQL_PORT') ?: '3306'));
putenv('DB_DATABASE=' . getenv('TEST_MYSQL_DATABASE'));
putenv('DB_USERNAME=' . getenv('TEST_MYSQL_USERNAME'));
putenv('DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
$target = dbConnection();
$source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($source, 'test');

$source->exec("INSERT INTO planos (nome, preco) VALUES ('Plano base', 19.90)");
$source->exec("INSERT INTO tenants (plano_id, tipo, nome_organizacao) VALUES (1, 'cliente', 'Cliente de teste')");

require_once dirname(__DIR__, 2) . '/database/migration/sqlite_to_mysql.php';
$result = ieprMigrarSqliteParaMysql($source, $target);
$planCount = (int) $target->query('SELECT COUNT(*) FROM planos')->fetchColumn();
$tenantName = $target->query('SELECT nome_organizacao FROM tenants WHERE id = 1')->fetchColumn();

if (($result['total_records'] ?? null) !== 2
    || ($result['tables']['planos']['row_count'] ?? null) !== 1
    || ($result['tables']['tenants']['row_count'] ?? null) !== 1
    || $planCount !== 1
    || $tenantName !== 'Cliente de teste') {
    throw new RuntimeException('SQLite-to-MySQL migration must preserve canonical rows and report their counts.');
}

fwrite(STDOUT, "PASS: SQLite-to-MySQL migration transfers canonical data and reports checksums.\n");

return 'pass';
