<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/admin_bootstrap.php';

$name = getenv('IEPRCARD_INITIAL_ADMIN_NAME');
$email = getenv('IEPRCARD_INITIAL_ADMIN_EMAIL');
$password = getenv('IEPRCARD_INITIAL_ADMIN_PASSWORD');
if ($name === false || $email === false || $password === false) {
    fwrite(STDERR, "Defina IEPRCARD_INITIAL_ADMIN_NAME, IEPRCARD_INITIAL_ADMIN_EMAIL e IEPRCARD_INITIAL_ADMIN_PASSWORD no ambiente do processo.\n");
    exit(2);
}

try {
    $id = adminAuthProvisionInitialSuperadmin(dbConnection(), $name, $email, $password);
    fwrite(STDOUT, "Administrador SaaS inicial criado (ID {$id}). Remova a senha do ambiente do processo.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Provisionamento não concluído: ' . $error->getMessage() . "\n");
    exit(1);
}
