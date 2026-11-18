<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_session.php';

/** @return array{id: int, nome: string, email: string, papel_sistema: string}|null */
function adminAuthAuthenticate(PDO $pdo, string $email, string $password): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $validEmail = filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false
        && strlen($normalizedEmail) <= 254;

    $statement = $pdo->prepare(
        'SELECT id, nome, email, senha_hash, papel_sistema, tenant_id, ativo '
        . 'FROM usuarios WHERE email = :email AND tenant_id IS NULL ORDER BY id LIMIT 2'
    );
    $statement->execute(['email' => $validEmail ? $normalizedEmail : '']);
    $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
    $user = count($matches) === 1 ? $matches[0] : null;

    static $dummyHash = null;
    $dummyHash ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $hash = $user === null ? $dummyHash : (string) $user['senha_hash'];
    $passwordMatches = password_verify($password, $hash);

    if (!$validEmail || $user === null || !$passwordMatches
        || (int) $user['ativo'] !== 1
        || $user['papel_sistema'] !== 'superadmin'
        || $user['tenant_id'] !== null) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'nome' => (string) $user['nome'],
        'email' => (string) $user['email'],
        'papel_sistema' => (string) $user['papel_sistema'],
    ];
}

/** @param array<string, mixed> $session */
function adminAuthCsrfToken(array &$session): string
{
    return authSessionCsrfToken($session);
}

/** @param array<string, mixed> $session */
function adminAuthVerifyCsrf(array $session, mixed $submittedToken): bool
{
    return authSessionVerifyCsrf($session, $submittedToken);
}

function adminAuthStartSession(?bool $https = null): bool
{
    return authSessionStart('IEPRCARDADMIN', $https);
}

/** @return array{id: int, nome: string, email: string, papel_sistema: string}|null */
function adminAuthLogin(PDO $pdo, string $email, string $password): ?array
{
    if (!adminAuthStartSession()) {
        return null;
    }

    $admin = adminAuthAuthenticate($pdo, $email, $password);
    if ($admin === null) {
        return null;
    }

    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        return null;
    }

    $_SESSION['admin_user_id'] = $admin['id'];
    adminAuthCsrfToken($_SESSION);
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity_at'] = time();

    return $admin;
}

/** @return array{id: int, nome: string, email: string, papel_sistema: string}|null */
function adminAuthCurrentUser(PDO $pdo): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== 'IEPRCARDADMIN') {
        return null;
    }
    if (!array_key_exists('admin_user_id', $_SESSION)) {
        return null;
    }
    $userId = filter_var($_SESSION['admin_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lastActivity = filter_var($_SESSION['last_activity_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false || $lastActivity === false || time() - $lastActivity > 1800) {
        adminAuthResetExpiredSession();
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, nome, email, papel_sistema FROM usuarios '
        . 'WHERE id = :id AND tenant_id IS NULL AND papel_sistema = :role AND ativo = 1'
    );
    $statement->execute(['id' => $userId, 'role' => 'superadmin']);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        adminAuthResetExpiredSession();
        return null;
    }
    $_SESSION['last_activity_at'] = time();

    return [
        'id' => (int) $user['id'],
        'nome' => (string) $user['nome'],
        'email' => (string) $user['email'],
        'papel_sistema' => (string) $user['papel_sistema'],
    ];
}

function adminAuthLogout(): void
{
    authSessionLogout('IEPRCARDADMIN');
}

function adminAuthResetExpiredSession(): void
{
    authSessionResetExpired('IEPRCARDADMIN');
}

function adminAuthRequestIsHttps(): bool
{
    return authSessionRequestIsHttps();
}

function adminAuthAllowAttempt(string $clientIp, ?int $timestamp = null, ?string $storagePath = null): bool
{
    $storagePath ??= (string) (getenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH') ?: dirname(__DIR__) . '/var/admin-login-rate-limit.json');
    return authSessionAllowAttempt($clientIp, $timestamp, $storagePath);
}
