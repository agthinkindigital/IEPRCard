<?php
declare(strict_types=1);

require_once __DIR__ . '/whatsapp_gateway_urls.php';

/** @param array<string,mixed> $parameters
 *  @param callable(string,string,array<string,mixed>):array{status:int,body:string}|null $transport
 *  @return array<string,mixed>
 */
function whatsappGatewayApiRequest(
    string $apiBaseUrl,
    string $apiToken,
    string $action,
    array $parameters = [],
    ?callable $transport = null,
    int $timeoutSeconds = 5
): array {
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D', $action) !== 1
        || $apiToken === '' || strlen($apiToken) > 4096) {
        throw new InvalidArgumentException('Requisição GOWA inválida.');
    }
    $apiBaseUrl = whatsappGatewayValidateBaseUrl($apiBaseUrl);
    $url = rtrim($apiBaseUrl, '/') . '/api.php?acao=' . rawurlencode($action);
    $body = json_encode($parameters + ['token' => $apiToken], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $response = $transport !== null
        ? $transport($url, $body, ['Content-Type' => 'application/json'])
        : whatsappGatewayCurlRequest($url, $body, $timeoutSeconds);
    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300
        || !is_string($response['body'] ?? null)) {
        throw new RuntimeException('WHATSAPP_GOWA_CONNECTION_FAILED');
    }
    $decoded = json_decode($response['body'], true, 32);
    if (!is_array($decoded)) {
        throw new RuntimeException('WHATSAPP_GOWA_CONNECTION_FAILED');
    }
    return $decoded;
}

/** @return array{status:int,body:string} */
function whatsappGatewayCurlRequest(string $url, string $body, int $timeoutSeconds = 5): array
{
    if (!function_exists('curl_init') || $timeoutSeconds < 1 || $timeoutSeconds > 30) {
        throw new RuntimeException('WHATSAPP_GOWA_TRANSPORT_UNAVAILABLE');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('WHATSAPP_GOWA_TRANSPORT_UNAVAILABLE');
    }
    $parts = parse_url($url);
    $localHttp = is_array($parts)
        && strtolower((string) ($parts['scheme'] ?? '')) === 'http'
        && in_array(trim(strtolower((string) ($parts['host'] ?? '')), '[]'), ['localhost', '127.0.0.1', '::1'], true)
        && in_array(strtolower(getenv('APP_ENV') ?: 'production'), ['dev', 'test'], true);
    $allowedProtocols = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0;
    if ($localHttp && defined('CURLPROTO_HTTP')) {
        $allowedProtocols |= CURLPROTO_HTTP;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => $allowedProtocols,
        CURLOPT_REDIR_PROTOCOLS => $allowedProtocols,
    ]);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($responseBody) || strlen($responseBody) > 262144) {
        throw new RuntimeException('WHATSAPP_GOWA_CONNECTION_FAILED');
    }
    return ['status' => $status, 'body' => $responseBody];
}
