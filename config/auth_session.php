<?php
declare(strict_types=1);

function authSessionCsrfToken(array &$session): string
{
    if (!isset($session['csrf_token']) || !is_string($session['csrf_token'])
        || !preg_match('/^[a-f0-9]{64}$/D', $session['csrf_token'])) {
        $session['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $session['csrf_token'];
}

function authSessionVerifyCsrf(array $session, mixed $submittedToken): bool
{
    $expectedToken = $session['csrf_token'] ?? null;
    if (!is_string($expectedToken) || !is_string($submittedToken)
        || !preg_match('/^[a-f0-9]{64}$/D', $expectedToken)
        || !preg_match('/^[a-f0-9]{64}$/D', $submittedToken)) {
        return false;
    }

    return hash_equals($expectedToken, $submittedToken);
}

function authSessionStart(string $name, ?bool $https = null): bool
{
    if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $name) !== 1) {
        return false;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return hash_equals($name, session_name());
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return false;
    }

    if (session_name() !== $name && session_id() !== '') {
        $requestedCookieId = $_COOKIE[$name] ?? null;
        if (!is_string($requestedCookieId) || !hash_equals($requestedCookieId, session_id())) {
            session_id('');
        }
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '1800');
    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https ?? authSessionRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return session_start();
}

function authSessionLogout(?string $expectedName = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE
        || ($expectedName !== null && !hash_equals($expectedName, session_name()))) {
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

function authSessionResetExpired(string $name): void
{
    authSessionLogout($name);
    if (authSessionStart($name)) {
        $_SESSION = [];
    }
}

function authSessionRequestIsHttps(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function authSessionAllowAttempt(string $clientIp, ?int $timestamp = null, ?string $storagePath = null): bool
{
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false || $storagePath === null || $storagePath === '') {
        return false;
    }

    $timestamp ??= time();
    $currentWindow = intdiv($timestamp, 60);
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
