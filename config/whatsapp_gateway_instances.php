<?php
declare(strict_types=1);

require_once __DIR__ . '/whatsapp_gateway_api.php';

/** @param array<string,mixed> $identity
 *  @return array{id:int,instance_name:string,device_id:string,webhook_url:string}
 */
function whatsappGatewayInstanceProvision(
    PDO $pdo,
    array $identity,
    int $tenantId,
    ?int $subclientId = null,
    ?string $publicBaseUrl = null,
    ?callable $transport = null,
    bool $registrationFlowEnabled = false
): array {
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    if ($tenantId < 1 || ($subclientId !== null && $subclientId < 1)) {
        throw new InvalidArgumentException('Escopo de WhatsApp inválido.');
    }
    $scopeKey = $subclientId === null ? 'tenant:' . $tenantId : 'subclient:' . $subclientId;
    $existing = $pdo->prepare('SELECT id FROM whatsapp_instancias WHERE escopo_chave = :scope LIMIT 1');
    $existing->execute(['scope' => $scopeKey]);
    if ($existing->fetchColumn() !== false) {
        throw new DomainException('WHATSAPP_INSTANCE_ALREADY_EXISTS');
    }

    $tenant = $pdo->prepare(
        "SELECT 1 FROM tenants WHERE id = :tenant AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo'"
    );
    $tenant->execute(['tenant' => $tenantId]);
    if ($tenant->fetchColumn() === false) {
        throw new DomainException('WHATSAPP_TENANT_UNAVAILABLE');
    }
    if ($subclientId !== null) {
        $unit = $pdo->prepare(
            "SELECT 1 FROM tenants WHERE id = :unit AND parent_id = :tenant AND tipo = 'subcliente' AND status_conta = 'ativo'"
        );
        $unit->execute(['unit' => $subclientId, 'tenant' => $tenantId]);
        if ($unit->fetchColumn() === false) {
            throw new DomainException('WHATSAPP_TENANT_UNAVAILABLE');
        }
    }

    $configuration = $pdo->query(
        "SELECT api_base_url, api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($configuration)) {
        throw new DomainException('WHATSAPP_GOWA_NOT_CONFIGURED');
    }
    $apiToken = aiGlobalConfigOpen((string) $configuration['api_token_cifrado']);
    $publicBaseUrl = whatsappGatewayValidatePublicBaseUrl(
        $publicBaseUrl ?? (string) (getenv('APP_BASE_URL') ?: '')
    );
    $instanceName = 'ieprcard-' . bin2hex(random_bytes(8));
    $webhookToken = bin2hex(random_bytes(32));
    $webhookUrl = $publicBaseUrl . '/webhook-gowa.php?token=' . $webhookToken;
    $cronUrl = $publicBaseUrl . '/webhook-gowa-validate.php';
    $now = gmdate('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        "INSERT INTO whatsapp_instancias "
        . "(tenant_id, subcliente_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, fluxo_cadastro_ativo, estado, criado_em, atualizado_em) "
        . "VALUES (:tenant, :subclient, :scope, :name, NULL, :token_hash, :flow_enabled, 'provisionando', :created, :updated)"
    );
    $insert->execute([
        'tenant' => $tenantId,
        'subclient' => $subclientId,
        'scope' => $scopeKey,
        'name' => $instanceName,
        'token_hash' => hash('sha256', $webhookToken),
        'flow_enabled' => $registrationFlowEnabled ? 1 : 0,
        'created' => $now,
        'updated' => $now,
    ]);
    $instanceId = (int) $pdo->lastInsertId();

    try {
        $apiResult = whatsappGatewayApiRequest(
            (string) $configuration['api_base_url'],
            $apiToken,
            'criar_instancia',
            [
                'apelido' => 'IEPRCard ' . $instanceName,
                'webhook_url' => $webhookUrl,
                'cron_url' => $cronUrl,
                'nome_instancia_destino' => $instanceName,
            ],
            $transport,
            20
        );
        $deviceId = is_string($apiResult['device_id'] ?? null) ? trim($apiResult['device_id']) : '';
        if (($apiResult['ok'] ?? false) !== true || $deviceId === '' || strlen($deviceId) > 180) {
            throw new RuntimeException('WHATSAPP_GOWA_INSTANCE_CREATE_FAILED');
        }
        $update = $pdo->prepare(
            "UPDATE whatsapp_instancias SET device_id = :device, estado = 'ativa', atualizado_em = :updated "
            . "WHERE id = :id AND estado = 'provisionando'"
        );
        $update->execute(['device' => $deviceId, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $instanceId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('WHATSAPP_INSTANCE_LOCAL_PERSIST_FAILED');
        }
    } catch (Throwable $exception) {
        $failed = $pdo->prepare(
            "UPDATE whatsapp_instancias SET estado = 'falha', atualizado_em = :updated "
            . "WHERE id = :id AND estado = 'provisionando'"
        );
        $failed->execute(['updated' => gmdate('Y-m-d H:i:s'), 'id' => $instanceId]);
        throw $exception;
    }

    return [
        'id' => $instanceId,
        'instance_name' => $instanceName,
        'device_id' => $deviceId,
        'webhook_url' => $webhookUrl,
    ];
}

/** Rotate the bearer secret and update the external instance before returning its URL.
 *  @param array<string,mixed> $identity
 *  @return array{webhook_url:string}
 */
function whatsappGatewayRotateInstanceWebhook(
    PDO $pdo,
    array $identity,
    int $instanceId,
    ?string $publicBaseUrl = null,
    ?callable $transport = null
): array {
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    $query = $pdo->prepare(
        "SELECT i.device_id, i.nome_instancia, c.api_base_url, c.api_token_cifrado "
        . "FROM whatsapp_instancias i CROSS JOIN whatsapp_config_global c "
        . "WHERE i.id = :id AND i.estado = 'ativa' AND c.chave = 'global' LIMIT 1"
    );
    $query->execute(['id' => $instanceId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !is_string($row['device_id']) || $row['device_id'] === '') {
        throw new DomainException('WHATSAPP_INSTANCE_UNAVAILABLE');
    }
    $baseUrl = whatsappGatewayValidatePublicBaseUrl($publicBaseUrl ?? (string) (getenv('APP_BASE_URL') ?: ''));
    $token = bin2hex(random_bytes(32));
    $webhookUrl = $baseUrl . '/webhook-gowa.php?token=' . $token;
    $response = whatsappGatewayApiRequest(
        (string) $row['api_base_url'],
        aiGlobalConfigOpen((string) $row['api_token_cifrado']),
        'atualizar_instancia',
        [
            'device_id' => (string) $row['device_id'],
            'webhook_url' => $webhookUrl,
            'cron_url' => $baseUrl . '/webhook-gowa-validate.php',
        ],
        $transport,
        20
    );
    if (($response['ok'] ?? false) !== true) {
        throw new RuntimeException('WHATSAPP_GOWA_INSTANCE_UPDATE_FAILED');
    }
    $update = $pdo->prepare(
        "UPDATE whatsapp_instancias SET webhook_token_hash = :token_hash, atualizado_em = :updated "
        . "WHERE id = :id AND estado = 'ativa'"
    );
    $update->execute(['token_hash' => hash('sha256', $token), 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $instanceId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('WHATSAPP_INSTANCE_LOCAL_UPDATE_FAILED');
    }
    return ['webhook_url' => $webhookUrl];
}
