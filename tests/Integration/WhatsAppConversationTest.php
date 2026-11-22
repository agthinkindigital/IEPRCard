<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_conversation.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker_output.php';
$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x64", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(str_repeat("\x65", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
try {
    schemaAplicarMigracoes($pdo, 'test');
    schemaGarantirEstrutura($pdo, 'test');
    $pdo->exec("INSERT INTO planos (nome, ativo) VALUES ('Conversation test plan', 1)");
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')->execute([
        'plan' => $planId, 'feature' => 'titular.criar',
    ]);
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')->execute([
        'plan' => $planId, 'feature' => 'carteirinha.gerar',
    ]);
    $tenant = $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, status_conta, plano_id, limite_titulares, limite_carteirinhas) VALUES ('cliente', 'Conversation Test', 'ativo', :plan, 10, 10)");
    $tenant->execute(['plan' => $planId]);
    $tenantId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO categorias_titular (tenant_id, nome_categoria, ativo) VALUES (:tenant, :name, 1)')
        ->execute(['tenant' => $tenantId, 'name' => 'Membro']);
    $categoryId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, ativo, compartilhado_subclientes) '
        . 'VALUES (:tenant, :name, :orientation, 1, 0)'
    )->execute(['tenant' => $tenantId, 'name' => 'Layout Membro', 'orientation' => 'horizontal']);
    $designId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE categorias_titular SET design_padrao_id = :design WHERE id = :id')
        ->execute(['design' => $designId, 'id' => $categoryId]);
    $pdo->prepare(
        'INSERT INTO ia_config_global (provedor, chave_cifrada, modelo, ativo, criado_em, atualizado_em) '
        . 'VALUES (:provider, :key, :model, 1, :created, :updated)'
    )->execute([
        'provider' => 'openai', 'key' => aiGlobalConfigSeal('synthetic-ai-key'), 'model' => 'gpt-test',
        'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
    ]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO whatsapp_instancias "
        . "(tenant_id, escopo_chave, nome_instancia, webhook_token_hash, estado, fluxo_cadastro_ativo, criado_em, atualizado_em) "
        . "VALUES (:tenant, :scope, :name, :token, 'ativa', 1, :created, :updated)"
    )->execute([
        'tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'name' => 'conversation-test',
        'token' => hash('sha256', $token), 'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
    ]);
    $instanceId = (int) $pdo->lastInsertId();
    $instance = [
        'id' => $instanceId, 'tenant_id' => $tenantId, 'subcliente_id' => null,
        'nome_instancia' => 'conversation-test', 'fluxo_cadastro_ativo' => true,
    ];
    $sent = [];
    $reply = static function (array $instance, string $phone, string $message) use (&$sent): void {
        $sent[] = [$instance['id'], $phone, $message];
    };
    $noAi = static function (): array {
        throw new RuntimeException('AI must not run outside the consented name-extraction step.');
    };
    $disabledInstance = $instance;
    $disabledInstance['fluxo_cadastro_ativo'] = false;
    $pdo->prepare('UPDATE whatsapp_instancias SET fluxo_cadastro_ativo = 0 WHERE id = :id')->execute(['id' => $instanceId]);
    whatsappConversationHandleIncoming(
        $pdo,
        $disabledInstance,
        ['telefone' => '5511999991234', 'texto' => 'dados pessoais sem opt-in', 'id_mensagem' => 'disabled-flow', 'media' => null],
        static function () use (&$sent): void { $sent[] = ['unexpected']; },
        static function (): array { throw new RuntimeException('A disabled instance must not invoke AI.'); },
        1_799_999_940
    );
    if ($sent !== [] || (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_conversas')->fetchColumn() !== 0) {
        throw new RuntimeException('The WhatsApp registration flow must remain inert until explicitly enabled per instance.');
    }
    $pdo->prepare('UPDATE whatsapp_instancias SET fluxo_cadastro_ativo = 1 WHERE id = :id')->execute(['id' => $instanceId]);
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        [
            'telefone' => '5511999991234', 'texto' => 'Meu nome é Ana e meu CPF é 12345678909',
            'id_mensagem' => 'message-before-consent', 'media' => null,
        ],
        $reply,
        $noAi,
        1_800_000_000
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    $serialized = json_encode($conversation, JSON_THROW_ON_ERROR);
    if (!is_array($conversation) || $conversation['estado'] !== 'aguardando_consentimento'
        || $conversation['consentimento_em'] !== null || !is_string($conversation['contato_hmac'])
        || str_contains($serialized, '5511999991234') || str_contains($serialized, 'Ana')
        || str_contains($serialized, '12345678909') || str_contains($serialized, 'Meu nome é')
        || count($sent) !== 1 || !str_contains($sent[0][2], 'OpenAI')
        || !str_contains($sent[0][2], 'ACEITO')) {
        throw new RuntimeException('A first GOWA message must request explicit consent without storing or forwarding its content.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => 'ACEITO', 'id_mensagem' => 'explicit-consent', 'media' => null],
        $reply,
        $noAi,
        1_800_000_060
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation) || $conversation['estado'] !== 'menu_principal'
        || $conversation['aviso_versao'] !== WHATSAPP_CONVERSATION_NOTICE_VERSION
        || $conversation['consentimento_finalidade'] !== WHATSAPP_CONVERSATION_PURPOSE
        || $conversation['consentimento_em'] !== gmdate('Y-m-d H:i:s', 1_800_000_060)
        || count($sent) !== 2 || !str_contains($sent[1][2], 'cadastro individual')) {
        throw new RuntimeException('An explicit affirmative response must record versioned consent and continue into the deterministic menu.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => '1', 'id_mensagem' => 'select-individual', 'media' => null],
        $reply,
        $noAi,
        1_800_000_120
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation) || $conversation['estado'] !== 'aguardando_nome'
        || count($sent) !== 3 || !str_contains($sent[2][2], 'nome completo')) {
        throw new RuntimeException('The deterministic menu must start individual registration and ask for the required name.');
    }
    $aiCalls = [];
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => 'Ana Maria Silva', 'id_mensagem' => 'provide-name', 'media' => null],
        $reply,
        static function (string $text, array $context) use (&$aiCalls): array {
            $aiCalls[] = [$text, $context];
            return ['nome_completo' => 'Ana Maria Silva'];
        },
        1_800_000_180
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation) || $conversation['estado'] !== 'aguardando_cpf'
        || count($aiCalls) !== 1 || $aiCalls[0][0] !== 'Ana Maria Silva'
        || str_contains((string) $conversation['dados_cifrados'], 'Ana Maria Silva')
        || count($sent) !== 4 || !str_contains($sent[3][2], 'CPF')) {
        throw new RuntimeException('The name must be interpreted only after consent, validated and stored only in encrypted draft state.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => '123.456.789-09', 'id_mensagem' => 'provide-cpf', 'media' => null],
        $reply,
        $noAi,
        1_800_000_240
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation) || $conversation['estado'] !== 'aguardando_categoria'
        || str_contains((string) $conversation['dados_cifrados'], '12345678909')
        || count($sent) !== 5 || !str_contains($sent[4][2], 'Membro')) {
        throw new RuntimeException('A valid CPF must be stored only in encrypted draft state and followed by active category choices.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => '1', 'id_mensagem' => 'choose-category', 'media' => null],
        $reply,
        $noAi,
        1_800_000_300
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    $draft = whatsappConversationOpen((string) $conversation['dados_cifrados']);
    if ($conversation['estado'] !== 'aguardando_design' || ($draft['categoria_id'] ?? null) !== $categoryId
        || count($sent) !== 6 || !str_contains($sent[5][2], 'Layout Membro')) {
        throw new RuntimeException('The category selection must be active, tenant-scoped and lead to eligible designs.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => '1', 'id_mensagem' => 'choose-design', 'media' => null],
        $reply,
        $noAi,
        1_800_000_360
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    $draft = whatsappConversationOpen((string) $conversation['dados_cifrados']);
    if ($conversation['estado'] !== 'aguardando_foto' || ($draft['design_id'] ?? null) !== $designId
        || count($sent) !== 7 || !str_contains($sent[6][2], 'opcional')) {
        throw new RuntimeException('The design selection must be eligible and photo collection must start only after consent.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => 'PULAR', 'id_mensagem' => 'skip-photo', 'media' => null],
        $reply,
        $noAi,
        1_800_000_420
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    if ($conversation['estado'] !== 'aguardando_confirmacao' || count($sent) !== 8
        || !str_contains($sent[7][2], 'Ana Maria Silva') || !str_contains($sent[7][2], '123.456.789-09')) {
        throw new RuntimeException('The confirmation step must summarize the validated data after consent.');
    }
    $contactSubjectBeforeRegistration = privacySubjectFindForRecord($pdo, 'whatsapp_conversa', (int) $conversation['id']);
    if (!is_array($contactSubjectBeforeRegistration)) {
        throw new RuntimeException('The WhatsApp contact must have a removable privacy subject before holder creation.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => 'CONFIRMAR', 'id_mensagem' => 'confirm-registration', 'media' => null],
        $reply,
        $noAi,
        1_800_000_480
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    $holder = $pdo->query('SELECT * FROM titulares')->fetch(PDO::FETCH_ASSOC);
    $job = $pdo->query("SELECT * FROM lotes_importacao WHERE origem_tipo = 'emissao'")->fetch(PDO::FETCH_ASSOC);
    $holderSubject = is_array($holder) ? privacySubjectFindForRecord($pdo, 'titular', (int) $holder['id']) : null;
    $conversationSubject = is_array($conversation)
        ? privacySubjectFindForRecord($pdo, 'whatsapp_conversa', (int) $conversation['id']) : null;
    $oldContactSubject = $pdo->prepare('SELECT estado FROM privacy_subjects WHERE id = :id');
    $oldContactSubject->execute(['id' => $contactSubjectBeforeRegistration['id']]);
    if (!is_array($holder) || $holder['cpf'] !== '12345678909' || $holder['whatsapp'] !== '+5511999991234'
        || (int) $holder['categoria_id'] !== $categoryId || !is_array($job) || (int) $job['design_id'] !== $designId
        || $job['modo_saida'] !== 'webp' || $job['status_lote'] !== 'na_fila'
        || $conversation['estado'] !== 'emissao_pendente' || (int) $conversation['titular_id'] !== (int) $holder['id']
        || (int) $conversation['job_id'] !== (int) $job['id'] || $conversation['dados_cifrados'] !== null
        || !is_array($holderSubject) || !is_array($conversationSubject)
        || $holderSubject['id'] !== $conversationSubject['id']
        || $oldContactSubject->fetchColumn() !== 'anonimizado') {
        throw new RuntimeException('Confirmed registration must group the known contact and holder under one removable subject, erase the old contact key, and create the issuance job.');
    }
    cardIssuanceWorkerFinalize($pdo, (int) $job['id'], 1_800_000_500);
    $deliveryState = $pdo->query('SELECT estado FROM whatsapp_conversas')->fetchColumn();
    if ($deliveryState !== 'entrega_pendente') {
        throw new RuntimeException('A completed issuance job must queue the card delivery in the same finalization transaction.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999991234', 'texto' => 'SAIR', 'id_mensagem' => 'revoke-consent', 'media' => null],
        $reply,
        static function (): array { throw new RuntimeException('Revocation must not call AI.'); },
        1_800_000_540
    );
    $conversation = $pdo->query('SELECT * FROM whatsapp_conversas')->fetch(PDO::FETCH_ASSOC);
    $paused = (int) $pdo->query('SELECT ia_pausada FROM titulares LIMIT 1')->fetchColumn();
    if (!is_array($conversation) || $conversation['estado'] !== 'revogado' || $conversation['revogado_em'] === null
        || $conversation['contato_hmac'] !== null || $conversation['dados_cifrados'] !== null || $paused !== 1) {
        throw new RuntimeException('Consent revocation must stop the flow, erase its draft reference, and pause AI for a linked holder.');
    }
    $pdo->prepare(
        'INSERT INTO whatsapp_conversas (instancia_id, tenant_id, contato_hmac, estado, dados_cifrados, expira_em, criado_em, atualizado_em) '
        . 'VALUES (:instance, :tenant, :contact, :state, :data, :expires, :created, :updated)'
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'contact' => hash('sha256', 'expired-unlinked-contact'),
        'state' => 'concluida', 'data' => 'synthetic-encrypted-conversation',
        'expires' => '2026-11-22 09:00:00', 'created' => '2026-11-22 08:00:00', 'updated' => '2026-11-22 08:00:00',
    ]);
    $expiredConversationId = (int) $pdo->lastInsertId();
    $expiredSubject = privacySubjectCreate($pdo, 'contato', $tenantId, '2026-11-22 08:00:00');
    privacySubjectLinkRecord($pdo, $expiredSubject['id'], 'whatsapp_conversa', $expiredConversationId);
    $expiredCleanup = whatsappConversationCleanupExpired($pdo, 100, strtotime('2026-11-22 10:00:00 UTC'));
    $expiredCount = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_conversas WHERE id = :id');
    $expiredCount->execute(['id' => $expiredConversationId]);
    $expiredSubjectState = $pdo->prepare('SELECT estado FROM privacy_subjects WHERE id = :id');
    $expiredSubjectState->execute(['id' => $expiredSubject['id']]);
    if ($expiredCleanup !== 1 || (int) $expiredCount->fetchColumn() !== 0
        || $expiredSubjectState->fetchColumn() !== 'anonimizado') {
        throw new RuntimeException('An unlinked WhatsApp conversation must erase its backup key before the 24-hour cleanup deletes it.');
    }
    $pdo->prepare(
        'INSERT INTO whatsapp_conversas (instancia_id, tenant_id, contato_hmac, estado, dados_cifrados, expira_em, criado_em, atualizado_em) '
        . 'VALUES (:instance, :tenant, :contact, :state, :data, :expires, :created, :updated)'
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'contact' => hash('sha256', 'expired-unlinked-without-subject'),
        'state' => 'concluida', 'data' => 'synthetic-unlinked-conversation',
        'expires' => '2026-11-22 09:00:00', 'created' => '2026-11-22 08:00:00', 'updated' => '2026-11-22 08:00:00',
    ]);
    $unlinkedConversationId = (int) $pdo->lastInsertId();
    $missingSubjectFailedClosed = false;
    try {
        whatsappConversationCleanupExpired($pdo, 100, strtotime('2026-11-22 10:00:00 UTC'));
    } catch (RuntimeException $exception) {
        $missingSubjectFailedClosed = $exception->getMessage() === 'PRIVACY_SUBJECT_RECORD_MISSING';
    }
    $unlinkedCount = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_conversas WHERE id = :id');
    $unlinkedCount->execute(['id' => $unlinkedConversationId]);
    if (!$missingSubjectFailedClosed || (int) $unlinkedCount->fetchColumn() !== 1) {
        throw new RuntimeException('Expired WhatsApp data without a privacy subject must remain stored and fail closed.');
    }
    fwrite(STDOUT, "PASS: WhatsApp conversation requests consent before storing or forwarding personal data.\n");
} finally {
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
    if ($oldAnonymizationKey === false) putenv('IEPR_ANONYMIZATION_KEY');
    else putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
}
return 'pass';
