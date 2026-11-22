<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/admin_migration_preflight.php';

$admin = adminExigirAcesso();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'validate') {
        $_SESSION['admin_migration_notice'] = 'Pré-verificações atualizadas.';
        session_write_close();
        header('Location: /admin/migracao-banco.php', true, 303);
        exit;
    }
    if ($action === 'queue') {
        if (($_POST['confirm_copy'] ?? null) !== 'yes') {
            http_response_code(400);
            exit('Confirme que revisou a origem e o destino.');
        }
        $preflight = adminMigrationPreflight();
        if (!$preflight['ready']) {
            $_SESSION['admin_migration_notice'] = 'A cópia não foi enfileirada. Corrija os itens bloqueados e valide novamente.';
            session_write_close();
            header('Location: /admin/migracao-banco.php', true, 303);
            exit;
        }
        $job = adminMigrationQueueCreate($admin['id'], null, null, (int) $preflight['source_records']);
        $_SESSION['admin_migration_notice'] = $job === null
            ? 'Já existe uma cópia aguardando ou em execução.'
            : 'Cópia enfileirada. O worker CLI iniciará o processamento em segundo plano.';
        session_write_close();
        header('Location: /admin/migracao-banco.php', true, 303);
        exit;
    }
    http_response_code(400);
    exit('Ação inválida.');
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$notice = is_string($_SESSION['admin_migration_notice'] ?? null) ? $_SESSION['admin_migration_notice'] : '';
unset($_SESSION['admin_migration_notice']);
$csrfToken = adminAuthCsrfToken($_SESSION);
$state = adminMigrationStateRead();
$preflight = adminMigrationPreflight();
$activeJob = in_array($state['status'] ?? '', ['queued', 'running'], true);
$results = is_array($state['result'] ?? null) ? $state['result'] : null;
$destinationChecks = array_values(array_filter(
    $preflight['checks'],
    static fn(array $check): bool => $check['label'] === 'Destino pronto para receber'
));
$destinationReady = count($destinationChecks) === 1 && $destinationChecks[0]['status'] === 'Aprovado';
function migrationEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function migrationCount(int $value): string
{
    return number_format(max(0, $value), 0, ',', '.');
}
$validatedAt = (int) ($preflight['validated_at'] ?? time());
$totalTables = max(1, (int) ($state['total_tables'] ?? adminMigrationExpectedTableCount()));
$progress = min(100, (int) floor(100 * (int) ($state['completed_tables'] ?? 0) / $totalTables));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Migração de banco</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <script src="/assets/admin-migration.js" defer></script>
</head>
<body data-state-endpoint="/admin/migracao-status.php" data-state="<?= migrationEscape((string) ($state['status'] ?? 'unknown')) ?>">
<aside class="sidebar">
    <a class="brand" href="/admin/migracao-banco.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a>
    <p class="nav-heading">PLATAFORMA</p>
    <nav aria-label="Navegação administrativa">
        <a href="#overview">Visão geral</a><a href="#source">Clientes</a><a href="#execution">Operações</a>
        <a href="/admin/atualizar-schema.php">Atualização de schema</a>
        <a href="/admin/atendimento-ia.php">Atendimento e IA</a>
        <a class="active" aria-current="page" href="/admin/migracao-banco.php">Migração de dados SQLite → MySQL</a>
        <a href="/admin/backup-destinos.php">Backup externo</a>
        <a href="#results">Auditoria</a><a href="#help">Configurações</a>
    </nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= migrationEscape($admin['nome']) ?></strong><small>Acesso restrito</small></span></div>
</aside>
<header class="topbar">
    <p>Administração <span aria-hidden="true">/</span> Infraestrutura <span aria-hidden="true">/</span> <strong>Migração de banco</strong></p>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= migrationEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form>
</header>
<main id="overview" class="content">
    <p class="breadcrumb">Operações&nbsp; / &nbsp;Infraestrutura</p>
    <div class="page-heading"><div><h1>Migração de banco</h1><p>Copie os dados do SQLite para o MySQL após validar a origem e o destino.</p></div><span class="service-badge <?= $preflight['ready'] ? 'good' : 'warn' ?>"><span aria-hidden="true"></span><?= $preflight['ready'] ? 'Serviço conectado' : 'Revisão necessária' ?></span></div>

    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= migrationEscape($notice) ?></p><?php endif; ?>
    <section class="warning-banner" aria-label="Orientação de segurança"><span class="warning-icon" aria-hidden="true">!</span><div><strong>A cópia só começa após todas as verificações passarem.</strong><p>O destino não pode conter dados operacionais; o catálogo de permissões só é aceito quando confere com a origem. Nenhum registro pessoal será exibido nesta tela.</p></div></section>

    <section class="connection-grid" aria-label="Bancos envolvidos">
        <article class="panel connection-card" id="source">
            <div class="card-heading"><span class="database-icon" aria-hidden="true">+</span><h2>Origem SQLite</h2><span class="status-chip <?= count(array_filter($preflight['checks'], static fn(array $c): bool => str_contains($c['label'], 'origem') && $c['status'] === 'Bloqueado')) === 0 ? 'good' : 'bad' ?>"><?= count(array_filter($preflight['checks'], static fn(array $c): bool => str_contains($c['label'], 'origem') && $c['status'] === 'Bloqueado')) === 0 ? 'Válida' : 'Bloqueada' ?></span></div>
            <dl><div><dt>Arquivo</dt><dd><?= migrationEscape((string) $preflight['source_name']) ?></dd></div><div><dt>Schema</dt><dd><?= migrationCount(adminMigrationExpectedTableCount()) ?> tabelas canônicas</dd></div><div><dt>Registros</dt><dd><?= migrationCount((int) $preflight['source_records']) ?> em <?= count($preflight['table_estimates']) ?> tabelas</dd></div></dl>
            <small class="subtle">Caminho completo oculto</small>
        </article>
        <article class="panel connection-card">
            <div class="card-heading"><span class="database-icon" aria-hidden="true">▤</span><h2>Destino MySQL / MariaDB</h2><span class="status-chip <?= $destinationReady ? 'good' : 'bad' ?>"><?= $destinationReady ? 'Válido' : 'Bloqueado' ?></span></div>
            <dl><div><dt>Banco</dt><dd><?= migrationEscape((string) $preflight['target_name']) ?></dd></div><div><dt>Conexão</dt><dd>Credenciais ocultas</dd></div><div><dt>Registros atuais</dt><dd><?= $destinationReady ? ((int) $preflight['destination_records'] > 0 ? migrationCount((int) $preflight['destination_records']) . ' permissões · catálogo compatível' : '0 · pronto para receber') : ((int) $preflight['destination_records'] > 0 ? 'Contém registros' : 'Aguardando validação') ?></dd></div></dl>
            <small class="subtle">A ferramenta não cria nem altera schema.</small>
        </article>
    </section>

    <section class="panel checks-panel" aria-labelledby="checks-title">
        <div class="section-heading"><div><h2 id="checks-title">Pré-verificações</h2><p>Validação executada às <?= date('H:i:s', $validatedAt) ?>.</p></div><form method="post"><input type="hidden" name="csrf_token" value="<?= migrationEscape($csrfToken) ?>"><input type="hidden" name="action" value="validate"><button class="secondary-button" type="submit">Validar novamente</button></form></div>
        <ul class="check-list">
            <?php foreach ($preflight['checks'] as $check): ?>
                <li><span class="check-name"><?= migrationEscape((string) $check['label']) ?><small><?= migrationEscape((string) $check['detail']) ?></small></span><span class="check-status <?= $check['status'] === 'Aprovado' ? 'good' : ($check['status'] === 'Atenção' ? 'attention' : 'bad') ?>"><?= migrationEscape((string) $check['status']) ?></span></li>
            <?php endforeach; ?>
            <li><span class="check-name">Estimativa de <?= migrationCount((int) $preflight['source_records']) ?> <?= (int) $preflight['source_records'] === 1 ? 'registro' : 'registros' ?> · nenhum dado pessoal será exibido</span><span class="check-status <?= $preflight['ready'] ? 'good' : 'bad' ?>"><?= $preflight['ready'] ? 'Aprovado' : 'Bloqueado' ?></span></li>
        </ul>
    </section>

    <section class="panel execution-panel" id="execution" aria-labelledby="execution-title">
        <div class="section-heading"><h2 id="execution-title">Execução</h2><span id="job-status" aria-live="polite" class="job-chip state-<?= migrationEscape((string) ($state['status'] ?? 'unknown')) ?>"><?= migrationEscape(adminMigrationStatusLabel((string) ($state['status'] ?? 'unknown'))) ?></span></div>
        <p id="job-message" class="execution-copy" aria-live="polite"><?= migrationEscape(adminMigrationStatusMessage($state)) ?></p>
        <progress class="progress-track" id="progress-bar" aria-label="Tabelas verificadas" value="<?= (int) ($state['completed_tables'] ?? 0) ?>" max="<?= $totalTables ?>"><?= $progress ?>%</progress>
        <div class="progress-labels"><span id="progress-tables"><?= migrationCount((int) ($state['completed_tables'] ?? 0)) ?> de <?= migrationCount((int) ($state['total_tables'] ?? adminMigrationExpectedTableCount())) ?> tabelas verificadas</span><span id="progress-records"><?= migrationCount((int) ($state['completed_records'] ?? 0)) ?> de <?= migrationCount((int) ($state['total_records'] ?? $preflight['source_records'])) ?> registros processados</span></div>
        <?php if (isset($state['current_table']) && is_string($state['current_table'])): ?><p class="current-table">Tabela atual: <code><?= migrationEscape($state['current_table']) ?></code></p><?php endif; ?>
        <?php if (isset($state['error']) && is_string($state['error']) && $state['error'] !== ''): ?><p class="migration-error" role="alert"><?= migrationEscape($state['error']) ?></p><?php endif; ?>
        <form method="post" id="queue-form" class="queue-form">
            <input type="hidden" name="csrf_token" value="<?= migrationEscape($csrfToken) ?>"><input type="hidden" name="action" value="queue"><input type="hidden" name="confirm_copy" id="confirm-copy" value="no">
            <label class="confirm-label"><input type="checkbox" id="reviewed" <?= !$preflight['ready'] || $activeJob ? 'disabled' : '' ?>><span>Confirmo que revisei a origem e o destino vazio e autorizo copiar os dados.</span></label>
            <button class="primary-button" id="review-button" type="button" <?= !$preflight['ready'] || $activeJob ? 'disabled' : '' ?>>Revisar e iniciar</button>
        </form>
    </section>

    <?php if ($results !== null): ?>
    <section class="panel results-panel" id="results" aria-labelledby="results-title"><h2 id="results-title">Resultado da cópia</h2><p><?= migrationCount((int) ($results['total_records'] ?? 0)) ?> registros verificados em <?= migrationCount(count($results['tables'] ?? [])) ?> tabelas; duração <?= migrationCount((int) ($results['duration_ms'] ?? 0)) ?> ms.</p>
        <div class="table-scroll" role="region" aria-label="Contagens e checksums por tabela" tabindex="0"><table><thead><tr><th>Tabela</th><th>Registros</th><th>Checksum SHA-256</th></tr></thead><tbody>
        <?php foreach (($results['tables'] ?? []) as $table => $summary): ?><tr><td><code><?= migrationEscape((string) $table) ?></code></td><td><?= migrationCount((int) ($summary['row_count'] ?? 0)) ?></td><td><code><?= migrationEscape((string) ($summary['sha256'] ?? '')) ?></code></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>

    <aside class="info-banner" id="help"><span aria-hidden="true">i</span><div><strong>A migração copia os dados; não altera o arquivo SQLite nem troca a configuração ativa do sistema.</strong><p>A ativação do MySQL continua sendo uma etapa separada de implantação e validação.</p></div></aside>
</main>
<dialog id="confirm-dialog" aria-labelledby="dialog-title"><form method="dialog"><button class="dialog-close" aria-label="Fechar revisão">×</button></form><h2 id="dialog-title">Confirmar cópia de dados</h2><p>O destino <strong><?= migrationEscape((string) $preflight['target_name']) ?></strong> está vazio e receberá <?= migrationCount((int) $preflight['source_records']) ?> registros da origem <strong><?= migrationEscape((string) $preflight['source_name']) ?></strong>.</p><p>A execução usa o worker CLI em segundo plano. Ela não altera o SQLite nem ativa o MySQL.</p><div class="dialog-actions"><button id="cancel-copy" class="secondary-button" type="button">Cancelar</button><button id="confirm-button" class="primary-button" type="button">Confirmar cópia</button></div></dialog>
</body>
</html>
<?php
function adminMigrationStatusLabel(string $status): string
{
    return match ($status) {
        'queued' => 'Em fila', 'running' => 'Em execução', 'completed' => 'Concluída', 'failed' => 'Falha', default => 'Aguardando início',
    };
}

/** @param array<string, mixed> $state */
function adminMigrationStatusMessage(array $state): string
{
    return match ($state['status'] ?? 'idle') {
        'queued' => 'A cópia está na fila e será iniciada pelo worker CLI. Esta página pode ser fechada.',
        'running' => 'A cópia está em segundo plano. Esta página pode ser fechada; o estado é preservado.',
        'completed' => 'Cópia concluída e validada por contagem e checksum. A ativação do MySQL é uma etapa separada.',
        'failed' => 'A execução foi encerrada. Confira a orientação abaixo e valide novamente antes de tentar outra vez.',
        default => 'A cópia será executada em segundo plano. Esta página poderá ser fechada durante o processamento.',
    };
}
?>
