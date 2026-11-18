<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';

adminAuthSendSecurityHeaders();
if (!adminAuthStartSession()) {
    http_response_code(500);
    exit('Não foi possível iniciar uma sessão segura.');
}

function adminLoginEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (adminAuthCurrentUser(dbConnection()) !== null) {
    $destination = adminAuthConsumeReturnPath($_SESSION);
    header('Location: ' . $destination, true, 303);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$error = '';
if ($method === 'POST') {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!adminAuthAllowAttempt($clientIp)) {
        http_response_code(429);
        $error = 'Não foi possível concluir a autenticação. Tente novamente mais tarde.';
    } else {
        $csrfToken = $_POST['csrf_token'] ?? null;
        if (!adminAuthVerifyCsrf($_SESSION, $csrfToken)) {
            http_response_code(403);
            $error = 'Solicitação inválida. Atualize a página e tente novamente.';
        } else {
            $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $destination = adminAuthConsumeReturnPath($_SESSION);
            $admin = adminAuthLogin(dbConnection(), $email, $password);
            if ($admin !== null) {
                session_write_close();
                header('Location: ' . $destination, true, 303);
                exit;
            }

            http_response_code(401);
            $error = 'E-mail ou senha inválidos.';
        }
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$csrfToken = adminAuthCsrfToken($_SESSION);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · acesso administrativo</title>
    <link rel="stylesheet" href="/assets/admin-login.css">
    <script src="/assets/admin-login.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/admin/login.php" aria-label="IEPRCard — acesso administrativo">
        <span class="brand-mark" aria-hidden="true"><span></span><span></span></span>
        <span>IEPRCard</span>
    </a>
    <span class="environment-label">Ambiente administrativo</span>
</header>
<main class="login-main">
    <section class="login-card" aria-labelledby="login-title">
        <div class="lock-mark" aria-hidden="true"><span></span></div>
        <h1 id="login-title">Entrar na administração</h1>
        <p class="intro">Acesso restrito ao Administrador SaaS</p>
        <?php if ($error !== ''): ?>
            <p class="form-error" role="alert"><?= adminLoginEscape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="/admin/login.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= adminLoginEscape($csrfToken) ?>">
            <div class="field-group">
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required autofocus>
            </div>
            <div class="field-group">
                <label for="password">Senha</label>
                <div class="password-control">
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button class="password-toggle" type="button" aria-pressed="false" aria-controls="password">Mostrar</button>
                </div>
            </div>
            <button class="submit-button" type="submit">Entrar</button>
        </form>
        <div class="security-note">Acesso restrito e protegido contra abuso.</div>
    </section>
    <footer class="login-footer">
        <p>IEPRCard · Área restrita</p>
        <p>Se a sessão expirar, entre novamente para continuar.</p>
    </footer>
</main>
</body>
</html>
