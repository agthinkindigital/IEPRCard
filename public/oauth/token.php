<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';

function mcpOauthTokenResponse(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcpOauthTokenResponse(405, ['error' => 'invalid_request'], ['Allow' => 'POST']);
}
$appEnvironment = strtolower(getenv('APP_ENV') ?: 'production');
$secureRequest = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!in_array($appEnvironment, ['dev', 'test', 'staging'], true) && !$secureRequest) {
    mcpOauthTokenResponse(400, ['error' => 'invalid_request']);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/x-www-form-urlencoded') {
    mcpOauthTokenResponse(400, ['error' => 'invalid_request']);
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 8192 || mcpOauthHasDuplicateParameters($rawBody)) {
    mcpOauthTokenResponse(400, ['error' => 'invalid_request']);
}
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
$credentials = mcpOauthReadBasicCredentials(is_string($authorization) ? $authorization : null);
if ($credentials === null) {
    mcpOauthTokenResponse(401, ['error' => 'invalid_client'], ['WWW-Authenticate' => 'Basic realm="oauth"']);
}
$input = $_POST;
$input['_basic_credentials'] = $credentials;
try {
    $token = mcpOauthIssueClientCredentialsToken(dbConnection(), $input);
    mcpOauthTokenResponse(200, $token);
} catch (DomainException $exception) {
    $error = match ($exception->getMessage()) {
        'MCP_OAUTH_INVALID_CLIENT' => 'invalid_client',
        'MCP_OAUTH_INVALID_SCOPE' => 'invalid_scope',
        'MCP_OAUTH_RATE_LIMITED' => 'temporarily_unavailable',
        'MCP_OAUTH_INVALID_TARGET' => 'invalid_target',
        default => 'invalid_request',
    };
    $status = $error === 'invalid_client' ? 401 : ($error === 'slow_down' ? 429 : 400);
    $headers = $status === 401 ? ['WWW-Authenticate' => 'Basic realm="oauth"'] : [];
    if ($exception->getMessage() === 'MCP_OAUTH_RATE_LIMITED') {
        $headers['Retry-After'] = '60';
    }
    mcpOauthTokenResponse($status, ['error' => $error], $headers);
} catch (Throwable) {
    mcpOauthTokenResponse(503, ['error' => 'temporarily_unavailable']);
}
