<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_provisioning.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$pdo = dbConnection();
$context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($context === null || $context->tipo !== 'cliente' || $context->tenantId === null) {
    http_response_code(404);
    exit('Página não encontrada.');
}
if (!tenantAuthStartSession()) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$identity = tenantAuthCurrentUser($pdo, $context);
if ($identity === null) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
header('Cache-Control: no-store, private');
if ($identity['papel_sistema'] !== 'titular_cliente' || $identity['subcliente_id'] !== null) {
    http_response_code(403);
    exit('Esta área é exclusiva do Cliente Master da Sede.');
}

$tenantId = $context->tenantId;
$rootQuery = $pdo->prepare(
    "SELECT slug_subdominio, limite_subclientes FROM tenants "
    . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
);
$rootQuery->execute(['id' => $tenantId]);
$root = $rootQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($root)) {
    http_response_code(404);
    exit('Página não encontrada.');
}
$slug = (string) $root['slug_subdominio'];
$baseDomain = (string) (getenv('TENANT_BASE_DOMAIN') ?: '');
$limit = filter_var($root['limite_subclientes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$limit = $limit === false ? null : (int) $limit;
$countQuery = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente'");
$countQuery->execute(['tenant' => $tenantId]);
$unitCount = (int) $countQuery->fetchColumn();
$canCreateByRole = tenantSubclientCanCreate($pdo, $context, $identity, $tenantId);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action !== 'create') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    if (!$canCreateByRole) {
        http_response_code(403);
        exit('Sua conta não tem permissão para criar unidades.');
    }

    try {
        tenantSubclientCreate($pdo, $context, $identity, $tenantId, [
            'nome_organizacao' => is_string($_POST['nome_organizacao'] ?? null) ? $_POST['nome_organizacao'] : null,
            'slug_subdominio' => is_string($_POST['slug_subdominio'] ?? null) ? $_POST['slug_subdominio'] : null,
        ]);
        $_SESSION['tenant_units_notice'] = 'Unidade criada. Ela já está vinculada à sua Sede.';
    } catch (DomainException $exception) {
        $_SESSION['tenant_units_notice'] = match ($exception->getMessage()) {
            'TENANT_SUBCLIENT_INVALID' => 'Informe um nome e um identificador válidos para o subdomínio.',
            'TENANT_SUBCLIENT_SLUG_CONFLICT', 'TENANT_SUBCLIENT_RESERVED_SLUG' => 'Subdomínio indisponível. Informe outro identificador.',
            'TENANT_SUBCLIENT_QUOTA_EXCEEDED' => 'Limite de unidades atingido ('
                . tenantQuotaChildCount($pdo, $tenantId) . ' de '
                . (tenantQuotaNullableInteger(tenantQuotaLoadRoot($pdo, $tenantId)['limite_subclientes']) ?? 'não configurado') . ').',
            'TENANT_SUBCLIENT_QUOTA_UNCONFIGURED' => 'O limite da Sede não está configurado. Fale com o administrador da plataforma.',
            'TENANT_SUBCLIENT_ROOT_INACTIVE' => 'A conta da Sede não está ativa e não pode criar unidades.',
            'TENANT_SUBCLIENT_PLAN_UNAVAILABLE' => 'O plano da Sede não está disponível para criar unidades.',
            'TENANT_SUBCLIENT_DENIED' => 'Sua conta não tem permissão para criar unidades.',
            default => 'Não foi possível criar a unidade. Revise os dados e tente novamente.',
        };
    } catch (Throwable) {
        $_SESSION['tenant_units_notice'] = 'Não foi possível criar a unidade. Tente novamente.';
    }
    session_write_close();
    header('Location: /tenant/unidades.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$unitsQuery = $pdo->prepare(
    "SELECT child.id, child.nome_organizacao, child.slug_subdominio, child.status_conta, "
    . "child.limite_titulares, "
    . "(SELECT u.nome FROM usuarios u WHERE u.tenant_id = child.parent_id AND u.subcliente_id = child.id "
    . "AND u.papel_sistema = 'responsavel_sub' AND u.ativo = 1 ORDER BY u.id LIMIT 1) AS responsavel, "
    . "(SELECT COUNT(*) FROM titulares h WHERE h.tenant_id = child.parent_id AND h.subcliente_id = child.id AND h.ativo = 1) AS titulares "
    . "FROM tenants child WHERE child.parent_id = :tenant AND child.tipo = 'subcliente' ORDER BY child.nome_organizacao, child.id"
);
$unitsQuery->execute(['tenant' => $tenantId]);
$units = $unitsQuery->fetchAll(PDO::FETCH_ASSOC);
$notice = is_string($_SESSION['tenant_units_notice'] ?? null) ? $_SESSION['tenant_units_notice'] : '';
unset($_SESSION['tenant_units_notice']);
$csrfToken = tenantAuthCsrfToken($_SESSION);
$available = $limit === null ? null : max(0, $limit - $unitCount);
$canCreate = $canCreateByRole && $available !== null && $available > 0;
$organization = (string) ($context->branding['nome_organizacao'] ?? 'Instituição');

function tenantUnitsEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tenantUnitsStatus(string $status): array
{
    return match ($status) {
        'ativo' => ['Ativa', 'active'],
        'pendente' => ['Pendente', 'pending'],
        'suspenso' => ['Suspensa', 'suspended'],
        'encerrado', 'cancelado' => ['Encerrada', 'inactive'],
        default => ['Em análise', 'pending'],
    };
}

function tenantUnitsAllocation(mixed $limit): string
{
    $value = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $value === false ? 'Pool' : number_format((int) $value, 0, ',', '.');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Unidades</title>
    <link rel="stylesheet" href="/assets/tenant-admin.css">
    <link rel="stylesheet" href="/assets/tenant-units.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/tenant/unidades.php"><span class="brand-mark" aria-hidden="true">+</span><strong>IEPRCard</strong></a>
    <p class="nav-heading">INSTITUIÇÃO</p>
    <p class="organization"><?= tenantUnitsEscape($organization) ?></p>
    <nav aria-label="Navegação da instituição">
        <a class="active" aria-current="page" href="/tenant/unidades.php">Unidades</a>
        <a href="/tenant/equipe.php">Equipe</a>
        <a href="/tenant/dominios.php">Domínios próprios</a>
    </nav>
    <p class="sidebar-note">Área do Cliente Master da Sede</p>
</aside>
<header class="topbar"><p>Painel <span aria-hidden="true">/</span> <strong>Unidades</strong></p></header>
<main class="content">
    <div class="page-heading">
        <div><h1>Unidades</h1><p>Organize filiais e acompanhe os limites da sua Sede.</p></div>
        <?php if ($canCreate): ?><a class="primary-link" href="#nova-unidade">+ Nova unidade</a>
        <?php else: ?><span class="disabled-action" aria-disabled="true">+ Nova unidade</span><?php endif; ?>
    </div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= tenantUnitsEscape($notice) ?></p><?php endif; ?>
    <section class="quota-summary" aria-label="Resumo do limite de unidades">
        <article><span>Unidades cadastradas</span><strong><?= number_format($unitCount, 0, ',', '.') ?></strong></article>
        <article><span>Limite da Sede</span><strong><?= $limit === null ? 'Não configurado' : number_format($limit, 0, ',', '.') ?></strong></article>
        <article><span>Disponíveis</span><strong><?= $available === null ? 'Não configurado' : number_format($available, 0, ',', '.') ?></strong></article>
    </section>
    <?php if ($limit === null): ?><p class="quota-notice" role="status">O limite de unidades da Sede ainda não está configurado.</p>
    <?php elseif ($available === 0): ?><p class="quota-notice" role="status">Limite de unidades atingido. A Sede não pode criar mais unidades agora.</p><?php endif; ?>
    <section class="panel unit-list" aria-labelledby="unit-list-title">
        <h2 id="unit-list-title">Unidades da rede</h2>
        <p class="subtle">Apenas o Cliente Master da Sede pode criar e administrar unidades.</p>
        <?php if ($units === []): ?><p class="empty-state">Sua rede ainda não tem unidades.</p>
        <?php else: ?>
            <div class="unit-table-wrap" role="region" aria-label="Lista de unidades" tabindex="0">
                <table>
                    <thead><tr><th scope="col">Unidade</th><th scope="col">Responsável</th><th scope="col">Situação</th><th scope="col">Titulares</th></tr></thead>
                    <tbody>
                    <?php foreach ($units as $unit): ?>
                        <?php [$statusLabel, $statusClass] = tenantUnitsStatus((string) $unit['status_conta']); ?>
                        <?php $hostname = (string) $unit['slug_subdominio'] . '.' . $slug . ($baseDomain !== '' ? '.' . $baseDomain : ' · domínio da plataforma não configurado'); ?>
                        <?php $holderLimit = tenantUnitsAllocation($unit['limite_titulares']); ?>
                        <tr>
                            <td data-label="Unidade"><strong><?= tenantUnitsEscape((string) $unit['nome_organizacao']) ?></strong><small><?= tenantUnitsEscape($hostname) ?></small></td>
                            <td data-label="Responsável"><?= tenantUnitsEscape(is_string($unit['responsavel']) && $unit['responsavel'] !== '' ? $unit['responsavel'] : 'Nenhum responsável') ?></td>
                            <td data-label="Situação"><span class="unit-status <?= tenantUnitsEscape($statusClass) ?>"><?= tenantUnitsEscape($statusLabel) ?></span></td>
                            <td data-label="Titulares ativos"><?= number_format((int) $unit['titulares'], 0, ',', '.') ?> / <?= tenantUnitsEscape($holderLimit) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="backend-note">Alteração, suspensão e encerramento de unidades ainda não estão disponíveis nesta tela.</p>
    </section>
    <?php if ($canCreate): ?>
    <section class="panel create-unit" id="nova-unidade" aria-labelledby="create-unit-title">
        <h2 id="create-unit-title">Nova unidade</h2>
        <p class="subtle">O subdomínio será exclusivo desta Sede.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= tenantUnitsEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <label for="unit-name">Nome da unidade</label><input id="unit-name" name="nome_organizacao" type="text" maxlength="180" required>
            <label for="unit-slug">Identificador de subdomínio</label>
            <div class="slug-input"><input id="unit-slug" name="slug_subdominio" type="text" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?" maxlength="63" autocapitalize="none" autocomplete="off" required><span>.<?= tenantUnitsEscape($slug) ?>.<?= $baseDomain !== '' ? tenantUnitsEscape($baseDomain) : 'domínio da plataforma não configurado' ?></span></div>
            <small>Use letras minúsculas, números e hífen; comece e termine com letra ou número.</small>
            <button type="submit">Criar unidade</button>
        </form>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
