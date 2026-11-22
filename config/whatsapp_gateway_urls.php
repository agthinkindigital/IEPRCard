<?php
declare(strict_types=1);

function whatsappGatewayValidateBaseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new InvalidArgumentException('URL da API GOWA inválida.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))) {
        throw new InvalidArgumentException('URL da API GOWA inválida.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $isLoopback = in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
    $environment = strtolower(getenv('APP_ENV') ?: 'production');
    if (($scheme !== 'https' && !($scheme === 'http' && $isLoopback && in_array($environment, ['dev', 'test'], true)))
        || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false && !$isLoopback
        || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $host) !== 1
        || preg_match('#(?:^|/)\.\.?(/|$)|[\\%]#', (string) ($parts['path'] ?? '')) === 1) {
        throw new InvalidArgumentException('URL da API GOWA inválida. Use HTTPS e um host público.');
    }
    return rtrim($url, '/');
}

function whatsappGatewayValidatePublicBaseUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new InvalidArgumentException('APP_BASE_URL precisa ser HTTPS pública (HTTP local só em dev/test).');
    }
    $environment = strtolower(getenv('APP_ENV') ?: 'production');
    $host = strtolower((string) ($parts['host'] ?? ''));
    $isLoopback = in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        || ($scheme !== 'https' && !($scheme === 'http' && $isLoopback && in_array($environment, ['dev', 'test'], true)))
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))
        || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $host) !== 1
        || ($environment === 'production' && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false)) {
        throw new InvalidArgumentException('APP_BASE_URL precisa ser HTTPS pública (HTTP local só em dev/test).');
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('/^(?:[A-Za-z0-9._~-]+\/?)*$/D', ltrim($path, '/')) !== 1
        || str_contains($path, '..') || str_contains($path, '%')) {
        throw new InvalidArgumentException('APP_BASE_URL inválida.');
    }
    return $url;
}
