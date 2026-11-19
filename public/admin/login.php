<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/admin_bootstrap.php';

adminAuthSendSecurityHeaders();
header('X-Robots-Tag: noindex, nofollow');
adminExigirHostDaPlataforma();
if (!adminAuthStartSession()) {
    http_response_code(500);
    exit('Não foi possível iniciar uma sessão segura.');
}

function adminLoginEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminLoginSetupTokenIsValid(array $session, mixed $submittedToken): bool
{
    $sessionToken = $session['admin_setup_token'] ?? null;
    $issuedAt = filter_var($session['admin_setup_token_issued_at'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($submittedToken) || preg_match('/^[a-f0-9]{64}$/D', $submittedToken) !== 1
        || !is_string($sessionToken) || preg_match('/^[a-f0-9]{64}$/D', $sessionToken) !== 1
        || $issuedAt === false || $issuedAt < time() - 600 || $issuedAt > time()) {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function adminLoginRedirectToCleanUrl(): never
{
    header('Location: /admin/login.php', true, 303);
    exit;
}

$pdo = dbConnection();
if (adminAuthCurrentUser($pdo) !== null) {
    $destination = adminAuthConsumeReturnPath($_SESSION);
    header('Location: ' . $destination, true, 303);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$hasUsers = adminBootstrapHasUsers($pdo);
$setupMode = false;
$setupToken = null;
if ($hasUsers) {
    unset($_SESSION['admin_setup_token'], $_SESSION['admin_setup_token_issued_at']);
    if (array_key_exists('token', $_GET) || array_key_exists('setup_token', $_POST)) {
        adminLoginRedirectToCleanUrl();
    }
} elseif ($method === 'GET') {
    $requestedToken = $_GET['token'] ?? null;
    if (adminLoginSetupTokenIsValid($_SESSION, $requestedToken)) {
        $setupMode = true;
        $setupToken = $requestedToken;
    } else {
        $setupToken = bin2hex(random_bytes(32));
        $_SESSION['admin_setup_token'] = $setupToken;
        $_SESSION['admin_setup_token_issued_at'] = time();
        header('Location: /admin/login.php?token=' . rawurlencode($setupToken), true, 303);
        exit;
    }
} else {
    $requestedToken = $_GET['token'] ?? null;
    if (!adminLoginSetupTokenIsValid($_SESSION, $requestedToken)
        || !is_string($_POST['setup_token'] ?? null)
        || !hash_equals((string) $requestedToken, (string) $_POST['setup_token'])) {
        unset($_SESSION['admin_setup_token'], $_SESSION['admin_setup_token_issued_at']);
        adminLoginRedirectToCleanUrl();
    }
    $setupMode = true;
    $setupToken = $requestedToken;
}

$error = '';
$setupName = '';
$setupEmail = '';
if ($method === 'POST') {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!adminAuthAllowAttempt($clientIp)) {
        http_response_code(429);
        $error = 'Não foi possível concluir a solicitação. Tente novamente mais tarde.';
    } elseif (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Solicitação inválida. Atualize a página e tente novamente.';
    } elseif ($setupMode) {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $setupName = $name;
        $setupEmail = $email;
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        $nameLength = preg_match_all('/./us', $name);
        $passwordLength = preg_match_all('/./us', $password);

        if ($password !== $passwordConfirm) {
            http_response_code(422);
            $error = 'As senhas não correspondem.';
        } elseif ($passwordLength === false || $passwordLength < 12 || strlen($password) > 1024) {
            http_response_code(422);
            $error = 'A senha deve ter pelo menos 12 caracteres e no máximo 1024 bytes.';
        } elseif ($name === '' || $nameLength === false || $nameLength > 180
            || filter_var(strtolower($email), FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            http_response_code(422);
            $error = 'Informe um nome com até 180 caracteres e um e-mail válido.';
        } elseif (!session_regenerate_id(true)) {
            http_response_code(500);
            $error = 'Não foi possível concluir a configuração inicial.';
        } else {
            try {
                adminAuthProvisionInitialSuperadmin($pdo, $name, $email, $password);
                $_SESSION = [];
                unset($setupToken);
                session_write_close();
                adminLoginRedirectToCleanUrl();
            } catch (RuntimeException $exception) {
                if (adminBootstrapHasUsers($pdo)) {
                    unset($_SESSION['admin_setup_token'], $_SESSION['admin_setup_token_issued_at']);
                    adminLoginRedirectToCleanUrl();
                }
                http_response_code(500);
                $error = 'Não foi possível concluir a configuração inicial.';
            } catch (Throwable $exception) {
                http_response_code(500);
                $error = 'Não foi possível concluir a configuração inicial.';
            }
        }
    } else {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $destination = adminAuthConsumeReturnPath($_SESSION);
        $admin = adminAuthLogin($pdo, $email, $password);
        if ($admin !== null) {
            session_write_close();
            header('Location: ' . $destination, true, 303);
            exit;
        }

        http_response_code(401);
        $error = 'E-mail ou senha inválidos.';
    }
}

$csrfToken = adminAuthCsrfToken($_SESSION);
$loginTitle = $setupMode ? 'Configurar administrador' : 'Entrar na administração';
$intro = $setupMode
    ? 'Este será o primeiro usuário global do sistema.'
    : 'Acesso restrito ao Administrador SaaS';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · <?= adminLoginEscape($setupMode ? 'configuração inicial' : 'acesso administrativo') ?></title>
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
        <h1 id="login-title"><?= adminLoginEscape($loginTitle) ?></h1>
        <p class="intro"><?= adminLoginEscape($intro) ?></p>
        <?php if ($error !== ''): ?>
            <p class="form-error" role="alert"><?= adminLoginEscape($error) ?></p>
        <?php endif; ?>
        <?php if ($setupMode): ?>
            <form method="post" action="/admin/login.php?token=<?= rawurlencode((string) $setupToken) ?>" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= adminLoginEscape($csrfToken) ?>">
                <input type="hidden" name="setup_token" value="<?= adminLoginEscape((string) $setupToken) ?>">
                <div class="field-group">
                    <label for="name">Nome</label>
                    <input id="name" name="name" type="text" autocomplete="name" maxlength="180" value="<?= adminLoginEscape($setupName) ?>" required autofocus>
                </div>
                <div class="field-group">
                    <label for="email">E-mail</label>
                    <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= adminLoginEscape($setupEmail) ?>" required>
                </div>
                <div class="field-group">
                    <label for="password">Senha</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required>
                </div>
                <div class="field-group">
                    <label for="password-confirm">Confirmar senha</label>
                    <input id="password-confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required>
                </div>
                <button class="submit-button" type="submit">Criar administrador</button>
            </form>
            <div class="security-note">Disponível somente enquanto não houver usuários.</div>
        <?php else: ?>
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
        <?php endif; ?>
    </section>
    <footer class="login-footer">
        <p>IEPRCard · Área restrita</p>
        <p><?= $setupMode ? 'Depois da configuração, entre com as credenciais criadas.' : 'Se a sessão expirar, entre novamente para continuar.' ?></p>
    </footer>
</main>
</body>
</html>
