<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_grants.php';
require_once dirname(__DIR__, 2) . '/config/mcp_support_github.php';

$admin = adminExigirAcesso();
if (($admin['papel_sistema'] ?? null) !== 'superadmin') {
    http_response_code(403);
    exit('Acesso negado.');
}
$pdo = dbConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'grant.create') {
            $targets = [];
            foreach ($_POST['targets'] ?? [] as $target) {
                if (!is_string($target) || preg_match('/^(sede|unidade):([1-9][0-9]*)$/D', $target, $match) !== 1) continue;
                $targets[] = ['type' => $match[1], 'id' => (int) $match[2]];
            }
            $capabilities = array_values(array_filter($_POST['capabilities'] ?? [], 'is_string'));
            if (in_array('repo.publish', $capabilities, true)) {
                $github = mcpSupportGithubRead($pdo, $admin);
                if (!$github['configured'] || $github['last_test_status'] !== 'success') {
                    throw new DomainException('MCP_SUPPORT_GITHUB_NOT_READY');
                }
            }
            $created = mcpSupportGrantCreate($pdo, $admin, [
                'label' => mcpSupportAdminPostString($_POST, 'label'),
                'duration' => mcpSupportAdminPostString($_POST, 'duration'),
                'targets' => $targets,
                'capabilities' => $capabilities,
            ]);
            $_SESSION['mcp_support_one_time_secret'] = $created;
            $_SESSION['mcp_support_notice'] = 'Grant criado. Copie o segredo agora; ele não será exibido novamente.';
        } elseif ($action === 'grant.revoke') {
            if (!mcpSupportGrantRevoke($pdo, $admin, $_POST['grant_id'] ?? null)) {
                $_SESSION['mcp_support_notice'] = 'O grant já estava revogado ou expirado.';
            } else {
                $_SESSION['mcp_support_notice'] = 'Grant revogado imediatamente.';
            }
        } elseif ($action === 'github.save_test') {
            $saved = mcpSupportGithubSaveTested($pdo, $admin, mcpSupportAdminPostString($_POST, 'token'));
            $_SESSION['mcp_support_notice'] = $saved['status'] === 'success'
                ? 'PAT testado e salvo cifrado. O valor não pode ser consultado nesta tela.'
                : 'Teste do PAT falhou; o valor anterior, se existente, foi mantido.';
        } elseif ($action === 'github.test') {
            $result = mcpSupportGithubTestSaved($pdo, $admin);
            $_SESSION['mcp_support_notice'] = $result['status'] === 'success'
                ? 'PAT válido; acesso ao repositório e branch DEV confirmados.'
                : 'Teste falhou. O PAT salvo foi mantido; revise permissões e validade.';
        } elseif ($action === 'github.remove') {
            mcpSupportGithubRemove($pdo, $admin);
            $_SESSION['mcp_support_notice'] = 'PAT removido. Publicação via MCP desabilitada.';
        } else {
            http_response_code(400);
            exit('Ação inválida.');
        }
    } catch (DomainException $exception) {
        $_SESSION['mcp_support_notice'] = match ($exception->getMessage()) {
            'MCP_SUPPORT_GITHUB_NOT_READY' => 'Configure e teste o PAT do GitHub antes de conceder publicação em DEV.',
            'MCP_SUPPORT_GITHUB_DENIED', 'MCP_SUPPORT_GRANT_DENIED' => 'Ação disponível somente ao superadmin ativo.',
            default => 'Não foi possível concluir a operação. Revise os campos e tente novamente.',
        };
    } catch (Throwable) {
        $_SESSION['mcp_support_notice'] = 'Não foi possível concluir a operação. Revise os dados e tente novamente.';
    }
    session_write_close();
    header('Location: /admin/acesso-mcp.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$notice = is_string($_SESSION['mcp_support_notice'] ?? null) ? $_SESSION['mcp_support_notice'] : '';
unset($_SESSION['mcp_support_notice']);
$oneTimeSecret = is_array($_SESSION['mcp_support_one_time_secret'] ?? null) ? $_SESSION['mcp_support_one_time_secret'] : null;
unset($_SESSION['mcp_support_one_time_secret']);
$csrfToken = adminAuthCsrfToken($_SESSION);
$github = mcpSupportGithubRead($pdo, $admin);
$grants = mcpSupportGrantList($pdo, $admin);
$sedes = $pdo->query("SELECT id, nome_organizacao FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' ORDER BY nome_organizacao, id")->fetchAll(PDO::FETCH_ASSOC);
$units = $pdo->query("SELECT id, parent_id, nome_organizacao FROM tenants WHERE tipo = 'subcliente' AND status_conta = 'ativo' ORDER BY parent_id, nome_organizacao, id")->fetchAll(PDO::FETCH_ASSOC);

function mcpSupportAdminEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** @param array<string,mixed> $post */
function mcpSupportAdminPostString(array $post, string $key): string
{
    if (!array_key_exists($key, $post) || !is_string($post[$key])) throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
    return $post[$key];
}
function mcpSupportAdminDate(?string $value): string
{
    if ($value === null) return '—';
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? '—' : gmdate('d/m/Y H:i', $timestamp) . ' UTC';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso MCP · IEPRCard</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <style>
        .mcp-layout{display:grid;gap:1.25rem}.mcp-panel{background:#fff;border:1px solid #dce3ec;border-radius:14px;padding:1.25rem}.mcp-panel h2{margin-top:0}.mcp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem}.mcp-field{display:grid;gap:.45rem}.mcp-field input,.mcp-field select{width:100%;padding:.7rem;border:1px solid #bcc8d8;border-radius:8px}.mcp-targets{max-height:260px;overflow:auto;border:1px solid #dce3ec;border-radius:8px;padding:.8rem}.mcp-targets label,.mcp-checks label{display:block;padding:.35rem}.mcp-unit{padding-left:1.5rem!important}.mcp-checks{display:flex;flex-wrap:wrap;gap:.5rem 1.25rem}.mcp-actions{display:flex;gap:.65rem;flex-wrap:wrap;margin-top:1rem}.mcp-secret{overflow-wrap:anywhere;padding:1rem;background:#fff8dc;border:1px solid #ead28a;border-radius:8px}.mcp-table-wrap{overflow:auto}.mcp-table{width:100%;border-collapse:collapse}.mcp-table th,.mcp-table td{text-align:left;padding:.7rem;border-bottom:1px solid #e2e8f0;vertical-align:top}.mcp-muted{color:#596579}.mcp-danger{color:#a32626}.mcp-notice{padding:.8rem 1rem;background:#eaf5ee;border-radius:8px}.mcp-inline{display:inline}.mcp-small{font-size:.88rem}
        @media(max-width:650px){.mcp-table,.mcp-table tbody,.mcp-table tr,.mcp-table td{display:block}.mcp-table thead{display:none}.mcp-table tr{padding:.5rem 0;border-bottom:1px solid #ccd5df}.mcp-table td{border:0;padding:.25rem}}
    </style>
</head>
<body>
<aside class="sidebar"><a class="brand" href="/admin/acesso-mcp.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a>
    <p class="nav-heading">ADMINISTRAÇÃO</p><nav aria-label="Navegação administrativa"><a href="/admin/migracao-banco.php">Visão geral</a><a href="/admin/atualizar-schema.php">Operação</a><a href="/admin/backup-destinos.php">Backup externo</a><a href="/admin/instalacao.php">Instalação</a><a href="/admin/atendimento-ia.php">Atendimento e IA</a><a class="active" aria-current="page" href="/admin/acesso-mcp.php">Acesso MCP</a></nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= mcpSupportAdminEscape($admin['nome']) ?></strong><small>Superadmin</small></span></div></aside>
<header class="topbar"><p>Administração <span aria-hidden="true">/</span> <strong>Acesso MCP</strong></p><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form></header>
<main class="content"><p class="breadcrumb">Administração&nbsp; / &nbsp;Acesso MCP</p>
    <div class="page-heading"><div><h1>Manutenção assistida</h1><p>Grants temporários para tools permitidas e publicação controlada somente em DEV.</p></div></div>
    <?php if ($notice !== ''): ?><p class="mcp-notice" role="status"><?= mcpSupportAdminEscape($notice) ?></p><?php endif; ?>
    <?php if ($oneTimeSecret !== null): ?><section class="mcp-secret" aria-labelledby="secret-title"><h2 id="secret-title">Segredo exibido uma única vez</h2><p>Copie agora para configurar o cliente MCP. A tela não guarda nem recupera esse valor.</p><p><strong>client_id</strong>: <code><?= mcpSupportAdminEscape((string) $oneTimeSecret['public_id']) ?></code></p><p><strong>client_secret</strong>: <code><?= mcpSupportAdminEscape((string) $oneTimeSecret['secret']) ?></code></p><p><strong>Expira em</strong>: <?= mcpSupportAdminEscape(mcpSupportAdminDate((string) $oneTimeSecret['expires_at'])) ?></p></section><?php endif; ?>
    <div class="mcp-layout">
        <section class="mcp-panel" aria-labelledby="github-title"><h2 id="github-title">Publicação em DEV</h2><p>Repositório fixo <code>agthinkindigital/IEPRCard</code>. O PAT é testado antes de ser salvo, cifrado com a chave da instalação e nunca volta a ser exibido.</p>
            <p>Estado: <strong><?= $github['configured'] ? ($github['last_test_status'] === 'success' ? 'Conectado e testado' : 'Configurado; teste pendente ou falhou') : 'Não configurado' ?></strong> · último teste <?= mcpSupportAdminEscape(mcpSupportAdminDate($github['last_tested_at'])) ?></p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><input type="hidden" name="action" value="github.save_test"><label class="mcp-field">Fine-grained PAT restrito a este repositório<input type="password" name="token" required minlength="20" maxlength="500" autocomplete="new-password" placeholder="Cole o novo PAT para substituir"></label><div class="mcp-actions"><button class="primary-button" type="submit">Testar e salvar</button></div></form>
            <?php if ($github['configured']): ?><div class="mcp-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><input type="hidden" name="action" value="github.test"><button class="secondary-button" type="submit">Testar configuração salva</button></form><form method="post" onsubmit="return confirm('Remover o PAT e desabilitar publicação via MCP?')"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><input type="hidden" name="action" value="github.remove"><button class="secondary-button mcp-danger" type="submit">Remover PAT</button></form></div><?php endif; ?>
        </section>
        <section class="mcp-panel" aria-labelledby="grant-title"><h2 id="grant-title">Criar grant temporário</h2><p>As unidades da Sede são congeladas no momento da criação. Um grant de publicação exige PAT testado.</p>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><input type="hidden" name="action" value="grant.create"><div class="mcp-grid"><label class="mcp-field">Rótulo do acesso<input name="label" required maxlength="120" autocomplete="off"></label><label class="mcp-field">Duração<select name="duration" required><?php foreach (MCP_SUPPORT_GRANT_DURATIONS as $duration => $_seconds): ?><option value="<?= mcpSupportAdminEscape($duration) ?>"><?= mcpSupportAdminEscape($duration) ?></option><?php endforeach; ?></select></label></div>
                <h3>Tenants e unidades autorizados</h3><div class="mcp-targets"><?php foreach ($sedes as $sede): ?><label><input type="checkbox" name="targets[]" value="sede:<?= (int) $sede['id'] ?>"> Sede: <?= mcpSupportAdminEscape((string) $sede['nome_organizacao']) ?> <span class="mcp-muted">(inclui unidades atuais)</span></label><?php foreach ($units as $unit): if ((int) $unit['parent_id'] !== (int) $sede['id']) continue; ?><label class="mcp-unit"><input type="checkbox" name="targets[]" value="unidade:<?= (int) $unit['id'] ?>"> Unidade: <?= mcpSupportAdminEscape((string) $unit['nome_organizacao']) ?></label><?php endforeach; endforeach; ?><?php if ($sedes === []): ?><p class="mcp-muted">Não há Sedes ativas para selecionar.</p><?php endif; ?></div>
                <h3>Capacidades</h3><div class="mcp-checks"><label><input type="checkbox" name="capabilities[]" value="system.read"> system.read</label><label><input type="checkbox" name="capabilities[]" value="telemetry.read"> telemetry.read</label><label><input type="checkbox" name="capabilities[]" value="db.inspect"> db.inspect</label><label><input type="checkbox" name="capabilities[]" value="repo.publish" <?= !$github['configured'] || $github['last_test_status'] !== 'success' ? 'disabled' : '' ?>> repo.publish (DEV)<?= !$github['configured'] || $github['last_test_status'] !== 'success' ? ' · configure PAT primeiro' : '' ?></label></div><div class="mcp-actions"><button class="primary-button" type="submit">Criar grant</button></div></form>
        </section>
        <section class="mcp-panel" aria-labelledby="grants-title"><h2 id="grants-title">Grants emitidos</h2><div class="mcp-table-wrap"><table class="mcp-table"><thead><tr><th>Acesso</th><th>Escopo e alvos</th><th>Validade / estado</th><th>Ação</th></tr></thead><tbody><?php foreach ($grants as $grant): ?><tr><td><?= mcpSupportAdminEscape($grant['label']) ?><br><span class="mcp-muted mcp-small">criado por <?= mcpSupportAdminEscape($grant['created_by'] ?? 'conta removida') ?> · <?= mcpSupportAdminEscape(mcpSupportAdminDate($grant['created_at'])) ?></span></td><td><?= mcpSupportAdminEscape(implode(', ', $grant['capabilities'])) ?><br><span class="mcp-muted mcp-small"><?= count($grant['targets']) ?> alvos congelados</span></td><td><?= mcpSupportAdminEscape(mcpSupportAdminDate($grant['expires_at'])) ?><br><strong><?= mcpSupportAdminEscape($grant['state']) ?></strong><br><span class="mcp-muted mcp-small">último uso <?= mcpSupportAdminEscape(mcpSupportAdminDate($grant['last_used_at'])) ?></span></td><td><?php if ($grant['state'] === 'active'): ?><form method="post" onsubmit="return confirm('Revogar este grant imediatamente?')"><input type="hidden" name="csrf_token" value="<?= mcpSupportAdminEscape($csrfToken) ?>"><input type="hidden" name="action" value="grant.revoke"><input type="hidden" name="grant_id" value="<?= (int) $grant['id'] ?>"><button class="secondary-button mcp-danger" type="submit">Revogar</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?><?php if ($grants === []): ?><tr><td colspan="4" class="mcp-muted">Nenhum grant foi criado.</td></tr><?php endif; ?></tbody></table></div></section>
    </div>
</main></body></html>
