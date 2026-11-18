<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$host = getenv('TEST_MYSQL_HOST');
$database = getenv('TEST_MYSQL_DATABASE');
$username = getenv('TEST_MYSQL_USERNAME');
if (!in_array('mysql', PDO::getAvailableDrivers(), true) || $host === false || $database === false || $username === false) {
    if (filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('CI requires MySQL/MariaDB configuration for admin authentication tests.');
    }
    fwrite(STDOUT, "SKIP: admin authentication MySQL test needs TEST_MYSQL_* environment variables.\n");
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
schemaGarantirEstrutura($pdo, 'test');
$suffix = bin2hex(random_bytes(8));
$email = 'admin-' . $suffix . '@example.test';
$tenantName = 'AdminAuthTenant' . $suffix;
$pdo->beginTransaction();
try {
    $global = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (NULL, :role, :name, :email, :password_hash, 1)'
    );
    $global->execute([
        'role' => 'superadmin',
        'name' => 'Administrador MySQL',
        'email' => $email,
        'password_hash' => password_hash('mysql-test-password', PASSWORD_DEFAULT),
    ]);
    if (adminAuthAuthenticate($pdo, $email, 'mysql-test-password') === null
        || adminAuthAuthenticate($pdo, $email, 'wrong-password') !== null) {
        throw new RuntimeException('MySQL/MariaDB must authenticate the global superadmin by password hash and deny a wrong password.');
    }

    $tenant = $pdo->prepare('INSERT INTO tenants (nome_organizacao) VALUES (:name)');
    $tenant->execute(['name' => $tenantName]);
    $tenantId = (int) $pdo->lastInsertId();
    $scoped = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, :role, :name, :email, :password_hash, 1)'
    );
    $scoped->execute([
        'tenant' => $tenantId,
        'role' => 'superadmin',
        'name' => 'Administrador escopado',
        'email' => 'scoped-' . $suffix . '@example.test',
        'password_hash' => password_hash('mysql-test-password', PASSWORD_DEFAULT),
    ]);
    $scopedEmail = 'scoped-' . $suffix . '@example.test';
    if (adminAuthAuthenticate($pdo, $scopedEmail, 'mysql-test-password') !== null) {
        throw new RuntimeException('MySQL/MariaDB must deny a tenant-scoped superadmin.');
    }
    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

fwrite(STDOUT, "PASS: MySQL/MariaDB admin authentication enforces global scope and stored password hashes.\n");

return 'pass';
