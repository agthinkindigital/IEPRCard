<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_github.php';

$integrationKey = base64_encode(str_repeat("\x36", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
$configuration = ['IEPR_INTEGRATION_KEY' => $integrationKey];
$token = 'github_pat_synthetic_fine_grained_token_123456';
$fakeTransport = static function (string $method, string $url, array $headers, ?string $body) use ($token): array {
    if ($method !== 'GET' || $body !== null || ($headers['Authorization'] ?? null) !== 'Bearer ' . $token
        || ($headers['Accept'] ?? null) !== 'application/vnd.github+json') {
        throw new RuntimeException('GitHub connection probe sent unexpected request data.');
    }
    if (str_ends_with($url, '/repos/agthinkindigital/IEPRCard')) {
        return ['status' => 200, 'body' => '{"full_name":"agthinkindigital/IEPRCard"}'];
    }
    if (str_ends_with($url, '/branches/PROD/protection')) {
        return ['status' => 200, 'body' => json_encode([
            'required_status_checks' => [
                'strict' => true,
                'contexts' => ['php-database-tests (mysql:8.4)', 'php-database-tests (mariadb:10.11)', 'php-pdf-render'],
            ],
            'enforce_admins' => ['enabled' => true],
            'required_pull_request_reviews' => ['required_approving_review_count' => 0],
            'allow_force_pushes' => ['enabled' => false],
            'allow_deletions' => ['enabled' => false],
        ], JSON_THROW_ON_ERROR)];
    }
    if (str_ends_with($url, '/git/ref/heads/DEV')) {
        return ['status' => 200, 'body' => '{"object":{"sha":"' . str_repeat('a', 40) . '"}}'];
    }
    if (str_ends_with($url, '/commits/' . str_repeat('a', 40) . '/check-runs?per_page=100')) {
        return ['status' => 200, 'body' => json_encode([
            'check_runs' => array_map(static fn(string $name): array => [
                'name' => $name, 'status' => 'completed', 'conclusion' => 'success',
            ], ['php-database-tests (mysql:8.4)', 'php-database-tests (mariadb:10.11)', 'php-pdf-render']),
        ], JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('GitHub connection probe requested an unexpected endpoint.');
};

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaGarantirEstrutura($pdo, 'test');
    $email = 'mcp-github-' . bin2hex(random_bytes(5)) . '@example.test';
    $pdo->prepare(
        "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES ('superadmin', 'GitHub Test Admin', :email, :password, 1)"
    )->execute(['email' => $email, 'password' => password_hash('synthetic-password', PASSWORD_DEFAULT)]);
    $identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];

    try {
        try {
            mcpSupportGithubSaveTested($pdo, ['id' => $identity['id'], 'papel_sistema' => 'tenant_admin'], $token, $configuration, $fakeTransport);
            throw new RuntimeException($driver . ': tenant identity must not configure global GitHub publishing.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_SUPPORT_GITHUB_DENIED') throw $exception;
        }

        $saved = mcpSupportGithubSaveTested($pdo, $identity, $token, $configuration, $fakeTransport);
        $stored = $pdo->query('SELECT token_cifrado, ultimo_teste_status FROM mcp_support_github_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $safe = mcpSupportGithubRead($pdo, $identity);
        if (($saved['status'] ?? null) !== 'success' || !is_array($stored)
            || $stored['token_cifrado'] === $token || aiGlobalConfigOpen((string) $stored['token_cifrado'], $configuration) !== $token
            || $stored['ultimo_teste_status'] !== 'success' || ($safe['configured'] ?? null) !== true
            || str_contains(json_encode($safe, JSON_THROW_ON_ERROR), $token) || array_key_exists('token', $safe)
            || ($safe['repository'] ?? null) !== 'agthinkindigital/IEPRCard') {
            throw new RuntimeException($driver . ': GitHub PAT must be tested, encrypted at rest and omitted from safe reads.');
        }

        $failedReplacement = mcpSupportGithubSaveTested(
            $pdo,
            $identity,
            'github_pat_invalid_candidate_987654321',
            $configuration,
            static fn(): array => ['status' => 401, 'body' => '{"message":"Bad credentials"}']
        );
        $afterFailure = (string) $pdo->query('SELECT token_cifrado FROM mcp_support_github_config WHERE id = 1')->fetchColumn();
        if (($failedReplacement['status'] ?? null) !== 'failed'
            || aiGlobalConfigOpen($afterFailure, $configuration) !== $token) {
            throw new RuntimeException($driver . ': failed PAT validation must preserve the last known good token.');
        }

        $removed = mcpSupportGithubRemove($pdo, $identity);
        if ($removed !== true || (mcpSupportGithubRead($pdo, $identity)['configured'] ?? true) !== false) {
            throw new RuntimeException($driver . ': superadmin must be able to revoke the GitHub publishing credential.');
        }
    } finally {
        $pdo->prepare('DELETE FROM mcp_support_github_config WHERE id = 1')->execute();
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $identity['id']]);
    }
}

fwrite(STDOUT, "PASS: GitHub PAT setup is superadmin-only, encrypted, tested before replacement and never readable.\n");
