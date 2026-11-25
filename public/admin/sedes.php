<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_invites.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_operator_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/tenant_delegated_permissions.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_access.php';

$admin = adminExigirAcesso();
$pdo = dbConnection();
$context = new TenantContext('admin', null, null, []);
$identity = ['id' => (int) $admin['id'], 'papel_sistema' => $admin['papel_sistema']];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

function adminSedesEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminSedesText(array $input, string $key, int $max): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value)) return '';
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    return $length !== false && $length <= $max ? $value : '';
}

function adminSedesOptionalLimit(array $input, string $key): int|string|null
{
    $value = $input[$key] ?? null;
    if ($value === null || $value === '') return null;
    return is_string($value) || is_int($value) ? $value : null;
}

$notice = is_string($_SESSION['admin_sedes_notice'] ?? null) ? $_SESSION['admin_sedes_notice'] : '';
unset($_SESSION['admin_sedes_notice']);
$oneTimeInviteUrl = is_string($_SESSION['admin_sedes_invite_url'] ?? null) ? $_SESSION['admin_sedes_invite_url'] : '';
unset($_SESSION['admin_sedes_invite_url']);

if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = $_POST['action'] ?? null;
    try {
        if ($action === 'create_direct') {
            $planId = filter_var($_POST['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($planId === false) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
            $limits = tenantQuotaPlanCeilings($pdo, (int) $planId);
            foreach ($limits as $limit) if ($limit === null) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
            tenantProvisionCreate($pdo, $context, $identity, [
                'nome_organizacao' => adminSedesText($_POST, 'nome_organizacao', 180),
                'slug_subdominio' => adminSedesText($_POST, 'slug_subdominio', 63),
                'nome_titular' => adminSedesText($_POST, 'nome_titular', 180),
                'email_titular' => adminSedesText($_POST, 'email_titular', 254),
                'senha_titular' => $_POST['senha_titular'] ?? null,
                'plano_id' => (int) $planId,
                'status_conta' => 'pendente',
            ], null, $limits);
            $_SESSION['admin_sedes_notice'] = 'Sede criada como pendente. Ative-a depois de validar os dados e combinar o acesso com o responsável.';
        } elseif ($action === 'create_invite') {
            $planId = filter_var($_POST['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($planId === false) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
            $invite = adminTenantInviteCreate($pdo, $context, $identity, (int) $planId, adminSedesText($_POST, 'email_convite', 254));
            $_SESSION['admin_sedes_invite_url'] = '/admin/concluir-sede.php?token=' . rawurlencode($invite['token']);
            $_SESSION['admin_sedes_notice'] = 'Convite criado. Copie o link de uso único antes de sair desta página.';
        } elseif ($action === 'activate') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
            adminTenantSedeActivate($pdo, $context, $identity, (int) $tenantId);
            $_SESSION['admin_sedes_notice'] = 'Sede e responsável ativados.';
        } elseif ($action === 'revoke_invite') {
            $inviteId = filter_var($_POST['invite_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($inviteId === false) throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
            adminTenantInviteRevoke($pdo, $context, $identity, (int) $inviteId);
            $_SESSION['admin_sedes_notice'] = 'Convite revogado.';
        } elseif ($action === 'create_unit') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) throw new DomainException('TENANT_SUBCLIENT_INVALID');
            $result = tenantSubclientCreate($pdo, $context, $identity, (int) $tenantId, [
                'nome_organizacao' => adminSedesText($_POST, 'nome_unidade', 180),
                'slug_subdominio' => adminSedesText($_POST, 'slug_unidade', 63),
            ]);
            tenantQuotaSetSubclientAllocation($pdo, $context, $identity, (int) $tenantId, $result['subcliente_id'], [
                'limite_usuarios_equipe' => null, 'limite_titulares' => null, 'limite_carteirinhas' => null,
            ]);
            $_SESSION['admin_sedes_notice'] = 'Unidade criada com cotas em pool da Sede.';
        } elseif ($action === 'create_unit_owner') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $unitId = filter_var($_POST['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false || $unitId === false) throw new DomainException('TENANT_OPERATOR_TARGET_INVALID');
            tenantSubclientOperatorCreate($pdo, $context, $identity, (int) $tenantId, (int) $unitId, [
                'nome' => adminSedesText($_POST, 'nome_responsavel_unidade', 180),
                'email' => adminSedesText($_POST, 'email_responsavel_unidade', 254),
                'senha' => $_POST['senha_responsavel_unidade'] ?? null,
            ]);
            $_SESSION['admin_sedes_notice'] = 'Responsável da unidade criado.';
        } elseif ($action === 'set_delegated_permissions') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) throw new DomainException('TENANT_DELEGATION_DENIED');
            $permissions = is_array($_POST['permissoes_unidade'] ?? null) ? $_POST['permissoes_unidade'] : [];
            tenantDelegatedPermissionsSet($pdo, $context, $identity, (int) $tenantId, $permissions);
            $_SESSION['admin_sedes_notice'] = 'Permissões delegadas às unidades atualizadas.';
        } elseif ($action === 'set_sede_limits') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) throw new DomainException('TENANT_QUOTA_TARGET_INVALID');
            tenantQuotaSetRootLimits($pdo, $context, $identity, (int) $tenantId, [
                'limite_subclientes' => adminSedesOptionalLimit($_POST, 'limite_subclientes'),
                'limite_usuarios_equipe' => adminSedesOptionalLimit($_POST, 'limite_usuarios_equipe'),
                'limite_titulares' => adminSedesOptionalLimit($_POST, 'limite_titulares'),
                'limite_carteirinhas' => adminSedesOptionalLimit($_POST, 'limite_carteirinhas'),
            ]);
            $_SESSION['admin_sedes_notice'] = 'Tetos da Sede atualizados dentro dos limites do plano.';
        } elseif ($action === 'set_unit_allocation') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $unitId = filter_var($_POST['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false || $unitId === false) throw new DomainException('TENANT_QUOTA_TARGET_INVALID');
            tenantQuotaSetSubclientAllocation($pdo, $context, $identity, (int) $tenantId, (int) $unitId, [
                'limite_usuarios_equipe' => adminSedesOptionalLimit($_POST, 'limite_usuarios_equipe'),
                'limite_titulares' => adminSedesOptionalLimit($_POST, 'limite_titulares'),
                'limite_carteirinhas' => adminSedesOptionalLimit($_POST, 'limite_carteirinhas'),
            ]);
            $_SESSION['admin_sedes_notice'] = 'Alocação da unidade atualizada.';
        } elseif ($action === 'create_sede_access') {
            $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) throw new DomainException('ADMIN_TENANT_ACCESS_INVALID');
            $permissions = is_array($_POST['permissoes_acesso'] ?? null) ? $_POST['permissoes_acesso'] : [];
            adminTenantSedeAccessCreate($pdo, $context, $identity, (int) $tenantId, [
                'nome' => adminSedesText($_POST, 'nome_acesso', 180),
                'email' => adminSedesText($_POST, 'email_acesso', 254),
                'senha' => $_POST['senha_acesso'] ?? null,
                'perfil' => adminSedesText($_POST, 'perfil_acesso', 120),
                'permissoes' => $permissions,
            ]);
            $_SESSION['admin_sedes_notice'] = 'Acesso da Sede criado com as permissões selecionadas.';
        } else {
            throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
        }
    } catch (DomainException $exception) {
        $_SESSION['admin_sedes_notice'] = match ($exception->getMessage()) {
            'TENANT_PLAN_UNAVAILABLE' => 'Selecione um plano ativo com permissões e cotas configuradas.',
            'TENANT_QUOTA_EXCEEDED', 'TENANT_SUBCLIENT_QUOTA_EXCEEDED', 'TENANT_OPERATOR_QUOTA_EXCEEDED' => 'A ação ultrapassaria uma cota definida no plano da Sede.',
            'TENANT_SUBCLIENT_SLUG_CONFLICT' => 'Esse identificador de unidade já está em uso.',
            'TENANT_OPERATOR_EMAIL_CONFLICT', 'TENANT_PROVISION_EMAIL_CONFLICT' => 'Não foi possível usar esse e-mail. Verifique os dados e tente novamente.',
            'TENANT_DELEGATION_PERMISSION_FORBIDDEN', 'TENANT_DELEGATION_PERMISSION_UNAVAILABLE' => 'Uma das permissões não é permitida ou não está incluída no plano da Sede.',
            'ADMIN_TENANT_INVITE_UNAVAILABLE' => 'O convite não está disponível, expirou ou já foi utilizado.',
            'ADMIN_TENANT_SEDE_INVALID' => 'A Sede não está mais pendente ou não possui um responsável pendente.',
            default => 'Não foi possível concluir a operação. Verifique os dados e cotas da Sede.',
        };
    } catch (Throwable) {
        $_SESSION['admin_sedes_notice'] = 'Não foi possível concluir a operação agora. Tente novamente.';
    }
    session_write_close();
    header('Location: /admin/sedes.php', true, 303);
    exit;
}

$plans = $pdo->query('SELECT id, nome FROM planos WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll(PDO::FETCH_ASSOC);
$sedes = $pdo->query(
    "SELECT t.id, t.nome_organizacao, t.nome_responsavel, t.slug_subdominio, t.status_conta, t.plano_id, p.nome AS plano_nome, "
    . "u.nome AS owner_name, u.email AS owner_email, t.permissoes_unidade_json, t.limite_subclientes, t.limite_usuarios_equipe, t.limite_titulares, t.limite_carteirinhas "
    . "FROM tenants t LEFT JOIN planos p ON p.id = t.plano_id "
    . "LEFT JOIN usuarios u ON u.tenant_id = t.id AND u.subcliente_id IS NULL AND u.papel_sistema = 'titular_cliente' "
    . "WHERE t.parent_id IS NULL AND t.tipo = 'cliente' ORDER BY t.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$invites = $pdo->query(
    'SELECT i.id, i.expira_em, i.consumido_em, i.revogado_em, i.email_hash, p.nome AS plano_nome '
    . 'FROM tenant_owner_invites i JOIN planos p ON p.id = i.plano_id ORDER BY i.id DESC LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
$unitRows = $pdo->query(
    "SELECT id, parent_id, nome_organizacao, slug_subdominio FROM tenants WHERE tipo = 'subcliente' ORDER BY parent_id, nome_organizacao"
)->fetchAll(PDO::FETCH_ASSOC);
$unitsByTenant = [];
foreach ($unitRows as $unit) $unitsByTenant[(int) $unit['parent_id']][] = $unit;
$features = $pdo->query(
    "SELECT p.chave, p.descricao FROM permissoes p JOIN planos pl ON pl.ativo = 1 "
    . "JOIN plano_feature_flags f ON f.plano_id = pl.id AND f.feature_key = p.chave AND f.habilitado = 1 "
    . "WHERE (p.chave LIKE 'titular.%' OR p.chave LIKE 'carteirinha.%' OR p.chave LIKE 'design.%' OR p.chave = 'telemetria.unidade') "
    . 'GROUP BY p.chave, p.descricao ORDER BY p.chave'
)->fetchAll(PDO::FETCH_ASSOC);
$planFeatures = [];
$planFeatureRows = $pdo->query(
    'SELECT plano_id, feature_key FROM plano_feature_flags WHERE habilitado = 1 ORDER BY feature_key'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($planFeatureRows as $featureRow) $planFeatures[(int) $featureRow['plano_id']][] = (string) $featureRow['feature_key'];
$sedeAccesses = $pdo->query(
    "SELECT u.id, u.tenant_id, u.nome, u.email, u.papel_sistema, u.ativo, p.nome_perfil FROM usuarios u "
    . 'LEFT JOIN perfis_acesso p ON p.id = u.perfil_acesso_id '
    . "WHERE u.subcliente_id IS NULL AND u.papel_sistema IN ('titular_cliente', 'subacesso') ORDER BY u.tenant_id, u.id"
)->fetchAll(PDO::FETCH_ASSOC);
$accessesByTenant = [];
foreach ($sedeAccesses as $access) $accessesByTenant[(int) $access['tenant_id']][] = $access;
$csrfToken = adminAuthCsrfToken($_SESSION);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Sedes e acessos · IEPRCard</title><link rel="stylesheet" href="/assets/admin-migration.css"><link rel="stylesheet" href="/assets/admin-tenants.css">
</head>
<body>
<aside class="sidebar"><a class="brand" href="/admin/sedes.php"><span class="brand-mark" aria-hidden="true">I</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO GLOBAL</small></span></a><p class="nav-heading">SUPERADMIN · MASTER</p><nav aria-label="Navegação administrativa"><a class="active" aria-current="page" href="/admin/sedes.php">Sedes e acessos</a><a href="/admin/planos.php">Planos de clientes</a><a href="/admin/instalacao.php">Perfil da instalação</a><a href="/admin/eventos-tecnicos.php">Eventos técnicos</a><a href="/admin/backup-destinos.php">Backups</a><a href="/admin/acesso-mcp.php">Acesso MCP</a></nav><div class="sidebar-user"><span class="avatar" aria-hidden="true">M</span><span><strong><?= adminSedesEscape((string) $admin['nome']) ?></strong><small>Master · superadmin</small></span></div></aside>
<header class="topbar"><p>Painel global <span aria-hidden="true">/</span> <strong>Sedes e acessos</strong></p><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form></header>
<main class="content tenant-admin-content">
    <p class="breadcrumb">Painel global&nbsp; / &nbsp;Sedes e acessos</p><div class="page-heading"><div><h1>Sedes e acessos</h1><p>Gerencie Sedes, responsáveis, unidades e permissões em qualquer tenant.</p></div><a class="primary-button" href="#new-sede">+ Cadastrar Sede</a></div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= adminSedesEscape($notice) ?></p><?php endif; ?>
    <?php if ($oneTimeInviteUrl !== ''): ?><section class="info-banner tenant-info"><strong>Link de convite — exibido uma vez</strong><p>Envie manualmente ao destinatário. O link expira em 72 horas e fica vinculado ao e-mail informado.</p><label>Link de uso único<input readonly value="<?= adminSedesEscape($oneTimeInviteUrl) ?>" onclick="this.select()"></label></section><?php endif; ?>
    <section class="panel tenant-plan-panel"><div class="section-heading"><h2>Sedes cadastradas</h2><span class="service-badge"><span aria-hidden="true"></span><?= count($sedes) ?> <?= count($sedes) === 1 ? 'Sede' : 'Sedes' ?></span></div>
    <?php if ($sedes === []): ?><div class="empty-state"><strong>Nenhuma Sede cadastrada</strong><p>Cadastre uma Sede com responsável ou crie um convite para o responsável configurá-la.</p></div><?php else: ?>
    <div class="table-wrap"><table class="tenant-table"><thead><tr><th>Organização</th><th>Responsável</th><th>Plano e cotas</th><th>Estado</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($sedes as $sede): ?><tr><td><strong><?= adminSedesEscape((string) $sede['nome_organizacao']) ?></strong><small><?= adminSedesEscape((string) $sede['slug_subdominio']) ?></small></td><td><?= adminSedesEscape((string) ($sede['owner_name'] ?? $sede['nome_responsavel'] ?? '—')) ?><small><?= adminSedesEscape((string) ($sede['owner_email'] ?? '')) ?></small></td><td><?= adminSedesEscape((string) ($sede['plano_nome'] ?? 'Sem plano')) ?><div class="quota-summary"><span>Unidades <?= (int) $sede['limite_subclientes'] ?></span><span>Equipe <?= (int) $sede['limite_usuarios_equipe'] ?></span><span>Titulares <?= (int) $sede['limite_titulares'] ?></span><span>Emissões <?= (int) $sede['limite_carteirinhas'] ?></span></div></td><td><span class="status-pill <?= $sede['status_conta'] === 'ativo' ? 'status-active' : 'status-pending' ?>"><?= $sede['status_conta'] === 'ativo' ? 'Ativa' : 'Pendente' ?></span></td><td><?php if ($sede['status_conta'] === 'pendente'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="tenant_id" value="<?= (int) $sede['id'] ?>"><button class="secondary-button" type="submit">Ativar</button></form><?php else: ?><a class="secondary-button" href="#sede-<?= (int) $sede['id'] ?>">Gerenciar</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
    <?php if ($invites !== []): ?><section class="panel tenant-plan-panel"><div class="section-heading"><h2>Convites de Sede</h2><span>Não armazenamos o e-mail em texto.</span></div><div class="table-wrap"><table class="tenant-table"><thead><tr><th>Plano</th><th>Estado</th><th>Expira em</th><th>Ação</th></tr></thead><tbody><?php foreach ($invites as $invite): $expired = $invite['expira_em'] <= gmdate('Y-m-d H:i:s'); $status = $invite['consumido_em'] !== null ? 'Usado' : ($invite['revogado_em'] !== null ? 'Revogado' : ($expired || $invite['email_hash'] === null ? 'Expirado' : 'Pendente')); ?><tr><td><?= adminSedesEscape((string) $invite['plano_nome']) ?></td><td><?= adminSedesEscape($status) ?></td><td><?= adminSedesEscape((string) $invite['expira_em']) ?> UTC</td><td><?php if ($status === 'Pendente'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>"><button class="secondary-button" type="submit">Revogar</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
    <section class="panel tenant-plan-panel" id="new-sede"><div class="section-heading"><div><h2>Cadastrar Sede</h2><p>Escolha provisionar diretamente ou emitir um convite de uso único.</p></div></div>
    <?php if ($plans === []): ?><div class="empty-state"><strong>Não há plano ativo</strong><p><a href="/admin/planos.php">Crie um plano de cliente</a> antes de provisionar uma Sede.</p></div><?php else: ?>
    <div class="field-grid">
    <form method="post" class="tenant-form"><h3>Criar Sede e primeiro responsável</h3><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="create_direct"><label>Nome da organização<input name="nome_organizacao" maxlength="180" required></label><label>Identificador da Sede<input name="slug_subdominio" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" maxlength="63" required></label><label>Nome do responsável<input name="nome_titular" maxlength="180" required></label><label>E-mail do responsável<input name="email_titular" type="email" maxlength="254" required></label><label>Senha inicial (mínimo 12 caracteres)<input name="senha_titular" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required></label><label>Plano<select name="plano_id" required><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= adminSedesEscape((string) $plan['nome']) ?></option><?php endforeach; ?></select></label><p class="tenant-footnote">A Sede e o responsável começam pendentes. As quatro cotas iniciais recebem os tetos do plano; a ativação é manual.</p><button class="primary-button" type="submit">Criar Sede pendente</button></form>
    <form method="post" class="tenant-form"><h3>Convidar para criar uma Sede</h3><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="create_invite"><label>E-mail vinculado ao convite<input name="email_convite" type="email" maxlength="254" required></label><label>Plano da futura Sede<select name="plano_id" required><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"><?= adminSedesEscape((string) $plan['nome']) ?></option><?php endforeach; ?></select></label><p class="tenant-footnote">O link é mostrado uma vez, vale por 72 horas e só pode ser usado com esse e-mail. A Sede criada começa pendente.</p><button class="primary-button" type="submit">Gerar convite</button></form>
    </div><?php endif; ?></section>
    <?php foreach ($sedes as $sede): if ($sede['status_conta'] !== 'ativo') continue; $tenantId = (int) $sede['id']; ?>
    <section class="panel tenant-plan-panel" id="sede-<?= $tenantId ?>"><div class="section-heading"><div><h2><?= adminSedesEscape((string) $sede['nome_organizacao']) ?> · unidades e acessos</h2><p>As ações do superadmin usam os mesmos limites da Sede e do plano.</p></div></div>
      <div class="field-grid">
       <form method="post" class="tenant-form"><h3>Tetos desta Sede</h3><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="set_sede_limits"><input type="hidden" name="tenant_id" value="<?= $tenantId ?>"><label>Unidades<input name="limite_subclientes" type="number" min="0" max="<?= (int) $sede['limite_subclientes'] ?>" value="<?= (int) $sede['limite_subclientes'] ?>" required></label><label>Equipe<input name="limite_usuarios_equipe" type="number" min="0" max="<?= (int) $sede['limite_usuarios_equipe'] ?>" value="<?= (int) $sede['limite_usuarios_equipe'] ?>" required></label><label>Titulares<input name="limite_titulares" type="number" min="0" max="<?= (int) $sede['limite_titulares'] ?>" value="<?= (int) $sede['limite_titulares'] ?>" required></label><label>Emissões<input name="limite_carteirinhas" type="number" min="0" max="<?= (int) $sede['limite_carteirinhas'] ?>" value="<?= (int) $sede['limite_carteirinhas'] ?>" required></label><button class="secondary-button" type="submit">Salvar tetos</button></form>
       <form method="post" class="tenant-form"><h3>Nova unidade</h3><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="create_unit"><input type="hidden" name="tenant_id" value="<?= $tenantId ?>"><label>Nome da empresa/unidade<input name="nome_unidade" maxlength="180" required></label><label>Identificador da unidade<input name="slug_unidade" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" maxlength="63" required></label><button class="primary-button" type="submit">Criar unidade</button></form>
       <form method="post" class="tenant-form"><h3>Permissões delegadas às unidades</h3><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="set_delegated_permissions"><input type="hidden" name="tenant_id" value="<?= $tenantId ?>"><?php $delegated = tenantDelegatedPermissionsDecode($sede['permissoes_unidade_json'] ?? null); foreach ($features as $feature): $key = (string) $feature['chave']; if (!in_array($key, $planFeatures[(int) $sede['plano_id']] ?? [], true)) continue; ?><label class="feature-option"><input type="checkbox" name="permissoes_unidade[]" value="<?= adminSedesEscape($key) ?>"<?= in_array($key, $delegated, true) ? ' checked' : '' ?>><span><?= adminSedesEscape((string) $feature['descricao']) ?></span></label><?php endforeach; ?><button class="secondary-button" type="submit">Salvar delegações</button></form>
      </div>
      <div class="table-wrap"><table class="tenant-table"><thead><tr><th>Usuários da Sede</th><th>E-mail</th><th>Perfil</th><th>Estado</th></tr></thead><tbody><?php foreach ($accessesByTenant[$tenantId] ?? [] as $access): ?><tr><td><?= adminSedesEscape((string) $access['nome']) ?></td><td><?= adminSedesEscape((string) $access['email']) ?></td><td><?= adminSedesEscape((string) ($access['nome_perfil'] ?? 'Responsável da Sede')) ?></td><td><?= (int) $access['ativo'] === 1 ? 'Ativo' : 'Pendente' ?></td></tr><?php endforeach; ?><tr><td colspan="4"><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="create_sede_access"><input type="hidden" name="tenant_id" value="<?= $tenantId ?>"><input name="nome_acesso" maxlength="180" placeholder="Nome do novo subusuário" required><input name="email_acesso" type="email" maxlength="254" placeholder="E-mail" required><input name="senha_acesso" type="password" minlength="12" maxlength="1024" placeholder="Senha inicial" autocomplete="new-password" required><input name="perfil_acesso" maxlength="120" placeholder="Nome do perfil" required><?php foreach ($features as $feature): $key = (string) $feature['chave']; if (!in_array($key, $planFeatures[(int) $sede['plano_id']] ?? [], true)) continue; ?><label class="feature-option"><input type="checkbox" name="permissoes_acesso[]" value="<?= adminSedesEscape($key) ?>"><span><?= adminSedesEscape((string) $feature['descricao']) ?></span></label><?php endforeach; ?><button class="secondary-button" type="submit">Criar subusuário da Sede</button></form></td></tr></tbody></table></div>
      <?php $units = $unitsByTenant[$tenantId] ?? []; if ($units !== []): ?><div class="table-wrap"><table class="tenant-table"><thead><tr><th>Unidade</th><th>Identificador</th><th>Acesso responsável</th></tr></thead><tbody><?php foreach ($units as $unit): ?><tr><td><?= adminSedesEscape((string) $unit['nome_organizacao']) ?></td><td><?= adminSedesEscape((string) $unit['slug_subdominio']) ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= adminSedesEscape($csrfToken) ?>"><input type="hidden" name="action" value="create_unit_owner"><input type="hidden" name="tenant_id" value="<?= $tenantId ?>"><input type="hidden" name="subcliente_id" value="<?= (int) $unit['id'] ?>"><input name="nome_responsavel_unidade" maxlength="180" placeholder="Nome" required><input name="email_responsavel_unidade" type="email" maxlength="254" placeholder="E-mail" required><input name="senha_responsavel_unidade" type="password" minlength="12" maxlength="1024" placeholder="Senha inicial" autocomplete="new-password" required><button class="secondary-button" type="submit">Criar responsável</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section><?php endforeach; ?>
    <p class="tenant-footnote">Master é a conta global do superadmin, sem plano de cliente. Ativação é manual; pagamentos, repasses e webhooks não fazem parte do MVP.</p>
</main>
</body></html>
