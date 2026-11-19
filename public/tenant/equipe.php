<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_operator_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/tenant_delegated_permissions.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy_support.php';

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
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'update_permissions') {
        if (!tenantDelegationCanManage($pdo, $context, $identity, $tenantId)) {
            http_response_code(403);
            exit('Sua conta não tem permissão para alterar as permissões delegadas.');
        }
        try {
            tenantDelegatedPermissionsSet(
                $pdo,
                $context,
                $identity,
                $tenantId,
                $_POST['permissions'] ?? []
            );
            $_SESSION['tenant_team_notice'] = 'Permissões delegadas atualizadas para as unidades da rede.';
        } catch (DomainException $exception) {
            $_SESSION['tenant_team_notice'] = match ($exception->getMessage()) {
                'TENANT_DELEGATION_INVALID' => 'A seleção de permissões é inválida. Revise e tente novamente.',
                'TENANT_DELEGATION_PERMISSION_FORBIDDEN' => 'Uma ou mais permissões não podem ser delegadas às unidades.',
                'TENANT_DELEGATION_PERMISSION_UNKNOWN', 'TENANT_DELEGATION_PERMISSION_UNAVAILABLE' => 'Uma ou mais permissões não estão disponíveis no plano da Sede.',
                'TENANT_DELEGATION_ROOT_INACTIVE' => 'A conta da Sede não pode alterar permissões neste momento.',
                'TENANT_DELEGATION_PLAN_UNAVAILABLE' => 'O plano da Sede não permite alterar permissões neste momento.',
                'TENANT_DELEGATION_DENIED' => 'Sua conta não tem permissão para alterar as permissões delegadas.',
                default => 'Não foi possível atualizar as permissões. Revise a seleção e tente novamente.',
            };
        } catch (Throwable) {
            $_SESSION['tenant_team_notice'] = 'Não foi possível atualizar as permissões. Tente novamente.';
        }
        session_write_close();
        header('Location: /tenant/equipe.php', true, 303);
        exit;
    }
    if ($action !== 'create_operator') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    if (!tenantSubclientOperatorCanManage($pdo, $context, $identity, $tenantId)) {
        http_response_code(403);
        exit('Sua conta não tem permissão para criar operadores.');
    }
    $unitId = filter_var($_POST['subcliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if ($unitId === false) {
            throw new DomainException('TENANT_OPERATOR_INVALID');
        }
        tenantSubclientOperatorCreate($pdo, $context, $identity, $tenantId, (int) $unitId, [
            'nome' => is_string($_POST['nome'] ?? null) ? $_POST['nome'] : null,
            'email' => is_string($_POST['email'] ?? null) ? $_POST['email'] : null,
            'senha' => is_string($_POST['senha'] ?? null) ? $_POST['senha'] : null,
        ]);
        $_SESSION['tenant_team_notice'] = 'Responsável de unidade criado com acesso restrito à unidade selecionada.';
    } catch (DomainException $exception) {
        $_SESSION['tenant_team_notice'] = match ($exception->getMessage()) {
            'TENANT_OPERATOR_INVALID' => 'Confira nome, e-mail, unidade e senha (mínimo de 12 caracteres).',
            'TENANT_OPERATOR_TARGET_INVALID' => 'A unidade selecionada não está disponível para novos acessos.',
            'TENANT_OPERATOR_EMAIL_CONFLICT' => 'E-mail indisponível. Informe outro endereço.',
            'TENANT_OPERATOR_QUOTA_UNCONFIGURED' => 'O limite de operadores da Sede não está configurado. Fale com o administrador da plataforma.',
            'TENANT_OPERATOR_QUOTA_EXCEEDED' => 'Limite de operadores atingido na Sede ou na unidade selecionada.',
            'TENANT_OPERATOR_ROOT_INACTIVE' => 'A conta da Sede não pode criar operadores neste momento.',
            'TENANT_OPERATOR_PLAN_UNAVAILABLE' => 'O plano da Sede não permite criar operadores neste momento.',
            'TENANT_OPERATOR_DENIED' => 'Sua conta não tem permissão para criar operadores.',
            default => 'Não foi possível criar o acesso. Revise os dados e tente novamente.',
        };
    } catch (Throwable) {
        $_SESSION['tenant_team_notice'] = 'Não foi possível criar o acesso. Tente novamente.';
    }
    session_write_close();
    header('Location: /tenant/equipe.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$rootQuery = $pdo->prepare(
    "SELECT nome_organizacao, plano_id, limite_usuarios_equipe, permissoes_unidade_json FROM tenants "
    . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
);
$rootQuery->execute(['id' => $tenantId]);
$root = $rootQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($root)) {
    http_response_code(404);
    exit('Página não encontrada.');
}

$operatorQuery = $pdo->prepare(
    "SELECT u.id, u.nome, u.email, u.ativo, u.papel_sistema, u.subcliente_id, "
    . "unit.nome_organizacao AS unidade, profile.nome_perfil "
    . "FROM usuarios u INNER JOIN tenants unit ON unit.id = u.subcliente_id "
    . "AND unit.parent_id = u.tenant_id AND unit.tipo = 'subcliente' "
    . "LEFT JOIN perfis_acesso profile ON profile.id = u.perfil_acesso_id AND profile.tenant_id = u.tenant_id "
    . "WHERE u.tenant_id = :tenant AND u.subcliente_id IS NOT NULL "
    . "AND u.papel_sistema IN ('responsavel_sub', 'subacesso') ORDER BY unit.nome_organizacao, u.nome, u.id"
);
$operatorQuery->execute(['tenant' => $tenantId]);
$operators = $operatorQuery->fetchAll(PDO::FETCH_ASSOC);
$selectedOperator = null;
$effectivePermissions = [];
$selectedOperatorId = filter_var($_GET['operador'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($selectedOperatorId !== false) {
    $selectedQuery = $pdo->prepare(
        "SELECT u.id, u.nome, u.email, u.papel_sistema, u.subcliente_id, unit.nome_organizacao AS unidade "
        . "FROM usuarios u INNER JOIN tenants unit ON unit.id = u.subcliente_id "
        . "AND unit.parent_id = u.tenant_id AND unit.tipo = 'subcliente' "
        . "WHERE u.id = :user AND u.tenant_id = :tenant AND u.subcliente_id IS NOT NULL "
        . "AND u.papel_sistema IN ('responsavel_sub', 'subacesso') LIMIT 1"
    );
    $selectedQuery->execute(['user' => $selectedOperatorId, 'tenant' => $tenantId]);
    $selectedOperatorRow = $selectedQuery->fetch(PDO::FETCH_ASSOC);
    if (is_array($selectedOperatorRow)) {
        $selectedOperator = $selectedOperatorRow;
        $operatorContext = new TenantContext('subcliente', $tenantId, (int) $selectedOperator['subcliente_id'], []);
        $operatorIdentity = [
            'id' => (int) $selectedOperator['id'],
            'tenant_id' => $tenantId,
            'subcliente_id' => (int) $selectedOperator['subcliente_id'],
            'papel_sistema' => (string) $selectedOperator['papel_sistema'],
        ];
        $allPermissionsQuery = $pdo->prepare(
            'SELECT p.chave, p.descricao FROM permissoes p '
            . 'INNER JOIN plano_feature_flags f ON f.feature_key = p.chave '
            . 'AND f.plano_id = :plan AND f.habilitado = 1 ORDER BY p.chave'
        );
        $allPermissionsQuery->execute(['plan' => (int) $root['plano_id']]);
        $allPermissions = $allPermissionsQuery->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allPermissions as $permission) {
            if (!tenantDelegatedPermissionIsAllowed((string) $permission['chave'])) continue;
            if (pode(
                $pdo,
                $operatorContext,
                $operatorIdentity,
                (string) $permission['chave'],
                (int) $selectedOperator['subcliente_id']
            )) {
                $effectivePermissions[] = $permission;
            }
        }
    }
}
$operatorCount = tenantSubclientOperatorCount($pdo, $tenantId);
$limitValue = tenantQuotaNullableInteger($root['limite_usuarios_equipe']);
$canManageOperators = tenantSubclientOperatorCanManage($pdo, $context, $identity, $tenantId);
$canManageDelegation = tenantDelegationCanManage($pdo, $context, $identity, $tenantId);
if (!$canManageOperators && !$canManageDelegation) {
    http_response_code(403);
    exit('Sua conta não tem permissão para consultar a equipe da Sede.');
}
$availableOperators = $limitValue === null ? null : max(0, $limitValue - $operatorCount);
$unitsQuery = $pdo->prepare(
    "SELECT id, nome_organizacao FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente' "
    . "AND status_conta = 'ativo' ORDER BY nome_organizacao, id"
);
$unitsQuery->execute(['tenant' => $tenantId]);
$units = $unitsQuery->fetchAll(PDO::FETCH_ASSOC);
$canCreateOperator = $canManageOperators && $availableOperators !== null && $availableOperators > 0 && $units !== [];
$organization = (string) ($context->branding['nome_organizacao'] ?? $root['nome_organizacao'] ?? 'Instituição');
$notice = is_string($_SESSION['tenant_team_notice'] ?? null) ? $_SESSION['tenant_team_notice'] : '';
unset($_SESSION['tenant_team_notice']);
$csrfToken = tenantAuthCsrfToken($_SESSION);

$delegatedPermissions = tenantDelegatedPermissionsDecode($root['permissoes_unidade_json'] ?? null);
$permissionQuery = $pdo->prepare(
    'SELECT p.chave, p.descricao FROM permissoes p '
    . 'INNER JOIN plano_feature_flags f ON f.feature_key = p.chave AND f.plano_id = :plan AND f.habilitado = 1 '
    . 'ORDER BY p.chave'
);
$permissionQuery->execute(['plan' => (int) $root['plano_id']]);
$delegablePermissions = array_values(array_filter(
    $permissionQuery->fetchAll(PDO::FETCH_ASSOC),
    static fn (array $permission): bool => tenantDelegatedPermissionIsAllowed((string) $permission['chave'])
));

function tenantTeamEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tenantTeamRole(array $operator): string
{
    if ($operator['papel_sistema'] === 'responsavel_sub') return 'Responsável da unidade';
    return is_string($operator['nome_perfil'] ?? null) && $operator['nome_perfil'] !== ''
        ? (string) $operator['nome_perfil']
        : 'Acesso de unidade';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Equipe</title>
    <link rel="stylesheet" href="/assets/tenant-admin.css">
    <link rel="stylesheet" href="/assets/tenant-team.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/tenant/unidades.php"><span class="brand-mark" aria-hidden="true">+</span><strong>IEPRCard</strong></a>
    <p class="nav-heading">INSTITUIÇÃO</p>
    <p class="organization"><?= tenantTeamEscape($organization) ?></p>
    <nav aria-label="Navegação da instituição">
        <a href="/tenant/unidades.php">Unidades</a>
        <a class="active" aria-current="page" href="/tenant/equipe.php">Equipe</a>
        <a href="/tenant/dominios.php">Domínios próprios</a>
    </nav>
    <p class="sidebar-note">Área do Cliente Master da Sede</p>
</aside>
<header class="topbar"><p>Painel <span aria-hidden="true">/</span> <strong>Equipe</strong></p></header>
<main class="content">
    <div class="page-heading">
        <div><h1>Equipe</h1><p>Gerencie quem opera cada unidade e o que pode fazer.</p></div>
        <?php if ($canCreateOperator): ?><a class="primary-link" href="#novo-operador">+ Adicionar operador</a>
        <?php else: ?><span class="disabled-action" aria-disabled="true">+ Adicionar operador</span><?php endif; ?>
    </div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= tenantTeamEscape($notice) ?></p><?php endif; ?>
    <p class="info-banner"><strong>A criação e a gestão de acessos são exclusivas da Sede.</strong><br>Operadores de unidade não podem criar outros usuários nem ampliar as permissões delegadas.</p>
    <?php if ($limitValue === null): ?><p class="team-quota-notice" role="status">O limite de operadores da Sede ainda não está configurado.</p>
    <?php elseif ($availableOperators === 0): ?><p class="team-quota-notice" role="status">Limite de operadores da Sede atingido (<?= number_format($operatorCount, 0, ',', '.') ?> de <?= number_format($limitValue, 0, ',', '.') ?>).</p><?php endif; ?>
    <section class="panel" aria-labelledby="team-title">
        <h2 id="team-title">Operadores das unidades</h2>
        <p class="subtle">Acesso da conta: <?= number_format($operatorCount, 0, ',', '.') ?> de <?= $limitValue === null ? 'Não configurado' : number_format($limitValue, 0, ',', '.') ?> operadores utilizados</p>
        <?php if ($operators === []): ?><p class="empty-state">Sua rede ainda não tem operadores de unidade.</p>
        <?php else: ?>
            <div class="table-scroll" role="region" aria-label="Operadores das unidades" tabindex="0">
                <table class="team-list">
                    <thead><tr><th scope="col">Pessoa</th><th scope="col">Unidade</th><th scope="col">Perfil</th><th scope="col">Acesso</th><th scope="col">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($operators as $operator): ?>
                        <tr>
                            <td data-label="Pessoa"><strong><?= tenantTeamEscape((string) $operator['nome']) ?></strong><small><?= tenantTeamEscape((string) $operator['email']) ?></small></td>
                            <td data-label="Unidade"><?= tenantTeamEscape((string) $operator['unidade']) ?></td>
                            <td data-label="Perfil"><?= tenantTeamEscape(tenantTeamRole($operator)) ?></td>
                            <td data-label="Acesso"><span class="status status-<?= (int) $operator['ativo'] === 1 ? 'ativo' : 'inativo' ?>"><?= (int) $operator['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                            <td data-label="Ações"><a href="/tenant/equipe.php?operador=<?= (int) $operator['id'] ?>#acesso-operador">Ver acesso</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php if ($selectedOperator !== null): ?>
    <section class="panel operator-access" id="acesso-operador" aria-labelledby="operator-access-title">
        <h2 id="operator-access-title">Acesso efetivo de <?= tenantTeamEscape((string) $selectedOperator['nome']) ?></h2>
        <p class="subtle"><?= tenantTeamEscape((string) $selectedOperator['unidade']) ?> · <?= tenantTeamEscape((string) $selectedOperator['email']) ?></p>
        <?php if ($effectivePermissions === []): ?><p class="empty-state">Este acesso não tem permissões ativas no momento.</p>
        <?php else: ?><ul><?php foreach ($effectivePermissions as $permission): ?>
            <li><span><?= tenantTeamEscape((string) $permission['descricao']) ?></span><code><?= tenantTeamEscape((string) $permission['chave']) ?></code></li>
        <?php endforeach; ?></ul><?php endif; ?>
        <p class="backend-note">Esta consulta é somente leitura. As permissões refletem o perfil, os limites da Sede e do plano.</p>
    </section>
    <?php endif; ?>
    <?php if ($canCreateOperator): ?>
    <section class="panel create-operator" id="novo-operador" aria-labelledby="new-operator-title">
        <h2 id="new-operator-title">Adicionar operador</h2>
        <p class="subtle">O acesso será criado como responsável de uma unidade e ficará restrito a ela.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= tenantTeamEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="create_operator">
            <label for="operator-name">Nome</label><input id="operator-name" name="nome" type="text" maxlength="180" autocomplete="name" required>
            <label for="operator-email">E-mail</label><input id="operator-email" name="email" type="email" maxlength="254" autocomplete="email" required>
            <label for="operator-unit">Unidade</label>
            <select id="operator-unit" name="subcliente_id" required>
                <option value="">Selecione uma unidade</option>
                <?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>"><?= tenantTeamEscape((string) $unit['nome_organizacao']) ?></option><?php endforeach; ?>
            </select>
            <label for="operator-password">Senha temporária</label><input id="operator-password" name="senha" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required>
            <small>Use pelo menos 12 caracteres e compartilhe a senha inicial de forma segura.</small>
            <button type="submit">Criar responsável</button>
        </form>
    </section>
    <?php endif; ?>
    <section class="panel delegated-access" aria-labelledby="delegated-access-title">
        <h2 id="delegated-access-title">Permissões delegadas às unidades</h2>
        <p class="subtle">Estas permissões são o limite máximo de acesso dos operadores da rede.</p>
        <?php if ($delegablePermissions === []): ?><p class="empty-state">O plano não tem permissões de negócio disponíveis para delegação.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= tenantTeamEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="update_permissions">
                <ul><?php foreach ($delegablePermissions as $permission): ?>
                    <li>
                        <label><input type="checkbox" name="permissions[]" value="<?= tenantTeamEscape((string) $permission['chave']) ?>"<?= in_array($permission['chave'], $delegatedPermissions, true) ? ' checked' : '' ?><?= $canManageDelegation ? '' : ' disabled' ?>>
                            <span><?= tenantTeamEscape((string) $permission['descricao']) ?></span><code><?= tenantTeamEscape((string) $permission['chave']) ?></code></label>
                    </li>
                <?php endforeach; ?></ul>
                <?php if ($canManageDelegation): ?><button type="submit">Salvar permissões</button>
                <?php else: ?><p class="backend-note">Sua conta não pode alterar as permissões delegadas.</p><?php endif; ?>
            </form>
        <?php endif; ?>
        <p class="backend-note">O perfil de cada pessoa pode restringir ainda mais este limite. Novos acessos respeitam o limite total da Sede e o limite opcional da unidade.</p>
    </section>
</main>
</body>
</html>
