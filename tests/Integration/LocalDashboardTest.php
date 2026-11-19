<?php
declare(strict_types=1);

$environment = [];
foreach (['DB_DRIVER', 'DB_HOST', 'DB_PASSWORD', 'IEPRCARD_LOCAL_HEARTBEAT'] as $key) {
    $value = getenv($key);
    $environment[$key] = $value === false ? null : $value;
}

$heartbeat = tempnam(sys_get_temp_dir(), 'ieprcard-heartbeat-');
if ($heartbeat === false) {
    throw new RuntimeException('Could not create a temporary heartbeat file.');
}

putenv('DB_DRIVER=mysql');
putenv('DB_HOST=production.example.invalid');
putenv('DB_PASSWORD=local-dashboard-secret-must-not-appear');
putenv('IEPRCARD_LOCAL_HEARTBEAT=' . $heartbeat);

ob_start();
require dirname(__DIR__, 2) . '/public/index.php';
$html = ob_get_clean();

foreach ($environment as $key => $value) {
    if ($value === null) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

$heartbeatValue = file_get_contents($heartbeat);
unlink($heartbeat);

if (!is_string($html) || !str_contains($html, 'O ambiente local aceita apenas MySQL/MariaDB no próprio computador.')) {
    throw new RuntimeException('The local dashboard must refuse a remote MySQL host.');
}
if (str_contains($html, 'local-dashboard-secret-must-not-appear')) {
    throw new RuntimeException('The local dashboard must not expose database credentials.');
}
if (!is_string($html) || !str_contains($html, 'href="/admin/login.php"') || !str_contains($html, 'acesse o login administrativo')) {
    throw new RuntimeException('The local dashboard must link to the existing administrative login.');
}
if (str_contains($html, 'após a implementação dos módulos de autenticação')) {
    throw new RuntimeException('The local dashboard must not describe implemented authentication as future work.');
}
if (!is_string($heartbeatValue) || $heartbeatValue === '') {
    throw new RuntimeException('An HTTP page render must update the idle-timeout heartbeat.');
}

fwrite(STDOUT, "PASS: local dashboard links to admin login, blocks remote MySQL, hides credentials, and refreshes activity.\n");

return 'pass';
