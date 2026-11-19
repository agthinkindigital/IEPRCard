<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/card_validation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    $respond(405, ['erro' => 'metodo_nao_permitido']);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ratePath = getenv('IEPRCARD_QR_RATE_LIMIT_PATH');
if ($ratePath === false || $ratePath === '') {
    $ratePath = dirname(__DIR__) . '/var/qr-rate-limit.json';
}
if (!authSessionAllowAttempt($clientIp, null, $ratePath)) {
    $respond(filter_var($clientIp, FILTER_VALIDATE_IP) === false ? 503 : 429, ['erro' => 'limite_de_leituras']);
}

$token = $_GET['codigo'] ?? '';
if (!is_string($token)) {
    $token = '';
}

try {
    $respond(200, cardValidationResolve(dbConnection(), $token));
} catch (Throwable) {
    $respond(503, ['erro' => 'validacao_indisponivel']);
}
