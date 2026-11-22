<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway_webhook.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_conversation.php';

$previousKey = getenv('IEPR_INTEGRATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x43", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);

try {
    schemaAplicarMigracoes($pdo, 'test');
    schemaGarantirEstrutura($pdo, 'test');
    $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'Conversation Failure Test', 'ativo')")
        ->execute();
    $tenantId = (int) $pdo->lastInsertId();
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO ia_config_global (provedor, chave_cifrada, modelo, ativo, criado_em, atualizado_em) '
        . 'VALUES (:provider, :key, :model, 1, :created, :updated)'
    )->execute([
        'provider' => 'openai', 'key' => aiGlobalConfigSeal('synthetic-ai-key'), 'model' => 'gpt-test',
        'created' => $now, 'updated' => $now,
    ]);
    $pdo->prepare(
        "INSERT INTO whatsapp_instancias (tenant_id, escopo_chave, nome_instancia, webhook_token_hash, estado, fluxo_cadastro_ativo, criado_em, atualizado_em) "
        . "VALUES (:tenant, :scope, 'conversation-failure-test', :token, 'ativa', 1, :created, :updated)"
    )->execute([
        'tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'token' => hash('sha256', 'synthetic-token'),
        'created' => $now, 'updated' => $now,
    ]);
    $instance = [
        'id' => (int) $pdo->lastInsertId(), 'tenant_id' => $tenantId, 'subcliente_id' => null,
        'nome_instancia' => 'conversation-failure-test', 'fluxo_cadastro_ativo' => true,
    ];
    $sent = [];
    $reply = static function (array $instance, string $phone, string $message) use (&$sent): void {
        $sent[] = [$phone, $message];
    };
    $noAi = static function (): array {
        throw new RuntimeException('Consent and menu steps must not call AI.');
    };

    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => '5511999990001', 'texto' => 'RECUSO', 'id_mensagem' => 'refusal', 'media' => null],
        $reply,
        $noAi,
        1_800_000_000
    );
    $refused = $pdo->query('SELECT estado, contato_hmac, consentimento_em, dados_cifrados FROM whatsapp_conversas LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($refused) || $refused['estado'] !== 'recusado' || $refused['contato_hmac'] !== null
        || $refused['consentimento_em'] !== null || $refused['dados_cifrados'] !== null) {
        throw new RuntimeException('Refusal must terminate collection without retaining contact identity or a draft.');
    }

    $phone = '5511999990002';
    $messages = [
        ['texto' => 'Olá', 'id_mensagem' => 'start'],
        ['texto' => 'ACEITO', 'id_mensagem' => 'consent'],
        ['texto' => '1', 'id_mensagem' => 'menu'],
    ];
    foreach ($messages as $index => $message) {
        whatsappConversationHandleIncoming(
            $pdo,
            $instance,
            ['telefone' => $phone, 'media' => null] + $message,
            $reply,
            $noAi,
            1_800_000_060 + ($index * 60)
        );
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => $phone, 'texto' => 'Meu nome é Carlos Silva', 'id_mensagem' => 'ai-failure', 'media' => null],
        $reply,
        static function (): array { throw new RuntimeException('Synthetic provider outage.'); },
        1_800_000_240
    );
    $conversation = $pdo->query("SELECT * FROM whatsapp_conversas WHERE estado = 'aguardando_nome' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conversation) || $conversation['consentimento_em'] === null || $conversation['dados_cifrados'] === null
        || !str_contains((string) end($sent)[1], 'Tente novamente')
        || (whatsappConversationOpen((string) $conversation['dados_cifrados'])['nome_completo'] ?? null) !== null) {
        throw new RuntimeException('An AI outage must leave the consented conversation retryable without storing raw input.');
    }
    whatsappConversationHandleIncoming(
        $pdo,
        $instance,
        ['telefone' => $phone, 'texto' => 'Carlos Silva', 'id_mensagem' => 'ai-retry', 'media' => null],
        $reply,
        static fn (): array => ['nome_completo' => 'Carlos Silva'],
        1_800_000_300
    );
    if ($pdo->query('SELECT estado FROM whatsapp_conversas WHERE id = ' . (int) $conversation['id'])->fetchColumn() !== 'aguardando_cpf') {
        throw new RuntimeException('A valid retry must resume the same consented conversation after provider recovery.');
    }

    $context = new TenantContext('cliente', $tenantId, null, []);
    $forgedIdentity = [
        'papel_sistema' => 'integracao_whatsapp', 'tenant_id' => $tenantId + 1, 'subcliente_id' => null,
        'instancia_id' => (int) $instance['id'], 'conversation_id' => (int) $conversation['id'],
    ];
    if (whatsappGatewayServicePrincipalCan($pdo, $context, $forgedIdentity, $tenantId, null, 'titular.criar')) {
        throw new RuntimeException('A service principal with a forged tenant scope must not create a titular.');
    }
    fwrite(STDOUT, "PASS: WhatsApp refusal, provider outage recovery, and forged service-principal scope denial.\n");
} finally {
    if ($previousKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $previousKey);
}

return 'pass';
