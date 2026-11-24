<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';
require_once __DIR__ . '/mcp_support_grants.php';

const MCP_SUPPORT_GITHUB_REPOSITORY = 'agthinkindigital/IEPRCard';
const MCP_SUPPORT_GITHUB_REQUIRED_CHECKS = [
    'php-database-tests (mysql:8.4)',
    'php-database-tests (mariadb:10.11)',
    'php-pdf-render',
];

/** @param array<string,mixed> $identity */
function mcpSupportGithubAssertSuperadmin(PDO $pdo, array $identity): int
{
    try {
        return mcpSupportGrantAssertSuperadmin($pdo, $identity);
    } catch (DomainException) {
        throw new DomainException('MCP_SUPPORT_GITHUB_DENIED');
    }
}

/** @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport */
function mcpSupportGithubRequest(string $token, string $path, ?callable $transport = null): array
{
    return mcpSupportGithubRequestMethod($token, 'GET', $path, null, $transport);
}

/** @param array<string,mixed>|null $payload @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport */
function mcpSupportGithubRequestMethod(string $token, string $method, string $path, ?array $payload = null, ?callable $transport = null): array
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..')) {
        throw new InvalidArgumentException('MCP_SUPPORT_GITHUB_PATH_INVALID');
    }
    if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
        throw new InvalidArgumentException('MCP_SUPPORT_GITHUB_METHOD_INVALID');
    }
    $url = 'https://api.github.com' . $path;
    $headers = [
        'Accept' => 'application/vnd.github+json',
        'Authorization' => 'Bearer ' . $token,
        'X-GitHub-Api-Version' => '2022-11-28',
        'User-Agent' => 'IEPRCard-MCP-Support',
    ];
    $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body !== null) $headers['Content-Type'] = 'application/json';
    if ($transport !== null) {
        $response = $transport($method, $url, $headers, $body);
        return is_array($response) && is_int($response['status'] ?? null) && is_string($response['body'] ?? null)
            && strlen($response['body']) <= 131072 ? $response : ['status' => 0, 'body' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => ''];
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return ['status' => 0, 'body' => ''];
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_map(static fn(string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 131072,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return is_string($body) && strlen($body) <= 131072 ? ['status' => $status, 'body' => $body] : ['status' => 0, 'body' => ''];
}

function mcpSupportGithubPathAllowed(string $path): bool
{
    if (strlen($path) > 300 || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/')
        || preg_match('~^[A-Za-z0-9._/-]+$~D', $path) !== 1 || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1
        || str_ends_with($path, '/') || str_starts_with(strtolower($path), '.github/')) {
        return false;
    }
    $segments = explode('/', strtolower($path));
    if (in_array('vendor', $segments, true) || in_array('var', $segments, true) || in_array('storage', $segments, true)
        || in_array('uploads', $segments, true) || in_array('.git', $segments, true)) {
        return false;
    }
    $base = strtolower(basename($path));
    if ($base === '.env' || str_starts_with($base, '.env.') || in_array($base, ['composer.json', 'composer.lock', 'phpunit.xml'], true)
        || in_array(pathinfo($base, PATHINFO_EXTENSION), ['zip', 'gz', 'jpg', 'jpeg', 'png', 'webp', 'pdf', 'pem', 'key', 'p12'], true)) {
        return false;
    }
    return true;
}

function mcpSupportGithubContentContainsSecret(string $content): bool
{
    return preg_match(
        '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|'
        . '\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{20,}|'
        . 'sk-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{20,}|AIza[0-9A-Za-z_-]{30,}|AKIA[0-9A-Z]{16}|'
        . '(?:sk|rk)_(?:live|test)_[0-9A-Za-z]{16,})\b/i',
        $content
    ) === 1;
}

/** @param array<string,mixed> $principal @param array<string,mixed> $input @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport @param array<string,mixed>|null $configuration @return array<string,mixed> */
function mcpSupportGithubPublish(PDO $pdo, array $principal, array $input, ?callable $transport = null, ?array $configuration = null): array
{
    $grantId = filter_var($principal['grant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($principal['kind'] ?? null) !== 'support' || $grantId === false
        || !in_array('repo.publish', $principal['scopes'] ?? [], true)) {
        throw new DomainException('MCP_SUPPORT_GITHUB_DENIED');
    }
    mcpSupportGithubAssertGrantActive($pdo, $principal, 'repo.publish');
    $operationKey = $input['idempotency_key'] ?? null;
    $expectedSha = $input['expected_head_sha'] ?? null;
    $message = $input['commit_message'] ?? null;
    $files = $input['files'] ?? null;
    if (!is_string($operationKey) || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $operationKey) !== 1
        || !is_string($expectedSha) || preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1
        || !is_string($message) || trim($message) === '' || strlen($message) > 120 || preg_match('/[\r\n\x00-\x1f]/', $message) === 1
        || !is_array($files) || $files === [] || count($files) > 20) {
        throw new DomainException('MCP_SUPPORT_GITHUB_INPUT_INVALID');
    }
    $totalBytes = 0;
    $normalizedFiles = [];
    foreach ($files as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null) || !mcpSupportGithubPathAllowed($file['path'])
            || !is_string($file['content'] ?? null) || str_contains($file['content'], "\0")
            || !mb_check_encoding($file['content'], 'UTF-8')) {
            throw new DomainException('MCP_SUPPORT_GITHUB_INPUT_INVALID');
        }
        $totalBytes += strlen($file['content']);
        if ($totalBytes > 262144 || array_key_exists($file['path'], $normalizedFiles)) {
            throw new DomainException('MCP_SUPPORT_GITHUB_INPUT_INVALID');
        }
        if (mcpSupportGithubContentContainsSecret($file['content'])) {
            throw new DomainException('MCP_SUPPORT_GITHUB_SECRET_REJECTED');
        }
        $normalizedFiles[$file['path']] = $file['content'];
    }
    ksort($normalizedFiles, SORT_STRING);

    $existing = $pdo->prepare(
        "SELECT grant_id, commit_sha, result FROM mcp_support_audit_logs WHERE operation_key = :operation "
        . "AND tool = 'repo.publish' LIMIT 1"
    );
    $existing->execute(['operation' => $operationKey]);
    $previous = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($previous)) {
        if ((int) $previous['grant_id'] === (int) $grantId && is_string($previous['commit_sha'])
            && preg_match('/^[a-f0-9]{40}$/D', $previous['commit_sha']) === 1 && $previous['result'] === 'ok') {
            $commitChecks = mcpSupportGithubReadChecks($pdo, $principal, $previous['commit_sha'], $transport, $configuration);
            return ['status' => 'success', 'branch' => 'DEV', 'commit_sha' => $previous['commit_sha'], 'ci_status' => $commitChecks['status'], 'checks' => $commitChecks['checks'], 'idempotent_replay' => true];
        }
        throw new DomainException('MCP_SUPPORT_GITHUB_OPERATION_PENDING');
    }

    $credential = $pdo->query('SELECT token_cifrado FROM mcp_support_github_config WHERE id = 1')->fetchColumn();
    if (!is_string($credential) || $credential === '') {
        throw new DomainException('MCP_SUPPORT_GITHUB_NOT_CONFIGURED');
    }
    try {
        $token = aiGlobalConfigOpen($credential, $configuration);
    } catch (Throwable) {
        throw new DomainException('MCP_SUPPORT_GITHUB_NOT_CONFIGURED');
    }
    $readiness = mcpSupportGithubTestToken($token, $transport);
    if ($readiness['status'] !== 'success' || $readiness['dev_head_sha'] !== $expectedSha) {
        throw new DomainException('MCP_SUPPORT_GITHUB_NOT_READY');
    }

    $repo = '/repos/' . MCP_SUPPORT_GITHUB_REPOSITORY;
    $baseCommit = mcpSupportGithubRequest($token, $repo . '/git/commits/' . $expectedSha, $transport);
    $baseCommitData = $baseCommit['status'] === 200 ? json_decode($baseCommit['body'], true) : null;
    $baseTree = is_array($baseCommitData) && is_string($baseCommitData['tree']['sha'] ?? null)
        && preg_match('/^[a-f0-9]{40}$/D', $baseCommitData['tree']['sha']) === 1 ? $baseCommitData['tree']['sha'] : null;
    $treeResponse = $baseTree === null ? ['status' => 0, 'body' => ''] : mcpSupportGithubRequest($token, $repo . '/git/trees/' . $baseTree . '?recursive=1', $transport);
    $treeData = $treeResponse['status'] === 200 ? json_decode($treeResponse['body'], true) : null;
    if (!is_array($treeData) || ($treeData['truncated'] ?? true) !== false || !is_array($treeData['tree'] ?? null)) {
        throw new DomainException('MCP_SUPPORT_GITHUB_NOT_READY');
    }
    $tracked = [];
    foreach ($treeData['tree'] as $entry) {
        if (is_array($entry) && is_string($entry['path'] ?? null) && ($entry['type'] ?? null) === 'blob'
            && in_array($entry['mode'] ?? null, ['100644', '100755'], true) && is_string($entry['sha'] ?? null)) {
            $tracked[$entry['path']] = $entry['sha'];
        }
    }
    foreach ($normalizedFiles as $path => $_content) {
        if (!isset($tracked[$path])) {
            throw new DomainException('MCP_SUPPORT_GITHUB_PATH_NOT_TRACKED');
        }
    }

    $correlationId = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $pending = $pdo->prepare(
        "INSERT INTO mcp_support_audit_logs (actor_user_id, grant_id, capability, tool, operation_key, result, correlation_id, created_at) "
        . "VALUES (:actor, :grant, 'repo.publish', 'repo.publish', :operation, 'pending', :correlation, :created)"
    );
    $pending->execute([
        'actor' => $principal['actor_user_id'] ?? null, 'grant' => $grantId, 'operation' => $operationKey,
        'correlation' => $correlationId, 'created' => $now,
    ]);
    $auditId = (int) $pdo->lastInsertId();

    try {
        $treeEntries = [];
        foreach ($normalizedFiles as $path => $content) {
            $blob = mcpSupportGithubRequestMethod($token, 'POST', $repo . '/git/blobs', [
                'content' => base64_encode($content), 'encoding' => 'base64',
            ], $transport);
            $blobData = $blob['status'] === 201 ? json_decode($blob['body'], true) : null;
            if (!is_array($blobData) || !is_string($blobData['sha'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $blobData['sha']) !== 1) {
                throw new RuntimeException('MCP_SUPPORT_GITHUB_WRITE_FAILED');
            }
            $treeEntries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blobData['sha']];
        }
        $tree = mcpSupportGithubRequestMethod($token, 'POST', $repo . '/git/trees', ['base_tree' => $baseTree, 'tree' => $treeEntries], $transport);
        $treeResponseData = $tree['status'] === 201 ? json_decode($tree['body'], true) : null;
        $newTreeSha = is_array($treeResponseData) ? ($treeResponseData['sha'] ?? null) : null;
        if (!is_string($newTreeSha) || preg_match('/^[a-f0-9]{40}$/D', $newTreeSha) !== 1) {
            throw new RuntimeException('MCP_SUPPORT_GITHUB_WRITE_FAILED');
        }
        $commitBody = [
            'message' => trim($message),
            'tree' => $newTreeSha,
            'parents' => [$expectedSha],
            'author' => ['name' => 'Leonardo Nascimento', 'email' => 'agenciathinkingdigital@gmail.com', 'date' => gmdate('Y-m-d\TH:i:s\Z')],
            'committer' => ['name' => 'Leonardo Nascimento', 'email' => 'agenciathinkingdigital@gmail.com', 'date' => gmdate('Y-m-d\TH:i:s\Z')],
        ];
        $commit = mcpSupportGithubRequestMethod($token, 'POST', $repo . '/git/commits', $commitBody, $transport);
        $commitData = $commit['status'] === 201 ? json_decode($commit['body'], true) : null;
        $commitSha = is_array($commitData) ? ($commitData['sha'] ?? null) : null;
        if (!is_string($commitSha) || preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) {
            throw new RuntimeException('MCP_SUPPORT_GITHUB_WRITE_FAILED');
        }
        mcpSupportGithubAssertGrantActive($pdo, $principal, 'repo.publish');
        $latest = mcpSupportGithubRequest($token, $repo . '/git/ref/heads/DEV', $transport);
        $latestData = $latest['status'] === 200 ? json_decode($latest['body'], true) : null;
        if (($latestData['object']['sha'] ?? null) !== $expectedSha) {
            throw new DomainException('MCP_SUPPORT_GITHUB_HEAD_CHANGED');
        }
        $refUpdate = mcpSupportGithubRequestMethod($token, 'PATCH', $repo . '/git/refs/heads/DEV', ['sha' => $commitSha, 'force' => false], $transport);
        if ($refUpdate['status'] !== 200) {
            throw new RuntimeException('MCP_SUPPORT_GITHUB_WRITE_FAILED');
        }
        $checks = mcpSupportGithubReadChecks($pdo, $principal, $commitSha, $transport, $configuration);
        $finish = $pdo->prepare(
            'UPDATE mcp_support_audit_logs SET commit_sha = :sha, result = :result WHERE id = :id AND result = \'pending\''
        );
        $finish->execute(['sha' => $commitSha, 'result' => 'ok', 'id' => $auditId]);
        return ['status' => 'success', 'branch' => 'DEV', 'commit_sha' => $commitSha, 'ci_status' => $checks['status'], 'checks' => $checks['checks'], 'idempotent_replay' => false];
    } catch (Throwable $exception) {
        $pdo->prepare('UPDATE mcp_support_audit_logs SET result = \'failed\' WHERE id = :id AND result = \'pending\'')->execute(['id' => $auditId]);
        throw $exception;
    }
}

/** @param array<string,mixed> $principal */
function mcpSupportGithubAssertGrantActive(PDO $pdo, array $principal, string $capability): void
{
    $grantId = filter_var($principal['grant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($principal['kind'] ?? null) !== 'support' || $grantId === false) {
        throw new DomainException('MCP_SUPPORT_GITHUB_DENIED');
    }
    $query = $pdo->prepare('SELECT capacidades_json, expira_em, revogado_em FROM mcp_support_grants WHERE id = :id LIMIT 1');
    $query->execute(['id' => $grantId]);
    $grant = $query->fetch(PDO::FETCH_ASSOC);
    $capabilities = is_array($grant) ? json_decode((string) $grant['capacidades_json'], true) : null;
    if (!is_array($grant) || $grant['revogado_em'] !== null || (string) $grant['expira_em'] <= gmdate('Y-m-d H:i:s')
        || !is_array($capabilities) || !in_array($capability, $capabilities, true)) {
        throw new DomainException('MCP_AUTH_INVALID');
    }
}

/** @param array<string,mixed> $principal @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport @param array<string,mixed>|null $configuration @return array{status:string,checks:array<string,string>} */
function mcpSupportGithubReadChecks(PDO $pdo, array $principal, string $commitSha, ?callable $transport = null, ?array $configuration = null): array
{
    mcpSupportGithubAssertGrantActive($pdo, $principal, 'repo.publish');
    $credential = $pdo->query('SELECT token_cifrado FROM mcp_support_github_config WHERE id = 1')->fetchColumn();
    if (!is_string($credential) || $credential === '') throw new DomainException('MCP_SUPPORT_GITHUB_NOT_CONFIGURED');
    $token = aiGlobalConfigOpen($credential, $configuration);
    $response = mcpSupportGithubRequest($token, '/repos/' . MCP_SUPPORT_GITHUB_REPOSITORY . '/commits/' . $commitSha . '/check-runs?per_page=100', $transport);
    $data = $response['status'] === 200 ? json_decode($response['body'], true) : null;
    $checks = [];
    if (is_array($data) && is_array($data['check_runs'] ?? null)) {
        foreach ($data['check_runs'] as $check) {
            if (is_array($check) && is_string($check['name'] ?? null)) {
                $checks[$check['name']] = ($check['status'] ?? null) === 'completed'
                    ? (string) ($check['conclusion'] ?? 'unknown') : (string) ($check['status'] ?? 'unknown');
            }
        }
    }
    $states = array_map(static fn(string $name): string => $checks[$name] ?? 'missing', MCP_SUPPORT_GITHUB_REQUIRED_CHECKS);
    $status = count(array_filter($states, static fn(string $state): bool => $state !== 'success')) === 0
        ? 'success'
        : (count(array_filter($states, static fn(string $state): bool => in_array($state, ['failure', 'cancelled', 'timed_out', 'action_required'], true))) > 0 ? 'failure' : 'pending');
    return ['status' => $status, 'checks' => array_combine(MCP_SUPPORT_GITHUB_REQUIRED_CHECKS, $states) ?: []];
}

/** @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport @return array{status:string,repository:string,dev_head_sha:?string,checks:array<string,string>} */
function mcpSupportGithubTestToken(string $token, ?callable $transport = null): array
{
    $token = trim($token);
    if (strlen($token) < 30 || strlen($token) > 255 || preg_match('/^[A-Za-z0-9_]+$/D', $token) !== 1) {
        return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => null, 'checks' => []];
    }
    $base = '/repos/' . MCP_SUPPORT_GITHUB_REPOSITORY;
    $repository = mcpSupportGithubRequest($token, $base, $transport);
    $repositoryData = $repository['status'] === 200 ? json_decode($repository['body'], true) : null;
    if (!is_array($repositoryData) || strtolower((string) ($repositoryData['full_name'] ?? '')) !== strtolower(MCP_SUPPORT_GITHUB_REPOSITORY)) {
        return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => null, 'checks' => []];
    }

    $protection = mcpSupportGithubRequest($token, $base . '/branches/PROD/protection', $transport);
    $protectionData = $protection['status'] === 200 ? json_decode($protection['body'], true) : null;
    $requiredContexts = is_array($protectionData) && is_array($protectionData['required_status_checks']['contexts'] ?? null)
        ? $protectionData['required_status_checks']['contexts'] : [];
    $pullRequestProtection = $protectionData['required_pull_request_reviews'] ?? null;
    $branchIsProtected = is_array($protectionData)
        && ($protectionData['required_status_checks']['strict'] ?? false) === true
        && array_diff(MCP_SUPPORT_GITHUB_REQUIRED_CHECKS, $requiredContexts) === []
        && ($protectionData['enforce_admins']['enabled'] ?? false) === true
        && is_array($pullRequestProtection)
        && filter_var($pullRequestProtection['required_approving_review_count'] ?? null, FILTER_VALIDATE_INT) !== false
        && ($protectionData['allow_force_pushes']['enabled'] ?? true) === false
        && ($protectionData['allow_deletions']['enabled'] ?? true) === false;
    if (!$branchIsProtected) {
        return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => null, 'checks' => []];
    }

    $reference = mcpSupportGithubRequest($token, $base . '/git/ref/heads/DEV', $transport);
    $referenceData = $reference['status'] === 200 ? json_decode($reference['body'], true) : null;
    $headSha = is_array($referenceData) && is_string($referenceData['object']['sha'] ?? null)
        && preg_match('/^[a-f0-9]{40}$/D', $referenceData['object']['sha']) === 1 ? $referenceData['object']['sha'] : null;
    if ($headSha === null) {
        return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => null, 'checks' => []];
    }
    $checksResponse = mcpSupportGithubRequest($token, $base . '/commits/' . $headSha . '/check-runs?per_page=100', $transport);
    $checksData = $checksResponse['status'] === 200 ? json_decode($checksResponse['body'], true) : null;
    $checks = [];
    if (is_array($checksData) && is_array($checksData['check_runs'] ?? null)) {
        foreach ($checksData['check_runs'] as $check) {
            if (is_array($check) && is_string($check['name'] ?? null)) {
                $checks[$check['name']] = ($check['status'] ?? null) === 'completed'
                    ? (string) ($check['conclusion'] ?? 'unknown') : (string) ($check['status'] ?? 'unknown');
            }
        }
    }
    foreach (MCP_SUPPORT_GITHUB_REQUIRED_CHECKS as $required) {
        if (($checks[$required] ?? null) !== 'success') {
            return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => $headSha, 'checks' => $checks];
        }
    }
    return ['status' => 'success', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'dev_head_sha' => $headSha, 'checks' => $checks];
}

/** @param array<string,mixed> $identity @param array<string,mixed>|null $configuration @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport @return array{status:string,repository:string} */
function mcpSupportGithubSaveTested(PDO $pdo, array $identity, string $token, ?array $configuration = null, ?callable $transport = null): array
{
    $actorId = mcpSupportGithubAssertSuperadmin($pdo, $identity);
    $tested = mcpSupportGithubTestToken($token, $transport);
    if ($tested['status'] !== 'success') {
        mcpSupportGithubAudit($pdo, $actorId, 'github.credential.test', 'failed');
        return ['status' => 'failed', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY];
    }
    $encrypted = aiGlobalConfigSeal(trim($token), $configuration);
    $now = gmdate('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        $existing = $pdo->query('SELECT id FROM mcp_support_github_config WHERE id = 1')->fetchColumn();
        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO mcp_support_github_config (id, token_cifrado, configurado_por_usuario_id, criado_em, atualizado_em, ultimo_teste_status, ultimo_teste_em) '
                . "VALUES (1, :token, :actor, :created, :updated, 'success', :tested)"
            );
            $insert->execute(['token' => $encrypted, 'actor' => $actorId, 'created' => $now, 'updated' => $now, 'tested' => $now]);
        } else {
            $update = $pdo->prepare(
                "UPDATE mcp_support_github_config SET token_cifrado = :token, configurado_por_usuario_id = :actor, atualizado_em = :updated, "
                . "ultimo_teste_status = 'success', ultimo_teste_em = :tested WHERE id = 1"
            );
            $update->execute(['token' => $encrypted, 'actor' => $actorId, 'updated' => $now, 'tested' => $now]);
        }
        mcpSupportGithubAudit($pdo, $actorId, 'github.credential.save', 'ok');
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    return ['status' => 'success', 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY];
}

/** @param array<string,mixed> $identity @return array{configured:bool,repository:string,last_test_status:string|null,last_tested_at:string|null,updated_at:string|null} */
function mcpSupportGithubRead(PDO $pdo, array $identity): array
{
    mcpSupportGithubAssertSuperadmin($pdo, $identity);
    $row = $pdo->query('SELECT atualizado_em, ultimo_teste_status, ultimo_teste_em FROM mcp_support_github_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['configured' => false, 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY, 'last_test_status' => null, 'last_tested_at' => null, 'updated_at' => null];
    }
    return [
        'configured' => true,
        'repository' => MCP_SUPPORT_GITHUB_REPOSITORY,
        'last_test_status' => is_string($row['ultimo_teste_status']) ? $row['ultimo_teste_status'] : null,
        'last_tested_at' => is_string($row['ultimo_teste_em']) ? $row['ultimo_teste_em'] : null,
        'updated_at' => (string) $row['atualizado_em'],
    ];
}

/** @param array<string,mixed> $identity @param array<string,mixed>|null $configuration @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $transport @return array{status:string,repository:string} */
function mcpSupportGithubTestSaved(PDO $pdo, array $identity, ?array $configuration = null, ?callable $transport = null): array
{
    $actorId = mcpSupportGithubAssertSuperadmin($pdo, $identity);
    $stored = $pdo->query('SELECT token_cifrado FROM mcp_support_github_config WHERE id = 1')->fetchColumn();
    if (!is_string($stored) || $stored === '') {
        throw new DomainException('MCP_SUPPORT_GITHUB_NOT_CONFIGURED');
    }
    $token = aiGlobalConfigOpen($stored, $configuration);
    $tested = mcpSupportGithubTestToken($token, $transport);
    $status = $tested['status'];
    $pdo->prepare('UPDATE mcp_support_github_config SET ultimo_teste_status = :status, ultimo_teste_em = :tested WHERE id = 1')
        ->execute(['status' => $status, 'tested' => gmdate('Y-m-d H:i:s')]);
    mcpSupportGithubAudit($pdo, $actorId, 'github.credential.test', $status === 'success' ? 'ok' : 'failed');
    return ['status' => $status, 'repository' => MCP_SUPPORT_GITHUB_REPOSITORY];
}

/** @param array<string,mixed> $identity */
function mcpSupportGithubRemove(PDO $pdo, array $identity): bool
{
    $actorId = mcpSupportGithubAssertSuperadmin($pdo, $identity);
    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM mcp_support_github_config WHERE id = 1');
        $delete->execute();
        if ($delete->rowCount() === 0) {
            $pdo->commit();
            return false;
        }
        mcpSupportGithubAudit($pdo, $actorId, 'github.credential.remove', 'ok');
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function mcpSupportGithubAudit(PDO $pdo, int $actorId, string $tool, string $result): void
{
    $statement = $pdo->prepare(
        'INSERT INTO mcp_support_audit_logs (actor_user_id, tool, result, correlation_id, created_at) '
        . 'VALUES (:actor_id, :tool, :result, :correlation_id, :created_at)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'tool' => $tool,
        'result' => $result,
        'correlation_id' => bin2hex(random_bytes(16)),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}
