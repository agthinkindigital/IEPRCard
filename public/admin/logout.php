<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_auth.php';
require_once dirname(__DIR__, 2) . '/config/admin_security_headers.php';

adminAuthSendSecurityHeaders();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método não permitido.');
}
if (!adminAuthStartSession()) {
    http_response_code(500);
    exit('Não foi possível iniciar uma sessão segura.');
}
if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Solicitação inválida.');
}

adminAuthLogout();
header('Location: /admin/login.php', true, 303);
exit;
