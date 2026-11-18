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
