<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/card_validation.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'none'; style-src 'self'; img-src 'self'");
if (authSessionRequestIsHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function cardValidationPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$states = [
    'ativa' => [
        'title' => 'Carteirinha ativa',
        'description' => 'A situação registrada para esta carteirinha é ativa.',
        'class' => 'status-active',
        'symbol' => '✓',
    ],
    'suspensa' => [
        'title' => 'Carteirinha suspensa',
        'description' => 'Esta carteirinha não está ativa. Fale com a organização emissora.',
        'class' => 'status-suspended',
        'symbol' => '!',
    ],
    'vencida' => [
        'title' => 'Carteirinha vencida',
        'description' => 'Esta carteirinha não está ativa. Fale com a organização emissora.',
        'class' => 'status-expired',
        'symbol' => '!',
    ],
    'revogada' => [
        'title' => 'Carteirinha revogada',
        'description' => 'Esta carteirinha não está ativa. Fale com a organização emissora.',
        'class' => 'status-revoked',
        'symbol' => '!',
    ],
    'invalida' => [
        'title' => 'Código inválido',
        'description' => 'Não foi possível validar este código. Confira o código ou aponte novamente a câmera.',
        'class' => 'status-invalid',
        'symbol' => '?',
    ],
    'rate_limited' => [
        'title' => 'Muitas consultas',
        'description' => 'Aguarde um pouco antes de consultar outra carteirinha.',
        'class' => 'status-unavailable',
        'symbol' => '!',
    ],
    'unavailable' => [
        'title' => 'Validação indisponível',
        'description' => 'Não foi possível concluir a consulta agora. Tente novamente mais tarde.',
        'class' => 'status-unavailable',
        'symbol' => '!',
    ],
];

$httpStatus = 200;
$viewState = 'invalida';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    header('Allow: GET');
    $httpStatus = 405;
    $viewState = 'unavailable';
} else {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ratePath = getenv('IEPRCARD_QR_RATE_LIMIT_PATH');
    if ($ratePath === false || $ratePath === '') {
        $ratePath = dirname(__DIR__) . '/var/qr-rate-limit.json';
    }

    if (!authSessionAllowAttempt($clientIp, null, $ratePath)) {
        $httpStatus = filter_var($clientIp, FILTER_VALIDATE_IP) === false ? 503 : 429;
        $viewState = $httpStatus === 429 ? 'rate_limited' : 'unavailable';
    } else {
        $token = $_GET['codigo'] ?? '';
        try {
            $result = cardValidationResolve(dbConnection(), is_string($token) ? $token : '');
            $candidateState = $result['status'] ?? 'invalida';
            $viewState = isset($states[$candidateState]) ? $candidateState : 'invalida';
        } catch (Throwable) {
            $httpStatus = 503;
            $viewState = 'unavailable';
        }
    }
}

http_response_code($httpStatus);
$state = $states[$viewState];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= cardValidationPageEscape($state['title']) ?> · IEPRCard</title>
    <link rel="stylesheet" href="/assets/qr-validation.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="IEPRCard, início">IEPRCard</a>
        <span class="header-note">Verificação pública</span>
    </header>
    <main class="page-shell">
        <section class="verification-card" aria-labelledby="status-title">
            <p class="eyebrow">VALIDAÇÃO DE CARTEIRINHA</p>
            <div class="status-mark <?= cardValidationPageEscape($state['class']) ?>" aria-hidden="true">
                <?= cardValidationPageEscape($state['symbol']) ?>
            </div>
            <div class="status-copy" role="status" aria-live="polite">
                <h1 id="status-title"><?= cardValidationPageEscape($state['title']) ?></h1>
                <p><?= cardValidationPageEscape($state['description']) ?></p>
            </div>
            <p class="privacy-note">A consulta pública mostra somente a situação da carteirinha. Não exibe nome, foto, CPF, unidade ou validade.</p>
            <form class="another-code" action="/validar/" method="get">
                <label for="codigo">Validar outra carteirinha</label>
                <div class="form-row">
                    <input id="codigo" name="codigo" type="text" inputmode="text" maxlength="128" autocomplete="off" spellcheck="false">
                    <button type="submit">Consultar</button>
                </div>
            </form>
        </section>
        <p class="footer-note">Se precisar de ajuda, procure a organização emissora.</p>
    </main>
</body>
</html>
