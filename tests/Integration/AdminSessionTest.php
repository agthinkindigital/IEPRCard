<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');
$insert = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password_hash, 1)');
$insert->execute([
    'role' => 'superadmin',
    'name' => 'Administrador de sessão',
    'email' => 'session-admin@example.test',
    'password_hash' => password_hash('session-password', PASSWORD_DEFAULT),
]);

if (!adminAuthStartSession(false)) {
    throw new RuntimeException('Administrative session must start.');
}
$_SESSION['pre_authentication_value'] = 'must-not-survive';
$previousSessionId = session_id();
$admin = adminAuthLogin($pdo, 'session-admin@example.test', 'session-password');
if ($admin === null || session_id() === $previousSessionId) {
    throw new RuntimeException('Successful authentication must rotate the session ID.');
}
if (isset($_SESSION['pre_authentication_value']) || ($_SESSION['admin_user_id'] ?? null) !== $admin['id']) {
    throw new RuntimeException('Authentication must replace, not inherit, the pre-authentication session.');
}

$currentAdmin = adminAuthCurrentUser($pdo);
if ($currentAdmin === null || $currentAdmin['id'] !== $admin['id']) {
    throw new RuntimeException('A valid administrative session must resolve its active global user.');
}

$_SESSION['last_activity_at'] = time() - 1801;
if (adminAuthCurrentUser($pdo) !== null || session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION['admin_user_id'])) {
    throw new RuntimeException('An administrative session idle for more than 30 minutes must be destroyed.');
}
if (adminAuthLogin($pdo, 'session-admin@example.test', 'session-password') === null) {
    throw new RuntimeException('An active administrator must be able to start a new session after expiry.');
}
$admin = adminAuthCurrentUser($pdo);
if ($admin === null) {
    throw new RuntimeException('A renewed administrator session must resolve its active global user.');
}

$deactivate = $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = :id');
$deactivate->execute(['id' => $admin['id']]);
if (adminAuthCurrentUser($pdo) !== null) {
    throw new RuntimeException('A user deactivated after login must lose the administrative session on the next request.');
}

$cookie = session_get_cookie_params();
if ($cookie['httponly'] !== true || strtolower((string) $cookie['samesite']) !== 'lax') {
    throw new RuntimeException('Administrative session cookies must be HttpOnly and SameSite=Lax.');
}

adminAuthLogout();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
    throw new RuntimeException('Logout must destroy the active session.');
}

$_SERVER['HTTPS'] = 'on';
if (!adminAuthStartSession()) {
    throw new RuntimeException('Secure HTTPS session must start.');
}
if (session_get_cookie_params()['secure'] !== true) {
    throw new RuntimeException('Administrative session cookies must use Secure on HTTPS.');
}
adminAuthLogout();
unset($_SERVER['HTTPS']);

if (adminAuthSafeReturnPath('/admin/reports/monthly.php') !== '/admin/reports/monthly.php'
    || adminAuthSafeReturnPath('//external.example.test/path') !== '/admin/instalacao.php'
    || adminAuthSafeReturnPath('/admin/../login.php') !== '/admin/instalacao.php'
    || adminAuthSafeReturnPath('/admin/tool.php?next=https://external.example.test') !== '/admin/instalacao.php') {
    throw new RuntimeException('Administrative return paths must stay within a safe local admin route.');
}

fwrite(STDOUT, "PASS: admin login rotates a protected session and logout destroys it.\n");

return 'pass';
