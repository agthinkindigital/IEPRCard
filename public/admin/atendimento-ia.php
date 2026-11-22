<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_test.php';

$admin = adminExigirAcesso();
$pdo = dbConnection();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    atendimentoIaHandlePost($pdo, $admin);
}
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$csrfToken = adminAuthCsrfToken($_SESSION);
$notice = is_string($_SESSION['ai_admin_notice'] ?? null) ? $_SESSION['ai_admin_notice'] : '';
unset($_SESSION['ai_admin_notice']);
$oneTimeWebhookUrl = is_string($_SESSION['ai_admin_webhook_url'] ?? null) ? $_SESSION['ai_admin_webhook_url'] : '';
unset($_SESSION['ai_admin_webhook_url']);
$aiStatus = aiGlobalConfigRead($pdo, $admin);
$gowaStatus = whatsappGatewayConfigRead($pdo, $admin);
$instances = $pdo->query(
    'SELECT i.id, i.tenant_id, i.subcliente_id, i.nome_instancia, i.device_id, i.estado, i.fluxo_cadastro_ativo, i.atualizado_em, '
    . 't.nome_organizacao AS tenant_nome, u.nome_organizacao AS unidade_nome FROM whatsapp_instancias i '
    . 'LEFT JOIN tenants t ON t.id = i.tenant_id LEFT JOIN tenants u ON u.id = i.subcliente_id ORDER BY i.id DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$tenants = $pdo->query(
    "SELECT id, nome_organizacao AS nome FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' ORDER BY nome_organizacao"
)->fetchAll(PDO::FETCH_ASSOC);
$units = $pdo->query(
    "SELECT id, parent_id, nome_organizacao AS nome FROM tenants WHERE parent_id IS NOT NULL AND tipo = 'subcliente' AND status_conta = 'ativo' ORDER BY nome_organizacao"
)->fetchAll(PDO::FETCH_ASSOC);
$masterKeyReady = true;
try {
    aiGlobalConfigEncryptionKey();
} catch (Throwable) {
    $masterKeyReady = false;
}
$publicBaseReady = true;
try {
    whatsappGatewayValidatePublicBaseUrl((string) (getenv('APP_BASE_URL') ?: ''));
} catch (Throwable) {
    $publicBaseReady = false;
}

function atendimentoIaEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function atendimentoIaHandlePost(PDO $pdo, array $admin): never
{
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    try {
        if (aiGlobalConfigEncryptionKey() === '') throw new RuntimeException('AI_CONFIG_KEY_UNAVAILABLE');
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'save_gowa') {
            $result = whatsappGatewayConfigSaveTested($pdo, $admin, atendimentoIaPost('api_base_url'), atendimentoIaPost('gowa_token'));
            atendimentoIaNotice($result['status'] === 'success'
                ? 'Conexão aprovada e configuração GOWA salva. O token não será exibido novamente.'
                : 'Falha ao testar a nova configuração. A conexão GOWA salva anteriormente foi preservada.');
        } elseif ($action === 'test_gowa') {
            $result = whatsappGatewayConfigTest($pdo, $admin);
            atendimentoIaNotice($result['status'] === 'success' ? 'Conexão GOWA aprovada.' : 'Falha ao testar GOWA. Revise URL, token e conectividade.');
        } elseif ($action === 'save_ai') {
            $provider = atendimentoIaPost('provider');
            aiGlobalConfigSave($pdo, $admin, $provider, atendimentoIaPost('api_key'), atendimentoIaPost('model'));
            atendimentoIaNotice('Configuração salva. A chave não será exibida novamente.');
        } elseif ($action === 'activate_ai') {
            $provider = atendimentoIaPost('provider');
            $apiKey = atendimentoIaPost('api_key');
            $model = atendimentoIaPost('model');
            $test = atendimentoIaTestSubmittedProvider($pdo, $admin, $provider, $apiKey, $model);
            if ($test['status'] !== 'success') throw new DomainException('AI_PROVIDER_TEST_FAILED');
            aiGlobalConfigSave($pdo, $admin, $provider, $apiKey, $model);
            aiGlobalConfigSetActive($pdo, $admin, $provider);
            atendimentoIaNotice('Teste sintético aprovado e provedor principal atualizado para novas conversas.');
        } elseif ($action === 'test_ai') {
            $result = atendimentoIaTestSubmittedProvider(
                $pdo, $admin, atendimentoIaPost('provider'), atendimentoIaPost('api_key'), atendimentoIaPost('model')
            );
            atendimentoIaNotice($result['status'] === 'success' ? 'Teste sintético de IA aprovado.' : 'Falha no teste sintético de IA. A seleção ativa não foi alterada.');
        } elseif ($action === 'provision_instance') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $subclientValue = atendimentoIaPost('subclient_id');
            $subclientId = $subclientValue === '' ? null : filter_var($subclientValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false || ($subclientValue !== '' && $subclientId === false)) throw new InvalidArgumentException('Escopo inválido.');
            $flowEnabled = atendimentoIaPost('flow_enabled') === '1';
            $instance = whatsappGatewayInstanceProvision($pdo, $admin, $tenantId, $subclientId ?: null, null, null, $flowEnabled);
            $_SESSION['ai_admin_webhook_url'] = $instance['webhook_url'];
            atendimentoIaNotice('Instância provisionada. Copie agora a URL exclusiva do webhook.');
        } elseif ($action === 'toggle_flow') {
            $id = filter_var($_POST['instance_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $enabled = atendimentoIaPost('enabled');
            if ($id === false || !in_array($enabled, ['0', '1'], true)) throw new InvalidArgumentException('Instância inválida.');
            whatsappGatewayInstanceSetRegistrationFlow($pdo, $admin, $id, $enabled === '1');
            atendimentoIaNotice($enabled === '1' ? 'Fluxo de cadastro habilitado para esta instância.' : 'Fluxo de cadastro desabilitado para esta instância.');
        } elseif ($action === 'rotate_webhook') {
            $id = filter_var($_POST['instance_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Instância inválida.');
            $rotated = whatsappGatewayRotateInstanceWebhook($pdo, $admin, $id);
            $_SESSION['ai_admin_webhook_url'] = $rotated['webhook_url'];
            atendimentoIaNotice('Webhook rotacionado. A URL anterior foi invalidada; copie a nova URL agora.');
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (InvalidArgumentException) {
        atendimentoIaNotice('Revise os campos. Nenhuma alteração inválida foi aplicada.');
    } catch (DomainException $exception) {
        atendimentoIaNotice(atendimentoIaSafeError($exception->getMessage()));
    } catch (Throwable) {
        atendimentoIaNotice('Não foi possível concluir a operação. Revise a configuração e tente novamente.');
    }
    session_write_close();
    header('Location: /admin/atendimento-ia.php', true, 303);
    exit;
}

function atendimentoIaPost(string $key): string
{
    if (!isset($_POST[$key])) return '';
    if (!is_string($_POST[$key])) throw new InvalidArgumentException('Campo inválido.');
    return trim($_POST[$key]);
}

function atendimentoIaTestSubmittedProvider(PDO $pdo, array $admin, string $provider, string $apiKey, string $model): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $admin);
    if ($apiKey === '') {
        $query = $pdo->prepare('SELECT chave_cifrada FROM ia_config_global WHERE provedor = :provider LIMIT 1');
        $query->execute(['provider' => $provider]);
        $stored = $query->fetchColumn();
        if (!is_string($stored)) return ['status' => 'failed', 'error_code' => 'not_configured'];
        $apiKey = aiGlobalConfigOpen($stored);
    }
    return aiGlobalConfigTestCredentials($provider, $apiKey, $model);
}

function atendimentoIaNotice(string $message): void
{
    $_SESSION['ai_admin_notice'] = $message;
}

function atendimentoIaSafeError(string $code): string
{
    return match ($code) {
        'AI_CONFIG_FORBIDDEN' => 'Acesso restrito ao superadmin.',
        'AI_CONFIG_KEY_UNAVAILABLE', 'AI_CONFIG_KEY_INVALID', 'AI_CONFIG_SECRET_UNAVAILABLE' => 'IEPR_INTEGRATION_KEY ausente ou inválida no ambiente do PHP.',
        'AI_PROVIDER_NOT_CONFIGURED' => 'Configure e teste o provedor antes de selecioná-lo.',
        'AI_PROVIDER_TEST_FAILED' => 'O teste sintético falhou. O provedor ativo continua inalterado.',
        'WHATSAPP_GOWA_NOT_CONFIGURED' => 'Configure a conexão GOWA antes de provisionar ou testar instâncias.',
        'WHATSAPP_INSTANCE_ALREADY_EXISTS' => 'Já existe uma instância para esse escopo.',
        'WHATSAPP_TENANT_UNAVAILABLE' => 'Cliente ou unidade não encontrado ou inativo.',
        default => 'A operação não pode ser concluída com a configuração atual.',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Atendimento e IA</title>
    <link rel="stylesheet" href="/assets/admin-migration.css"><link rel="stylesheet" href="/assets/admin-whatsapp-ai.css">
</head>
<body>
<aside class="sidebar"><a class="brand" href="/admin/atendimento-ia.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a><p class="nav-heading">ADMINISTRAÇÃO</p>
    <nav aria-label="Navegação administrativa"><a href="/admin/migracao-banco.php">Visão geral</a><a href="#instances">Clientes</a><a href="#instances">Domínios</a><a href="/admin/atualizar-schema.php">Operação</a><a href="/admin/backup-destinos.php">Backup externo</a><a class="active" aria-current="page" href="/admin/atendimento-ia.php">Atendimento e IA</a></nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= atendimentoIaEscape($admin['nome']) ?></strong><small>Superadmin</small></span></div>
</aside>
<header class="topbar"><p>Administração <span aria-hidden="true">/</span> <strong>Atendimento e IA</strong></p><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= atendimentoIaEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form></header>
<main class="content"><p class="breadcrumb">Administração&nbsp; / &nbsp;Integrações</p><div class="page-heading"><div><h1>Atendimento e IA</h1><p>Conexão global da plataforma e atendimento habilitado por instância.</p></div><span class="admin-only">Somente superadmin</span></div>
    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= atendimentoIaEscape($notice) ?></p><?php endif; ?>
    <?php if (!$masterKeyReady): ?><section class="warning-banner" role="alert"><strong>Chave de integração indisponível</strong><p>Configure IEPR_INTEGRATION_KEY no ambiente do PHP antes de salvar ou testar credenciais.</p></section><?php endif; ?>
    <?php if ($oneTimeWebhookUrl !== ''): ?><section class="one-time-secret" aria-labelledby="webhook-secret-title"><h2 id="webhook-secret-title">URL do webhook — copie agora</h2><p>Esta URL contém um token de uso exclusivo. Ela será ocultada ao sair desta página.</p><code><?= atendimentoIaEscape($oneTimeWebhookUrl) ?></code></section><?php endif; ?>
    <section class="warning-banner"><strong>Configuração global da plataforma</strong><p>Aplica-se aos clientes que habilitarem o atendimento. O consumo de IA é feito na conta global configurada.</p></section>
    <section class="panel config-section" aria-labelledby="gowa-title"><h2 id="gowa-title">Canal WhatsApp · GOWA VIP</h2><p>Conexão global; cada instância recebe um webhook exclusivo.</p>
        <form method="post" class="config-grid"><input type="hidden" name="csrf_token" value="<?= atendimentoIaEscape($csrfToken) ?>"><input type="hidden" name="action" value="save_gowa">
            <label>URL da API<input name="api_base_url" type="url" maxlength="500" value="<?= atendimentoIaEscape($gowaStatus['api_base_url'] ?? '') ?>" placeholder="https://api.exemplo.net" required></label>
            <label>Token de API<input name="gowa_token" type="password" maxlength="4096" autocomplete="new-password" placeholder="<?= $gowaStatus['token_configured'] ? 'Token protegido · informe somente para substituir' : 'Informe o token GOWA' ?>" <?= $gowaStatus['token_configured'] ? '' : 'required' ?>></label>
            <div class="form-actions"><button class="secondary-button" type="submit" form="gowa-test">Testar GOWA</button><button class="primary-button" type="submit">Salvar conexão</button></div>
        </form>
        <form method="post" id="gowa-test" class="hidden-form"><input type="hidden" name="csrf_token" value="<?= atendimentoIaEscape($csrfToken) ?>"><input type="hidden" name="action" value="test_gowa"></form>
        <p class="status-note"><?= $gowaStatus['configured'] ? 'Token protegido; nunca será reexibido.' : 'Conexão ainda não configurada.' ?> <?= $gowaStatus['last_test_status'] === 'success' ? 'Último teste: conexão aprovada.' : ($gowaStatus['last_test_status'] === 'failed' ? 'Último teste: falha de conexão.' : '') ?></p>
    </section>
    <section class="provider-grid" aria-label="Provedores de IA"><?php foreach (['openai' => 'OpenAI', 'gemini' => 'Google Gemini'] as $key => $label): $provider = $aiStatus['providers'][$key]; ?>
        <article class="panel provider-card"><div class="provider-heading"><h2><?= $label ?></h2><span class="status-chip <?= $provider['active'] ? 'good' : '' ?>"><?= $provider['active'] ? 'Principal' : ($provider['configured'] ? 'Configurado' : 'Opcional') ?></span></div>
            <p><?= $provider['configured'] ? 'Chave protegida · modelo ' . atendimentoIaEscape($provider['model'] ?? '') : 'Cadastre este provedor se necessário.' ?></p>
            <form method="post" class="provider-form"><input type="hidden" name="csrf_token" value="<?= atendimentoIaEscape($csrfToken) ?>"><input type="hidden" name="action" value="save_ai"><input type="hidden" name="provider" value="<?= $key ?>">
                <label>Chave de API<input name="api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="<?= $provider['configured'] ? 'Chave protegida · vazio mantém a atual' : 'Informe a chave' ?>" <?= $provider['configured'] ? '' : 'required' ?>></label>
                <label>Modelo<input name="model" maxlength="120" value="<?= atendimentoIaEscape($provider['model'] ?? '') ?>" placeholder="Ex.: modelo disponível no provedor" required></label>
                <div class="form-actions"><button class="secondary-button" type="submit" name="action" value="test_ai" <?= $provider['configured'] ? '' : 'disabled' ?>>Testar IA</button><?php if (!$provider['active']): ?><button class="secondary-button" type="submit" name="action" value="activate_ai" <?= $provider['configured'] ? '' : 'disabled' ?>>Testar e ativar</button><?php endif; ?><button class="primary-button" type="submit" name="action" value="save_ai">Salvar configuração</button></div>
            </form>
        </article><?php endforeach; ?></section>
    <section class="panel behavior-note"><h2>Comportamento de seleção</h2><p>Uma conversa usa somente o provedor principal. Fallback automático fica desativado; uma falha não altera a seleção nem a configuração salva.</p><p>Teste de IA envia uma mensagem sintética, sem dados de titulares.</p></section>
    <section class="panel consent-preview"><h2>Prévia do consentimento</h2><p>O bot informa finalidade e provedor antes de solicitar dados ou foto. Mensagens recebidas antes do aceite não são salvas nem encaminhadas à IA.</p><blockquote>Para solicitar sua carteirinha, usaremos os dados necessários. Com sua autorização, o texto poderá ser processado por <?= atendimentoIaEscape($aiStatus['active_provider'] ?? 'provedor de IA ainda não selecionado') ?>. Deseja continuar?</blockquote></section>
    <?php require __DIR__ . '/atendimento-ia-instances.php'; ?>
</main>
</body></html>
