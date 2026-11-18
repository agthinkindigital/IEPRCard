<?php
declare(strict_types=1);

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
    if (!isset($session['csrf_token']) || !is_string($session['csrf_token'])
        || !preg_match('/^[a-f0-9]{64}$/D', $session['csrf_token'])) {
        $session['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $session['csrf_token'];
}

/** @param array<string, mixed> $session */
function adminAuthVerifyCsrf(array $session, mixed $submittedToken): bool
{
    $expectedToken = $session['csrf_token'] ?? null;
    if (!is_string($expectedToken) || !is_string($submittedToken)
        || !preg_match('/^[a-f0-9]{64}$/D', $expectedToken)
        || !preg_match('/^[a-f0-9]{64}$/D', $submittedToken)) {
        return false;
    }

    return hash_equals($expectedToken, $submittedToken);
}

function adminAuthStartSession(?bool $https = null): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return false;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '1800');
    session_name('IEPRCARDADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https ?? adminAuthRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return session_start();
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
    if (session_status() !== PHP_SESSION_ACTIVE) {
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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function adminAuthResetExpiredSession(): void
{
    adminAuthLogout();
    if (adminAuthStartSession()) {
        $_SESSION = [];
    }
}

function adminAuthRequestIsHttps(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function adminAuthAllowAttempt(string $clientIp, ?int $timestamp = null, ?string $storagePath = null): bool
{
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $timestamp ??= time();
    $currentWindow = intdiv($timestamp, 60);
    $storagePath ??= (string) (getenv('IEPRCARD_ADMIN_RATE_LIMIT_PATH') ?: dirname(__DIR__) . '/var/admin-login-rate-limit.json');
    $directory = dirname($storagePath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }

    @chmod($directory, 0700);
    $handle = @fopen($storagePath, 'c+');
    if ($handle === false) {
        return false;
    }
    @chmod($storagePath, 0600);

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        $entries = [];
        if (is_string($contents) && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                return false;
            }
            foreach ($decoded as $key => $entry) {
                if (is_string($key) && is_array($entry)
                    && isset($entry['window'], $entry['count'])
                    && (int) $entry['window'] === $currentWindow
                    && (int) $entry['count'] > 0) {
                    $entries[$key] = ['window' => $currentWindow, 'count' => (int) $entry['count']];
                }
            }
        }

        $key = hash('sha256', $clientIp);
        $count = $entries[$key]['count'] ?? 0;
        $allowed = $count < 60;
        if ($allowed) {
            $entries[$key] = ['window' => $currentWindow, 'count' => $count + 1];
        }

        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            return false;
        }

        return $allowed;
    } catch (Throwable) {
        return false;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
