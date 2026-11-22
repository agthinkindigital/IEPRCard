<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/installation_profile.php';

$admin = adminExigirAcesso();
$pdo = dbConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

function installationPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installationPagePostString(array $post, string $key, int $maxLength = 255): string
{
    $value = $post[$key] ?? '';
    if (!is_string($value)) return '';
    return substr(trim($value), 0, $maxLength);
}

$formValues = is_array($_SESSION['installation_profile_form'] ?? null)
    ? $_SESSION['installation_profile_form'] : [];
$notice = is_string($_SESSION['installation_profile_notice'] ?? null)
    ? $_SESSION['installation_profile_notice'] : '';
unset($_SESSION['installation_profile_form'], $_SESSION['installation_profile_notice']);

if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    if (($_POST['action'] ?? null) !== 'save') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    $formValues = [
        'modo' => installationPagePostString($_POST, 'modo', 20),
        'hostname_plataforma' => installationPagePostString($_POST, 'hostname_plataforma'),
        'dominio_base' => installationPagePostString($_POST, 'dominio_base'),
        'tenant_local_id' => installationPagePostString($_POST, 'tenant_local_id', 20),
    ];
    if (($_POST['confirm_impact'] ?? null) !== '1') {
        $_SESSION['installation_profile_form'] = $formValues;
        $_SESSION['installation_profile_notice'] = 'Confirme que compreendeu o efeito para salvar a configuração.';
        session_write_close();
        header('Location: /admin/instalacao.php?erro=1', true, 303);
        exit;
    }
    try {
        installationProfileSave($pdo, $formValues, (int) $admin['id']);
        unset($_SESSION['installation_profile_form']);
        $_SESSION['installation_profile_notice'] = 'Configuração salva. O perfil passa a valer nas próximas requisições.';
        $destination = adminAuthConsumeReturnPath($_SESSION);
        session_write_close();
        header('Location: ' . $destination . '?salvo=1', true, 303);
        exit;
    } catch (InvalidArgumentException) {
        $_SESSION['installation_profile_notice'] = 'Não foi possível salvar. Revise os campos e tente novamente; a configuração atual foi preservada.';
        $_SESSION['installation_profile_form'] = $formValues;
        session_write_close();
        header('Location: /admin/instalacao.php?erro=1', true, 303);
        exit;
    } catch (DomainException) {
        $_SESSION['installation_profile_notice'] = 'Não foi possível salvar. Confirme uma Sede ativa e tente novamente; a configuração atual foi preservada.';
        $_SESSION['installation_profile_form'] = $formValues;
        session_write_close();
        header('Location: /admin/instalacao.php?erro=1', true, 303);
        exit;
    } catch (Throwable) {
        $_SESSION['installation_profile_notice'] = 'Não foi possível salvar agora. Tente novamente; a configuração atual foi preservada.';
        $_SESSION['installation_profile_form'] = $formValues;
        session_write_close();
        header('Location: /admin/instalacao.php?erro=1', true, 303);
        exit;
    }
}

$profile = installationProfileCurrent($pdo);
$selectedMode = (string) ($formValues['modo'] ?? $profile['modo'] ?? 'hospedado');
if (!in_array($selectedMode, ['hospedado', 'local_host'], true)) $selectedMode = 'hospedado';
$platformHost = (string) ($formValues['hostname_plataforma'] ?? $profile['hostname_plataforma'] ?? '');
$baseDomain = (string) ($formValues['dominio_base'] ?? $profile['dominio_base'] ?? '');
$selectedTenantId = (string) ($formValues['tenant_local_id'] ?? $profile['tenant_local_id'] ?? '');
$csrfToken = adminAuthCsrfToken($_SESSION);
$sedesQuery = $pdo->query(
    "SELECT root.id, root.nome_organizacao, (SELECT COUNT(*) FROM tenants unit "
    . "WHERE unit.parent_id = root.id AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo') AS unidades_ativas "
    . "FROM tenants root WHERE root.parent_id IS NULL AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
    . 'ORDER BY root.nome_organizacao, root.id'
);
$sedes = $sedesQuery->fetchAll(PDO::FETCH_ASSOC);
$activeUnitCount = 0;
foreach ($sedes as $sede) {
    if ((string) $sede['id'] === $selectedTenantId) $activeUnitCount = (int) $sede['unidades_ativas'];
}
$actorName = null;
if ($profile !== null) {
    $actorQuery = $pdo->prepare('SELECT nome FROM usuarios WHERE id = :id LIMIT 1');
    $actorQuery->execute(['id' => (int) $profile['ator_usuario_id']]);
    $candidateName = $actorQuery->fetchColumn();
    $actorName = is_string($candidateName) ? $candidateName : null;
}
$modeLabel = match ($profile['modo'] ?? null) {
    'local_host' => 'Local Host ativo',
    'hospedado' => 'Hospedado ativo',
    default => 'Aguardando configuração inicial',
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Modo de hospedagem</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <link rel="stylesheet" href="/assets/admin-installation.css">
    <script src="/assets/admin-installation.js" defer></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/admin/instalacao.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a>
    <p class="nav-heading">ADMINISTRAÇÃO</p>
    <nav aria-label="Navegação administrativa">
        <a href="/admin/migracao-banco.php">Visão geral</a>
        <a href="#installation">Clientes</a><a href="#installation">Domínios</a>
        <a href="/admin/atualizar-schema.php">Operação</a>
        <a class="active" aria-current="page" href="/admin/instalacao.php">Instalação</a>
        <a href="/admin/backup-destinos.php">Backup externo</a><a href="/admin/atendimento-ia.php">Atendimento e IA</a>
    </nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= installationPageEscape($admin['nome']) ?></strong><small>Superadmin</small></span></div>
</aside>
<header class="topbar">
    <p>Administração <span aria-hidden="true">/</span> <strong>Instalação</strong></p>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= installationPageEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form>
</header>
<main class="content" id="installation">
    <p class="breadcrumb">Administração&nbsp; / &nbsp;Instalação</p>
    <div class="page-heading"><div><h1>Modo de hospedagem</h1><p>Escolha como esta instalação identifica a empresa e os endereços do sistema.</p></div></div>
    <div class="info-banner" role="note"><span aria-hidden="true">i</span><div><strong>Configuração global da instalação</strong><p>A alteração não move dados. Ela define quais hostnames e empresas esta aplicação pode atender.</p></div></div>
    <?php if ($notice !== ''): ?><p class="notice <?= str_contains($notice, 'Não foi possível') || str_contains($notice, 'Confirme') ? 'notice-error' : '' ?>" role="<?= str_contains($notice, 'Não foi possível') || str_contains($notice, 'Confirme') ? 'alert' : 'status' ?>" aria-live="polite"><?= installationPageEscape($notice) ?></p><?php endif; ?>
    <form class="installation-form" method="post" action="/admin/instalacao.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?= installationPageEscape($csrfToken) ?>">
        <fieldset class="mode-options">
            <legend>Perfil de instalação</legend>
            <label class="mode-card<?= $selectedMode === 'hospedado' ? ' selected' : '' ?>">
                <input type="radio" name="modo" value="hospedado"<?= $selectedMode === 'hospedado' ? ' checked' : '' ?>>
                <span class="mode-title">Hospedado</span>
                <span>Atende vários clientes por subdomínios e domínios próprios.</span>
                <span>O tenant é resolvido por host validado e sessão autenticada.</span>
                <small>Para hospedagem compartilhada ou VPS publicada na internet.</small>
            </label>
            <label class="mode-card<?= $selectedMode === 'local_host' ? ' selected' : '' ?>">
                <input type="radio" name="modo" value="local_host"<?= $selectedMode === 'local_host' ? ' checked' : '' ?>>
                <span class="mode-title">Local Host</span>
                <span>Esta instalação atende uma empresa Sede e as unidades dela.</span>
                <span>Não usa hostname da plataforma, subdomínio ou domínio próprio.</span>
                <small>A Sede é selecionada pelo superadmin; usuários continuam no próprio escopo.</small>
            </label>
        </fieldset>

        <section class="panel mode-fields hosted-fields" id="hosted-fields"<?= $selectedMode !== 'hospedado' ? ' hidden' : '' ?> aria-labelledby="hosted-title">
            <h2 id="hosted-title">Hostnames públicos</h2>
            <p>Informe os endereços públicos usados pela instalação hospedada.</p>
            <div class="field-grid">
                <label for="platform-host">Hostname da plataforma
                    <input id="platform-host" name="hostname_plataforma" type="text" maxlength="253" autocomplete="url" value="<?= installationPageEscape($platformHost) ?>" placeholder="admin.exemplo.com">
                </label>
                <label for="base-domain">Domínio base dos clientes
                    <input id="base-domain" name="dominio_base" type="text" maxlength="253" autocomplete="url" value="<?= installationPageEscape($baseDomain) ?>" placeholder="exemplo.com">
                </label>
            </div>
            <div class="warning-banner"><span class="warning-icon" aria-hidden="true">!</span><div><strong>Antes de ativar os domínios</strong><p>O DNS deve apontar para esta aplicação e o HTTPS precisa estar ativo. Domínios próprios dos clientes são verificados separadamente e só resolvem após validação.</p></div></div>
        </section>

        <section class="panel mode-fields local-fields" id="local-fields"<?= $selectedMode !== 'local_host' ? ' hidden' : '' ?> aria-labelledby="local-title">
            <h2 id="local-title">Empresa disponível nesta instalação</h2>
            <p>Selecione uma única Sede ativa. As unidades descendentes continuam acessíveis conforme o perfil do usuário.</p>
            <div class="field-grid local-grid">
                <label for="local-tenant">Empresa Sede
                    <select id="local-tenant" name="tenant_local_id"<?= $sedes === [] ? ' disabled' : '' ?>>
                        <option value="">Selecione uma Sede ativa</option>
                        <?php foreach ($sedes as $sede): ?>
                            <option value="<?= (int) $sede['id'] ?>" data-units="<?= (int) $sede['unidades_ativas'] ?>"<?= (string) $sede['id'] === $selectedTenantId ? ' selected' : '' ?>><?= installationPageEscape((string) $sede['nome_organizacao']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="unit-count" aria-live="polite"><span>Unidades ativas vinculadas</span><strong id="active-unit-count"><?= $activeUnitCount ?> <?= $activeUnitCount === 1 ? 'unidade ativa' : 'unidades ativas' ?></strong></div>
            </div>
            <?php if ($sedes === []): ?><p class="notice notice-error" role="alert">Não há Sede ativa disponível. Ative ou configure uma Sede antes de selecionar Local Host.</p><?php endif; ?>
            <div class="warning-banner"><span class="warning-icon" aria-hidden="true">!</span><div><strong>Limites do modo Local Host</strong><p>Uma única Sede pode operar nesta instalação. Domínios próprios ficam desativados; as URLs usam localhost e só são acessíveis nesta máquina. O acesso respeita o usuário e a Sede configurados.</p></div></div>
        </section>

        <section class="profile-status panel" aria-labelledby="profile-status-title">
            <h2 id="profile-status-title">Estado da configuração</h2>
            <p><span class="status-chip <?= $profile === null ? 'attention' : 'good' ?>"><?= installationPageEscape($modeLabel) ?></span>
                <?php if ($profile !== null && $actorName !== null): ?><span class="saved-by">Salvo por <?= installationPageEscape($actorName) ?> · revisão <?= (int) $profile['id'] ?> · <?= installationPageEscape((string) $profile['criado_em']) ?></span><?php endif; ?></p>
        </section>

        <div class="form-footer">
            <label class="confirm-impact"><input type="checkbox" name="confirm_impact" value="1" required><span>Confirmo que compreendi o efeito: o perfil será aplicado nas próximas requisições, sem mover nem apagar dados.</span></label>
            <button class="primary-button" type="submit">Salvar configuração</button>
        </div>
    </form>
</main>
</body>
</html>
