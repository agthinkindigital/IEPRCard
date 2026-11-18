<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-admin-bootstrap-' . bin2hex(random_bytes(6)) . '.sqlite';
$lockPath = $path . '.lock';
try {
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $path]);
    schemaGarantirEstrutura($pdo, 'test');
    $id = adminAuthProvisionInitialSuperadmin($pdo, '  Admin Inicial  ', 'FIRST@EXAMPLE.TEST', 'a-long-initial-password', $lockPath);
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
