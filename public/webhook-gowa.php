<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/request_correlation.php';

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/auth_session.php';
require_once dirname(__DIR__) . '/config/whatsapp_gateway.php';
require_once dirname(__DIR__) . '/config/whatsapp_gateway_webhook.php';
$conversationHandler = dirname(__DIR__) . '/config/whatsapp_conversation.php';
require_once $conversationHandler;

function gowaWebhookRespond(int $status): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gowaWebhookRespond(405);
}
$environment = strtolower(getenv('APP_ENV') ?: 'production');
$secure = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!in_array($environment, ['dev', 'test'], true) && !$secure) {
    gowaWebhookRespond(400);
}
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
    gowaWebhookRespond(503);
}
$rateLimitPath = getenv('IEPRCARD_GOWA_RATE_LIMIT_PATH');
if (!is_string($rateLimitPath) || $rateLimitPath === '') {
    $rateLimitPath = dirname(__DIR__) . '/var/gowa-rate-limit.json';
}
if (!authSessionAllowAttempt($clientIp, null, $rateLimitPath, 1200)) {
    gowaWebhookRespond(429);
}
$token = $_GET['token'] ?? null;
if (!is_string($token)) {
    gowaWebhookRespond(401);
}
try {
    whatsappGatewayResolveWebhookInstance(dbConnection(), $token);
} catch (DomainException) {
    gowaWebhookRespond(401);
} catch (Throwable) {
    gowaWebhookRespond(503);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    gowaWebhookRespond(415);
}
$rawBody = file_get_contents('php://input', false, null, 0, 2_097_153);
if (!is_string($rawBody) || strlen($rawBody) > 2_097_152) {
    gowaWebhookRespond(413);
}
$payload = json_decode($rawBody, true, 32);
if (!is_array($payload) || array_is_list($payload)) {
    gowaWebhookRespond(400);
}
if (!function_exists('whatsappConversationHandleIncoming')) {
    // Keep the gateway retrying until the consent-aware conversation slice is installed.
    gowaWebhookRespond(503);
}

try {
    whatsappGatewayProcessWebhook(
        dbConnection(),
        $token,
        $payload,
        static function (array $instance, array $message): void {
            whatsappConversationHandleIncoming(dbConnection(), $instance, $message);
        }
    );
    gowaWebhookRespond(204);
} catch (DomainException $exception) {
    gowaWebhookRespond($exception->getMessage() === 'WHATSAPP_WEBHOOK_RATE_LIMITED' ? 429 : 400);
} catch (Throwable) {
    gowaWebhookRespond(503);
}
