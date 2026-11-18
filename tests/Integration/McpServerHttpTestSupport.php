<?php
declare(strict_types=1);

/** @return array{int,string|null,list<string>} */
function mcpServerHttpRequest(string $url, string $method, ?string $body = null, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s([0-9]{3})\s/', $responseHeaders[0], $matches) === 1) {
        $status = (int) $matches[1];
    }
    return [$status, $response === false ? null : $response, $responseHeaders];
}

/** @return array{headers:Closure,meta:Closure,request:Closure} */
function mcpServerHttpRpcFactories(): array
{
    $headers = static function (string $method, ?string $name = null, string $version = '2026-07-28', string $tokenValue = ''): array {
        $requestHeaders = [
            'Accept: application/json, text/event-stream', 'Content-Type: application/json',
            'MCP-Protocol-Version: ' . $version, 'Mcp-Method: ' . $method,
            'Authorization: Bearer ' . $tokenValue, 'Origin: https://client.example.test',
        ];
        if ($name !== null) {
            $requestHeaders[] = 'Mcp-Name: ' . $name;
        }
        return $requestHeaders;
    };
    $meta = static fn(string $version = '2026-07-28'): array => [
        'io.modelcontextprotocol/protocolVersion' => $version,
        'io.modelcontextprotocol/clientInfo' => ['name' => 'Integration test', 'version' => '1.0'],
        'io.modelcontextprotocol/clientCapabilities' => (object) [],
    ];
    $request = static fn(string $method, string $version = '2026-07-28', array $params = []): string => json_encode([
        'jsonrpc' => '2.0', 'id' => 'req-1', 'method' => $method,
        'params' => ['_meta' => $meta($version), ...$params],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return ['headers' => $headers, 'meta' => $meta, 'request' => $request];
}

/** @param array<string, mixed> $changes */
function mcpServerHttpResignToken(string $token, string $key, array $changes): string
{
    [$header, $payload] = explode('.', $token, 3);
    $claims = json_decode((string) mcpServerBase64UrlDecode($payload), true);
    $claims = array_replace($claims, $changes);
    $payload = mcpOauthBase64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $input = $header . '.' . $payload;
    return $input . '.' . mcpOauthBase64UrlEncode(hash_hmac('sha256', $input, $key, true));
}

/** @param Closure(string):array<string,mixed> $meta @param Closure(string,string,array<string,mixed>):string $rpc @param Closure(string,?string,string,string):array $rpcHeaders */
function mcpServerHttpToolAssertions(string $url, string $token, Closure $meta, Closure $rpc, Closure $rpcHeaders): void
{
    if (mcpToolRegistry(['telemetry.read']) !== []
        || (mcpToolCall('system.read', [], ['telemetry.read'])['error']['code'] ?? null) !== -32602) {
        throw new RuntimeException('MCP tool registry must enforce scopes without revealing hidden tools.');
    }
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/list'), $rpcHeaders('tools/list', null, '2026-07-28', $token));
    $list = json_decode((string) $response, true);
    $tools = is_array($list) ? ($list['result']['tools'] ?? null) : null;
    if ($status !== 200 || !is_array($tools) || count($tools) !== 1 || ($tools[0]['name'] ?? null) !== 'system.read'
        || str_contains((string) $response, 'code.patch')) {
        throw new RuntimeException('MCP tools/list must filter the deterministic registry by token scope.');
    }
    [$status, $response] = mcpServerHttpRequest(
        $url,
        'POST',
        $rpc('tools/call', '2026-07-28', ['name' => 'system.read', 'arguments' => (object) []]),
        $rpcHeaders('tools/call', 'system.read', '2026-07-28', $token)
    );
    $call = json_decode((string) $response, true);
    $structured = is_array($call) ? ($call['result']['structuredContent'] ?? null) : null;
    if ($status !== 200 || !is_array($structured) || ($structured['status'] ?? null) !== 'ready'
        || ($structured['protocolVersion'] ?? null) !== '2026-07-28'
        || isset($structured['tenant_id']) || isset($structured['host']) || isset($structured['database'])) {
        throw new RuntimeException('MCP system.read must return only the minimal public readiness contract.');
    }
    $invalidArguments = json_encode([
        'jsonrpc' => '2.0', 'id' => 'req-1', 'method' => 'tools/call',
        'params' => ['_meta' => $meta(), 'name' => 'system.read', 'arguments' => []],
    ], JSON_THROW_ON_ERROR);
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $invalidArguments, $rpcHeaders('tools/call', 'system.read', '2026-07-28', $token));
    if ($status !== 400 || !str_contains((string) $response, '"code":-32602')) {
        throw new RuntimeException('MCP tools/call must reject array arguments before dispatch.');
    }
}
