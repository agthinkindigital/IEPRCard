<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

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
schemaGarantirEstrutura($target, 'test');
schemaAplicarMigracoes($target, 'test');
$rollbackSource = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($rollbackSource, 'test');
schemaAplicarMigracoes($rollbackSource, 'test');
$permissionBaselineCount = (int) $rollbackSource->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
$rollbackSource->exec("INSERT INTO planos (nome, preco) VALUES ('Plano rollback', 19.90)");
$rollbackSource->exec("INSERT INTO tenants (plano_id, tipo, nome_organizacao) VALUES (1, 'cliente', 'Tenant rollback')");

require_once dirname(__DIR__, 2) . '/database/migration/sqlite_to_mysql.php';
$rollbackObserved = false;
try {
    ieprMigrarSqliteParaMysql($rollbackSource, $target, static function (array $progress): void {
        if (($progress['table'] ?? null) === 'planos') {
            throw new RuntimeException('test transfer rejection');
        }
    });
} catch (RuntimeException $exception) {
    $rollbackObserved = $exception->getMessage() === 'A transferência falhou e foi revertida no destino.';
}
$targetRowsAfterRollback = 0;
foreach (array_keys(\IEPRCard\Database\Schema\catalogoCanonico()) as $table) {
    $targetRowsAfterRollback += (int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
if (!$rollbackObserved || $targetRowsAfterRollback !== $permissionBaselineCount
    || (int) $target->query('SELECT COUNT(*) FROM permissoes')->fetchColumn() !== $permissionBaselineCount
    || (int) $target->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() !== 1) {
    throw new RuntimeException('A failed row transfer must roll back all destination rows and restore foreign-key checks. '
        . json_encode([
            'observed_rollback' => $rollbackObserved,
            'destination_rows' => $targetRowsAfterRollback,
            'expected_permission_rows' => $permissionBaselineCount,
            'actual_permission_rows' => (int) $target->query('SELECT COUNT(*) FROM permissoes')->fetchColumn(),
            'foreign_key_checks' => (int) $target->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn(),
        ], JSON_THROW_ON_ERROR));
}

$source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($source, 'test');
schemaAplicarMigracoes($source, 'test');

$source->exec("INSERT INTO planos (nome, preco) VALUES ('Plano base', 19.90)");
$source->exec("INSERT INTO tenants (plano_id, tipo, nome_organizacao) VALUES (1, 'cliente', 'Cliente de teste')");
$telemetryInsert = $source->prepare("INSERT INTO telemetria_eventos (tenant_id, categoria, evento_chave, quantidade, criado_em) VALUES (1, 'uso', 'migration.fixture', 1, '2026-11-18 12:00:00')");
$source->beginTransaction();
for ($sequence = 0; $sequence < 1000; $sequence++) {
    $telemetryInsert->execute();
}
$source->commit();

$progress = [];
$result = ieprMigrarSqliteParaMysql($source, $target, static function (array $update) use (&$progress): void {
    $progress[] = $update;
});
$planCount = (int) $target->query('SELECT COUNT(*) FROM planos')->fetchColumn();
$tenantName = $target->query('SELECT nome_organizacao FROM tenants WHERE id = 1')->fetchColumn();

if (($result['total_records'] ?? null) !== 1002 + $permissionBaselineCount
    || ($result['tables']['planos']['row_count'] ?? null) !== 1
    || ($result['tables']['tenants']['row_count'] ?? null) !== 1
    || ($result['tables']['permissoes']['row_count'] ?? null) !== $permissionBaselineCount
    || ($result['tables']['telemetria_eventos']['row_count'] ?? null) !== 1000
    || count($progress) !== 27
    || ($progress[26]['completed_tables'] ?? null) !== 27
    || ($progress[26]['completed_records'] ?? null) !== 1002 + $permissionBaselineCount
    || $planCount !== 1
    || $tenantName !== 'Cliente de teste'
    || ($result['duration_ms'] ?? PHP_INT_MAX) >= 5000) {
    throw new RuntimeException('SQLite-to-MySQL migration must preserve 1,000 fixture rows and finish below five seconds.');
}

$repeatRejected = false;
try {
    ieprMigrarSqliteParaMysql($source, $target);
} catch (RuntimeException $exception) {
    $repeatRejected = str_contains($exception->getMessage(), 'O destino contém dados; a execução pode já ter ocorrido.');
}
$finalTelemetryCount = (int) $target->query('SELECT COUNT(*) FROM telemetria_eventos')->fetchColumn();
if (!$repeatRejected || $finalTelemetryCount !== 1000) {
    throw new RuntimeException('A repeated migration must clearly refuse a populated destination without duplicating rows.');
}

$serverVersion = (string) $target->query('SELECT VERSION()')->fetchColumn();
fwrite(STDOUT, 'PASS: SQLite-to-MySQL migration transferred 1,000 fixture rows in ' . $result['duration_ms']
    . ' ms (PHP ' . PHP_VERSION . ', database ' . $serverVersion . ").\n");

return 'pass';
