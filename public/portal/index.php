<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'");
if (authSessionRequestIsHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function portalPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portalPageRedirectToSelf(): never
{
    header('Location: /portal/', true, 303);
    exit;
}

function portalPageFormatDate(?string $date): string
{
    if ($date === null) return 'Sem data de validade';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date) return 'Validade indisponível';

    return 'Válida até ' . $parsed->format('d/m/Y');
}

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (!portalAuthStartSession()) {
    http_response_code(503);
    exit('Portal indisponível. Tente novamente mais tarde.');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$error = '';
$notice = '';
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

try {
    $pdo = dbConnection();
    $context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
} catch (Throwable) {
    $pdo = null;
    $context = null;
    http_response_code(503);
}

if (array_key_exists('token', $_GET)) {
    $requestedToken = is_string($_GET['token']) ? $_GET['token'] : '';
    if ($pdo instanceof PDO && $context instanceof TenantContext) {
        if (portalAuthCurrentHolder($pdo, $context) === null) {
            $csrfToken = portalAuthCsrfToken($_SESSION);
            $identity = portalAuthLogin($pdo, $context, '', '', $requestedToken, $csrfToken, $clientIp);
            if ($identity === null) $_SESSION['portal_flash_error'] = 'Não foi possível validar. Revise os dados ou abra seu link individual.';
        }
    } else {
        $_SESSION['portal_flash_error'] = 'Não foi possível validar. Revise os dados ou abra seu link individual.';
    }
    portalPageRedirectToSelf();
}

if ($method === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!portalAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $error = 'Solicitação inválida. Atualize a página e tente novamente.';
    } elseif ($action === 'logout') {
        portalAuthLogout();
        portalPageRedirectToSelf();
    } elseif ($action === 'login') {
        $cpf = is_string($_POST['cpf'] ?? null) ? $_POST['cpf'] : '';
        $birthDate = is_string($_POST['data_nascimento'] ?? null) ? $_POST['data_nascimento'] : '';
        $identity = $pdo instanceof PDO && $context instanceof TenantContext
            ? portalAuthLogin($pdo, $context, $cpf, $birthDate, '', $_POST['csrf_token'] ?? null, $clientIp)
            : null;
        if ($identity !== null) portalPageRedirectToSelf();
        http_response_code(401);
        $error = 'Não foi possível validar. Revise os dados ou abra seu link individual.';
    } elseif ($action === 'select_card') {
        $cards = $pdo instanceof PDO && $context instanceof TenantContext ? portalAuthCards($pdo, $context) : [];
        $requestedCardId = filter_var($_POST['carteirinha_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $selectedCard = null;
        foreach ($cards as $card) {
            if ($requestedCardId !== false && $card['id'] === (int) $requestedCardId) {
                $selectedCard = $card;
                break;
            }
        }
        if ($selectedCard === null) {
            http_response_code(404);
            $error = 'Não foi possível abrir esta carteirinha. Atualize a página e tente novamente.';
        } else {
            $_SESSION['portal_selected_card_id'] = $selectedCard['id'];
            portalPageRedirectToSelf();
        }
    } else {
        http_response_code(400);
        $error = 'Solicitação inválida. Atualize a página e tente novamente.';
    }
}

if (isset($_SESSION['portal_flash_error']) && is_string($_SESSION['portal_flash_error'])) {
    $error = $_SESSION['portal_flash_error'];
    unset($_SESSION['portal_flash_error']);
}

$cards = $pdo instanceof PDO && $context instanceof TenantContext ? portalAuthCards($pdo, $context) : [];
$currentHolder = $pdo instanceof PDO && $context instanceof TenantContext ? portalAuthCurrentHolder($pdo, $context) : null;
$csrfToken = portalAuthCsrfToken($_SESSION);
$selectedCard = null;
if ($cards !== []) {
    $selectedId = filter_var($_SESSION['portal_selected_card_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    foreach ($cards as $card) {
        if ($selectedId !== false && $card['id'] === (int) $selectedId) {
            $selectedCard = $card;
            break;
        }
    }
    if ($selectedCard === null) {
        $selectedCard = $cards[0];
        $_SESSION['portal_selected_card_id'] = $selectedCard['id'];
    }
}

$side = is_string($_GET['lado'] ?? null) && in_array($_GET['lado'], ['frente', 'verso'], true) ? $_GET['lado'] : 'frente';
$pageTitle = $selectedCard !== null ? 'Sua carteirinha' : ($currentHolder !== null ? 'Carteirinha indisponível' : 'Acesse sua carteirinha');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · <?= portalPageEscape($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/portal-titular.css">
    <?php if ($selectedCard !== null): ?><script src="/assets/portal-titular.js" defer></script><?php endif; ?>
</head>
<body>
<header class="site-header">
    <a class="brand" href="/portal/" aria-label="IEPRCard, portal do titular"><span class="brand-mark" aria-hidden="true">+</span><span>IEPRCard</span></a>
    <?php if ($currentHolder !== null): ?>
        <form method="post" action="/portal/" class="logout-form">
            <input type="hidden" name="csrf_token" value="<?= portalPageEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <?php if ($selectedCard !== null): ?><span><?= portalPageEscape($selectedCard['nome_completo']) ?></span><?php endif; ?>
            <button type="submit" class="quiet-button">Sair</button>
        </form>
    <?php else: ?><span class="header-note">Portal seguro da organização</span><?php endif; ?>
</header>
<main class="page-shell">
    <?php if ($selectedCard === null && $currentHolder === null): ?>
        <section class="login-card" aria-labelledby="page-title">
            <p class="eyebrow">ACESSO PRIVADO</p>
            <h1 id="page-title">Acesse sua carteirinha</h1>
            <p class="intro">Entre com seus dados ou use o link individual enviado pela organização.</p>
            <?php if ($error !== ''): ?><p class="form-error" role="alert" aria-live="assertive"><?= portalPageEscape($error) ?></p><?php endif; ?>
            <form method="post" action="/portal/" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= portalPageEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="login">
                <label for="cpf">CPF</label>
                <input id="cpf" name="cpf" type="text" inputmode="numeric" autocomplete="username" maxlength="14" required>
                <label for="data_nascimento">Data de nascimento</label>
                <input id="data_nascimento" name="data_nascimento" type="date" autocomplete="bday" required>
                <button type="submit" class="primary-button">Acessar carteirinha</button>
            </form>
        </section>
    <?php elseif ($selectedCard === null): ?>
        <section class="login-card empty-card-state" aria-labelledby="page-title">
            <p class="eyebrow">ÁREA AUTENTICADA</p>
            <h1 id="page-title">Carteirinha indisponível</h1>
            <p class="intro">Não foi possível abrir uma carteirinha ativa agora. Procure a organização emissora para receber ajuda.</p>
        </section>
    <?php else: ?>
        <div class="portal-heading">
            <p class="eyebrow">ÁREA AUTENTICADA</p>
            <h1 id="page-title">Sua carteirinha</h1>
            <p class="issuer-line"><?= portalPageEscape($selectedCard['organizacao']) ?><?= $selectedCard['unidade'] !== null ? ' · ' . portalPageEscape($selectedCard['unidade']) : '' ?></p>
            <?php if ($selectedCard['unidade'] !== null): ?><p class="scope-note">Vinculada à Sede · somente você pode consultar esta área</p><?php endif; ?>
        </div>
        <?php if ($error !== ''): ?><p class="form-error" role="alert" aria-live="assertive"><?= portalPageEscape($error) ?></p><?php endif; ?>
        <?php if (count($cards) > 1): ?>
            <form method="post" action="/portal/" class="card-picker">
                <input type="hidden" name="csrf_token" value="<?= portalPageEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="select_card">
                <label for="carteirinha_id">Selecione a carteirinha</label>
                <div class="picker-row">
                    <select id="carteirinha_id" name="carteirinha_id">
                        <?php foreach ($cards as $card): ?>
                            <option value="<?= (int) $card['id'] ?>" <?= $card['id'] === $selectedCard['id'] ? 'selected' : '' ?>>Carteirinha de <?= portalPageEscape($card['cargo_funcao'] ?: 'membro') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="secondary-button">Abrir</button>
                </div>
            </form>
        <?php endif; ?>
        <section class="card-layout" aria-labelledby="card-title">
            <div class="digital-card-panel">
                <h2 id="card-title">Carteirinha digital <span class="active-badge">Ativa</span></h2>
                <div class="card-image-frame" data-card-image>
                    <?php $assetKey = $side === 'frente' ? 'webp_frente_url' : 'webp_verso_url'; ?>
                    <?php if (is_string($selectedCard[$assetKey]) && $selectedCard[$assetKey] !== ''): ?>
                        <img src="/portal/carteirinha/<?= (int) $selectedCard['id'] ?>/<?= portalPageEscape($side) ?>" alt="<?= $side === 'frente' ? 'Frente' : 'Verso' ?> da carteirinha de <?= portalPageEscape($selectedCard['nome_completo']) ?>" data-card-artifact>
                    <?php else: ?>
                        <p class="image-message" role="status">A imagem da carteirinha ainda não está disponível.</p>
                    <?php endif; ?>
                </div>
                <p class="card-live-message" data-card-status role="status" aria-live="polite">Carregando a <?= $side === 'frente' ? 'frente' : 'verso' ?> da carteirinha.</p>
                <nav class="side-controls" aria-label="Lado da carteirinha">
                    <a href="/portal/?lado=frente" <?= $side === 'frente' ? 'aria-current="page"' : '' ?>>‹ Frente</a>
                    <a href="/portal/?lado=verso" <?= $side === 'verso' ? 'aria-current="page"' : '' ?>>Verso ›</a>
                </nav>
            </div>
            <aside class="card-details" aria-label="Dados da carteirinha">
                <p class="eyebrow">TITULAR</p>
                <h2><?= portalPageEscape($selectedCard['nome_completo']) ?></h2>
                <?php if ($selectedCard['cargo_funcao'] !== null && $selectedCard['cargo_funcao'] !== ''): ?><p class="holder-role"><?= portalPageEscape($selectedCard['cargo_funcao']) ?></p><?php endif; ?>
                <p class="validity"><?= portalPageEscape(portalPageFormatDate($selectedCard['data_validade'])) ?></p>
                <div class="authenticity-note">
                    <strong>QR Code pronto para validação</strong>
                    <span>Aponte a câmera para conferir a situação.</span>
                </div>
                <a class="verify-link" href="/validar/">Sobre a validação pública</a>
            </aside>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
