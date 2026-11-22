<?php
declare(strict_types=1);

/** @param array<string,mixed> $conversation @param array<string,mixed> $instance @param array<string,mixed> $message
 *  @param callable(array<string,mixed>,string,string):void|null $reply
 *  @param callable(string,array<string,mixed>):array<string,mixed>|null $interpreter
 */
function whatsappConversationHandleRegistration(
    PDO $pdo,
    array $conversation,
    array $resolvedInstance,
    string $normalizedPhone,
    array $message,
    ?callable $reply,
    ?callable $interpreter,
    string $stamp,
    string $expiresAt,
    int $tenantId,
    ?int $subclientId
): void {
    if ($conversation['estado'] === 'aguardando_nome') {
        $inputText = $message['texto'] ?? '';
        if (!is_string($inputText) || trim($inputText) === '' || strlen($inputText) > 4000) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Não consegui identificar o nome. Envie somente seu nome completo em texto.', $reply);
            return;
        }
        try {
            $extracted = $interpreter !== null
                ? $interpreter($inputText, ['field' => 'nome_completo'])
                : whatsappConversationInterpret($pdo, $inputText, 'nome_completo');
            $name = titularCreateText($extracted['nome_completo'] ?? null, 180, false);
        } catch (Throwable) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Não foi possível analisar esse nome agora. Tente novamente em instantes, enviando somente o nome completo.', $reply);
            return;
        }
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        $draft['nome_completo'] = $name;
        $saved = $pdo->prepare(
            "UPDATE whatsapp_conversas SET estado = 'aguardando_cpf', dados_cifrados = :data, expira_em = :expires, atualizado_em = :updated "
            . "WHERE id = :id AND estado = 'aguardando_nome' AND consentimento_em IS NOT NULL AND revogado_em IS NULL"
        );
        $saved->execute([
            'data' => whatsappConversationSeal(json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'expires' => $expiresAt, 'updated' => $stamp, 'id' => (int) $conversation['id'],
        ]);
        if ($saved->rowCount() === 1) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Agora, informe seu CPF.', $reply);
        }
        return;
    }
    if ($conversation['estado'] === 'aguardando_cpf') {
        try {
            $cpf = titularCreateCpf($message['texto'] ?? null);
        } catch (DomainException) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'CPF inválido. Confira os números e envie o CPF novamente.', $reply);
            return;
        }
        $duplicate = $pdo->prepare('SELECT 1 FROM titulares WHERE tenant_id = :tenant AND cpf = :cpf LIMIT 1');
        $duplicate->execute(['tenant' => $tenantId, 'cpf' => $cpf]);
        if ($duplicate->fetchColumn() !== false) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Este CPF já está cadastrado. Procure a instituição para continuar.', $reply);
            return;
        }
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        $draft['cpf'] = $cpf;
        $categoryChoices = whatsappConversationCategoryChoices($pdo, (int) $tenantId);
        if ($categoryChoices === []) {
            $draft['categoria_id'] = null;
            $draft['categoria_nome'] = null;
            $designChoices = whatsappConversationDesignChoices($pdo, (int) $tenantId, $subclientId, null);
            if ($designChoices === []) {
                whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'A instituição ainda não tem um design ativo para emitir sua carteirinha. Procure a equipe responsável.', $reply);
                return;
            }
            $draft['design_options'] = $designChoices;
            $nextState = 'aguardando_design';
            $prompt = whatsappConversationDesignPrompt($designChoices);
        } else {
            $draft['category_options'] = $categoryChoices;
            $nextState = 'aguardando_categoria';
            $prompt = whatsappConversationCategoryPrompt($categoryChoices);
        }
        if (whatsappConversationSaveDraft($pdo, $conversation, $draft, $nextState, $stamp, $expiresAt)) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, $prompt, $reply);
        }
        return;
    }
    if ($conversation['estado'] === 'aguardando_categoria') {
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        $choice = filter_var(trim((string) ($message['texto'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $options = is_array($draft['category_options'] ?? null) ? $draft['category_options'] : [];
        if ($choice === false || $choice > count($options)) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, whatsappConversationCategoryPrompt($options), $reply);
            return;
        }
        $draft['categoria_id'] = $choice === 0 ? null : (int) $options[$choice - 1]['id'];
        $draft['categoria_nome'] = $choice === 0 ? null : (string) $options[$choice - 1]['name'];
        unset($draft['category_options']);
        $designChoices = whatsappConversationDesignChoices($pdo, (int) $tenantId, $subclientId, $draft['categoria_id']);
        if ($designChoices === []) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Não há design ativo elegível para esta unidade. Procure a instituição.', $reply);
            return;
        }
        $draft['design_options'] = $designChoices;
        if (whatsappConversationSaveDraft($pdo, $conversation, $draft, 'aguardando_design', $stamp, $expiresAt)) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, whatsappConversationDesignPrompt($designChoices), $reply);
        }
        return;
    }
    if ($conversation['estado'] === 'aguardando_design') {
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        $choice = filter_var(trim((string) ($message['texto'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $options = is_array($draft['design_options'] ?? null) ? $draft['design_options'] : [];
        if ($choice === false || !isset($options[$choice - 1])) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, whatsappConversationDesignPrompt($options), $reply);
            return;
        }
        $draft['design_id'] = (int) $options[$choice - 1]['id'];
        $draft['design_nome'] = (string) $options[$choice - 1]['name'];
        unset($draft['design_options']);
        if (whatsappConversationSaveDraft($pdo, $conversation, $draft, 'aguardando_foto', $stamp, $expiresAt)) {
            whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Se quiser incluir uma foto na carteirinha, envie a imagem agora. A foto é opcional; responda PULAR para continuar sem foto.', $reply);
        }
        return;
    }
    if ($conversation['estado'] === 'aguardando_foto') {
        $draft = whatsappConversationOpen((string) ($conversation['dados_cifrados'] ?? ''));
        $command = whatsappConversationCommand((string) ($message['texto'] ?? ''));
        if ($command === 'PULAR') {
            $draft['foto_url'] = null;
        } else {
            $media = $message['media'] ?? null;
            if (!is_array($media) || ($media['type'] ?? null) !== 'image'
                || !is_string($media['data'] ?? null) || !is_string($media['mimetype'] ?? null)) {
                whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'Envie uma imagem de até 1 MB ou responda PULAR para continuar sem foto.', $reply);
                return;
            }
            try {
                $photoBytes = whatsappConversationNormalizePhoto($media['data'], $media['mimetype']);
                $draft['foto_url'] = cardIssuanceWorkerStore((int) $tenantId, (int) $conversation['id'], 1, 'jpg', $photoBytes);
            } catch (DomainException) {
                whatsappConversationSend($pdo, $resolvedInstance, $normalizedPhone, 'A imagem não pôde ser aceita. Envie JPEG, PNG ou WebP com até 1 MB ou responda PULAR.', $reply);
                return;
            }
        }
        if (whatsappConversationSaveDraft($pdo, $conversation, $draft, 'aguardando_confirmacao', $stamp, $expiresAt)) {
            whatsappConversationSend(
                $pdo,
                $resolvedInstance,
                $normalizedPhone,
                whatsappConversationConfirmationText($draft),
                $reply
            );
        } else {
            whatsappConversationDeleteDraftPhoto((int) $tenantId, $draft);
        }
        return;
    }
    if ($conversation['estado'] === 'aguardando_confirmacao') {
        whatsappConversationConfirmRegistration(
            $pdo, $conversation, $resolvedInstance, $normalizedPhone, $message, $reply,
            $stamp, $expiresAt, (int) $tenantId, $subclientId
        );
    }
}
