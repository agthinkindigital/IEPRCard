<?php
declare(strict_types=1);

function mcpOauthBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** Reject repeated OAuth form fields before PHP's form parser hides their multiplicity. */
function mcpOauthHasDuplicateParameters(string $body): bool
{
    $seen = [];
    foreach (explode('&', $body) as $field) {
        if ($field === '') {
            continue;
        }
        $name = urldecode(explode('=', $field, 2)[0]);
        if (isset($seen[$name])) {
            return true;
        }
        $seen[$name] = true;
    }
    return false;
}

/** @return list<string> */
function mcpOauthSupportedScopes(): array
{
    return ['batch.dry_run', 'db.inspect', 'design.read', 'system.read', 'telemetry.read'];
}

/** @param array<string, string>|null $configuration */
function mcpOauthPublicBaseUrl(?array $configuration = null): string
{
    $configuration ??= [];
    $base = trim($configuration['MCP_PUBLIC_BASE_URL'] ?? (getenv('MCP_PUBLIC_BASE_URL') ?: ''));
    $parts = parse_url($base);
    $environment = strtolower($configuration['APP_ENV'] ?? (getenv('APP_ENV') ?: 'production'));
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
        || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        || isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)
        || strtolower((string) $parts['scheme']) !== 'https' && !in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('MCP_OAUTH_CONFIGURATION_INVALID');
    }
    $host = tenantResolverNormalizeHost((string) $parts['host']);
    if ($host === null) {
        throw new RuntimeException('MCP_OAUTH_CONFIGURATION_INVALID');
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return strtolower((string) $parts['scheme']) . '://' . $host . $port;
}

/** @param array<string, string>|null $configuration */
function mcpOauthSigningKey(?array $configuration = null): string
{
    $configuration ??= [];
    $encoded = $configuration['MCP_OAUTH_SIGNING_KEY'] ?? (getenv('MCP_OAUTH_SIGNING_KEY') ?: '');
    $key = $encoded === '' ? false : base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) < 32) {
        throw new RuntimeException('MCP_OAUTH_CONFIGURATION_INVALID');
    }
    return $key;
}

/** Atomic fixed-window counter on local private storage; no client secret or raw IP is written. */
function mcpOauthConsumeRateLimit(string $key, int $limit, ?string $directory = null, ?int $now = null): bool
{
    $directory ??= getenv('MCP_RATE_LIMIT_PATH') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-mcp-rate';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }
    if (is_link($directory) || fileowner($directory) !== getmyuid()) {
        return false;
    }
    @chmod($directory, 0700);
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }
    try {
        $now ??= time();
        rewind($handle);
        $current = json_decode((string) stream_get_contents($handle), true);
        $window = intdiv($now, 60);
        $count = is_array($current) && ($current['window'] ?? null) === $window ? (int) ($current['count'] ?? 0) : 0;
        $allowed = $count < $limit;
        if ($allowed) {
            ++$count;
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['window' => $window, 'count' => $count], JSON_THROW_ON_ERROR));
        fflush($handle);
        @chmod($path, 0600);
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
