<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-admin-bootstrap-' . bin2hex(random_bytes(6)) . '.sqlite';
$lockPath = $path . '.lock';
try {
    $occupiedPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
    schemaGarantirEstrutura($occupiedPdo, 'test');
    $occupiedPdo->exec("INSERT INTO tenants (nome_organizacao) VALUES ('Tenant existente')");
    $occupiedTenantId = (int) $occupiedPdo->lastInsertId();
    $existingUser = $occupiedPdo->prepare(
        'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant_id, :role, :name, :email, :password_hash, 0)'
    );
    $existingUser->execute([
        'tenant_id' => $occupiedTenantId,
        'role' => 'titular_cliente',
        'name' => 'Usuário já existente',
        'email' => 'existing-user@example.test',
        'password_hash' => password_hash('existing-password', PASSWORD_DEFAULT),
    ]);
    try {
        adminAuthProvisionInitialSuperadmin(
            $occupiedPdo,
            'Admin Inicial',
            'first@example.test',
            'a-long-initial-password',
            $lockPath,
        );
        throw new RuntimeException('Bootstrap must refuse when any user already exists.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Bootstrap must refuse when any user already exists.') {
            throw $error;
        }
    }
    if ((int) $occupiedPdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() !== 1) {
        throw new RuntimeException('Refused bootstrap must not add a user to a populated database.');
    }
    unset($occupiedPdo);

    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $path]);
    schemaGarantirEstrutura($pdo, 'test');
    try {
        adminAuthProvisionInitialSuperadmin($pdo, 'Admin Inicial', 'first@example.test', 'éééééé', $lockPath);
        throw new RuntimeException('Bootstrap must require at least 12 password characters, not only 12 bytes.');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() === 'Bootstrap must require at least 12 password characters, not only 12 bytes.') {
            throw $error;
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() !== 0) {
        throw new RuntimeException('A short Unicode password must not create an administrator.');
    }

    $id = adminAuthProvisionInitialSuperadmin($pdo, '  Admin Inicial  ', 'FIRST@EXAMPLE.TEST', 'a-long-initial-password', $lockPath);
    if (!is_array(privacySubjectFindForRecord($pdo, 'usuario', $id))) {
        throw new RuntimeException('The initial superadmin account must be linked to a PII-removable privacy subject.');
    }
    $admin = adminAuthAuthenticate($pdo, 'first@example.test', 'a-long-initial-password');
    if ($id < 1 || $admin === null || $admin['papel_sistema'] !== 'superadmin') {
        throw new RuntimeException('Bootstrap must create an active global superadmin with a verifiable password hash.');
    }

    try {
        adminAuthProvisionInitialSuperadmin($pdo, 'Second Admin', 'second@example.test', 'another-long-password', $lockPath);
        throw new RuntimeException('Bootstrap must refuse to provision a second global superadmin.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Bootstrap must refuse to provision a second global superadmin.') {
            throw $error;
        }
    }
    unset($pdo);
    fwrite(STDOUT, "PASS: first global superadmin can be provisioned once with a password hash.\n");
} finally {
    unset($pdo);
    foreach ([$path, $path . '-wal', $path . '-shm', $lockPath] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

return 'pass';
