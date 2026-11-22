<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/whatsapp_gateway.php';
require_once dirname(__DIR__) . '/config/whatsapp_gateway_webhook.php';

function gowaValidateRespond(int $status): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    gowaValidateRespond(405);
}
$environment = strtolower(getenv('APP_ENV') ?: 'production');
$secure = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!in_array($environment, ['dev', 'test'], true) && !$secure) {
    gowaValidateRespond(400);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    gowaValidateRespond(415);
}
$rawBody = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($rawBody) || strlen($rawBody) > 65536) {
    gowaValidateRespond(413);
}
$payload = json_decode($rawBody, true, 16);
if (!is_array($payload) || array_is_list($payload)) {
    gowaValidateRespond(400);
}
try {
    whatsappGatewayValidateCronPing(dbConnection(), $payload);
    gowaValidateRespond(200);
} catch (DomainException $exception) {
    gowaValidateRespond($exception->getMessage() === 'WHATSAPP_GOWA_PING_UNAUTHORIZED' ? 401 : 400);
} catch (Throwable) {
    gowaValidateRespond(503);
}
