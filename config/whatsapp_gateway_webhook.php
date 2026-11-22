<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';

/** Validate the GOWA license keepalive without persisting its payload.
 *  @param array<string,mixed> $payload
 */
function whatsappGatewayValidateCronPing(PDO $pdo, array $payload): void
{
    $providedToken = $payload['token'] ?? null;
    $users = $payload['usuarios'] ?? null;
    if (!is_string($providedToken) || $providedToken === '' || strlen($providedToken) > 4096
        || !is_array($users) || !array_is_list($users) || count($users) > 10000) {
        throw new DomainException('WHATSAPP_GOWA_PING_INVALID');
    }
    foreach ($users as $user) {
        if (!is_string($user) || $user === '' || strlen($user) > 120 || preg_match('/[\x00-\x1f\x7f]/', $user) === 1) {
            throw new DomainException('WHATSAPP_GOWA_PING_INVALID');
        }
    }
    $stored = $pdo->query(
        "SELECT api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetchColumn();
    if (!is_string($stored)) {
        throw new RuntimeException('WHATSAPP_GOWA_NOT_CONFIGURED');
    }
    $expectedToken = aiGlobalConfigOpen($stored);
    if (!hash_equals($expectedToken, $providedToken)) {
        throw new DomainException('WHATSAPP_GOWA_PING_UNAUTHORIZED');
    }
}

/** @return array{id:int,tenant_id:int,subcliente_id:?int,nome_instancia:string,device_id:?string,estado:string} */
function whatsappGatewayResolveWebhookInstance(PDO $pdo, string $token): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new DomainException('WHATSAPP_WEBHOOK_UNAUTHORIZED');
    }
    $statement = $pdo->prepare(
        "SELECT i.id, i.tenant_id, i.subcliente_id, i.nome_instancia, i.device_id, i.estado "
        . "FROM whatsapp_instancias i JOIN tenants root ON root.id = i.tenant_id "
        . "AND root.parent_id IS NULL AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . "LEFT JOIN tenants unit ON unit.id = i.subcliente_id AND unit.parent_id = i.tenant_id "
        . "AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
        . "WHERE i.webhook_token_hash = :hash AND i.estado = 'ativa' "
        . "AND (i.subcliente_id IS NULL OR unit.id IS NOT NULL) LIMIT 2"
    );
    $statement->execute(['hash' => hash('sha256', $token)]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        throw new DomainException('WHATSAPP_WEBHOOK_UNAUTHORIZED');
    }
    $row = $rows[0];
    return [
        'id' => (int) $row['id'],
        'tenant_id' => (int) $row['tenant_id'],
        'subcliente_id' => $row['subcliente_id'] === null ? null : (int) $row['subcliente_id'],
        'nome_instancia' => (string) $row['nome_instancia'],
        'device_id' => $row['device_id'] === null ? null : (string) $row['device_id'],
        'estado' => (string) $row['estado'],
    ];
}

/** Validate and deduplicate an event without persisting its body, phone, media or text.
 *  @param array<string,mixed> $payload
 *  @return array{instance:array<string,mixed>,event_hash:string,duplicate:bool,payload:array<string,mixed>}
 */
function whatsappGatewayReceiveEvent(PDO $pdo, string $token, array $payload, ?int $timestamp = null): array
{
    $instance = whatsappGatewayResolveWebhookInstance($pdo, $token);
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $messageId = $payload['id_mensagem'] ?? null;
    $phone = $payload['telefone'] ?? '';
    $text = $payload['texto'] ?? '';
    $media = $payload['media'] ?? null;
    $type = $payload['tipo'] ?? 'unknown';
    $fromMe = $payload['is_from_me'] ?? false;
    $group = $payload['grupo'] ?? false;
    if (!is_string($encodedPayload) || strlen($encodedPayload) > 262144
        || !is_string($payload['usuario'] ?? null)
        || !hash_equals($instance['nome_instancia'], trim($payload['usuario']))
        || !is_string($messageId) || trim($messageId) === '' || strlen($messageId) > 160
        || preg_match('/[\x00-\x1f\x7f]/', $messageId) === 1
        || !is_string($phone) || strlen($phone) > 80 || preg_match('/[\x00-\x1f\x7f]/', $phone) === 1
        || !is_string($text)
        || !is_array($media) && $media !== null
        || !is_string($type) || $type === '' || strlen($type) > 64
        || preg_match('/[\x00-\x1f\x7f]/', $type) === 1
        || !is_bool($fromMe) || !is_bool($group)) {
        throw new DomainException('WHATSAPP_WEBHOOK_PAYLOAD_INVALID');
    }
    $eventHash = hash_hmac(
        'sha256',
        $instance['id'] . ':' . trim($messageId),
        aiGlobalConfigEncryptionKey()
    );
    $now = gmdate('Y-m-d H:i:s', $timestamp ?? time());
    whatsappGatewayApplyWebhookRateLimit($pdo, $instance['id'], $timestamp);

    try {
        $insert = $pdo->prepare(
            "INSERT INTO webhook_gowa_eventos (instancia_id, evento_hash, estado, recebido_em, tentativas) "
            . "VALUES (:instance_id, :event_hash, 'recebido', :received_at, 0)"
        );
        $insert->execute(['instance_id' => $instance['id'], 'event_hash' => $eventHash, 'received_at' => $now]);
        $duplicate = false;
    } catch (PDOException $exception) {
        if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            throw $exception;
        }
        $find = $pdo->prepare(
            'SELECT id FROM webhook_gowa_eventos WHERE instancia_id = :instance_id AND evento_hash = :event_hash LIMIT 1'
        );
        $find->execute(['instance_id' => $instance['id'], 'event_hash' => $eventHash]);
        if ($find->fetchColumn() === false) {
            throw $exception;
        }
        $duplicate = true;
    }

    return [
        'instance' => $instance,
        'event_hash' => $eventHash,
        'duplicate' => $duplicate,
        'payload' => [
            'id_mensagem' => trim($messageId),
            'usuario' => $instance['nome_instancia'],
            'telefone' => $phone,
            'texto' => $text,
            'media' => $media,
            'tipo' => $type,
            'is_from_me' => $fromMe,
            'grupo' => $group,
        ],
    ];
}

/** Process an ephemeral event once; failed or timed-out work may be retried safely.
 *  @param array<string,mixed> $payload
 *  @param callable(array<string,mixed>,array<string,mixed>):void $consumer
 *  @return array{accepted:bool,duplicate:bool,processed:bool}
 */
function whatsappGatewayProcessWebhook(PDO $pdo, string $token, array $payload, callable $consumer, ?int $timestamp = null): array
{
    $event = whatsappGatewayReceiveEvent($pdo, $token, $payload, $timestamp);
    if (!whatsappGatewayClaimEvent($pdo, $event['instance']['id'], $event['event_hash'], $timestamp)) {
        return ['accepted' => true, 'duplicate' => true, 'processed' => false];
    }
    try {
        $consumer($event['instance'], $event['payload']);
        whatsappGatewayMarkEventProcessed($pdo, $event['instance']['id'], $event['event_hash'], $timestamp);
    } catch (Throwable $exception) {
        whatsappGatewayMarkEventFailed($pdo, $event['instance']['id'], $event['event_hash']);
        throw $exception;
    }
    return ['accepted' => true, 'duplicate' => $event['duplicate'], 'processed' => true];
}

function whatsappGatewayClaimEvent(PDO $pdo, int $instanceId, string $eventHash, ?int $timestamp = null): bool
{
    $nowTimestamp = $timestamp ?? time();
    $statement = $pdo->prepare(
        "UPDATE webhook_gowa_eventos SET estado = 'processando', processando_em = :now, tentativas = tentativas + 1 "
        . "WHERE instancia_id = :instance_id AND evento_hash = :event_hash AND (estado IN ('recebido','falha') "
        . "OR (estado = 'processando' AND processando_em < :stale))"
    );
    $statement->execute([
        'now' => gmdate('Y-m-d H:i:s', $nowTimestamp),
        'instance_id' => $instanceId,
        'event_hash' => $eventHash,
        'stale' => gmdate('Y-m-d H:i:s', $nowTimestamp - 300),
    ]);
    return $statement->rowCount() === 1;
}

function whatsappGatewayMarkEventFailed(PDO $pdo, int $instanceId, string $eventHash): void
{
    $statement = $pdo->prepare(
        "UPDATE webhook_gowa_eventos SET estado = 'falha', processando_em = NULL "
        . "WHERE instancia_id = :instance_id AND evento_hash = :event_hash AND estado = 'processando'"
    );
    $statement->execute(['instance_id' => $instanceId, 'event_hash' => $eventHash]);
}

function whatsappGatewayApplyWebhookRateLimit(PDO $pdo, int $instanceId, ?int $timestamp = null): void
{
    $since = gmdate('Y-m-d H:i:s', ($timestamp ?? time()) - 60);
    $perInstance = $pdo->prepare(
        'SELECT COUNT(*) FROM webhook_gowa_eventos WHERE instancia_id = :instance_id AND recebido_em >= :since'
    );
    $perInstance->execute(['instance_id' => $instanceId, 'since' => $since]);
    $global = $pdo->prepare('SELECT COUNT(*) FROM webhook_gowa_eventos WHERE recebido_em >= :since');
    $global->execute(['since' => $since]);
    if ((int) $perInstance->fetchColumn() >= 120 || (int) $global->fetchColumn() >= 1200) {
        throw new DomainException('WHATSAPP_WEBHOOK_RATE_LIMITED');
    }
}

function whatsappGatewayMarkEventProcessed(PDO $pdo, int $instanceId, string $eventHash, ?int $timestamp = null): void
{
    if ($instanceId < 1 || preg_match('/^[a-f0-9]{64}$/D', $eventHash) !== 1) {
        throw new InvalidArgumentException('Evento GOWA inválido.');
    }
    $statement = $pdo->prepare(
        "UPDATE webhook_gowa_eventos SET estado = 'processado', processado_em = :processed, processando_em = NULL "
        . "WHERE instancia_id = :instance_id AND evento_hash = :event_hash AND estado = 'processando'"
    );
    $statement->execute([
        'processed' => gmdate('Y-m-d H:i:s', $timestamp ?? time()),
        'instance_id' => $instanceId,
        'event_hash' => $eventHash,
    ]);
}
