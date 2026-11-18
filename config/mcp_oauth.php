<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/mcp_oauth_support.php';

/** @return array{client_id:string,client_secret:string}|null */
function mcpOauthReadBasicCredentials(?string $authorization): ?array
{
    if ($authorization === null || preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $authorization, $matches) !== 1) {
        return null;
    }
    $decoded = base64_decode($matches[1], true);
    if (!is_string($decoded) || !str_contains($decoded, ':')) {
        return null;
    }
    [$clientId, $clientSecret] = explode(':', $decoded, 2);
    $clientId = rawurldecode($clientId);
    $clientSecret = rawurldecode($clientSecret);
    if ($clientId === '' || strlen($clientId) > 80 || $clientSecret === '' || strlen($clientSecret) > 256) {
        return null;
    }
    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
}

/** @param array<string, mixed> $input @param array<string, string>|null $configuration */
function mcpOauthIssueClientCredentialsToken(PDO $pdo, array $input, ?array $configuration = null, ?string $rateDirectory = null): array
{
    $configuration ??= [];
    if (($input['grant_type'] ?? null) !== 'client_credentials' || !is_string($input['resource'] ?? null)
        || !is_string($input['scope'] ?? null) || isset($input['client_id']) || isset($input['client_secret'])) {
        throw new DomainException('MCP_OAUTH_INVALID_REQUEST');
    }
    $baseUrl = mcpOauthPublicBaseUrl($configuration);
    $resource = $baseUrl . '/mcp';
    if (!hash_equals($resource, $input['resource'])) {
        throw new DomainException('MCP_OAUTH_INVALID_TARGET');
    }
    $credentials = $input['_basic_credentials'] ?? null;
    if (!is_array($credentials) || !is_string($credentials['client_id'] ?? null) || !is_string($credentials['client_secret'] ?? null)) {
        throw new DomainException('MCP_OAUTH_INVALID_CLIENT');
    }
    $clientId = $credentials['client_id'];
    $secret = $credentials['client_secret'];
    $query = $pdo->prepare(
        'SELECT k.id, k.tenant_id, k.chave_hash, k.escopos_json, k.limite_requisicoes_minuto, k.ativo, k.expira_em, '
        . 't.status_conta FROM mcp_api_keys k INNER JOIN tenants t ON t.id = k.tenant_id '
        . "WHERE k.client_id = :client_id AND k.client_id IS NOT NULL AND t.parent_id IS NULL AND t.tipo = 'cliente' LIMIT 1"
    );
    $query->execute(['client_id' => $clientId]);
    $client = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new DomainException('MCP_OAUTH_INVALID_CLIENT');
    }
    if (!mcpOauthConsumeRateLimit('token:' . $clientId, 30, $rateDirectory)) {
        throw new DomainException('MCP_OAUTH_RATE_LIMITED');
    }
    $nowText = gmdate('Y-m-d H:i:s');
    if (!password_verify($secret, (string) ($client['chave_hash'] ?? ''))
        || (int) $client['ativo'] !== 1 || (string) $client['status_conta'] !== 'ativo'
        || $client['expira_em'] !== null && (string) $client['expira_em'] <= $nowText) {
        throw new DomainException('MCP_OAUTH_INVALID_CLIENT');
    }
    $granted = json_decode((string) $client['escopos_json'], true);
    if (!is_array($granted) || array_filter($granted, static fn(mixed $scope): bool => !is_string($scope)) !== []) {
        throw new DomainException('MCP_OAUTH_INVALID_SCOPE');
    }
    $requested = preg_split('/\s+/', trim($input['scope']), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($requested) || $requested === [] || count($requested) !== count(array_unique($requested))) {
        throw new DomainException('MCP_OAUTH_INVALID_SCOPE');
    }
    foreach ($requested as $scope) {
        if (!in_array($scope, $granted, true) || !in_array($scope, mcpOauthSupportedScopes(), true)) {
            throw new DomainException('MCP_OAUTH_INVALID_SCOPE');
        }
    }
    if (!mcpOauthConsumeRateLimit('token-issue:' . $clientId, (int) $client['limite_requisicoes_minuto'], $rateDirectory)) {
        throw new DomainException('MCP_OAUTH_RATE_LIMITED');
    }

    $issuedAt = time();
    $expiresAt = $issuedAt + 300;
    $header = mcpOauthBase64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = mcpOauthBase64UrlEncode(json_encode([
        'iss' => $baseUrl,
        'aud' => $resource,
        'sub' => $clientId,
        'client_id' => $clientId,
        'tenant_id' => (int) $client['tenant_id'],
        'scope' => implode(' ', $requested),
        'iat' => $issuedAt,
        'nbf' => $issuedAt,
        'exp' => $expiresAt,
        'jti' => mcpOauthBase64UrlEncode(random_bytes(16)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $payload;
    $token = $signingInput . '.' . mcpOauthBase64UrlEncode(hash_hmac('sha256', $signingInput, mcpOauthSigningKey($configuration), true));
    $used = $pdo->prepare('UPDATE mcp_api_keys SET ultimo_uso_em = :used WHERE id = :id AND ativo = 1');
    $used->execute(['used' => $nowText, 'id' => (int) $client['id']]);

    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => implode(' ', $requested)];
}

/** @param array<string, string>|null $configuration @return array<string, mixed> */
function mcpOauthProtectedResourceMetadata(?array $configuration = null): array
{
    $baseUrl = mcpOauthPublicBaseUrl($configuration);
    return [
        'resource' => $baseUrl . '/mcp',
        'authorization_servers' => [$baseUrl],
        'bearer_methods_supported' => ['header'],
        'scopes_supported' => mcpOauthSupportedScopes(),
    ];
}

/** @param array<string, string>|null $configuration @return array<string, mixed> */
function mcpOauthAuthorizationServerMetadata(?array $configuration = null): array
{
    $baseUrl = mcpOauthPublicBaseUrl($configuration);
    return [
        'issuer' => $baseUrl,
        'token_endpoint' => $baseUrl . '/oauth/token',
        'grant_types_supported' => ['client_credentials'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        'response_types_supported' => [],
        'scopes_supported' => mcpOauthSupportedScopes(),
    ];
}
