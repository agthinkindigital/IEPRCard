<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_plans.php';

$admin = adminExigirAcesso();
$pdo = dbConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

function adminPlansEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminPlansInputString(array $source, string $key, int $maxLength): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value)) return '';
    $value = trim($value);
    $length = preg_match_all('/./us', $value);

    return $length !== false && $length <= $maxLength ? $value : '';
}

$notice = is_string($_SESSION['admin_plan_notice'] ?? null) ? $_SESSION['admin_plan_notice'] : '';
unset($_SESSION['admin_plan_notice']);
$formValues = is_array($_SESSION['admin_plan_form'] ?? null) ? $_SESSION['admin_plan_form'] : [];
unset($_SESSION['admin_plan_form']);

if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    if (($_POST['action'] ?? null) === 'toggle_active') {
        $planId = filter_var($_POST['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            if ($planId === false) throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
            $current = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
            $current->execute(['id' => (int) $planId]);
            $active = $current->fetchColumn();
            if ($active === false) throw new DomainException('ADMIN_TENANT_PLAN_INVALID');
            adminTenantPlanSetActive($pdo, new TenantContext('admin', null, null, []), [
                'id' => (int) $admin['id'], 'papel_sistema' => $admin['papel_sistema'],
            ], (int) $planId, (int) $active !== 1);
            $_SESSION['admin_plan_notice'] = (int) $active === 1 ? 'Plano desativado.' : 'Plano ativado para novas Sedes.';
        } catch (DomainException $exception) {
            $_SESSION['admin_plan_notice'] = $exception->getMessage() === 'ADMIN_TENANT_PLAN_IN_USE'
                ? 'Não é possível desativar um plano enquanto ele tiver Sedes ativas.'
                : 'Não foi possível alterar o estado do plano.';
        } catch (Throwable) {
            $_SESSION['admin_plan_notice'] = 'Não foi possível alterar o estado do plano agora.';
        }
        session_write_close();
        header('Location: /admin/planos.php', true, 303);
        exit;
    }
    if (($_POST['action'] ?? null) !== 'create') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    $formValues = [
        'nome' => adminPlansInputString($_POST, 'nome', 120),
        'descricao' => adminPlansInputString($_POST, 'descricao', 1000),
        'ativo' => ($_POST['ativo'] ?? null) === '1',
        'permissoes' => is_array($_POST['permissoes'] ?? null) ? $_POST['permissoes'] : [],
        'cotas' => is_array($_POST['cotas'] ?? null) ? $_POST['cotas'] : [],
    ];
    try {
        adminTenantPlanCreate($pdo, new TenantContext('admin', null, null, []), [
            'id' => (int) $admin['id'], 'papel_sistema' => $admin['papel_sistema'],
        ], $formValues);
        $_SESSION['admin_plan_notice'] = 'Plano criado. Você já pode atribuí-lo ao cadastrar uma Sede.';
        unset($_SESSION['admin_plan_form']);
    } catch (DomainException $exception) {
        $_SESSION['admin_plan_form'] = $formValues;
        $_SESSION['admin_plan_notice'] = match ($exception->getMessage()) {
            'ADMIN_TENANT_PLAN_NAME_CONFLICT' => 'Já existe um plano com esse nome. Escolha outro nome.',
            'ADMIN_TENANT_PLAN_DENIED' => 'Esta conta não pode gerenciar planos.',
            default => 'Não foi possível criar o plano. Revise recursos e cotas e tente novamente.',
        };
    } catch (Throwable) {
        $_SESSION['admin_plan_form'] = $formValues;
        $_SESSION['admin_plan_notice'] = 'Não foi possível criar o plano agora. Tente novamente.';
    }
    session_write_close();
    header('Location: /admin/planos.php', true, 303);
    exit;
}

$plans = $pdo->query(
    'SELECT p.id, p.nome, p.descricao, p.ativo, p.ordem, '
    . '(SELECT COUNT(*) FROM tenants t WHERE t.parent_id IS NULL AND t.tipo = \'cliente\' AND t.plano_id = p.id) AS sedes_vinculadas '
    . 'FROM planos p ORDER BY p.ordem, p.id'
)->fetchAll(PDO::FETCH_ASSOC);
$featureStatement = $pdo->query(
    "SELECT chave, descricao FROM permissoes WHERE chave NOT LIKE 'admin.%' AND chave NOT LIKE 'financeiro.%' ORDER BY chave"
);
$features = $featureStatement->fetchAll(PDO::FETCH_ASSOC);
$planFlags = [];
if ($plans !== []) {
    $flagRows = $pdo->query('SELECT f.plano_id, f.feature_key, f.habilitado, f.limite_valor FROM plano_feature_flags f ORDER BY f.plano_id, f.feature_key')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($flagRows as $flag) {
        $planFlags[(int) $flag['plano_id']][(string) $flag['feature_key']] = $flag;
    }
}
$quotaFeatures = adminTenantPlanQuotaFeatureMap();
$quotaLabels = [
    'limite_subclientes' => 'Unidades',
    'limite_usuarios_equipe' => 'Equipe',
    'limite_titulares' => 'Titulares',
    'limite_carteirinhas' => 'Emissões',
];
$csrfToken = adminAuthCsrfToken($_SESSION);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Planos de clientes · IEPRCard</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <link rel="stylesheet" href="/assets/admin-tenants.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/admin/planos.php"><span class="brand-mark" aria-hidden="true">I</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO GLOBAL</small></span></a>
    <p class="nav-heading">SUPERADMIN · MASTER</p>
    <nav aria-label="Navegação administrativa">
        <a href="/admin/sedes.php">Sedes e acessos</a>
        <a class="active" aria-current="page" href="/admin/planos.php">Planos de clientes</a>
        <a href="/admin/instalacao.php">Perfil da instalação</a>
        <a href="/admin/eventos-tecnicos.php">Eventos técnicos</a>
        <a href="/admin/backup-destinos.php">Backups</a>
        <a href="/admin/acesso-mcp.php">Acesso MCP</a>
    </nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">M</span><span><strong><?= adminPlansEscape((string) $admin['nome']) ?></strong><small>Master · superadmin</small></span></div>
</aside>
<header class="topbar">
    <p>Painel global <span aria-hidden="true">/</span> <strong>Planos de clientes</strong></p>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= adminPlansEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form>
</header>
<main class="content tenant-admin-content">
    <p class="breadcrumb">Painel global&nbsp; / &nbsp;Planos de clientes</p>
    <div class="page-heading"><div><h1>Planos de clientes</h1><p>Defina permissões e cotas das Sedes. A conta Master não usa plano de cliente.</p></div><a class="primary-button" href="#new-plan">+ Novo plano</a></div>
    <div class="info-banner tenant-info"><strong>Recursos e limites da Sede</strong><p>As cotas definidas no plano valem também quando o superadmin cria usuários ou unidades para uma Sede. Pagamentos e webhooks não fazem parte do MVP.</p></div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= adminPlansEscape($notice) ?></p><?php endif; ?>
    <section class="panel tenant-plan-panel" aria-labelledby="plans-title">
        <div class="section-heading"><h2 id="plans-title">Planos cadastrados</h2><span class="service-badge"><span aria-hidden="true"></span><?= count($plans) ?> <?= count($plans) === 1 ? 'plano' : 'planos' ?></span></div>
        <?php if ($plans === []): ?>
            <div class="empty-state"><strong>Nenhum plano de cliente cadastrado</strong><p>Crie um plano para habilitar recursos e provisionar a primeira Sede.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table class="tenant-table"><thead><tr><th>Plano</th><th>Sedes vinculadas</th><th>Permissões</th><th>Estado</th><th>Cotas por Sede</th><th>Ação</th></tr></thead><tbody>
            <?php foreach ($plans as $plan): $flags = $planFlags[(int) $plan['id']] ?? []; ?>
                <tr><td><strong><?= adminPlansEscape((string) $plan['nome']) ?></strong><?php if ((string) $plan['descricao'] !== ''): ?><small><?= adminPlansEscape((string) $plan['descricao']) ?></small><?php endif; ?></td><td><?= (int) $plan['sedes_vinculadas'] ?></td><td><?= count(array_filter($flags, static fn(array $flag): bool => (int) $flag['habilitado'] === 1)) ?></td><td><span class="status-pill <?= (int) $plan['ativo'] === 1 ? 'status-active' : 'status-pending' ?>"><?= (int) $plan['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td><td><div class="quota-summary"><?php foreach ($quotaFeatures as $quotaKey => $featureKey): $flag = $flags[$featureKey] ?? null; ?><span><?= adminPlansEscape($quotaLabels[$quotaKey]) ?>: <?= is_array($flag) && $flag['limite_valor'] !== null ? (int) $flag['limite_valor'] : '—' ?></span><?php endforeach; ?></div></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= adminPlansEscape($csrfToken) ?>"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="plano_id" value="<?= (int) $plan['id'] ?>"><button class="secondary-button" type="submit"><?= (int) $plan['ativo'] === 1 ? 'Desativar' : 'Ativar' ?></button></form></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="panel tenant-plan-panel" id="new-plan" aria-labelledby="new-plan-title">
        <div class="section-heading"><div><h2 id="new-plan-title">Novo plano de cliente</h2><p>Crie uma configuração de recursos e tetos reutilizável por uma ou mais Sedes.</p></div></div>
        <form method="post" action="/admin/planos.php" class="tenant-form">
            <input type="hidden" name="action" value="create"><input type="hidden" name="csrf_token" value="<?= adminPlansEscape($csrfToken) ?>">
            <div class="field-grid"><label for="plan-name">Nome do plano<input id="plan-name" name="nome" maxlength="120" required value="<?= adminPlansEscape((string) ($formValues['nome'] ?? '')) ?>"></label><label for="plan-description">Descrição<textarea id="plan-description" name="descricao" maxlength="1000" rows="2"><?= adminPlansEscape((string) ($formValues['descricao'] ?? '')) ?></textarea></label></div>
            <fieldset class="plan-quota-fields"><legend>Tetos máximos por Sede</legend><p>Valores inteiros de zero ou maiores. Zero desabilita a capacidade; unidades e equipe consomem as cotas correspondentes.</p><div class="field-grid four-columns">
                <?php foreach ($quotaLabels as $quotaKey => $label): ?><label for="quota-<?= adminPlansEscape($quotaKey) ?>"><?= adminPlansEscape($label) ?><input id="quota-<?= adminPlansEscape($quotaKey) ?>" name="cotas[<?= adminPlansEscape($quotaKey) ?>]" type="number" min="0" max="2147483647" step="1" required value="<?= adminPlansEscape((string) (($formValues['cotas'][$quotaKey] ?? '') ?: '0')) ?>"></label><?php endforeach; ?>
            </div></fieldset>
            <fieldset class="feature-list"><legend>Permissões incluídas</legend><p>Permissões administrativas e financeiras não podem ser concedidas por um plano de cliente.</p><div class="feature-grid">
                <?php foreach ($features as $feature): $key = (string) $feature['chave']; $checked = in_array($key, $formValues['permissoes'] ?? [], true) || ($formValues === [] && in_array($key, ['titular.ver', 'titular.criar', 'titular.importar', 'carteirinha.gerar', 'carteirinha.lote', 'design.ver', 'design.criar', 'design.editar', 'design.mapear'], true)); ?><label class="feature-option"><input type="checkbox" name="permissoes[]" value="<?= adminPlansEscape($key) ?>"<?= $checked ? ' checked' : '' ?>><span><strong><?= adminPlansEscape((string) $feature['descricao']) ?></strong><small><?= adminPlansEscape($key) ?></small></span></label><?php endforeach; ?>
            </div></fieldset>
            <label class="active-option"><input type="checkbox" name="ativo" value="1"<?= ($formValues['ativo'] ?? true) ? ' checked' : '' ?>><span>Disponível para novas Sedes</span></label>
            <div class="form-footer"><button class="primary-button" type="submit">Salvar plano</button></div>
        </form>
    </section>
    <p class="tenant-footnote">Master é o papel global do superadmin, sem plano de cliente. Cobrança, checkout, repasse e webhooks continuam fora do MVP.</p>
</main>
</body>
</html>
