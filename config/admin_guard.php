<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/admin_security_headers.php';
require_once __DIR__ . '/tenant_resolver.php';

function adminExigirHostDaPlataforma(): void
{
    if (tenantResolverIsPlatformHost((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        return;
    }

    http_response_code(404);
    exit('Recurso não encontrado.');
}

/** @return array{id: int, nome: string, email: string, papel_sistema: string} */
function adminExigirAcesso(): array
{
    adminAuthSendSecurityHeaders();
    adminExigirHostDaPlataforma();
    if (!adminAuthStartSession()) {
        http_response_code(500);
        exit('Não foi possível iniciar uma sessão segura.');
    }

    $admin = adminAuthCurrentUser(dbConnection());
    if ($admin !== null) {
        return $admin;
    }

    $requestedPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin/'), PHP_URL_PATH);
    $_SESSION['admin_return_to'] = adminAuthSafeReturnPath($requestedPath);
    header('Location: /admin/login.php', true, 303);
    exit;
}

/** @param array<string, mixed> $session */
function adminAuthConsumeReturnPath(array &$session): string
{
    $path = $session['admin_return_to'] ?? '/admin/migracao-banco.php';
    unset($session['admin_return_to']);

    return is_string($path) ? adminAuthSafeReturnPath($path) : '/admin/migracao-banco.php';
}

function adminAuthSafeReturnPath(string $path): string
{
    if (!str_starts_with($path, '/admin/') || str_starts_with($path, '//')
        || str_contains($path, "\r") || str_contains($path, "\n")
        || str_contains($path, '?') || str_contains($path, '#')
        || preg_match('~^/admin/[A-Za-z0-9_./-]+$~D', $path) !== 1
        || in_array('..', explode('/', $path), true)
        || in_array($path, ['/admin/login.php', '/admin/logout.php'], true)) {
        return '/admin/migracao-banco.php';
    }

    return $path;
}
