<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');

$insert = $pdo->prepare('INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (NULL, NULL, NULL, NULL, :role, :name, :email, :password_hash, 1)');
$insert->execute([
    'role' => 'superadmin',
    'name' => 'Administrador de teste',
    'email' => 'admin@example.test',
    'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT),
]);
$adminId = (int) $pdo->lastInsertId();

$admin = adminAuthAuthenticate($pdo, 'admin@example.test', 'test-password-123');
if ($admin === null || $admin['id'] !== $adminId || $admin['papel_sistema'] !== 'superadmin') {
    throw new RuntimeException('An active global superadmin with a valid password must authenticate.');
}
if (adminAuthAuthenticate($pdo, 'admin@example.test', 'wrong-password') !== null) {
    throw new RuntimeException('An incorrect password must not authenticate.');
}

$insert->execute([
    'role' => 'titular_cliente',
    'name' => 'Usuário sem papel de sistema',
    'email' => 'non-admin@example.test',
    'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT),
]);
if (adminAuthAuthenticate($pdo, 'non-admin@example.test', 'test-password-123') !== null) {
    throw new RuntimeException('A non-superadmin user must not authenticate to the system administration area.');
}

$pdo->exec("INSERT INTO tenants (nome_organizacao) VALUES ('Tenant de teste')");
$tenantId = (int) $pdo->lastInsertId();
$scopedInsert = $pdo->prepare('INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (:tenant_id, :role, :name, :email, :password_hash, 1)');
$scopedInsert->execute([
    'tenant_id' => $tenantId,
    'role' => 'superadmin',
    'name' => 'Usuário com papel fora do escopo global',
    'email' => 'scoped-admin@example.test',
    'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT),
]);
if (adminAuthAuthenticate($pdo, 'scoped-admin@example.test', 'test-password-123') !== null) {
    throw new RuntimeException('A tenant-scoped user must not authenticate to the global administration area.');
}

$inactiveInsert = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 0)');
$inactiveInsert->execute([
    'role' => 'superadmin',
    'name' => 'Administrador inativo',
    'email' => 'inactive-admin@example.test',
    'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT),
]);
if (adminAuthAuthenticate($pdo, 'inactive-admin@example.test', 'test-password-123') !== null) {
    throw new RuntimeException('An inactive global superadmin must not authenticate.');
}

fwrite(STDOUT, "PASS: an active global superadmin can authenticate with the stored password hash.\n");

return 'pass';
