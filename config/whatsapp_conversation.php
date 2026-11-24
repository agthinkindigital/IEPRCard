<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';
require_once __DIR__ . '/whatsapp_gateway_api.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/titular_privacy_keys.php';
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/titular_provisioning.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/card_issuance_worker_storage.php';
require_once __DIR__ . '/card_issuance.php';
require_once __DIR__ . '/whatsapp_conversation_registration.php';
require_once __DIR__ . '/whatsapp_conversation_state.php';
require_once __DIR__ . '/whatsapp_conversation_ai.php';
require_once __DIR__ . '/whatsapp_conversation_delivery.php';
require_once __DIR__ . '/whatsapp_conversation_confirm.php';
require_once __DIR__ . '/whatsapp_conversation_flow.php';

const WHATSAPP_CONVERSATION_NOTICE_VERSION = '2026-11-22.v1';
const WHATSAPP_CONVERSATION_PURPOSE = 'cadastro_titular_emissao_carteirinha';
const WHATSAPP_CONVERSATION_TTL_SECONDS = 86400;

/** @param array<string,mixed> $instance @param array<string,mixed> $message
 *  @param callable(array<string,mixed>,string,string):void|null $reply
 *  @param callable(string,array<string,mixed>):array<string,mixed>|null $interpreter
 */
function whatsappConversationHandleIncoming(
    PDO $pdo,
    array $instance,
    array $message,
    ?callable $reply = null,
    ?callable $interpreter = null,
    ?int $now = null,
    ?string $correlationId = null
): void {
    $instanceId = filter_var($instance['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($instance['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $subclientId = ($instance['subcliente_id'] ?? null) === null
        ? null
        : filter_var($instance['subcliente_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($instanceId === false || $tenantId === false || $subclientId === false) {
        throw new DomainException('WHATSAPP_CONVERSATION_UNAVAILABLE');
    }
    $scope = $pdo->prepare(
        "SELECT i.id, i.tenant_id, i.subcliente_id, i.nome_instancia, i.device_id, i.estado, i.fluxo_cadastro_ativo "
        . "FROM whatsapp_instancias i JOIN tenants root ON root.id = i.tenant_id "
        . "AND root.parent_id IS NULL AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . "LEFT JOIN tenants unit ON unit.id = i.subcliente_id AND unit.parent_id = i.tenant_id "
        . "AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
        . "WHERE i.id = :id AND i.tenant_id = :tenant "
        . "AND ((i.subcliente_id IS NULL AND :subclient_null IS NULL) OR (i.subcliente_id = :subclient AND unit.id IS NOT NULL)) LIMIT 1"
    );
    $scope->execute([
        'id' => $instanceId, 'tenant' => $tenantId,
        'subclient_null' => $subclientId, 'subclient' => $subclientId,
    ]);
    $resolvedInstance = $scope->fetch(PDO::FETCH_ASSOC);
    if (!is_array($resolvedInstance)) {
        throw new DomainException('WHATSAPP_CONVERSATION_UNAVAILABLE');
    }
    if ($resolvedInstance['estado'] !== 'ativa' || (int) $resolvedInstance['fluxo_cadastro_ativo'] !== 1) {
        return;
    }
    if (($message['is_from_me'] ?? false) === true || ($message['grupo'] ?? false) === true) {
        return;
    }
    $phone = $message['telefone'] ?? null;
    if (!is_string($phone) || $phone === '') {
        throw new DomainException('WHATSAPP_CONVERSATION_MESSAGE_INVALID');
    }
    try {
        $normalizedPhone = phoneNormalizeBr($phone);
    } catch (InvalidArgumentException) {
        throw new DomainException('WHATSAPP_CONVERSATION_MESSAGE_INVALID');
    }
    $key = aiGlobalConfigEncryptionKey();
    $contactHash = hash_hmac('sha256', (string) $instanceId . ':' . $normalizedPhone, $key);
    $now ??= time();
    $stamp = gmdate('Y-m-d H:i:s', $now);
    $expiresAt = gmdate('Y-m-d H:i:s', $now + WHATSAPP_CONVERSATION_TTL_SECONDS);
    $provider = whatsappConversationActiveProvider($pdo);

    $pdo->prepare('DELETE FROM whatsapp_conversas WHERE contato_hmac = :contact AND expira_em < :now AND titular_id IS NULL')
        ->execute(['contact' => $contactHash, 'now' => $stamp]);
    $find = $pdo->prepare(
        'SELECT id, tenant_id, subcliente_id, estado, aviso_versao, consentimento_em, revogado_em, titular_id, job_id, dados_cifrados '
        . 'FROM whatsapp_conversas WHERE instancia_id = :instance AND contato_hmac = :contact AND expira_em >= :now LIMIT 1'
    );
    $find->execute(['instance' => $instanceId, 'contact' => $contactHash, 'now' => $stamp]);
    $conversation = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation)) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO whatsapp_conversas (instancia_id, tenant_id, subcliente_id, contato_hmac, estado, expira_em, criado_em, atualizado_em) '
                . "VALUES (:instance, :tenant, :subclient, :contact, 'aguardando_consentimento', :expires, :created, :updated)"
            );
            $insert->execute([
                'instance' => $instanceId, 'tenant' => $tenantId, 'subclient' => $subclientId,
                'contact' => $contactHash, 'expires' => $expiresAt, 'created' => $stamp, 'updated' => $stamp,
            ]);
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $exception;
        }
        $find->execute(['instance' => $instanceId, 'contact' => $contactHash, 'now' => $stamp]);
        $conversation = $find->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($conversation)) {
        throw new RuntimeException('WHATSAPP_CONVERSATION_STATE_UNAVAILABLE');
    }
    privacySubjectEnsureForRecord($pdo, 'whatsapp_conversa', (int) $conversation['id'], (int) $tenantId);

    if ($conversation['consentimento_em'] !== null && $conversation['revogado_em'] === null
        && whatsappConversationCommand((string) ($message['texto'] ?? '')) === 'SAIR') {
        whatsappConversationRevoke($pdo, $conversation, $stamp, $resolvedInstance, $normalizedPhone, $reply);
        return;
    }

    if ($conversation['estado'] === 'aguardando_consentimento') {
        $command = whatsappConversationCommand((string) ($message['texto'] ?? ''));
        if (in_array($command, ['ACEITO', 'ACEITAR', 'CONCORDO', 'SIM'], true)) {
            $consent = $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'menu_principal', aviso_versao = :version, "
                . 'consentimento_finalidade = :purpose, consentimento_em = :consented, dados_cifrados = :data, '
                . 'expira_em = :expires, atualizado_em = :updated '
                . "WHERE id = :id AND estado = 'aguardando_consentimento' AND expira_em >= :now"
            );
            $consent->execute([
                'version' => WHATSAPP_CONVERSATION_NOTICE_VERSION,
                'purpose' => WHATSAPP_CONVERSATION_PURPOSE,
                'consented' => $stamp,
                'data' => whatsappConversationSeal('{}'),
                'expires' => $expiresAt,
                'updated' => $stamp,
                'id' => (int) $conversation['id'],
                'now' => $stamp,
            ]);
            if ($consent->rowCount() === 1) {
                whatsappConversationSend(
                    $pdo,
                    $resolvedInstance,
                    $normalizedPhone,
                    'Consentimento registrado. Para iniciar o cadastro individual, responda 1. Você poderá encerrar a qualquer momento enviando SAIR.',
                    $reply
                );
            }
            return;
        }
        if (in_array($command, ['SAIR', 'NAO', 'NAO ACEITO', 'RECUSO'], true)) {
            $refusal = $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'recusado', contato_hmac = NULL, dados_cifrados = NULL, atualizado_em = :updated "
                . "WHERE id = :id AND estado = 'aguardando_consentimento'"
            );
            $refusal->execute(['updated' => $stamp, 'id' => (int) $conversation['id']]);
            whatsappConversationSend(
                $pdo,
                $resolvedInstance,
                $normalizedPhone,
                'Tudo bem. Nenhum dado foi guardado e o cadastro não foi iniciado. Para começar depois, envie uma nova mensagem.',
                $reply
            );
            return;
        }
        $text = 'Para iniciar o cadastro individual e a emissão da sua carteirinha, seus dados serão usados pela instituição e poderão ser analisados pelo provedor de IA ' . $provider . '. Nenhuma mensagem ou foto será armazenada antes do seu aceite. Responda ACEITO para continuar ou SAIR para encerrar.';
        whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, $text, $reply);
        return;
    }
    if ($conversation['estado'] === 'menu_principal') {
        if (whatsappConversationCommand((string) ($message['texto'] ?? '')) === 'SAIR') {
            whatsappConversationRevoke($pdo, $conversation, $stamp, $resolvedInstance, $normalizedPhone, $reply);
            return;
        }
        if (whatsappConversationCommand((string) ($message['texto'] ?? '')) !== '1') {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Responda 1 para iniciar o cadastro individual ou SAIR para encerrar.', $reply);
            return;
        }
        $start = $pdo->prepare(
            "UPDATE whatsapp_conversas SET estado = 'aguardando_nome', dados_cifrados = :data, "
            . 'expira_em = :expires, atualizado_em = :updated WHERE id = :id AND estado = \'menu_principal\''
        );
        $start->execute([
            'data' => whatsappConversationSeal(json_encode(['nome_completo' => null, 'cpf' => null, 'categoria_id' => null, 'design_id' => null], JSON_THROW_ON_ERROR)),
            'expires' => $expiresAt, 'updated' => $stamp, 'id' => (int) $conversation['id'],
        ]);
        whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Informe seu nome completo.', $reply);
        return;
    }
    whatsappConversationHandleRegistration(
        $pdo, $conversation, $resolvedInstance, $normalizedPhone, $message, $reply,
        $interpreter, $stamp, $expiresAt, (int) $tenantId, $subclientId, $correlationId
    );
    return;
}
