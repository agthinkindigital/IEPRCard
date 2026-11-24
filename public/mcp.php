<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/request_correlation.php';

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/mcp_server.php';

function mcpHttpRespond(int $status, ?array $response = null, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($response !== null) {
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function mcpHttpAcceptsStreamableJson(string $header): bool
{
    $accepted = [];
    foreach (explode(',', $header) as $item) {
        $parts = array_map('trim', explode(';', $item));
        $type = strtolower(array_shift($parts) ?? '');
        $quality = 1.0;
        foreach ($parts as $parameter) {
            if (preg_match('/^q=([0-9.]+)$/iD', $parameter, $matches) === 1) {
                $quality = (float) $matches[1];
            }
        }
        if ($quality > 0) {
            $accepted[$type] = true;
        }
    }
    return isset($accepted['application/json'], $accepted['text/event-stream']);
}

function mcpHttpAuthenticateHeader(): string
{
    try {
        $metadataUrl = mcpOauthPublicBaseUrl() . '/.well-known/oauth-protected-resource/mcp';
    } catch (Throwable) {
        $metadataUrl = '/.well-known/oauth-protected-resource/mcp';
    }
    return 'Bearer resource_metadata="' . $metadataUrl . '"';
}

function mcpHttpAudit(?array $principal, string $method, string $result, float $startedAt, ?array $request = null): string
{
    try {
        return mcpServerWriteAudit(dbConnection(), $principal, $method, $result, $startedAt, $request, httpRequestCorrelationId());
    } catch (Throwable) {
        mcpHttpRespond(503, ['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'Service temporarily unavailable.']]);
    }
}

if (!mcpServerOriginAllowed(isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null)) {
    mcpHttpRespond(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Origin is not allowed.']]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mcpHttpRespond(405, null, ['Allow' => 'POST']);
}
$appEnvironment = strtolower(getenv('APP_ENV') ?: 'production');
$secureRequest = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!in_array($appEnvironment, ['dev', 'test'], true) && !$secureRequest) {
    mcpHttpRespond(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'HTTPS is required.']]);
}
if (isset($_SERVER['HTTP_MCP_SESSION_ID']) || isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
    mcpHttpRespond(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Sessions and resumable streams are unsupported.']]);
}
if (!mcpHttpAcceptsStreamableJson((string) ($_SERVER['HTTP_ACCEPT'] ?? ''))) {
    mcpHttpRespond(406, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Unsupported response media types.']]);
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    mcpHttpRespond(415, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Content-Type must be application/json.']]);
}
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
try {
    $principal = mcpServerAuthenticate(dbConnection(), is_string($authorization) ? $authorization : null);
} catch (DomainException $exception) {
    $rateLimited = $exception->getMessage() === 'MCP_RATE_LIMITED';
    if ($rateLimited) {
        mcpHttpRespond(429, null, ['Retry-After' => '60']);
    }
    mcpHttpRespond(401, null, ['WWW-Authenticate' => mcpHttpAuthenticateHeader() . ', error="invalid_token"']);
} catch (Throwable) {
    mcpHttpRespond(503, ['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'Service temporarily unavailable.']]);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 65536) {
    mcpHttpRespond(413, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Request body is too large.']]);
}
$requestObject = json_decode($rawBody);
$request = json_decode($rawBody, true);
if (!$requestObject instanceof stdClass || !is_array($request)
    || !(($requestObject->params ?? null) instanceof stdClass)
    || !(($requestObject->params->_meta ?? null) instanceof stdClass)
    || !(($requestObject->params->_meta->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass)
    || isset($requestObject->params->_meta->{'io.modelcontextprotocol/clientInfo'})
        && (!(($requestObject->params->_meta->{'io.modelcontextprotocol/clientInfo'}) instanceof stdClass)
            || !is_string($requestObject->params->_meta->{'io.modelcontextprotocol/clientInfo'}->name ?? null)
            || !is_string($requestObject->params->_meta->{'io.modelcontextprotocol/clientInfo'}->version ?? null))) {
    $correlation = mcpHttpAudit($principal, 'invalid', 'error', microtime(true), is_array($request) ? $request : null);
    mcpHttpRespond(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid JSON-RPC request.']], ['X-Correlation-ID' => $correlation]);
}
if (($request['method'] ?? null) === 'tools/call' && isset($requestObject->params->arguments)
    && !($requestObject->params->arguments instanceof stdClass)) {
    $correlation = mcpHttpAudit($principal, 'tools/call', 'error', microtime(true), $request);
    mcpHttpRespond(400, ['jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']], ['X-Correlation-ID' => $correlation]);
}
$method = is_string($request['method'] ?? null) ? $request['method'] : 'invalid';
$headerError = mcpServerHeaderValidation($request, [
    'protocol_version' => isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION']) ? (string) $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] : null,
    'method' => isset($_SERVER['HTTP_MCP_METHOD']) ? (string) $_SERVER['HTTP_MCP_METHOD'] : null,
    'name' => isset($_SERVER['HTTP_MCP_NAME']) ? (string) $_SERVER['HTTP_MCP_NAME'] : null,
]);
$startedAt = microtime(true);
if ($headerError !== null) {
    $correlation = mcpHttpAudit($principal, $method, 'error', $startedAt, $request);
    mcpHttpRespond(400, ['jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'error' => $headerError], ['X-Correlation-ID' => $correlation]);
}
$handled = mcpServerHandleRequest(dbConnection(), $request, $principal);
$result = $handled['status'] < 400 ? 'ok' : 'error';
$correlation = mcpHttpAudit($principal, $method, $result, $startedAt, $request);
mcpHttpRespond($handled['status'], $handled['response'], ['X-Correlation-ID' => $correlation]);
