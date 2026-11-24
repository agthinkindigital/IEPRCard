<?php
declare(strict_types=1);

function mcpServerBase64UrlDecode(string $value): string|false
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return false;
    }
    $padded = strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4);
    return base64_decode($padded, true);
}

/** @param array<string, string>|null $configuration */
function mcpServerOriginAllowed(?string $origin, ?array $configuration = null): bool
{
    if ($origin === null || $origin === '') {
        return true;
    }
    $configuration ??= [];
    $allowed = $configuration['MCP_ORIGIN_ALLOWLIST'] ?? (getenv('MCP_ORIGIN_ALLOWLIST') ?: '');
    if ($allowed === '' || strtolower(trim($origin)) === 'null') {
        return false;
    }
    $normalize = static function (string $value): ?string {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')
            || isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = tenantResolverNormalizeHost((string) $parts['host']);
        if ($host === null) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    };
    $requestOrigin = $normalize($origin);
    if ($requestOrigin === null) {
        return false;
    }
    foreach (explode(',', $allowed) as $candidate) {
        if ($normalize($candidate) === $requestOrigin) {
            return true;
        }
    }
    return false;
}

function mcpServerDecodeNameHeader(?string $value): string|false|null
{
    if ($value === null) {
        return null;
    }
    if (strlen($value) > 2048) {
        return false;
    }
    if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
        $decoded = base64_decode(substr($value, 9, -2), true);
        return is_string($decoded) ? $decoded : false;
    }
    return preg_match('/^[\x20-\x7E\t]*$/D', $value) === 1 ? $value : false;
}

/** @param array<string, mixed> $request */
function mcpServerHeaderValidation(array $request, array $headers): ?array
{
    $params = $request['params'] ?? null;
    $meta = is_array($params) ? ($params['_meta'] ?? null) : null;
    $bodyVersion = is_array($meta) ? ($meta['io.modelcontextprotocol/protocolVersion'] ?? null) : null;
    $headerVersion = $headers['protocol_version'] ?? null;
    $method = $request['method'] ?? null;
    $headerMethod = $headers['method'] ?? null;
    $nameRequired = in_array($method, ['tools/call', 'prompts/get', 'resources/read'], true);
    $expectedName = null;
    if ($method === 'tools/call' || $method === 'prompts/get') {
        $expectedName = is_array($params) ? ($params['name'] ?? null) : null;
    } elseif ($method === 'resources/read') {
        $expectedName = is_array($params) ? ($params['uri'] ?? null) : null;
    }
    $actualName = mcpServerDecodeNameHeader(is_string($headers['name'] ?? null) ? $headers['name'] : null);
    if (!is_string($headerVersion) || !is_string($bodyVersion) || !is_string($method)
        || strlen($headerVersion) > 40 || strlen($bodyVersion) > 40
        || !is_string($headerMethod) || preg_match('/^[A-Za-z0-9._\/-]{1,120}$/D', $headerMethod) !== 1
        || $headerMethod !== $method
        || $headerVersion !== $bodyVersion
        || !is_array($meta) || !is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)
        || ($actualName === false)
        || ($nameRequired && (!is_string($expectedName) || $expectedName === '' || $actualName !== $expectedName))
        || (!$nameRequired && $actualName !== null)) {
        return ['code' => -32020, 'message' => 'Header mismatch or missing request metadata.'];
    }
    if ($headerVersion !== '2026-07-28') {
        return [
            'code' => -32022,
            'message' => 'Unsupported protocol version.',
            'data' => ['supported' => ['2026-07-28'], 'requested' => $headerVersion],
        ];
    }
    return null;
}

/** @param array<string, mixed>|null $principal @param array<string,mixed>|null $request */
function mcpServerWriteAudit(PDO $pdo, ?array $principal, string $method, string $result, float $startedAt, ?array $request = null, ?string $correlationId = null): string
{
    if ($correlationId === null || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $correlationId)) {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $correlationId = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
    $knownMethods = ['ping', 'server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get'];
    $safeMethod = in_array($method, $knownMethods, true) ? $method : 'unknown';
    $scope = is_array($principal['scopes'] ?? null) ? ($principal['scopes'][0] ?? null) : null;
    if (($principal['kind'] ?? null) === 'support') {
        $tool = $method === 'tools/call' && is_array($request['params'] ?? null) && is_string($request['params']['name'] ?? null)
            ? $request['params']['name'] : $safeMethod;
        $knownTools = ['system.read', 'telemetry.read', 'db.inspect', 'repo.publish'];
        if (!in_array($tool, $knownTools, true)) $tool = 'unknown';
        $arguments = $method === 'tools/call' && is_array($request['params']['arguments'] ?? null) ? $request['params']['arguments'] : [];
        $tenantId = filter_var($arguments['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subclientId = array_key_exists('subcliente_id', $arguments)
            ? filter_var($arguments['subcliente_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($tenantId === false || $tenantId === null || $subclientId === false
            || !mcpSupportGrantAllowsTarget($principal, (int) $tenantId, $subclientId === null ? null : (int) $subclientId)) {
            $tenantId = null;
            $subclientId = null;
        }
        $statement = $pdo->prepare(
            'INSERT INTO mcp_support_audit_logs (actor_user_id, grant_id, capability, tool, target_tenant_id, '
            . 'target_subcliente_id, result, correlation_id, duration_ms, created_at) '
            . 'VALUES (:actor, :grant, :capability, :tool, :tenant, :subclient, :result, :correlation, :duration, :created)'
        );
        $statement->execute([
            'actor' => $principal['actor_user_id'] ?? null,
            'grant' => $principal['grant_id'] ?? null,
            'capability' => is_string($scope) ? $scope : null,
            'tool' => $tool,
            'tenant' => $tenantId,
            'subclient' => $subclientId,
            'result' => $result,
            'correlation' => $correlationId,
            'duration' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        return $correlationId;
    }
    $statement = $pdo->prepare(
        'INSERT INTO mcp_audit_logs (tenant_id, api_key_id, correlation_id, metodo, escopo, resultado, duracao_ms, criado_em) '
        . 'VALUES (:tenant_id, :api_key_id, :correlation_id, :method, :scope, :result, :duration, :created_at)'
    );
    $statement->execute([
        'tenant_id' => is_array($principal) ? $principal['tenant_id'] : null,
        'api_key_id' => is_array($principal) ? $principal['id'] : null,
        'correlation_id' => $correlationId,
        'method' => $safeMethod,
        'scope' => is_string($scope) ? $scope : null,
        'result' => $result,
        'duration' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    return $correlationId;
}
