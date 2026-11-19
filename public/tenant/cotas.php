<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy_support.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_operator_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/titular_provisioning_support.php';

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
$canManage = tenantQuotaCanManageSubclientAllocation($pdo, $context, $identity, $tenantId);
if (!$canManage) {
    http_response_code(403);
    exit('Sua conta não tem permissão para consultar ou distribuir cotas.');
}
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    if (($_POST['action'] ?? null) !== 'save_allocation') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    $subclientId = filter_var($_POST['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if ($subclientId === false) {
            throw new DomainException('TENANT_QUOTA_INVALID');
        }
        $allocation = [];
        foreach (['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'] as $key) {
            $value = $_POST[$key] ?? null;
            if ($value === 'pool' || $value === '') {
                $allocation[$key] = null;
            } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
                $allocation[$key] = $value;
            } else {
                throw new DomainException('TENANT_QUOTA_INVALID');
            }
        }
        tenantQuotaSetSubclientAllocation($pdo, $context, $identity, $tenantId, (int) $subclientId, $allocation);
        $_SESSION['tenant_quotas_notice'] = 'Alocação da unidade atualizada.';
    } catch (DomainException $exception) {
        $_SESSION['tenant_quotas_notice'] = match ($exception->getMessage()) {
            'TENANT_QUOTA_EXCEEDED' => is_int($subclientId)
                ? tenantQuotasExplainExceeded($pdo, $tenantId, $subclientId, $allocation ?? [])
                : 'A alocação excede um limite da Sede. Revise os valores e tente novamente.',
            'TENANT_QUOTA_UNCONFIGURED' => 'Um dos tetos da Sede ainda não está configurado. Fale com o administrador da plataforma.',
            'TENANT_QUOTA_TARGET_INVALID' => 'A unidade não está disponível nesta Sede.',
            'TENANT_QUOTA_INVALID' => 'Informe valores inteiros válidos ou selecione Pool em cada dimensão.',
            'TENANT_QUOTA_DENIED' => 'Sua conta não tem permissão para distribuir cotas.',
            default => 'Não foi possível salvar a alocação. Revise os dados e tente novamente.',
        };
    } catch (Throwable) {
        $_SESSION['tenant_quotas_notice'] = 'Não foi possível salvar a alocação. Tente novamente.';
    }
    session_write_close();
    header('Location: /tenant/cotas.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$root = tenantQuotaLoadRoot($pdo, $tenantId);
$rootUsage = [
    'limite_subclientes' => tenantQuotaNullableInteger($root['limite_subclientes']),
    'limite_usuarios_equipe' => tenantQuotaNullableInteger($root['limite_usuarios_equipe']),
    'limite_titulares' => tenantQuotaNullableInteger($root['limite_titulares']),
    'limite_carteirinhas' => tenantQuotaNullableInteger($root['limite_carteirinhas']),
];
$usageQuery = $pdo->prepare(
    "SELECT (SELECT COUNT(*) FROM usuarios u WHERE u.tenant_id = :tenant_users AND u.ativo = 1 "
    . "AND u.papel_sistema IN ('responsavel_sub', 'subacesso')) AS equipe, "
    . "(SELECT COUNT(*) FROM titulares h WHERE h.tenant_id = :tenant_holders AND h.ativo = 1) AS titulares, "
    . 'consumo_carteirinhas AS carteirinhas FROM tenants WHERE id = :tenant_root'
);
$usageQuery->execute(['tenant_users' => $tenantId, 'tenant_holders' => $tenantId, 'tenant_root' => $tenantId]);
$rootConsumed = $usageQuery->fetch(PDO::FETCH_ASSOC) ?: ['equipe' => 0, 'titulares' => 0, 'carteirinhas' => 0];
$unitQuery = $pdo->prepare(
    "SELECT unit.id, unit.nome_organizacao, unit.limite_usuarios_equipe, unit.limite_titulares, "
    . "unit.limite_carteirinhas, unit.consumo_carteirinhas, "
    . "(SELECT COUNT(*) FROM usuarios u WHERE u.tenant_id = unit.parent_id AND u.subcliente_id = unit.id "
    . "AND u.ativo = 1 AND u.papel_sistema IN ('responsavel_sub', 'subacesso')) AS equipe_usada, "
    . "(SELECT COUNT(*) FROM titulares h WHERE h.tenant_id = unit.parent_id AND h.subcliente_id = unit.id AND h.ativo = 1) AS titulares_usados "
    . "FROM tenants unit WHERE unit.parent_id = :tenant AND unit.tipo = 'subcliente' ORDER BY unit.nome_organizacao, unit.id"
);
$unitQuery->execute(['tenant' => $tenantId]);
$units = $unitQuery->fetchAll(PDO::FETCH_ASSOC);
$fixedTotals = ['limite_usuarios_equipe' => 0, 'limite_titulares' => 0, 'limite_carteirinhas' => 0];
foreach ($units as $unit) {
    foreach ($fixedTotals as $dimension => $_) {
        $fixedTotals[$dimension] += tenantQuotaNullableInteger($unit[$dimension]) ?? 0;
    }
}
$notice = is_string($_SESSION['tenant_quotas_notice'] ?? null) ? $_SESSION['tenant_quotas_notice'] : '';
unset($_SESSION['tenant_quotas_notice']);
$csrfToken = tenantAuthCsrfToken($_SESSION);
$organization = (string) ($context->branding['nome_organizacao'] ?? 'Instituição');

function tenantQuotasEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tenantQuotasFormat(mixed $value): string
{
    $number = tenantQuotaNullableInteger($value);
    return $number === null ? 'Pool' : number_format($number, 0, ',', '.');
}

/** @param array<string, int|string|null> $allocation */
function tenantQuotasExplainExceeded(PDO $pdo, int $tenantId, int $subclientId, array $allocation): string
{
    $dimensions = [
        'limite_usuarios_equipe' => ['equipe', "SELECT COUNT(*) FROM usuarios WHERE tenant_id = :tenant AND subcliente_id = :unit AND ativo = 1 AND papel_sistema IN ('responsavel_sub', 'subacesso')"],
        'limite_titulares' => ['titulares', 'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND subcliente_id = :unit AND ativo = 1'],
        'limite_carteirinhas' => ['emissões', 'SELECT consumo_carteirinhas FROM tenants WHERE id = :unit AND parent_id = :tenant'],
    ];
    $root = tenantQuotaLoadRoot($pdo, $tenantId);
    foreach ($dimensions as $key => [$label, $usageSql]) {
        $candidate = $allocation[$key] ?? null;
        if ($candidate === null) continue;
        $candidate = (int) $candidate;
        $usage = $pdo->prepare($usageSql);
        $usage->execute(['tenant' => $tenantId, 'unit' => $subclientId]);
        $currentUse = (int) $usage->fetchColumn();
        if ($candidate < $currentUse) {
            return 'A cota fixa de ' . $label . ' (' . number_format($candidate, 0, ',', '.')
                . ') não pode ficar abaixo do uso atual (' . number_format($currentUse, 0, ',', '.') . ').';
        }
        $ceiling = tenantQuotaNullableInteger($root[$key]);
        if ($ceiling === null) {
            return 'O teto da Sede para ' . $label . ' ainda não está configurado.';
        }
        $reserved = tenantQuotaFixedAllocationTotal($pdo, $tenantId, $key, $subclientId) + $candidate;
        if ($reserved > $ceiling) {
            return 'As cotas fixas de ' . $label . ' somariam ' . number_format($reserved, 0, ',', '.')
                . ', acima do teto da Sede (' . number_format($ceiling, 0, ',', '.') . ').';
        }
    }
    return 'A alocação excede um limite da Sede. Revise os valores e tente novamente.';
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Cotas da rede</title>
    <link rel="stylesheet" href="/assets/tenant-admin.css">
    <link rel="stylesheet" href="/assets/tenant-quotas.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/tenant/unidades.php"><span class="brand-mark" aria-hidden="true">+</span><strong>IEPRCard</strong></a>
    <p class="nav-heading">INSTITUIÇÃO</p><p class="organization"><?= tenantQuotasEscape($organization) ?></p>
    <nav aria-label="Navegação da instituição">
        <a href="/tenant/unidades.php">Unidades</a><a href="/tenant/equipe.php">Equipe</a>
        <a class="active" aria-current="page" href="/tenant/cotas.php">Cotas</a>
        <a href="/tenant/titulares.php">Titulares</a>
        <a href="/tenant/dominios.php">Domínios próprios</a>
    </nav><p class="sidebar-note">Área do Cliente Master da Sede</p>
</aside>
<header class="topbar"><p>Painel <span aria-hidden="true">/</span> <strong>Cotas</strong></p></header>
<main class="content">
    <div class="page-heading"><div><h1>Cotas da rede</h1><p>Distribua os limites da Sede entre as unidades sem ultrapassar os tetos configurados.</p></div></div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= tenantQuotasEscape($notice) ?></p><?php endif; ?>
    <section class="panel quota-ceilings" aria-labelledby="ceilings-title">
        <h2 id="ceilings-title">Limites definidos para a Sede</h2><p class="subtle">Os tetos são definidos pelo administrador da plataforma.</p>
        <?php foreach ([
            ['label' => 'Unidades', 'key' => 'limite_subclientes', 'used' => tenantQuotaChildCount($pdo, $tenantId)],
            ['label' => 'Equipe', 'key' => 'limite_usuarios_equipe', 'used' => (int) $rootConsumed['equipe']],
            ['label' => 'Titulares ativos', 'key' => 'limite_titulares', 'used' => (int) $rootConsumed['titulares']],
            ['label' => 'Emissões consumidas', 'key' => 'limite_carteirinhas', 'used' => (int) $rootConsumed['carteirinhas']],
        ] as $metric): ?>
            <article><span><?= tenantQuotasEscape($metric['label']) ?></span><strong><?= number_format($metric['used'], 0, ',', '.') ?> / <?= $rootUsage[$metric['key']] === null ? 'não configurado' : number_format($rootUsage[$metric['key']], 0, ',', '.') ?></strong></article>
        <?php endforeach; ?>
    </section>
    <section class="panel quota-units" aria-labelledby="units-title">
        <h2 id="units-title">Alocações por unidade</h2>
        <p class="subtle">Cota fixa reserva parte do limite. Pool usa o saldo compartilhado disponível da Sede.</p>
        <?php if ($units === []): ?><p class="empty-state">Cadastre uma unidade para distribuir cotas.</p>
        <?php else: ?>
        <div class="quota-table-wrap" role="region" aria-label="Alocações por unidade" tabindex="0"><table class="quota-table">
            <thead><tr><th scope="col">Unidade</th><th scope="col">Equipe usada / cota</th><th scope="col">Titulares ativos / cota</th><th scope="col">Emissões consumidas / cota</th><th scope="col">Salvar alocação</th></tr></thead>
            <tbody><?php foreach ($units as $unit): ?>
                <tr><th scope="row" data-label="Unidade"><?= tenantQuotasEscape((string) $unit['nome_organizacao']) ?></th>
                    <td data-label="Equipe usada / cota"><?= number_format((int) $unit['equipe_usada'], 0, ',', '.') ?> / <?= tenantQuotasFormat($unit['limite_usuarios_equipe']) ?></td>
                    <td data-label="Titulares ativos / cota"><?= number_format((int) $unit['titulares_usados'], 0, ',', '.') ?> / <?= tenantQuotasFormat($unit['limite_titulares']) ?></td>
                    <td data-label="Emissões consumidas / cota"><?= number_format((int) $unit['consumo_carteirinhas'], 0, ',', '.') ?> / <?= tenantQuotasFormat($unit['limite_carteirinhas']) ?></td>
                    <td data-label="Salvar alocação"><?php if ($canManage): ?>
                        <form method="post" class="allocation-form">
                            <input type="hidden" name="csrf_token" value="<?= tenantQuotasEscape($csrfToken) ?>"><input type="hidden" name="action" value="save_allocation"><input type="hidden" name="subcliente_id" value="<?= (int) $unit['id'] ?>">
                            <?php foreach (['limite_usuarios_equipe', 'limite_titulares', 'limite_carteirinhas'] as $dimension): ?><label><span><?= tenantQuotasEscape(match ($dimension) { 'limite_usuarios_equipe' => 'Equipe', 'limite_titulares' => 'Titulares', default => 'Emissões' }) ?></span><input type="number" min="0" max="2147483647" name="<?= tenantQuotasEscape($dimension) ?>" value="<?= tenantQuotaNullableInteger($unit[$dimension]) === null ? '' : (int) $unit[$dimension] ?>" placeholder="Pool" aria-label="<?= tenantQuotasEscape($dimension) ?> (vazio = Pool)"></label><?php endforeach; ?>
                            <p class="subtle">Deixe vazio para Pool.</p><button type="submit">Salvar</button>
                        </form>
                    <?php else: ?>Somente leitura<?php endif; ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <div class="fixed-reservations"><strong>Reservas fixas totais</strong>
            <p>Equipe: <?= number_format($fixedTotals['limite_usuarios_equipe'], 0, ',', '.') ?> / <?= tenantQuotasFormat($rootUsage['limite_usuarios_equipe']) ?> · Titulares: <?= number_format($fixedTotals['limite_titulares'], 0, ',', '.') ?> / <?= tenantQuotasFormat($rootUsage['limite_titulares']) ?> · Emissões: <?= number_format($fixedTotals['limite_carteirinhas'], 0, ',', '.') ?> / <?= tenantQuotasFormat($rootUsage['limite_carteirinhas']) ?></p>
            <p>O restante disponível continua no Pool da Sede. A soma fixa não pode ultrapassar o teto.</p>
        </div>
    </section>
</main>
</body>
</html>
