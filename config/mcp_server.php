<?php
declare(strict_types=1);

require_once __DIR__ . '/mcp_oauth.php';
require_once __DIR__ . '/mcp_tools.php';
require_once __DIR__ . '/mcp_server_support.php';

/** @return array<string,mixed> */
function mcpServerAuthenticate(PDO $pdo, ?string $authorization, ?array $configuration = null): array
{
    $configuration ??= [];
    if ($authorization === null || preg_match('/^Bearer ([A-Za-z0-9._-]{1,8192})$/iD', $authorization, $matches) !== 1) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    $tokenParts = explode('.', $matches[1]);
    if (count($tokenParts) !== 3) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    $headerJson = mcpServerBase64UrlDecode($tokenParts[0]);
    $payloadJson = mcpServerBase64UrlDecode($tokenParts[1]);
    $signature = mcpServerBase64UrlDecode($tokenParts[2]);
    $header = is_string($headerJson) ? json_decode($headerJson, true) : null;
    $claims = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT'
        || !is_array($claims) || !is_string($signature)) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    try {
        $baseUrl = mcpOauthPublicBaseUrl($configuration);
        $key = mcpOauthSigningKey($configuration);
    } catch (Throwable) {
        throw new RuntimeException('MCP_AUTH_CONFIGURATION_INVALID');
    }
    $expectedSignature = hash_hmac('sha256', $tokenParts[0] . '.' . $tokenParts[1], $key, true);
    $now = time();
    if (!hash_equals($expectedSignature, $signature) || ($claims['iss'] ?? null) !== $baseUrl
        || ($claims['aud'] ?? null) !== $baseUrl . '/mcp' || !is_string($claims['sub'] ?? null)
        || ($claims['client_id'] ?? null) !== $claims['sub'] || !is_int($claims['iat'] ?? null) || !is_int($claims['nbf'] ?? null)
        || !is_int($claims['exp'] ?? null) || $claims['iat'] > $now || $claims['nbf'] > $now
        || $claims['exp'] <= $now || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 300
        || !is_string($claims['jti'] ?? null) || $claims['jti'] === '' || !is_string($claims['scope'] ?? null)) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    $scopes = preg_split('/\s+/', trim($claims['scope']), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($scopes) || $scopes === [] || count($scopes) !== count(array_unique($scopes))) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    if (array_key_exists('support_grant_id', $claims)) {
        return mcpServerAuthenticateSupportGrant($pdo, $claims, $scopes);
    }
    if (!is_int($claims['tenant_id'] ?? null) || $claims['tenant_id'] < 1) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    $query = $pdo->prepare(
        'SELECT k.id, k.tenant_id, k.client_id, k.escopos_json, k.limite_requisicoes_minuto, k.ativo, k.expira_em, t.status_conta '
        . 'FROM mcp_api_keys k INNER JOIN tenants t ON t.id = k.tenant_id '
        . "WHERE k.client_id = :client_id AND k.client_id IS NOT NULL AND t.parent_id IS NULL AND t.tipo = 'cliente' LIMIT 1"
    );
    $query->execute(['client_id' => $claims['client_id']]);
    $client = $query->fetch(PDO::FETCH_ASSOC);
    $granted = is_array($client) ? json_decode((string) $client['escopos_json'], true) : null;
    $nowText = gmdate('Y-m-d H:i:s');
    if (!is_array($client) || (int) $client['ativo'] !== 1 || (string) $client['status_conta'] !== 'ativo'
        || (int) $client['tenant_id'] !== $claims['tenant_id'] || (string) $client['client_id'] !== $claims['client_id']
        || $client['expira_em'] !== null && (string) $client['expira_em'] <= $nowText
        || !is_array($granted) || array_filter($granted, static fn(mixed $scope): bool => !is_string($scope)) !== []) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    foreach ($scopes as $scope) {
        if (!in_array($scope, $granted, true) || !in_array($scope, mcpOauthSupportedScopes(), true)) {
            throw new DomainException('MCP_AUTH_INVALID');
        }
    }
    if (!mcpOauthConsumeRateLimit('mcp-call:' . $claims['client_id'], max(1, (int) $client['limite_requisicoes_minuto']))) {
        throw new DomainException('MCP_RATE_LIMITED');
    }
    return ['kind' => 'tenant', 'id' => (int) $client['id'], 'tenant_id' => (int) $client['tenant_id'], 'client_id' => (string) $client['client_id'], 'scopes' => $scopes];
}

/** @param array<string,mixed> $claims @param list<string> $scopes @return array<string,mixed> */
function mcpServerAuthenticateSupportGrant(PDO $pdo, array $claims, array $scopes): array
{
    $grantId = $claims['support_grant_id'];
    if (!is_int($grantId) || $grantId < 1 || isset($claims['tenant_id']) || !is_string($claims['client_id'] ?? null)
        || !str_starts_with($claims['client_id'], 'msp_')) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    $grantQuery = $pdo->prepare(
        'SELECT id, public_id, capacidades_json, expira_em, revogado_em, criado_por_usuario_id '
        . 'FROM mcp_support_grants WHERE id = :id AND public_id = :public_id LIMIT 1'
    );
    $grantQuery->execute(['id' => $grantId, 'public_id' => $claims['client_id']]);
    $grant = $grantQuery->fetch(PDO::FETCH_ASSOC);
    $now = gmdate('Y-m-d H:i:s');
    $capabilities = is_array($grant) ? json_decode((string) $grant['capacidades_json'], true) : null;
    if (!is_array($grant) || $grant['revogado_em'] !== null || (string) $grant['expira_em'] <= $now
        || !is_array($capabilities)) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, $capabilities, true)
            || !in_array($scope, MCP_SUPPORT_GRANT_CAPABILITIES, true)) {
            throw new DomainException('MCP_AUTH_INVALID');
        }
    }

    $targetQuery = $pdo->prepare(
        'SELECT tenant_id, subcliente_id FROM mcp_support_grant_targets WHERE grant_id = :grant '
        . 'ORDER BY tenant_id, CASE WHEN subcliente_id IS NULL THEN 0 ELSE 1 END, subcliente_id'
    );
    $targetQuery->execute(['grant' => $grantId]);
    $targets = [];
    $rootLookup = $pdo->prepare(
        "SELECT id FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
    );
    $unitLookup = $pdo->prepare(
        "SELECT child.id FROM tenants child INNER JOIN tenants parent ON parent.id = child.parent_id "
        . "WHERE child.id = :unit AND child.parent_id = :tenant AND child.tipo = 'subcliente' "
        . "AND child.status_conta = 'ativo' AND parent.tipo = 'cliente' AND parent.parent_id IS NULL "
        . "AND parent.status_conta = 'ativo' LIMIT 1"
    );
    foreach ($targetQuery->fetchAll(PDO::FETCH_ASSOC) as $target) {
        $tenantId = (int) $target['tenant_id'];
        if ($target['subcliente_id'] === null) {
            $rootLookup->execute(['id' => $tenantId]);
            if ($rootLookup->fetchColumn() !== false) {
                $targets[] = ['tenant_id' => $tenantId, 'subcliente_id' => null];
            }
            continue;
        }
        $unitId = (int) $target['subcliente_id'];
        $unitLookup->execute(['unit' => $unitId, 'tenant' => $tenantId]);
        if ($unitLookup->fetchColumn() !== false) {
            $targets[] = ['tenant_id' => $tenantId, 'subcliente_id' => $unitId];
        }
    }
    if ($targets === [] || !mcpOauthConsumeRateLimit('support-call:' . $grantId, 120)) {
        throw new DomainException($targets === [] ? 'MCP_AUTH_INVALID' : 'MCP_RATE_LIMITED');
    }
    $pdo->prepare('UPDATE mcp_support_grants SET ultimo_uso_em = :used WHERE id = :id AND revogado_em IS NULL')
        ->execute(['used' => $now, 'id' => $grantId]);

    return [
        'kind' => 'support',
        'id' => $grantId,
        'grant_id' => $grantId,
        'tenant_id' => null,
        'client_id' => (string) $grant['public_id'],
        'actor_user_id' => $grant['criado_por_usuario_id'] === null ? null : (int) $grant['criado_por_usuario_id'],
        'scopes' => $scopes,
        'targets' => $targets,
    ];
}

/** @param array<string, mixed> $request @param array{id:int,tenant_id:int,client_id:string,scopes:list<string>} $principal @return array{status:int,response:array<string,mixed>} */
function mcpServerHandleRequest(PDO $pdo, array $request, array $principal): array
{
    $id = $request['id'] ?? null;
    if (($request['jsonrpc'] ?? null) !== '2.0' || !array_key_exists('id', $request)
        || !(is_string($id) && $id !== '' || is_int($id) || is_float($id) && is_finite($id))
        || !is_string($request['method'] ?? null) || $request['method'] === ''
        || isset($request['result']) || isset($request['error']) || !is_array($request['params'] ?? null)
        || !is_array($request['params']['_meta'] ?? null)
        || !is_array($request['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] ?? null)) {
        return ['status' => 400, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid request.']]];
    }
    if ($request['method'] === 'ping') {
        return ['status' => 200, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []]];
    }
    if ($request['method'] === 'tools/list') {
        return ['status' => 200, 'response' => [
            'jsonrpc' => '2.0', 'id' => $id,
            'result' => [
                'resultType' => 'complete',
                'tools' => mcpToolRegistry($principal['scopes'], ($principal['kind'] ?? null) === 'support'),
                'ttlMs' => 300000,
                'cacheScope' => 'private',
            ],
        ]];
    }
    if ($request['method'] === 'tools/call') {
        $params = $request['params'];
        if (!is_string($params['name'] ?? null) || $params['name'] === '') {
            return ['status' => 400, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid tool name.']]];
        }
        $call = mcpToolCall($params['name'], $params['arguments'] ?? [], $principal['scopes'], $pdo, $principal);
        if (isset($call['error'])) {
            return ['status' => 400, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $call['error']]];
        }
        return ['status' => 200, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $call['result']]];
    }
    if ($request['method'] === 'server/discover') {
        return ['status' => 200, 'response' => [
            'jsonrpc' => '2.0', 'id' => $id,
            'result' => [
                'resultType' => 'complete',
                'supportedVersions' => ['2026-07-28'],
                'capabilities' => ['tools' => (object) []],
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'IEPRCard', 'version' => '0.1.0']],
                'ttlMs' => 300000,
                'cacheScope' => 'public',
            ],
        ]];
    }
    return ['status' => 404, 'response' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found.']]];
}
