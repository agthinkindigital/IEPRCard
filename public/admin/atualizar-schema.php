<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/schema_update.php';

$admin = adminExigirAcesso();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    if (($_POST['action'] ?? null) !== 'queue') {
        http_response_code(400);
        exit('Ação inválida.');
    }
    if (($_POST['confirm_backup'] ?? null) !== 'yes') {
        http_response_code(400);
        exit('Confirme que criou um backup restaurável no provedor.');
    }

    $preflight = schemaUpdatePreflight();
    if (!$preflight['ready']) {
        $_SESSION['schema_update_notice'] = 'A atualização não foi enfileirada. Resolva os itens bloqueados e valide novamente.';
    } elseif (!$preflight['can_queue']) {
        $_SESSION['schema_update_notice'] = in_array($preflight['job']['status'] ?? '', ['queued', 'running'], true)
            ? 'Já existe uma atualização aguardando ou em execução.'
            : 'A release atual já foi aplicada e o schema está válido.';
    } else {
        try {
            $job = schemaUpdateQueueCreate($admin['id'], true);
            $_SESSION['schema_update_notice'] = $job === null
                ? 'Já existe uma atualização aguardando ou em execução.'
                : 'Atualização enfileirada. O worker de tarefas agendadas iniciará o processamento.';
        } catch (Throwable) {
            http_response_code(503);
            exit('Não foi possível enfileirar a atualização com segurança. Verifique o armazenamento operacional.');
        }
    }
    session_write_close();
    header('Location: /admin/atualizar-schema.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$notice = is_string($_SESSION['schema_update_notice'] ?? null) ? $_SESSION['schema_update_notice'] : '';
unset($_SESSION['schema_update_notice']);
$csrfToken = adminAuthCsrfToken($_SESSION);
$preflight = schemaUpdatePreflight();
$state = $preflight['job'];
$status = (string) ($state['status'] ?? 'idle');
$activeJob = in_array($status, ['queued', 'running'], true);
$jobMessages = [
    'idle' => ['Aguardando início', 'Confirme o backup restaurável para enfileirar esta release.'],
    'queued' => ['Em fila', 'A execução será iniciada pelo worker agendado; você pode sair desta página.'],
    'running' => ['Em execução', 'A atualização está em segundo plano; o estado permanece disponível ao retornar.'],
    'completed' => ['Concluída', 'As migrations foram executadas e o schema canônico foi verificado.'],
    'failed' => ['Falha', 'A execução não foi concluída. Revise o resultado e confirme o schema antes de repetir.'],
    'unknown' => ['Estado indisponível', 'Não foi possível ler o estado operacional da atualização.'],
];
$jobMessage = $jobMessages[$status] ?? $jobMessages['unknown'];
$history = schemaUpdateAuditHistory();
function schemaUpdatePageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function schemaUpdatePageStatusClass(string $status): string
{
    return match ($status) {
        'Aprovado' => 'good',
        'Atenção' => 'attention',
        default => 'bad',
    };
}
function schemaUpdatePageEventLabel(string $event): string
{
    return match ($event) {
        'queued' => 'Enfileirada',
        'running' => 'Em execução',
        'completed' => 'Concluída',
        'failed' => 'Falha',
        default => 'Desconhecida',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Atualização de schema</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <link rel="stylesheet" href="/assets/schema-update.css">
    <script src="/assets/schema-update.js" defer></script>
</head>
<body data-state-endpoint="/admin/atualizar-schema-status.php" data-state="<?= schemaUpdatePageEscape($status) ?>" data-can-queue="<?= $preflight['can_queue'] ? 'true' : 'false' ?>">
<aside class="sidebar">
    <a class="brand" href="/admin/atualizar-schema.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a>
    <p class="nav-heading">PLATAFORMA</p>
    <nav aria-label="Navegação administrativa">
        <a class="active" aria-current="page" href="/admin/atualizar-schema.php">Atualização de schema</a>
        <a href="/admin/instalacao.php">Instalação</a>
        <a href="/admin/migracao-banco.php">Migração de dados SQLite → MySQL</a>
        <a href="/admin/backup-destinos.php">Backup externo</a>
        <a href="/admin/atendimento-ia.php">Atendimento e IA</a>
        <a href="#history">Histórico</a>
    </nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= schemaUpdatePageEscape($admin['nome']) ?></strong><small>Superadmin</small></span></div>
</aside>
<header class="topbar">
    <p>Administração <span aria-hidden="true">/</span> Infraestrutura <span aria-hidden="true">/</span> <strong>Atualização de schema</strong></p>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= schemaUpdatePageEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form>
</header>
<main id="overview" class="content">
    <p class="breadcrumb">Operações&nbsp; / &nbsp;Infraestrutura</p>
    <div class="page-heading"><div><h1>Atualização de schema</h1><p>Aplique as migrations versionadas incluídas nesta release.</p></div><span class="service-badge <?= $preflight['ready'] ? 'good' : 'warn' ?>"><span aria-hidden="true"></span><?= $preflight['ready'] ? 'Pré-verificação concluída' : 'Revisão necessária' ?></span></div>

    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= schemaUpdatePageEscape($notice) ?></p><?php endif; ?>
    <section class="info-banner" aria-label="Escopo da atualização"><span aria-hidden="true">i</span><div><strong>Esta ação altera a estrutura do banco; não copia registros SQLite → MySQL.</strong><p>Somente os arquivos numerados desta release podem ser executados. A tela não aceita SQL, nomes de arquivo ou comandos.</p></div></section>

    <section class="panel checks-panel" aria-labelledby="preflight-title">
        <div class="section-heading"><div><h2 id="preflight-title">Pré-verificação da release</h2><p>Conferência do ambiente, conexão, migrations e schema canônico.</p></div><span class="service-badge <?= $preflight['ready'] ? 'good' : 'warn' ?>"><span aria-hidden="true"></span><?= ucfirst(schemaUpdatePageEscape($preflight['environment'])) ?></span></div>
        <div class="connection-grid schema-summary">
            <article class="connection-card"><div class="card-heading"><span class="database-icon" aria-hidden="true">▤</span><h3>Ambiente e banco</h3></div>
                <dl><div><dt>Ambiente</dt><dd><?= schemaUpdatePageEscape(ucfirst($preflight['environment'])) ?></dd></div><div><dt>Driver</dt><dd><?= schemaUpdatePageEscape(strtoupper($preflight['driver'])) ?></dd></div><div><dt>Banco</dt><dd><?= schemaUpdatePageEscape($preflight['database_name'] !== '' ? $preflight['database_name'] : 'Indisponível') ?></dd></div><div><dt>Schema esperado</dt><dd><?= (int) $preflight['expected_tables'] ?> tabelas</dd></div></dl>
                <small class="subtle">Credenciais e caminhos completos ficam ocultos.</small>
            </article>
            <article class="connection-card"><div class="card-heading"><span class="database-icon" aria-hidden="true">#</span><h3>Migrations da release</h3></div>
                <dl><div><dt>Arquivos numerados</dt><dd><?= count($preflight['migrations']) ?> verificados</dd></div><div><dt>Identificação</dt><dd><code><?= schemaUpdatePageEscape(substr((string) ($preflight['release_id'] ?? ''), 0, 16)) ?></code></dd></div><div><dt>Diferenças atuais</dt><dd><?= (int) $preflight['schema']['drift_count'] ?></dd></div></dl>
                <small class="subtle">A ordem e os hashes vêm do repositório; nada é informado pelo navegador.</small>
            </article>
        </div>
        <ul class="check-list">
            <?php foreach ($preflight['checks'] as $check): ?><li><span class="check-name"><?= schemaUpdatePageEscape($check['label']) ?><small><?= schemaUpdatePageEscape($check['detail']) ?></small></span><span class="check-status <?= schemaUpdatePageStatusClass($check['status']) ?>"><?= schemaUpdatePageEscape($check['status']) ?></span></li><?php endforeach; ?>
        </ul>
        <ul class="migration-list" aria-label="Arquivos versionados incluídos nesta release">
            <?php foreach ($preflight['migrations'] as $migration): ?><li><code><?= schemaUpdatePageEscape($migration['id']) ?>.php</code><span>Expand-only · versão incluída no release</span></li><?php endforeach; ?>
        </ul>
    </section>

    <section class="panel backup-panel" aria-labelledby="backup-title">
        <div class="section-heading"><div><h2 id="backup-title">Backup e janela de execução</h2><p>Faça esta operação em horário de baixo uso.</p></div></div>
        <p>Crie e confirme um backup restaurável no painel do provedor antes de continuar. A aplicação não verifica esse backup externo automaticamente. As migrations podem causar breve bloqueio no banco; alterações já aplicadas não são desfeitas automaticamente.</p>
        <p class="subtle">O worker de tarefas agendadas processa as migrations em ordem e serializa a execução. Após enfileirar, você pode sair e retornar para consultar o estado.</p>
        <form id="queue-form" method="post" action="/admin/atualizar-schema.php">
            <input type="hidden" name="csrf_token" value="<?= schemaUpdatePageEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="queue">
            <label class="confirm-label" for="confirm-backup"><input id="confirm-backup" type="checkbox" name="confirm_backup" value="yes" required><span>Confirmo que o backup recente foi criado no painel do provedor e pode ser restaurado.</span></label>
            <button id="queue-button" class="primary-button" type="submit" disabled>Enfileirar atualização</button>
        </form>
        <?php if (!$preflight['can_queue'] && !$activeJob): ?><p class="subtle" role="status">A atualização ficará disponível quando a pré-verificação permitir uma nova execução.</p><?php endif; ?>
    </section>

    <section class="panel execution-panel" aria-labelledby="execution-title">
        <div class="section-heading"><h2 id="execution-title">Execução</h2><span id="job-status" class="job-chip state-<?= schemaUpdatePageEscape($status) ?>"><?= schemaUpdatePageEscape($jobMessage[0]) ?></span></div>
        <p id="job-message" class="execution-copy"><?= schemaUpdatePageEscape($jobMessage[1]) ?></p>
        <?php if (is_string($state['error'] ?? null) && $state['error'] !== ''): ?><p class="migration-error" role="alert"><?= schemaUpdatePageEscape($state['error']) ?></p><?php endif; ?>
        <?php if (is_array($state['result'] ?? null)): ?><p class="execution-copy">Release <code><?= schemaUpdatePageEscape(substr((string) ($state['result']['release_id'] ?? ''), 0, 16)) ?></code> · <?= (int) ($state['result']['migration_count'] ?? 0) ?> migrations verificadas · <?= (int) ($state['result']['schema_drift_count'] ?? 0) ?> diferenças restantes.</p><?php endif; ?>
    </section>

    <section class="panel results-panel" id="history" aria-labelledby="history-title">
        <div class="section-heading"><div><h2 id="history-title">Histórico de atualizações</h2><p>Registro operacional de release, administrador, horário, resultado e validação do schema.</p></div></div>
        <?php if ($history === []): ?><p>Nenhuma atualização registrada neste ambiente.</p><?php else: ?>
            <div class="table-scroll"><table class="history-table"><thead><tr><th scope="col">Release</th><th scope="col">Administrador</th><th scope="col">Data e hora UTC</th><th scope="col">Evento</th><th scope="col">Schema validado</th></tr></thead><tbody>
            <?php foreach ($history as $entry): ?><tr><td><code><?= schemaUpdatePageEscape(substr((string) ($entry['release_id'] ?? ''), 0, 16)) ?></code></td><td>#<?= (int) ($entry['actor_user_id'] ?? 0) ?></td><td><?= schemaUpdatePageEscape((string) ($entry['created_at'] ?? '')) ?></td><td><?= schemaUpdatePageEscape(schemaUpdatePageEventLabel((string) ($entry['status'] ?? ''))) ?></td><td><?= ($entry['status'] ?? null) === 'completed' && ($entry['schema_drift_count'] ?? -1) === 0 ? 'Sem divergência' : '—' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
