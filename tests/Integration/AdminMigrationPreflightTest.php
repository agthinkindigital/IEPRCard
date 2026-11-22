<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_migration_preflight.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-migration-preflight-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$path = $directory . DIRECTORY_SEPARATOR . 'source.sqlite';
$keys = ['DB_DRIVER', 'DB_SQLITE_PATH', 'MIGRATION_SOURCE_SQLITE_PATH', 'MIGRATION_TARGET_HOST', 'MIGRATION_TARGET_PORT', 'MIGRATION_TARGET_DATABASE', 'MIGRATION_TARGET_USERNAME', 'MIGRATION_TARGET_PASSWORD'];
$previous = [];
foreach ($keys as $key) {
    $value = getenv($key);
    $previous[$key] = $value === false ? null : $value;
}
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $path);
putenv('MIGRATION_SOURCE_SQLITE_PATH=' . $path);
putenv('MIGRATION_TARGET_HOST=127.0.0.1');
putenv('MIGRATION_TARGET_PORT=1');
putenv('MIGRATION_TARGET_DATABASE=target_db');
putenv('MIGRATION_TARGET_USERNAME=migration_user');
putenv('MIGRATION_TARGET_PASSWORD=do-not-display-this-secret');
try {
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $path]);
    schemaGarantirEstrutura($pdo, 'test');
    $pdo->exec("INSERT INTO planos (nome, preco) VALUES ('Fixture restrito', 10.00)");
    $pdo->exec("INSERT INTO tenants (plano_id, tipo, nome_organizacao) VALUES (1, 'cliente', 'Titular Confidencial')");
    schemaAplicarMigracoes($pdo, 'test');
    $permissionCount = (int) $pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();
    unset($pdo);

    $report = adminMigrationPreflight();
    $serialized = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (($report['ready'] ?? true) !== false || ($report['source_records'] ?? null) !== 4 + $permissionCount
        || ($report['destination_records'] ?? null) !== 0
        || ($report['source_name'] ?? null) !== 'source.sqlite'
        || str_contains($serialized, $path) || str_contains($serialized, 'Titular Confidencial')
        || str_contains($serialized, 'do-not-display-this-secret') || str_contains($serialized, 'migration_user')) {
        throw new RuntimeException('Preflight must report operational counts while hiding file paths, personal data, and credentials.');
    }
    $blocked = array_filter($report['checks'], static fn(array $check): bool => $check['status'] === 'Bloqueado');
    if ($blocked === []) {
        throw new RuntimeException('Preflight must block a copy when the destination cannot be connected and checked.');
    }

    $mysqlHost = getenv('TEST_MYSQL_HOST');
    $mysqlDatabase = getenv('TEST_MYSQL_DATABASE');
    $mysqlUsername = getenv('TEST_MYSQL_USERNAME');
    if (in_array('mysql', PDO::getAvailableDrivers(), true)
        && $mysqlHost !== false && $mysqlDatabase !== false && $mysqlUsername !== false) {
        $mysqlConfiguration = [
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => $mysqlHost,
            'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
            'DB_DATABASE' => $mysqlDatabase,
            'DB_USERNAME' => $mysqlUsername,
            'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
        ];
        $target = dbConnect($mysqlConfiguration);
        schemaGarantirEstrutura($target, 'test');
        schemaAplicarMigracoes($target, 'test');
        unset($target);
        putenv('MIGRATION_TARGET_HOST=' . $mysqlConfiguration['DB_HOST']);
        putenv('MIGRATION_TARGET_PORT=' . $mysqlConfiguration['DB_PORT']);
        putenv('MIGRATION_TARGET_DATABASE=' . $mysqlConfiguration['DB_DATABASE']);
        putenv('MIGRATION_TARGET_USERNAME=' . $mysqlConfiguration['DB_USERNAME']);
        putenv('MIGRATION_TARGET_PASSWORD=' . $mysqlConfiguration['DB_PASSWORD']);
        $mysqlReport = adminMigrationPreflight();
        $mysqlSerialized = json_encode($mysqlReport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (($mysqlReport['ready'] ?? false) !== true || ($mysqlReport['destination_records'] ?? null) !== $permissionCount
            || ($mysqlConfiguration['DB_PASSWORD'] !== '' && str_contains($mysqlSerialized, $mysqlConfiguration['DB_PASSWORD']))
            || str_contains($mysqlSerialized, $mysqlConfiguration['DB_USERNAME'])) {
            throw new RuntimeException('MySQL/MariaDB preflight must accept only the matching permission catalog and hide connection settings.');
        }
    }
    fwrite(STDOUT, "PASS: migration preflight is read-only, counts records, and redacts paths, credentials, and row data.\n");
} finally {
    unset($pdo);
    foreach ($keys as $key) {
        $value = $previous[$key];
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

return 'pass';
