<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_invites.php';

adminAuthSendSecurityHeaders();
header('X-Robots-Tag: noindex, nofollow');
adminExigirHostDaPlataforma();
if (!adminAuthStartSession()) {
    http_response_code(500);
    exit('Não foi possível iniciar uma sessão segura.');
}
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : (is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
$tokenValidFormat = preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
$pdo = dbConnection();
$error = '';
$complete = false;

function adminInviteEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!$tokenValidFormat) {
    http_response_code(404);
    $error = 'Este convite não está disponível. Verifique o link ou solicite outro convite ao administrador.';
} elseif (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    if (!adminAuthAllowAttempt((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        http_response_code(429);
        $error = 'Não foi possível concluir o convite agora. Aguarde e tente novamente.';
    } elseif (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Solicitação inválida. Atualize a página e tente novamente.';
    } else {
        $password = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $passwordConfirm = is_string($_POST['senha_confirmacao'] ?? null) ? $_POST['senha_confirmacao'] : '';
        if (!hash_equals($password, $passwordConfirm)) {
            http_response_code(422);
            $error = 'As senhas não correspondem.';
        } else {
            try {
                adminTenantInviteComplete($pdo, $token, is_string($_POST['email'] ?? null) ? $_POST['email'] : '', [
                    'nome_organizacao' => $_POST['nome_organizacao'] ?? null,
                    'slug_subdominio' => $_POST['slug_subdominio'] ?? null,
                    'nome_titular' => $_POST['nome_titular'] ?? null,
                    'senha_titular' => $password,
                ]);
                session_write_close();
                $complete = true;
            } catch (DomainException $exception) {
                http_response_code(422);
                $error = $exception->getMessage() === 'TENANT_SLUG_CONFLICT' || $exception->getMessage() === 'TENANT_PROVISION_RESERVED_SLUG'
                    ? 'Esse identificador não está disponível. Escolha outro.'
                    : 'Não foi possível concluir o convite. Confira os dados; se o link expirou ou foi usado, solicite outro.';
            } catch (Throwable) {
                http_response_code(500);
                $error = 'Não foi possível concluir o cadastro agora. Tente novamente mais tarde.';
            }
        }
    }
}
$csrfToken = adminAuthCsrfToken($_SESSION);
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Concluir cadastro da Sede · IEPRCard</title><link rel="stylesheet" href="/assets/admin-login.css"><link rel="stylesheet" href="/assets/admin-tenants.css"></head>
<body><main class="login-main"><section class="login-card" aria-labelledby="invite-title">
<a class="brand" href="/" aria-label="IEPRCard"><span class="brand-mark" aria-hidden="true">I</span><span>IEPRCard</span></a>
<?php if ($complete): ?><h1 id="invite-title">Cadastro recebido</h1><p>A Sede e a conta responsável foram criadas como pendentes. O acesso será liberado após a ativação manual pelo superadmin.</p>
<?php else: ?><h1 id="invite-title">Concluir cadastro da Sede</h1><p>Preencha os dados para vincular a conta ao convite. A Sede ficará pendente até a ativação manual.</p>
<?php if ($error !== ''): ?><p class="notice" role="alert"><?= adminInviteEscape($error) ?></p><?php endif; ?>
<?php if ($tokenValidFormat): ?><form method="post" action="/admin/concluir-sede.php?token=<?= rawurlencode($token) ?>" class="tenant-form"><input type="hidden" name="csrf_token" value="<?= adminInviteEscape($csrfToken) ?>"><input type="hidden" name="token" value="<?= adminInviteEscape($token) ?>"><label>E-mail vinculado ao convite<input name="email" type="email" maxlength="254" autocomplete="email" required></label><label>Nome da organização<input name="nome_organizacao" maxlength="180" required></label><label>Identificador da Sede<input name="slug_subdominio" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" maxlength="63" required></label><label>Nome do responsável<input name="nome_titular" maxlength="180" autocomplete="name" required></label><label>Senha (mínimo 12 caracteres)<input name="senha" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label><label>Confirme a senha<input name="senha_confirmacao" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label><button class="primary-button" type="submit">Criar Sede pendente</button></form><?php endif; ?>
<?php endif; ?></section></main></body></html>
