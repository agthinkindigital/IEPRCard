<?php
declare(strict_types=1);

/** @param array<string,mixed> $conversation @param array<string,mixed> $instance @param array<string,mixed> $message
 *  @param callable(array<string,mixed>,string,string):void|null $reply
 */
function whatsappConversationConfirmRegistration(
    PDO $pdo,
    array $conversation,
    array $resolvedInstance,
    string $normalizedPhone,
    array $message,
    ?callable $reply,
    string $stamp,
    string $expiresAt,
    int $tenantId,
    ?int $subclientId,
    ?string $correlationId = null
): void {
    if ($conversation['estado'] === 'aguardando_confirmacao') {
        if (whatsappConversationCommand((string) ($message['texto'] ?? '')) !== 'CONFIRMAR') {
            $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, whatsappConversationConfirmationText($draft), $reply);
            return;
        }
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        if (!is_string($draft['nome_completo'] ?? null) || !is_string($draft['cpf'] ?? null)
            || !is_int($draft['design_id'] ?? null) || $conversation['consentimento_em'] === null
            || $conversation['revogado_em'] !== null) {
            throw new DomainException('WHATSAPP_CONVERSATION_STATE_INVALID');
        }
        $claim = $pdo->prepare(
            "UPDATE whatsapp_conversas SET estado = 'confirmando', atualizado_em = :updated "
            . "WHERE id = :id AND estado = 'aguardando_confirmacao' AND consentimento_em IS NOT NULL AND revogado_em IS NULL"
        );
        $claim->execute(['updated' => $stamp, 'id' => (int) $conversation['id']]);
        if ($claim->rowCount() !== 1) {
            return;
        }
        $identity = [
            'id' => null,
            'papel_sistema' => 'integracao_whatsapp',
            'tenant_id' => (int) $tenantId,
            'subcliente_id' => $subclientId,
            'instancia_id' => (int) $resolvedInstance['id'],
            'conversation_id' => (int) $conversation['id'],
        ];
        $context = new TenantContext($subclientId === null ? 'cliente' : 'subcliente', (int) $tenantId, $subclientId, []);
        try {
            $holderId = ($conversation['titular_id'] ?? null) === null
                ? titularCreate($pdo, $context, $identity, (int) $tenantId, $subclientId, [
                    'nome_completo' => $draft['nome_completo'],
                    'cpf' => $draft['cpf'],
                    'categoria_id' => $draft['categoria_id'] ?? null,
                    'whatsapp' => $normalizedPhone,
                    'foto_url' => $draft['foto_url'] ?? null,
                ])
                : (int) $conversation['titular_id'];
            if (($conversation['titular_id'] ?? null) === null) {
                $linkHolder = $pdo->prepare(
                    "UPDATE whatsapp_conversas SET titular_id = :holder, atualizado_em = :updated "
                    . "WHERE id = :id AND estado IN ('confirmando','revogado') AND titular_id IS NULL"
                );
                $linkHolder->execute(['holder' => $holderId, 'updated' => $stamp, 'id' => (int) $conversation['id']]);
                if ($linkHolder->rowCount() !== 1) {
                    throw new RuntimeException('WHATSAPP_CONVERSATION_STATE_CONFLICT');
                }
            }
            privacySubjectMergeRecordInto(
                $pdo,
                'whatsapp_conversa',
                (int) $conversation['id'],
                'titular',
                $holderId
            );
            $currentState = $pdo->prepare('SELECT estado FROM whatsapp_conversas WHERE id = :id LIMIT 1');
            $currentState->execute(['id' => (int) $conversation['id']]);
            if ($currentState->fetchColumn() === 'revogado') {
                $pdo->prepare('UPDATE titulares SET ia_pausada = 1 WHERE id = :holder AND tenant_id = :tenant')
                    ->execute(['holder' => $holderId, 'tenant' => (int) $tenantId]);
                whatsappConversationSend(
                    $pdo,
                    $resolvedInstance,
                    $normalizedPhone,
                    'O cadastro foi interrompido após a revogação. Para solicitar a exclusão dos dados já cadastrados, procure a instituição.',
                    $reply
                );
                return;
            }
            $issuance = cardIssuanceCreateJob(
                $pdo,
                $context,
                $identity,
                (int) $tenantId,
                $subclientId,
                null,
                (int) $draft['design_id'],
                'webp',
                'whatsapp.' . hash('sha256', (int) $resolvedInstance['id'] . ':' . $conversation['id'] . ':webp:v1'),
                null,
                null,
                $holderId,
                $correlationId
            );
            $finish = $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'emissao_pendente', job_id = :job, dados_cifrados = NULL, atualizado_em = :updated "
                . "WHERE id = :id AND estado = 'confirmando' AND consentimento_em IS NOT NULL AND revogado_em IS NULL"
            );
            $finish->execute(['job' => $issuance['job_id'], 'updated' => $stamp, 'id' => (int) $conversation['id']]);
            if ($finish->rowCount() === 1) {
                whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Cadastro confirmado. A emissão foi autorizada e entrou na fila; enviaremos sua carteirinha por aqui quando estiver pronta.', $reply);
            }
        } catch (DomainException $exception) {
            $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'aguardando_confirmacao', atualizado_em = :updated "
                . "WHERE id = :id AND estado = 'confirmando' AND job_id IS NULL"
            )->execute(['updated' => $stamp, 'id' => (int) $conversation['id']]);
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, whatsappConversationSafeRegistrationFailure($exception->getMessage()), $reply);
        } catch (Throwable) {
            $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'aguardando_confirmacao', atualizado_em = :updated "
                . "WHERE id = :id AND estado = 'confirmando' AND job_id IS NULL"
            )->execute(['updated' => $stamp, 'id' => (int) $conversation['id']]);
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Não foi possível concluir o cadastro agora. Seus dados foram mantidos protegidos; tente novamente em instantes.', $reply);
        }
        return;
    }
}
