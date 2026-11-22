<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';

/** @param array<string, mixed> $identity */
function whatsappGatewayConfigSave(PDO $pdo, array $identity, string $apiBaseUrl, string $apiToken): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    $apiBaseUrl = whatsappGatewayValidateBaseUrl($apiBaseUrl);
    $apiToken = trim($apiToken);
    if ($apiToken !== '' && (strlen($apiToken) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $apiToken) === 1)) {
        throw new InvalidArgumentException('Credencial GOWA inválida.');
    }

    $existing = $pdo->prepare("SELECT api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global'");
    $existing->execute();
    $storedToken = $existing->fetchColumn();
    $isConfigured = is_string($storedToken);
    if ($apiToken === '' && !is_string($storedToken)) {
        throw new InvalidArgumentException('Informe o token de acesso GOWA.');
    }
    $sealedToken = $apiToken !== '' ? aiGlobalConfigSeal($apiToken) : (string) $storedToken;
    $now = gmdate('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            "UPDATE whatsapp_config_global SET api_base_url = :url, api_token_cifrado = :token, "
            . "ultimo_teste_status = NULL, ultimo_teste_em = NULL, ultimo_erro_codigo = NULL, atualizado_em = :updated "
            . "WHERE chave = 'global'"
        );
        $update->execute(['url' => $apiBaseUrl, 'token' => $sealedToken, 'updated' => $now]);
        if (!$isConfigured) {
            $insert = $pdo->prepare(
                "INSERT INTO whatsapp_config_global "
                . "(chave, api_base_url, api_token_cifrado, criado_em, atualizado_em) "
                . "VALUES ('global', :url, :token, :created, :updated)"
            );
            $insert->execute(['url' => $apiBaseUrl, 'token' => $sealedToken, 'created' => $now, 'updated' => $now]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return whatsappGatewayConfigRead($pdo, $identity);
}

/** @param array<string, mixed> $identity
 *  @return array{configured:bool,api_base_url:?string,token_configured:bool,last_test_status:?string,last_test_at:?string,last_error_code:?string}
 */
function whatsappGatewayConfigRead(PDO $pdo, array $identity): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    $row = $pdo->query(
        "SELECT api_base_url, api_token_cifrado, ultimo_teste_status, ultimo_teste_em, ultimo_erro_codigo "
        . "FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'configured' => false,
            'api_base_url' => null,
            'token_configured' => false,
            'last_test_status' => null,
            'last_test_at' => null,
            'last_error_code' => null,
        ];
    }

    return [
        'configured' => true,
        'api_base_url' => (string) $row['api_base_url'],
        'token_configured' => true,
        'last_test_status' => $row['ultimo_teste_status'] === null ? null : (string) $row['ultimo_teste_status'],
        'last_test_at' => $row['ultimo_teste_em'] === null ? null : (string) $row['ultimo_teste_em'],
        'last_error_code' => $row['ultimo_erro_codigo'] === null ? null : (string) $row['ultimo_erro_codigo'],
    ];
}

/** @param array<string, mixed> $identity
 *  @param callable(string,string,array<string,mixed>):array{status:int,body:string}|null $transport
 *  @return array{status:'success'|'failed',error_code:?string}
 */
function whatsappGatewayConfigTest(PDO $pdo, array $identity, ?callable $transport = null): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    $row = $pdo->query(
        "SELECT api_base_url, api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new DomainException('WHATSAPP_GOWA_NOT_CONFIGURED');
    }

    $errorCode = null;
    try {
        $token = aiGlobalConfigOpen((string) $row['api_token_cifrado']);
        $response = whatsappGatewayApiRequest(
            (string) $row['api_base_url'],
            $token,
            'StatusInstancias',
            [],
            $transport
        );
        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException('WHATSAPP_GOWA_CONNECTION_FAILED');
        }
    } catch (Throwable $exception) {
        $errorCode = match ($exception->getMessage()) {
            'AI_CONFIG_KEY_UNAVAILABLE', 'AI_CONFIG_KEY_INVALID', 'AI_CONFIG_SECRET_UNAVAILABLE' => 'encryption_key_unavailable',
            'WHATSAPP_GOWA_TRANSPORT_UNAVAILABLE' => 'transport_unavailable',
            default => 'connection_failed',
        };
    }

    $now = gmdate('Y-m-d H:i:s');
    $update = $pdo->prepare(
        "UPDATE whatsapp_config_global SET ultimo_teste_status = :status, ultimo_teste_em = :tested, "
        . 'ultimo_erro_codigo = :error, atualizado_em = :updated WHERE chave = \'global\''
    );
    $update->execute([
        'status' => $errorCode === null ? 'success' : 'failed',
        'tested' => $now,
        'error' => $errorCode,
        'updated' => $now,
    ]);

    return ['status' => $errorCode === null ? 'success' : 'failed', 'error_code' => $errorCode];
}

require_once __DIR__ . '/whatsapp_gateway_instances.php';
