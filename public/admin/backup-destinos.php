<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/backup_destinations.php';

$admin = adminExigirAcesso();
$pdo = dbConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = filter_var($_POST['destination_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if ($action === 'save') {
            $saved = backupDestinationSave($pdo, $admin, [
                'id' => $_POST['destination_id'] ?? 0,
                'name' => backupDestinationPostString($_POST, 'name'),
                'bucket' => backupDestinationPostString($_POST, 'bucket'),
                'region' => backupDestinationPostString($_POST, 'region'),
                'endpoint' => backupDestinationPostString($_POST, 'endpoint'),
                'path_style' => backupDestinationPostString($_POST, 'path_style') === '1',
                'access_key' => backupDestinationPostString($_POST, 'access_key'),
                'secret_key' => backupDestinationPostString($_POST, 'secret_key'),
                'retention_days' => backupDestinationPostString($_POST, 'retention_days'),
            ]);
            $_SESSION['backup_destination_notice'] = 'Destino salvo. Ele permanece inativo até passar no teste e ser ativado.';
            session_write_close();
            header('Location: /admin/backup-destinos.php', true, 303);
            exit;
        }
        if ($id === false) {
            throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
        }
        if ($action === 'test') {
            $result = backupDestinationTest($pdo, $admin, (int) $id);
            $_SESSION['backup_destination_notice'] = ($result['status'] ?? null) === 'success'
                ? 'Teste concluído: escrita, leitura e purga permanente do objeto temporário foram confirmadas.'
                : backupDestinationSafeTestFailure((string) ($result['error_code'] ?? 'connection_failed'));
        } elseif ($action === 'toggle') {
            $enabledValue = backupDestinationPostString($_POST, 'enabled');
            if (!in_array($enabledValue, ['0', '1'], true)) throw new InvalidArgumentException('Invalid enabled value.');
            backupDestinationSetEnabled($pdo, $admin, (int) $id, $enabledValue === '1');
            $_SESSION['backup_destination_notice'] = $enabledValue === '1'
                ? 'Destino ativado.'
                : 'Destino desativado.';
        } elseif ($action === 'delete') {
            $destination = backupDestinationReadOne($pdo, $admin, (int) $id);
            $confirmation = $_POST['confirm_name'] ?? null;
            if (!is_string($confirmation) || !hash_equals($destination['name'], trim($confirmation))) {
                $_SESSION['backup_destination_notice'] = 'O nome informado não confere. O destino continua cadastrado.';
            } else {
                backupDestinationDelete($pdo, $admin, (int) $id);
                $_SESSION['backup_destination_notice'] = 'Destino removido.';
            }
        } else {
            http_response_code(400);
            exit('Ação inválida.');
        }
    } catch (InvalidArgumentException) {
        $_SESSION['backup_destination_notice'] = 'Revise os campos do destino. A configuração não foi salva.';
    } catch (DomainException $exception) {
        $_SESSION['backup_destination_notice'] = backupDestinationSafeDomainMessage($exception->getMessage());
    } catch (Throwable) {
        $_SESSION['backup_destination_notice'] = 'Não foi possível concluir a operação. Revise a conexão e tente novamente.';
    }
    session_write_close();
    header('Location: /admin/backup-destinos.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$notice = is_string($_SESSION['backup_destination_notice'] ?? null) ? $_SESSION['backup_destination_notice'] : '';
unset($_SESSION['backup_destination_notice']);
$csrfToken = adminAuthCsrfToken($_SESSION);
$destinations = backupDestinationReadAll($pdo, $admin);
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = null;
if ($editId !== false && $editId !== null) {
    try {
        $candidate = backupDestinationReadOne($pdo, $admin, (int) $editId);
        if (!$candidate['privacy_keys_present']) $editing = $candidate;
    } catch (DomainException) {
    }
}
$isAdding = ($_GET['new'] ?? null) === '1';
$verifiedCount = count(array_filter($destinations, static fn(array $destination): bool => $destination['test_status'] === 'success'));
$formDestination = $editing ?? [
    'id' => 0, 'name' => '', 'bucket' => '', 'region' => '', 'endpoint' => null, 'path_style' => false,
];

function backupDestinationPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string, mixed> $post */
function backupDestinationPostString(array $post, string $key): string
{
    if (!array_key_exists($key, $post)) return '';
    if (!is_string($post[$key])) throw new InvalidArgumentException('Invalid form field.');
    return $post[$key];
}

function backupDestinationPageDate(?string $value): string
{
    if ($value === null || $value === '') return 'Ainda sem teste';
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === false ? 'Data indisponível' : date('d/m/Y · H:i', $timestamp);
}

function backupDestinationPageAge(?string $value): string
{
    if ($value === null || $value === '') return 'Nenhum snapshot concluído';
    $timestamp = strtotime($value . ' UTC');
    if ($timestamp === false) return 'Data indisponível';
    $minutes = max(0, (int) floor((time() - $timestamp) / 60));
    if ($minutes < 1) return 'há menos de 1 minuto';
    if ($minutes < 60) return 'há ' . $minutes . ' minuto' . ($minutes === 1 ? '' : 's');
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) return 'há ' . $hours . ' hora' . ($hours === 1 ? '' : 's');
    $days = (int) floor($hours / 24);
    return 'há ' . $days . ' dia' . ($days === 1 ? '' : 's');
}

function backupDestinationPageSnapshotState(array $destination): string
{
    if ($destination['pending_snapshot']) return 'Snapshot pendente; o worker tentará concluir o mesmo envio.';
    if ($destination['last_error_code'] !== null) return 'Último envio falhou; o worker tentará novamente.';
    if ($destination['last_success_at'] === null) return 'Ainda não há snapshot concluído.';
    return 'Último snapshot concluído ' . backupDestinationPageAge($destination['last_success_at']) . '.';
}

function backupDestinationSafeTestFailure(string $code): string
{
    return match ($code) {
        'cleanup_failed' => 'A purga permanente não foi comprovada. O destino não pode ser ativado; revise as permissões e teste novamente.',
        'probe_mismatch' => 'O objeto de teste não foi lido de forma íntegra. Revise a configuração e teste novamente.',
        'encryption_key_unavailable' => 'A chave de integração da instalação não está disponível. Revise a configuração do servidor.',
        default => 'Não foi possível confirmar escrita, leitura e purga permanente. Revise host, região, bucket e permissões.',
    };
}

function backupDestinationSafeDomainMessage(string $code): string
{
    return match ($code) {
        'BACKUP_DESTINATION_PRIVACY_KEYS_PRESENT' => 'Este destino mantém envelopes de chaves de titulares. Não altere nem remova o armazenamento enquanto esses envelopes dependerem dele.',
        'BACKUP_DESTINATION_DUPLICATE_STORAGE' => 'Este bucket e endpoint já estão cadastrados em outro destino. Use um armazenamento separado para manter cópias independentes.',
        'BACKUP_DESTINATION_SNAPSHOT_PENDING' => 'O destino tem um snapshot pendente de confirmação. Conclua a tentativa de backup antes de trocar o armazenamento ou remover o destino.',
        'BACKUP_DESTINATION_TEST_REQUIRED' => 'Ative o destino somente após um teste bem-sucedido de escrita, leitura e purga permanente.',
        'BACKUP_DESTINATION_NOT_FOUND' => 'O destino não foi encontrado. Atualize a página e tente novamente.',
        'BACKUP_DESTINATION_FORBIDDEN', 'AI_CONFIG_FORBIDDEN' => 'Acesso restrito ao superadmin.',
        default => 'A operação não pode ser concluída com a configuração atual. Revise os campos e tente novamente.',
    };
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Destinos externos</title>
    <link rel="stylesheet" href="/assets/admin-migration.css">
    <link rel="stylesheet" href="/assets/admin-backup-destinations.css">
    <script src="/assets/admin-backup-destinations.js" defer></script>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/admin/backup-destinos.php"><span class="brand-mark" aria-hidden="true">+</span><span><strong>IEPRCard</strong><small>ADMINISTRAÇÃO</small></span></a>
    <p class="nav-heading">ADMINISTRAÇÃO</p>
    <nav aria-label="Navegação administrativa">
        <a href="/admin/migracao-banco.php">Visão geral</a><a href="#destinations">Clientes</a><a href="#destinations">Domínios</a>
        <a href="/admin/atualizar-schema.php">Operação</a><a class="active" aria-current="page" href="/admin/backup-destinos.php">Backup externo</a>
        <a href="/admin/instalacao.php">Instalação</a>
        <a href="/admin/atendimento-ia.php">Atendimento e IA</a><a href="#help">Segurança</a>
    </nav>
    <div class="sidebar-user"><span class="avatar" aria-hidden="true">LN</span><span><strong><?= backupDestinationPageEscape($admin['nome']) ?></strong><small>Superadmin</small></span></div>
</aside>
<header class="topbar">
    <p>Administração <span aria-hidden="true">/</span> <strong>Backup externo</strong></p>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= backupDestinationPageEscape($csrfToken) ?>"><button class="logout-button" type="submit">Sair</button></form>
</header>
<main class="content">
    <p class="breadcrumb">Administração&nbsp; / &nbsp;Backup externo</p>
    <div class="page-heading"><div><h1>Destinos externos</h1><p>Cadastre destinos S3 compatíveis e valide o acesso antes de usá-los.</p></div><a class="primary-button add-button" href="/admin/backup-destinos.php?new=1">Adicionar destino</a></div>
    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= backupDestinationPageEscape($notice) ?></p><?php endif; ?>
    <p class="visually-hidden" id="operation-status" aria-live="polite"></p>
    <section class="warning-banner" aria-label="Operação de backup"><span class="warning-icon" aria-hidden="true">!</span><div><strong>Agendamento pelo cron do servidor</strong><p>Configure <code>php cron.php --once</code> para executar pelo menos a cada 15 minutos. O painel mostra o último resultado registrado por destino.</p><p>Um estado pendente é repetido com o mesmo identificador para evitar duplicação.</p></div></section>
    <section class="summary-grid" aria-label="Resumo dos destinos">
        <article class="panel summary-card"><span>Destinos cadastrados</span><strong><?= count($destinations) ?></strong></article>
        <article class="panel summary-card"><span>Conexões verificadas</span><strong><?= $verifiedCount ?> de <?= count($destinations) ?></strong></article>
        <article class="panel summary-card"><span>Snapshots ativos</span><strong><?= count(array_filter($destinations, static fn(array $destination): bool => $destination['enabled'])) ?></strong></article>
    </section>
    <section class="panel destination-panel" id="destinations" aria-labelledby="destinations-title">
        <div class="section-heading"><div><h2 id="destinations-title">Destinos cadastrados</h2><p>O teste usa um objeto temporário e comprova a purga permanente ao terminar.</p></div></div>
        <?php if ($destinations === []): ?><div class="empty-state"><strong>Nenhum destino cadastrado</strong><p>Adicione um destino S3 compatível para prepará-lo para uma futura rotina de backup.</p><a class="primary-button" href="/admin/backup-destinos.php?new=1">Adicionar destino</a></div>
        <?php else: ?><div class="destination-list">
            <?php foreach ($destinations as $destination): ?>
            <article class="destination-card">
                <div class="destination-main"><div><h3><?= backupDestinationPageEscape($destination['name']) ?></h3><p><?= backupDestinationPageEscape(strtoupper($destination['provider'])) ?> compatível · <?= $destination['enabled'] ? 'ativo' : 'inativo' ?></p></div>
                    <span class="status-chip <?= $destination['test_status'] === 'success' ? 'good' : ($destination['test_status'] === 'failed' ? 'bad' : '') ?>"><?= $destination['test_status'] === 'success' ? 'Conexão ok' : ($destination['test_status'] === 'failed' ? 'Falha no teste' : 'Não testada') ?></span></div>
                <dl><div><dt>Bucket / endpoint</dt><dd><?= backupDestinationPageEscape($destination['bucket']) ?><small><?= backupDestinationPageEscape($destination['endpoint'] ?? 'Endpoint padrão S3') ?></small></dd></div>
                    <div><dt>Último teste</dt><dd><?= backupDestinationPageEscape(backupDestinationPageDate($destination['tested_at'])) ?><small><?= $destination['test_status'] === 'success' ? 'Último teste bem-sucedido' : ($destination['test_status'] === 'failed' ? 'O destino permanece inativo após falha' : 'Ainda sem teste') ?></small></dd></div>
                    <div><dt>Último snapshot</dt><dd><?= backupDestinationPageEscape(backupDestinationPageAge($destination['last_success_at'])) ?><small><?= backupDestinationPageEscape(backupDestinationPageSnapshotState($destination)) ?></small></dd></div>
                    <div><dt>Retenção de snapshots</dt><dd><?= (int) $destination['retention_days'] ?> dias<small><?= $destination['retention_error_code'] !== null ? 'Falha na última purga' : ($destination['last_retention_at'] === null ? 'Purga ainda não executada' : backupDestinationPageDate($destination['last_retention_at'])) ?> · <?= (int) $destination['retention_removed_count'] ?> snapshots removidos na última execução</small></dd></div></dl>
                <?php if ($destination['privacy_keys_present']): ?><p class="locked-notice" role="note">Este destino mantém envelopes de chaves de titulares. Alteração do armazenamento ou remoção fica bloqueada enquanto houver dependências.</p><?php endif; ?>
                <div class="destination-actions">
                    <form method="post" data-pending-message="Testando conexão e purga permanente…"><input type="hidden" name="csrf_token" value="<?= backupDestinationPageEscape($csrfToken) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="destination_id" value="<?= (int) $destination['id'] ?>"><button class="secondary-button" type="submit">Testar</button></form>
                    <?php if (!$destination['privacy_keys_present']): ?><a class="secondary-button" href="/admin/backup-destinos.php?edit=<?= (int) $destination['id'] ?>">Editar</a><?php endif; ?>
                    <details class="more-actions"><summary>Mais</summary><div class="more-menu">
                        <form method="post" data-pending-message="Atualizando destino…"><input type="hidden" name="csrf_token" value="<?= backupDestinationPageEscape($csrfToken) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="destination_id" value="<?= (int) $destination['id'] ?>"><input type="hidden" name="enabled" value="<?= $destination['enabled'] ? '0' : '1' ?>"><button type="submit"><?= $destination['enabled'] ? 'Desativar' : 'Ativar' ?></button></form>
                        <?php if (!$destination['privacy_keys_present']): ?><details class="delete-confirm"><summary>Remover</summary><form method="post" data-pending-message="Removendo destino…"><input type="hidden" name="csrf_token" value="<?= backupDestinationPageEscape($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="destination_id" value="<?= (int) $destination['id'] ?>"><label>Digite <?= backupDestinationPageEscape($destination['name']) ?> para confirmar<input name="confirm_name" required autocomplete="off"></label><button class="danger-button" type="submit">Confirmar remoção</button></form></details><?php endif; ?>
                    </div></details>
                </div>
            </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
    <?php if ($editing !== null || $isAdding): ?>
    <section class="panel editor-panel" id="destination-editor" aria-labelledby="editor-title">
        <div class="section-heading"><div><h2 id="editor-title"><?= $editing === null ? 'Adicionar destino S3' : 'Editar destino S3' ?></h2><p>O teste usa um objeto temporário e não executa um backup.</p></div><a class="close-link" href="/admin/backup-destinos.php">Fechar</a></div>
        <form method="post" class="destination-form" data-pending-message="Salvando destino…">
            <input type="hidden" name="csrf_token" value="<?= backupDestinationPageEscape($csrfToken) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="destination_id" value="<?= (int) $formDestination['id'] ?>">
            <label>Nome do destino<input name="name" required maxlength="100" value="<?= backupDestinationPageEscape($formDestination['name']) ?>" placeholder="Ex.: Cópia principal"></label>
            <label>Tipo de serviço<select disabled aria-label="Tipo de serviço"><option>Amazon S3 / endpoint compatível</option></select><small>Outros serviços precisam oferecer API S3 validada.</small></label>
            <label>Bucket<input name="bucket" required maxlength="255" value="<?= backupDestinationPageEscape($formDestination['bucket']) ?>" placeholder="iepr-backups"></label>
            <label>Região<input name="region" required maxlength="100" value="<?= backupDestinationPageEscape($formDestination['region']) ?>" placeholder="us-east-1"></label>
            <label>Retenção de snapshots (dias)<input name="retention_days" type="number" min="1" max="3650" required value="<?= (int) ($formDestination['retention_days'] ?? 30) ?>"><small>Padrão: 30 dias · intervalo permitido: 1 a 3650.</small></label>
            <label class="wide">Endpoint personalizado<input name="endpoint" maxlength="2048" value="<?= backupDestinationPageEscape($formDestination['endpoint'] ?? '') ?>" placeholder="https://... · vazio para endpoint padrão da AWS"></label>
            <label class="path-style"><input type="checkbox" name="path_style" value="1" <?= $formDestination['path_style'] ? 'checked' : '' ?>> Usar endpoint por caminho <small>Necessário para vários serviços compatíveis.</small></label>
            <label>Access key<input name="access_key" type="password" maxlength="256" autocomplete="new-password" placeholder="Deixe vazio para manter a chave atual"></label>
            <label>Secret key<input name="secret_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="Deixe vazio para manter a chave atual"></label>
            <p class="credential-note wide"><strong>Privacidade das credenciais</strong><span>Depois de salvar, a tela mostra apenas se estão cadastradas. Para trocar, informe novas chaves; deixe os campos vazios para manter as atuais.</span></p>
            <div class="form-actions wide"><a class="secondary-button" href="/admin/backup-destinos.php">Cancelar</a><button class="primary-button" type="submit"><?= $editing === null ? 'Salvar destino' : 'Salvar alterações' ?></button></div>
        </form>
    </section>
    <?php endif; ?>
    <aside class="info-banner" id="help"><span aria-hidden="true">i</span><div><strong>O teste de conexão e os snapshots têm estados separados.</strong><p>O horário depende da configuração do cron no servidor. Falhas de retenção e snapshot são resumidas sem expor credenciais ou respostas do provedor.</p></div></aside>
</main>
</body>
</html>
