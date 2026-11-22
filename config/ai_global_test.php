<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';

/** @param array<string,mixed> $identity
 *  @param callable(string,array<string,string>,array<string,mixed>):array{status:int,body:string}|null $transport
 *  @return array{status:'success'|'failed',error_code:?string}
 */
function aiGlobalConfigTestProvider(PDO $pdo, array $identity, string $provider, ?callable $transport = null): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    aiGlobalConfigAssertProvider($provider);
    $query = $pdo->prepare('SELECT chave_cifrada, modelo FROM ia_config_global WHERE provedor = :provider LIMIT 1');
    $query->execute(['provider' => $provider]);
    $config = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($config)) {
        throw new DomainException('AI_PROVIDER_NOT_CONFIGURED');
    }

    $apiKey = aiGlobalConfigOpen((string) $config['chave_cifrada']);
    return aiGlobalConfigTestCredentials($provider, $apiKey, (string) $config['modelo'], $transport);
}

/** @param callable(string,array<string,string>,array<string,mixed>):array{status:int,body:string}|null $transport
 *  @return array{status:'success'|'failed',error_code:?string}
 */
function aiGlobalConfigTestCredentials(string $provider, string $apiKey, string $model, ?callable $transport = null): array
{
    aiGlobalConfigAssertProvider($provider);
    if ($apiKey === '' || strlen($apiKey) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $apiKey) === 1
        || $model === '' || strlen($model) > 120 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $model) !== 1) {
        return ['status' => 'failed', 'error_code' => 'invalid_configuration'];
    }
    $error = null;
    try {
        [$url, $headers, $payload] = aiGlobalConfigTestRequest($provider, $apiKey, $model);
        $response = $transport === null
            ? aiGlobalConfigTestCurl($url, $headers, $payload)
            : $transport($url, $headers, $payload);
        $body = $response['body'] ?? null;
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300
            || !is_string($body) || strlen($body) > 65536 || !aiGlobalConfigTestResponseValid($provider, $body)) {
            throw new RuntimeException('AI_TEST_FAILED');
        }
    } catch (Throwable $exception) {
        $error = match ($exception->getMessage()) {
            'AI_CONFIG_KEY_UNAVAILABLE', 'AI_CONFIG_KEY_INVALID', 'AI_CONFIG_SECRET_UNAVAILABLE' => 'encryption_key_unavailable',
            'AI_TEST_TRANSPORT_UNAVAILABLE' => 'transport_unavailable',
            default => 'connection_failed',
        };
    }

    return ['status' => $error === null ? 'success' : 'failed', 'error_code' => $error];
}

/** @return array{string,array<string,string>,array<string,mixed>} */
function aiGlobalConfigTestRequest(string $provider, string $key, string $model): array
{
    $sample = 'IEPRCard synthetic connection check';
    if ($provider === 'openai') {
        return ['https://api.openai.com/v1/chat/completions', ['Authorization' => 'Bearer ' . $key], [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Reply with exactly the word OK.'],
                ['role' => 'user', 'content' => $sample],
            ],
            'max_tokens' => 5,
            'temperature' => 0,
        ]];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    return [$url, ['x-goog-api-key' => $key], [
        'systemInstruction' => ['parts' => [['text' => 'Reply with exactly the word OK.']]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $sample]]]],
        'generationConfig' => ['maxOutputTokens' => 5, 'temperature' => 0],
    ]];
}

/** @param array<string,string> $headers @param array<string,mixed> $payload
 *  @return array{status:int,body:string}
 */
function aiGlobalConfigTestCurl(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('AI_TEST_TRANSPORT_UNAVAILABLE');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('AI_TEST_TRANSPORT_UNAVAILABLE');
    }
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded, CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

function aiGlobalConfigTestResponseValid(string $provider, string $body): bool
{
    $response = json_decode($body, true);
    $text = $provider === 'openai'
        ? ($response['choices'][0]['message']['content'] ?? null)
        : ($response['candidates'][0]['content']['parts'][0]['text'] ?? null);
    return is_string($text) && trim($text) !== '';
}
