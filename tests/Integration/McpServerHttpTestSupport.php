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
function mcpServerHttpToolAssertions(string $url, string $token, string $limitedToken, string $day, Closure $meta, Closure $rpc, Closure $rpcHeaders): void
{
    if (array_column(mcpToolRegistry(['telemetry.read']), 'name') !== ['telemetry.read']
        || (mcpToolCall('system.read', [], ['telemetry.read'])['error']['code'] ?? null) !== -32602) {
        throw new RuntimeException('MCP tool registry must enforce scopes without revealing hidden tools.');
    }
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/list'), $rpcHeaders('tools/list', null, '2026-07-28', $token));
    $list = json_decode((string) $response, true);
    $tools = is_array($list) ? ($list['result']['tools'] ?? null) : null;
    if ($status !== 200 || !is_array($tools) || array_column($tools, 'name') !== ['system.read', 'telemetry.read']
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

    [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/call', '2026-07-28', [
        'name' => 'telemetry.read', 'arguments' => ['from' => $day, 'to' => $day],
    ]), $rpcHeaders('tools/call', 'telemetry.read', '2026-07-28', $token));
    $telemetry = json_decode((string) $response, true);
    $rows = $telemetry['result']['structuredContent']['rows'] ?? null;
    if ($status !== 200 || !is_array($rows)) {
        throw new RuntimeException('MCP telemetry.read must return a structured rows array.');
    }
    $issuanceTotal = array_sum(array_map(static fn(array $row): int => $row['categoria'] === 'issuance' ? $row['quantidade'] : 0, $rows));
    $messagingTotal = array_sum(array_map(static fn(array $row): int => $row['categoria'] === 'messaging' ? $row['quantidade'] : 0, $rows));
    if (count($rows) !== 2
        || $issuanceTotal !== 7 || $messagingTotal !== 3
        || in_array(9, array_column($rows, 'quantidade'), true)
        || str_contains((string) $response, 'tenant_id') || str_contains((string) $response, 'subcliente_id')) {
        throw new RuntimeException('MCP telemetry.read must return only the authenticated Sede network aggregates.');
    }
    foreach ($rows as $row) {
        if (array_keys($row) !== ['dia', 'categoria', 'evento_chave', 'quantidade']) {
            throw new RuntimeException('MCP telemetry.read must expose only its allowlisted aggregate fields.');
        }
    }

    foreach ([
        ['from' => $day, 'to' => $day, 'tenant_id' => 999],
        ['from' => $day, 'to' => $day, 'subcliente_id' => 999],
        ['from' => '2026-02-30', 'to' => $day],
        ['from' => (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('-366 days')->format('Y-m-d'), 'to' => $day],
        ['from' => $day, 'to' => (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d')],
    ] as $invalidRange) {
        [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/call', '2026-07-28', [
            'name' => 'telemetry.read', 'arguments' => $invalidRange,
        ]), $rpcHeaders('tools/call', 'telemetry.read', '2026-07-28', $token));
        if ($status !== 400 || !str_contains((string) $response, '"code":-32602')) {
            throw new RuntimeException('MCP telemetry.read must reject caller scope selectors and invalid/unbounded date ranges.');
        }
    }

    [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/list'), $rpcHeaders('tools/list', null, '2026-07-28', $limitedToken));
    $limitedList = json_decode((string) $response, true);
    if ($status !== 200 || array_column($limitedList['result']['tools'] ?? [], 'name') !== ['system.read']) {
        throw new RuntimeException('MCP telemetry.read must not be discoverable without its OAuth scope.');
    }
    [$status, $response] = mcpServerHttpRequest($url, 'POST', $rpc('tools/call', '2026-07-28', [
        'name' => 'telemetry.read', 'arguments' => ['from' => $day, 'to' => $day],
    ]), $rpcHeaders('tools/call', 'telemetry.read', '2026-07-28', $limitedToken));
    if ($status !== 400 || !str_contains((string) $response, '"code":-32602')) {
        throw new RuntimeException('MCP telemetry.read must reject calls without the OAuth scope.');
    }
}
