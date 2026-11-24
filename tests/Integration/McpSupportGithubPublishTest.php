<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_github.php';
require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';
require_once dirname(__DIR__, 2) . '/config/mcp_server.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$tenantTools = mcpToolRegistry(mcpOauthSupportedScopes(), false);
$supportPublishTools = mcpToolRegistry(['repo.publish'], true);
if (array_filter($tenantTools, static fn(array $tool): bool => $tool['name'] === 'repo.publish') !== []
    || count($supportPublishTools) !== 1 || ($supportPublishTools[0]['name'] ?? null) !== 'repo.publish'
    || ($supportPublishTools[0]['annotations']['readOnlyHint'] ?? true) !== false) {
    throw new RuntimeException('repo.publish must be exposed only to a support principal carrying the capability.');
}

$token = 'github_pat_synthetic_publish_token_123456';
$baseSha = str_repeat('a', 40);
$blobSha = str_repeat('b', 40);
$treeSha = str_repeat('c', 40);
$commitSha = str_repeat('d', 40);
$devSha = $baseSha;
$calls = [];
$commitBody = null;
$transport = static function (string $method, string $url, array $headers, ?string $body) use ($token, $baseSha, $blobSha, $treeSha, $commitSha, &$devSha, &$calls, &$commitBody): array {
    if (($headers['Authorization'] ?? null) !== 'Bearer ' . $token) {
        throw new RuntimeException('GitHub PAT must be sent only in the authorization header.');
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
    if ($method === 'GET' && $path === '/repos/agthinkindigital/IEPRCard') {
        return ['status' => 200, 'body' => '{"full_name":"agthinkindigital/IEPRCard"}'];
    }
    if ($method === 'GET' && $path === '/repos/agthinkindigital/IEPRCard/branches/PROD/protection') {
        return ['status' => 200, 'body' => json_encode([
            'required_status_checks' => ['strict' => true, 'contexts' => MCP_SUPPORT_GITHUB_REQUIRED_CHECKS],
            'enforce_admins' => ['enabled' => true],
            'required_pull_request_reviews' => ['required_approving_review_count' => 0],
            'allow_force_pushes' => ['enabled' => false], 'allow_deletions' => ['enabled' => false],
        ], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && $path === '/repos/agthinkindigital/IEPRCard/git/ref/heads/DEV') {
        return ['status' => 200, 'body' => json_encode(['object' => ['sha' => $devSha]], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && preg_match('~^/repos/agthinkindigital/IEPRCard/commits/[a-f0-9]{40}/check-runs$~D', $path) === 1) {
        return ['status' => 200, 'body' => json_encode(['check_runs' => array_map(static fn(string $name): array => [
            'name' => $name, 'status' => 'completed', 'conclusion' => 'success',
        ], MCP_SUPPORT_GITHUB_REQUIRED_CHECKS)], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && $path === '/repos/agthinkindigital/IEPRCard/git/commits/' . $baseSha) {
        return ['status' => 200, 'body' => json_encode(['tree' => ['sha' => $treeSha]], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET' && $path === '/repos/agthinkindigital/IEPRCard/git/trees/' . $treeSha) {
        return ['status' => 200, 'body' => json_encode([
            'truncated' => false,
            'tree' => [['path' => 'README.md', 'type' => 'blob', 'mode' => '100644', 'sha' => $blobSha]],
        ], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'POST' && $path === '/repos/agthinkindigital/IEPRCard/git/blobs') {
        $payload = json_decode((string) $body, true);
        if (($payload['encoding'] ?? null) !== 'base64' || base64_decode((string) ($payload['content'] ?? ''), true) !== "# IEPRCard\nConteúdo atualizado\n") {
            throw new RuntimeException('GitHub patch must send only the requested UTF-8 file content.');
        }
        return ['status' => 201, 'body' => json_encode(['sha' => $blobSha], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'POST' && $path === '/repos/agthinkindigital/IEPRCard/git/trees') {
        $payload = json_decode((string) $body, true);
        if (($payload['base_tree'] ?? null) !== $treeSha || ($payload['tree'][0]['path'] ?? null) !== 'README.md') {
            throw new RuntimeException('GitHub patch must extend the current tree.');
        }
        return ['status' => 201, 'body' => json_encode(['sha' => $treeSha], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'POST' && $path === '/repos/agthinkindigital/IEPRCard/git/commits') {
        $commitBody = json_decode((string) $body, true);
        if (($commitBody['parents'][0] ?? null) !== $baseSha
            || ($commitBody['author']['name'] ?? null) !== 'Leonardo Nascimento'
            || ($commitBody['author']['email'] ?? null) !== 'agenciathinkingdigital@gmail.com'
            || ($commitBody['committer']['name'] ?? null) !== 'Leonardo Nascimento') {
            throw new RuntimeException('GitHub commit must keep the requested parent and Leonardo author identity.');
        }
        return ['status' => 201, 'body' => json_encode(['sha' => $commitSha], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'PATCH' && $path === '/repos/agthinkindigital/IEPRCard/git/refs/heads/DEV') {
        $payload = json_decode((string) $body, true);
        if (($payload['sha'] ?? null) !== $commitSha || ($payload['force'] ?? null) !== false) {
            throw new RuntimeException('GitHub ref update must be a non-force update to DEV only.');
        }
        $devSha = $commitSha;
        return ['status' => 200, 'body' => json_encode(['object' => ['sha' => $commitSha]], JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected GitHub request in safe publisher test: ' . $method . ' ' . $path);
};

foreach (tenantTestConnections() as $driver => $pdo) {
    $devSha = $baseSha;
    $calls = [];
    $commitBody = null;
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $email = 'mcp-publish-' . bin2hex(random_bytes(5)) . '@example.test';
    $pdo->prepare(
        "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES ('superadmin', 'MCP Publish Admin', :email, :password, 1)"
    )->execute(['email' => $email, 'password' => password_hash('synthetic-password', PASSWORD_DEFAULT)]);
    $identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
    $grant = null;
    $configuration = [
        'IEPR_INTEGRATION_KEY' => base64_encode(str_repeat("\x58", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        'MCP_PUBLIC_BASE_URL' => 'https://mcp.example.test',
        'MCP_OAUTH_SIGNING_KEY' => base64_encode(str_repeat("\x64", 32)),
        'APP_ENV' => 'production',
    ];

    try {
        mcpSupportGithubSaveTested($pdo, $identity, $token, $configuration, $transport);
        $grant = mcpSupportGrantCreate($pdo, $identity, [
            'label' => 'Publicação DEV', 'duration' => '1h',
            'targets' => [['type' => 'sede', 'id' => $fixture['tenant_id']]],
            'capabilities' => ['repo.publish'],
        ]);
        $oauth = mcpOauthIssueClientCredentialsToken($pdo, [
            'grant_type' => 'client_credentials', 'resource' => 'https://mcp.example.test/mcp',
            'scope' => 'repo.publish', '_basic_credentials' => ['client_id' => $grant['public_id'], 'client_secret' => $grant['secret']],
        ], $configuration);
        $principal = mcpServerAuthenticate($pdo, 'Bearer ' . $oauth['access_token'], $configuration);
        $input = [
            'idempotency_key' => 'support-op-00000001',
            'expected_head_sha' => $baseSha,
            'commit_message' => 'docs: update readme',
            'files' => [['path' => 'README.md', 'content' => "# IEPRCard\nConteúdo atualizado\n"]],
        ];
        $published = mcpSupportGithubPublish($pdo, $principal, $input, $transport, $configuration);
        if (($published['branch'] ?? null) !== 'DEV' || ($published['commit_sha'] ?? null) !== $commitSha
            || ($published['ci_status'] ?? null) !== 'success' || ($published['idempotent_replay'] ?? true) !== false
            || $devSha !== $commitSha || !is_array($commitBody)) {
            throw new RuntimeException($driver . ': MCP publisher must create a CI-checked Leonardo commit only on DEV.');
        }
        $replayed = mcpSupportGithubPublish($pdo, $principal, $input, $transport, $configuration);
        if (($replayed['commit_sha'] ?? null) !== $commitSha || ($replayed['idempotent_replay'] ?? false) !== true) {
            throw new RuntimeException($driver . ': repeating an idempotency key must not create another commit.');
        }
        $audit = $pdo->prepare('SELECT result, commit_sha FROM mcp_support_audit_logs WHERE operation_key = :operation');
        $audit->execute(['operation' => $input['idempotency_key']]);
        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($auditRow) || $auditRow['result'] !== 'ok' || $auditRow['commit_sha'] !== $commitSha
            || count(array_filter($calls, static fn(array $call): bool => $call['method'] === 'PATCH' && $call['path'] !== '/repos/agthinkindigital/IEPRCard/git/refs/heads/DEV')) > 0) {
            throw new RuntimeException($driver . ': publication audit must record only safe commit metadata and never allow a different branch.');
        }
        try {
            mcpSupportGithubPublish($pdo, $principal, array_replace($input, [
                'idempotency_key' => 'support-op-00000002', 'files' => [['path' => '.env', 'content' => 'APP_SECRET=bad']],
            ]), $transport, $configuration);
            throw new RuntimeException($driver . ': secret environment files must be blocked from MCP publishing.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'MCP_SUPPORT_GITHUB_INPUT_INVALID') throw $exception;
        }
        foreach ([
            'ghp_' . str_repeat('F', 32),
            'gho_' . str_repeat('A', 32),
            'ghu_' . str_repeat('B', 32),
            'ghs_' . str_repeat('C', 32),
            'ghr_' . str_repeat('D', 32),
            'github_pat_' . str_repeat('G', 24),
            'glpat-' . str_repeat('H', 24),
            'sk-' . str_repeat('e', 40),
            'xoxb-' . str_repeat('I', 24),
            'AIza' . str_repeat('J', 35),
            'AKIA' . str_repeat('K', 16),
            'sk_live_' . str_repeat('L', 20),
            "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----",
        ] as $index => $secretFixture) {
            try {
                mcpSupportGithubPublish($pdo, $principal, array_replace($input, [
                    'idempotency_key' => 'secret-op-' . str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                    'files' => [['path' => 'README.md', 'content' => 'example token: ' . $secretFixture]],
                ]), $transport, $configuration);
                throw new RuntimeException($driver . ': known integration tokens must be blocked from MCP publishing.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'MCP_SUPPORT_GITHUB_SECRET_REJECTED') throw $exception;
            }
        }
    } finally {
        if (is_array($grant)) $pdo->prepare('DELETE FROM mcp_support_grants WHERE id = :id')->execute(['id' => $grant['id']]);
        $pdo->prepare('DELETE FROM mcp_support_github_config WHERE id = 1')->execute();
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $identity['id']]);
        tenantTestCleanup($pdo, $fixture);
    }
}

fwrite(STDOUT, "PASS: MCP publisher writes idempotent commits to DEV only, with Leonardo attribution, CI status and audit.\n");
