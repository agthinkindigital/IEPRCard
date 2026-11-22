<?php
declare(strict_types=1);

function whatsappConversationSaveDraft(PDO $pdo, array $conversation, array $draft, string $nextState, string $stamp, string $expiresAt): bool
{
    $update = $pdo->prepare(
        'UPDATE whatsapp_conversas SET estado = :state, dados_cifrados = :data, expira_em = :expires, atualizado_em = :updated '
        . 'WHERE id = :id AND estado = :expected AND consentimento_em IS NOT NULL AND revogado_em IS NULL'
    );
    $update->execute([
        'state' => $nextState, 'data' => whatsappConversationSeal(json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        'expires' => $expiresAt, 'updated' => $stamp, 'id' => (int) $conversation['id'], 'expected' => (string) $conversation['estado'],
    ]);
    return $update->rowCount() === 1;
}

/** @param array<string,mixed> $conversation @param array<string,mixed> $instance
 *  @param callable(array<string,mixed>,string,string):void|null $reply
 */
function whatsappConversationRevoke(
    PDO $pdo,
    array $conversation,
    string $stamp,
    array $instance,
    string $phone,
    ?callable $reply
): void {
    $draft = [];
    $sealedDraft = $conversation['dados_cifrados'] ?? null;
    if (is_string($sealedDraft) && $sealedDraft !== '') {
        try {
            $draft = whatsappConversationOpen($sealedDraft);
        } catch (Throwable) {
            $draft = [];
        }
    }
    $statement = $pdo->prepare(
        "UPDATE whatsapp_conversas SET estado = 'revogado', revogado_em = :revoked, contato_hmac = NULL, "
        . 'dados_cifrados = NULL, expira_em = :expires, atualizado_em = :updated '
        . 'WHERE id = :id AND consentimento_em IS NOT NULL AND revogado_em IS NULL'
    );
    $statement->execute([
        'revoked' => $stamp, 'expires' => $stamp, 'updated' => $stamp, 'id' => (int) $conversation['id'],
    ]);
    $holderId = filter_var($conversation['titular_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($holderId !== false) {
        $pause = $pdo->prepare('UPDATE titulares SET ia_pausada = 1 WHERE id = :id AND tenant_id = :tenant');
        $pause->execute(['id' => $holderId, 'tenant' => (int) $conversation['tenant_id']]);
    }
    whatsappConversationDeleteDraftPhoto((int) $conversation['tenant_id'], $draft);
    whatsappConversationSend(
        $pdo,
        $instance,
        $phone,
        'Consentimento revogado. Interrompemos a coleta e o uso de IA; se seus dados já foram cadastrados, solicite a exclusão à instituição para concluir a anonimização no prazo previsto.',
        $reply
    );
}
function whatsappConversationCommand(string $text): string
{
    $normalized = strtr(trim($text), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
        'é' => 'e', 'ê' => 'e', 'É' => 'E', 'Ê' => 'E', 'í' => 'i', 'Í' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'Ú' => 'U', 'ç' => 'c', 'Ç' => 'C',
    ]);
    return strtoupper($normalized);
}
function whatsappConversationSeal(string $plaintext): string
{
    $key = aiGlobalConfigEncryptionKey();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
}

/** @return array<string,mixed> */
function whatsappConversationOpen(string $sealed): array
{
    $decoded = base64_decode($sealed, true);
    if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('WHATSAPP_CONVERSATION_STATE_UNAVAILABLE');
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(
        substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $nonce,
        aiGlobalConfigEncryptionKey()
    );
    $data = is_string($plaintext) ? json_decode($plaintext, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('WHATSAPP_CONVERSATION_STATE_UNAVAILABLE');
    }
    return $data;
}

/** @return array<string,mixed> */
