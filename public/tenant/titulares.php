<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/titular_read.php';
require_once dirname(__DIR__, 2) . '/config/titular_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_confirmation.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$pdo = dbConnection();
$context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($context === null || !in_array($context->tipo, ['cliente', 'subcliente'], true) || $context->tenantId === null) {
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

$identityUnitId = $identity['subcliente_id'];
$canRead = pode($pdo, $context, $identity, 'titular.ver', $identityUnitId);
$canCreate = pode($pdo, $context, $identity, 'titular.criar', $identityUnitId);
$canImport = pode($pdo, $context, $identity, 'titular.importar', $identityUnitId);
if (!$canRead) {
    http_response_code(403);
    exit('Sua conta não tem permissão para consultar titulares.');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    if ($_POST === [] && tenantTitularesRequestExceedsPostLimit($_SERVER)) {
        http_response_code(413);
        exit('O envio ultrapassa o limite de upload deste ambiente.');
    }
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!in_array($action, ['create_titular', 'stage_import', 'confirm_import', 'cancel_import'], true)) {
        http_response_code(400);
        exit('Ação inválida.');
    }

    try {
        if ($action === 'create_titular') {
            if (!$canCreate) {
                http_response_code(403);
                exit('Sua conta não tem permissão para cadastrar titulares.');
            }
            $targetUnitId = tenantTitularesResolveTargetUnit(
                $pdo, $context, $identity, $_POST['subcliente_id'] ?? '', 'titular.criar'
            );
            titularCreate($pdo, $context, $identity, $context->tenantId, $targetUnitId, [
                'nome_completo' => is_string($_POST['nome_completo'] ?? null) ? $_POST['nome_completo'] : null,
                'cpf' => is_string($_POST['cpf'] ?? null) ? $_POST['cpf'] : null,
                'matricula' => is_string($_POST['matricula'] ?? null) ? $_POST['matricula'] : null,
                'cargo_funcao' => is_string($_POST['cargo_funcao'] ?? null) ? $_POST['cargo_funcao'] : null,
                'categoria_id' => is_string($_POST['categoria_id'] ?? null) ? $_POST['categoria_id'] : null,
                'whatsapp' => is_string($_POST['whatsapp'] ?? null) ? $_POST['whatsapp'] : null,
            ]);
            $_SESSION['tenant_titulares_notice'] = 'Titular cadastrado.';
            $redirect = '/tenant/titulares.php#novo-titular';
        } elseif ($action === 'stage_import') {
            if (!$canImport) {
                http_response_code(403);
                exit('Sua conta não tem permissão para importar titulares.');
            }
            $targetUnitId = tenantTitularesResolveTargetUnit(
                $pdo, $context, $identity, $_POST['subcliente_id'] ?? '', 'titular.importar'
            );
            if (!pode($pdo, $context, $identity, 'titular.criar', $targetUnitId)) {
                http_response_code(403);
                exit('Sua conta não tem permissão para cadastrar titulares nesse destino.');
            }
            $upload = is_array($_FILES['arquivo'] ?? null) ? $_FILES['arquivo'] : [];
            if (($upload['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? null) === UPLOAD_ERR_FORM_SIZE) {
                throw new DomainException('IMPORT_FILE_SIZE_INVALID');
            }
            $photoUpload = is_array($_FILES['fotos_zip'] ?? null) ? $_FILES['fotos_zip'] : null;
            if ($photoUpload !== null && ($photoUpload['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
                $photoUpload = null;
            }
            $staged = batchImportStageUploadedFile(
                $pdo,
                $context,
                $identity,
                $context->tenantId,
                $targetUnitId,
                $upload,
                null,
                $photoUpload
            );
            $lotId = (int) $staged['lote_id'];
            $_SESSION['tenant_titulares_notice'] = 'Arquivo preparado. Revise as colunas e a amostra antes de confirmar.';
            $redirect = '/tenant/titulares.php?lote=' . $lotId . '#importacao';
        } elseif ($action === 'confirm_import') {
            if (!$canImport) {
                http_response_code(403);
                exit('Sua conta não tem permissão para confirmar importações.');
            }
            $lotId = tenantTitularesReadLotId($_POST['lote_id'] ?? null);
            $importState = batchImportStagingLer($pdo, $context, $identity, $lotId);
            $targetUnitId = tenantTitularesResolveLotTargetUnit($pdo, $context, $identity, $lotId, 'titular.importar');
            if ($importState['status'] !== 'aguardando_mapa') {
                $_SESSION['tenant_titulares_notice'] = 'Este lote não está aguardando mapeamento e não será reprocessado.';
                $redirect = '/tenant/titulares.php?lote=' . $lotId . '#importacao';
            } else {
                if (!pode($pdo, $context, $identity, 'titular.criar', $targetUnitId)) {
                    throw new DomainException('TITULAR_CREATE_DENIED');
                }
                $submittedMapping = is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [];
                $mapping = [];
                foreach ($submittedMapping as $index => $target) {
                    if ((!is_int($index) && (!is_string($index) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $index) !== 1))
                        || !is_string($target) || $target === '') {
                        continue;
                    }
                    $columnIndex = (int) $index;
                    if (!array_key_exists($columnIndex, $importState['colunas'])) {
                        throw new DomainException('IMPORT_MAPPING_INVALID');
                    }
                    $mapping[$importState['colunas'][$columnIndex]] = $target;
                }
                $result = batchImportConfirm(
                    $pdo,
                    $context,
                    $identity,
                    $context->tenantId,
                    $targetUnitId,
                    $lotId,
                    $mapping
                );
                $_SESSION['tenant_titulares_notice'] = 'Importação concluída: '
                    . number_format($result['imported'], 0, ',', '.')
                    . ($result['imported'] === 1 ? ' titular cadastrado.' : ' titulares cadastrados.');
                $redirect = '/tenant/titulares.php?lote=' . $lotId . '#importacao';
            }
        } else {
            if (!$canImport) {
                http_response_code(403);
                exit('Sua conta não tem permissão para cancelar importações.');
            }
            $lotId = tenantTitularesReadLotId($_POST['lote_id'] ?? null);
            $importState = batchImportStagingLer($pdo, $context, $identity, $lotId);
            $targetUnitId = tenantTitularesResolveLotTargetUnit($pdo, $context, $identity, $lotId, 'titular.importar');
            if ($importState['status'] !== 'aguardando_mapa') {
                $_SESSION['tenant_titulares_notice'] = 'Este lote não pode mais ser cancelado.';
            } else {
                batchImportStagingCancel($pdo, $context, $identity, $context->tenantId, $targetUnitId, $lotId);
                $_SESSION['tenant_titulares_notice'] = 'Importação cancelada e arquivos temporários removidos.';
            }
            $redirect = '/tenant/titulares.php?lote=' . $lotId . '#importacao';
        }
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
        $_SESSION['tenant_titulares_notice'] = tenantTitularesDomainMessage($error);
        $postedLotId = tenantTitularesReadLotId($_POST['lote_id'] ?? null, false);
        $redirect = $postedLotId === null
            ? '/tenant/titulares.php#novo-titular'
            : '/tenant/titulares.php?lote=' . $postedLotId . '#importacao';
    } catch (Throwable) {
        $_SESSION['tenant_titulares_notice'] = 'Não foi possível concluir a operação. Revise os dados e tente novamente.';
        $postedLotId = tenantTitularesReadLotId($_POST['lote_id'] ?? null, false);
        $redirect = $postedLotId === null
            ? '/tenant/titulares.php#novo-titular'
            : '/tenant/titulares.php?lote=' . $postedLotId . '#importacao';
    }
    session_write_close();
    header('Location: ' . $redirect, true, 303);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}

$filters = [];
foreach (['busca', 'situacao', 'subcliente_id', 'pagina', 'por_pagina'] as $key) {
    if (array_key_exists($key, $_GET) && is_string($_GET[$key])) {
        $filters[$key] = $_GET[$key];
    }
}
$errorMessage = '';
try {
    $listing = titularListar($pdo, $context, $identity, $filters);
} catch (DomainException $exception) {
    if (in_array($exception->getMessage(), ['TITULAR_READ_DENIED', 'TITULAR_IMPORT_READ_DENIED'], true)) {
        http_response_code(403);
        $errorMessage = 'Sua conta não tem permissão para consultar esse escopo.';
    } else {
        http_response_code(400);
        $errorMessage = 'Os filtros informados são inválidos. Revise e tente novamente.';
    }
    $listing = ['items' => [], 'total' => 0, 'pagina' => 1, 'por_pagina' => 25];
}

$units = [];
$createUnits = [];
$importUnits = [];
if ($context->tipo === 'cliente') {
    $unitQuery = $pdo->prepare(
        "SELECT id, nome_organizacao FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente' "
        . "AND status_conta = 'ativo' ORDER BY nome_organizacao, id"
    );
    $unitQuery->execute(['tenant' => $context->tenantId]);
    foreach ($unitQuery->fetchAll(PDO::FETCH_ASSOC) as $unit) {
        $unitId = (int) $unit['id'];
        if (pode($pdo, $context, $identity, 'titular.ver', $unitId)) {
            $units[] = ['id' => $unitId, 'nome' => (string) $unit['nome_organizacao']];
        }
        if (pode($pdo, $context, $identity, 'titular.criar', $unitId)) {
            $createUnits[] = ['id' => $unitId, 'nome' => (string) $unit['nome_organizacao']];
        }
        if (pode($pdo, $context, $identity, 'titular.importar', $unitId)
            && pode($pdo, $context, $identity, 'titular.criar', $unitId)) {
            $importUnits[] = ['id' => $unitId, 'nome' => (string) $unit['nome_organizacao']];
        }
    }
}
$canManageImport = $canImport && ($canCreate || $importUnits !== []);
$categoriesQuery = $pdo->prepare(
    'SELECT id, nome_categoria FROM categorias_titular WHERE tenant_id = :tenant AND ativo = 1 ORDER BY nome_categoria, id'
);
$categoriesQuery->execute(['tenant' => $context->tenantId]);
$categories = $categoriesQuery->fetchAll(PDO::FETCH_ASSOC);

$searchValue = is_string($filters['busca'] ?? null) ? $filters['busca'] : '';
$statusValue = is_string($filters['situacao'] ?? null) ? $filters['situacao'] : '';
$unitValue = is_string($filters['subcliente_id'] ?? null) ? $filters['subcliente_id'] : '';
$totalPages = max(1, (int) ceil($listing['total'] / $listing['por_pagina']));
$organization = (string) ($context->branding['nome_unidade'] ?? $context->branding['nome_organizacao'] ?? 'Instituição');
$notice = is_string($_SESSION['tenant_titulares_notice'] ?? null) ? $_SESSION['tenant_titulares_notice'] : '';
unset($_SESSION['tenant_titulares_notice']);
$csrfToken = tenantAuthCsrfToken($_SESSION);
$importState = null;
$importTargetUnitId = null;
$importStateError = '';
$importLotId = tenantTitularesReadLotId($_GET['lote'] ?? null, false);
if (array_key_exists('lote', $_GET)) {
    if (!$canImport) {
        http_response_code(403);
        $importStateError = 'Sua conta não tem permissão para consultar importações.';
    } elseif ($importLotId === null) {
        http_response_code(400);
        $importStateError = 'A referência do lote é inválida.';
    } else {
        try {
            $importState = batchImportStagingLer($pdo, $context, $identity, $importLotId);
            $importTargetUnitId = tenantTitularesResolveLotTargetUnit($pdo, $context, $identity, $importLotId, 'titular.importar');
        } catch (DomainException) {
            http_response_code(404);
            $importStateError = 'Lote não encontrado ou fora do seu escopo.';
            $importState = null;
        }
    }
}

function tenantTitularesEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tenantTitularesOrigin(string $origin): string
{
    return match ($origin) {
        'manual' => 'Cadastro individual',
        'csv' => 'CSV',
        'xls' => 'XLS',
        'xlsx' => 'XLSX',
        'json' => 'JSON',
        default => 'Importação',
    };
}

function tenantTitularesReadLotId(mixed $value, bool $throw = true): ?int
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) && !is_int($value)) {
        if ($throw) throw new DomainException('IMPORT_LOT_NOT_FOUND');
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        if ($throw) throw new DomainException('IMPORT_LOT_NOT_FOUND');
        return null;
    }

    return (int) $id;
}

/** @param array<string, mixed> $server */
function tenantTitularesRequestExceedsPostLimit(array $server): bool
{
    $contentLength = filter_var($server['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($contentLength === false || $contentLength === 0) return false;
    $configured = trim((string) ini_get('post_max_size'));
    if ($configured === '' || $configured === '0') return false;
    $unit = strtolower(substr($configured, -1));
    $number = filter_var(in_array($unit, ['k', 'm', 'g', 't'], true) ? substr($configured, 0, -1) : $configured, FILTER_VALIDATE_FLOAT);
    if ($number === false || $number <= 0) return false;
    $multiplier = match ($unit) {
        'k' => 1024,
        'm' => 1024 ** 2,
        'g' => 1024 ** 3,
        't' => 1024 ** 4,
        default => 1,
    };

    return $contentLength > $number * $multiplier;
}

function tenantTitularesResolveTargetUnit(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    mixed $rawUnitId,
    string $permission
): ?int {
    $identityUnitId = $identity['subcliente_id'] ?? null;
    if ($identityUnitId !== null) {
        return (int) $identityUnitId;
    }
    if (!is_string($rawUnitId) || $rawUnitId === '') {
        if (!pode($pdo, $context, $identity, $permission, null)) {
            throw new DomainException($permission === 'titular.criar' ? 'TITULAR_CREATE_DENIED' : 'TITULAR_IMPORT_READ_DENIED');
        }
        return null;
    }
    $unitId = filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($unitId === false || !pode($pdo, $context, $identity, $permission, (int) $unitId)) {
        throw new DomainException($permission === 'titular.criar' ? 'TITULAR_CREATE_DENIED' : 'TITULAR_IMPORT_READ_DENIED');
    }

    return (int) $unitId;
}

function tenantTitularesResolveLotTargetUnit(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $lotId,
    string $permission
): ?int
{
    $scope = tenantScopeBuildPredicate($identity, null, 'li');
    $query = $pdo->prepare(
        'SELECT li.subcliente_id FROM lotes_importacao li WHERE li.id = :id AND ' . $scope['sql'] . ' LIMIT 1'
    );
    $query->execute(['id' => $lotId] + $scope['params']);
    $rawUnitId = $query->fetchColumn();
    if ($rawUnitId === false) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }
    $unitId = $rawUnitId === null ? null : (int) $rawUnitId;
    if (!pode($pdo, $context, $identity, $permission, $unitId)) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }

    return $unitId;
}

function tenantTitularesDomainMessage(string $error): string
{
    return match ($error) {
        'TITULAR_CREATE_INVALID' => 'Confira o nome completo e os campos opcionais informados.',
        'TITULAR_CREATE_CPF_CONFLICT' => 'Já existe um titular com este CPF nesta instituição.',
        'TITULAR_CREATE_QUOTA_EXCEEDED' => 'O limite de titulares da Sede ou da unidade foi atingido.',
        'TITULAR_CREATE_QUOTA_UNCONFIGURED' => 'O limite de titulares ainda não está configurado. Fale com o administrador da plataforma.',
        'TITULAR_CREATE_ROOT_INACTIVE', 'TITULAR_CREATE_PLAN_UNAVAILABLE' => 'A conta não pode cadastrar titulares neste momento.',
        'TITULAR_CREATE_TARGET_INVALID' => 'A unidade selecionada não está disponível.',
        'TITULAR_CREATE_DENIED' => 'Sua conta não tem permissão para cadastrar titulares neste escopo.',
        'TITULAR_IMPORT_READ_DENIED' => 'Sua conta não tem permissão para importar nesse escopo.',
        'IMPORT_FORMAT_UNSUPPORTED' => 'Use um arquivo CSV, XLS, XLSX ou JSON.',
        'IMPORT_FILE_SIZE_INVALID' => 'O arquivo principal precisa ter até 10 MB e conter dados.',
        'IMPORT_PHOTO_ARCHIVE_INVALID' => 'O ZIP de fotos está inválido ou excede o limite permitido.',
        'IMPORT_ROW_LIMIT_EXCEEDED' => 'O arquivo ultrapassa o limite de 500 registros.',
        'IMPORT_COLUMN_LIMIT_EXCEEDED' => 'O arquivo ultrapassa o limite permitido de colunas.',
        'IMPORT_MAPPING_INVALID' => 'Mapeie Nome completo uma vez e não repita os campos de destino.',
        'IMPORT_LOT_NOT_FOUND' => 'Lote não encontrado ou fora do seu escopo.',
        'IMPORT_LOT_NOT_CONFIRMABLE' => 'Este lote não está aguardando mapeamento e não será reprocessado.',
        'IMPORT_LOT_NOT_CANCELLABLE' => 'Este lote não pode mais ser cancelado.',
        'IMPORT_FILE_INVALID' => 'O arquivo ou a amostra não está mais disponível. Envie o arquivo novamente.',
        default => 'Não foi possível processar o lote. Revise o arquivo e tente novamente.',
    };
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · Titulares</title>
    <link rel="stylesheet" href="/assets/tenant-admin.css">
    <link rel="stylesheet" href="/assets/tenant-titulares.css">
    <?php if ($canCreate || $canImport): ?><script src="/assets/tenant-titulares.js" defer></script><?php endif; ?>
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="/tenant/titulares.php"><span class="brand-mark" aria-hidden="true">+</span><strong>IEPRCard</strong></a>
    <p class="nav-heading">INSTITUIÇÃO</p>
    <p class="organization"><?= tenantTitularesEscape($organization) ?></p>
    <nav aria-label="Navegação da instituição">
        <?php if ($context->tipo === 'cliente'): ?>
            <a href="/tenant/unidades.php">Unidades</a>
            <a href="/tenant/equipe.php">Equipe</a>
            <a href="/tenant/cotas.php">Cotas</a>
            <a href="/tenant/dominios.php">Domínios próprios</a>
        <?php endif; ?>
        <a class="active" aria-current="page" href="/tenant/titulares.php">Titulares</a>
    </nav>
    <p class="sidebar-note"><?= $context->tipo === 'subcliente' ? 'Área da unidade' : 'Área do Cliente Master da Sede' ?></p>
</aside>
<header class="topbar"><p>Painel <span aria-hidden="true">/</span> <strong>Titulares</strong></p></header>
<main class="content">
    <div class="page-heading">
        <div><h1>Titulares</h1><p>Consulte, cadastre e importe titulares no escopo de <?= tenantTitularesEscape($organization) ?>.</p></div>
        <div class="titular-actions">
            <?php if ($canCreate): ?><a class="primary-link" href="#novo-titular">+ Novo titular</a><?php endif; ?>
    <?php if ($canManageImport): ?><a class="secondary-link" href="#importar-lista">Importar lista</a><?php endif; ?>
        </div>
    </div>
    <?php if ($notice !== ''): ?><p class="notice" role="status" aria-live="polite"><?= tenantTitularesEscape($notice) ?></p><?php endif; ?>
    <?php if ($errorMessage !== ''): ?><p class="notice" role="alert"><?= tenantTitularesEscape($errorMessage) ?></p><?php endif; ?>
    <section class="panel titular-list" aria-labelledby="titular-list-title">
        <div class="list-heading"><div><h2 id="titular-list-title">Pessoas cadastradas</h2><p class="subtle"><?= number_format($listing['total'], 0, ',', '.') ?> titulares neste resultado</p></div></div>
        <form class="titular-filters" method="get" action="/tenant/titulares.php">
            <label>Buscar por nome ou matrícula
                <input type="search" name="busca" value="<?= tenantTitularesEscape($searchValue) ?>" maxlength="180" autocomplete="off">
            </label>
            <label>Situação
                <select name="situacao">
                    <option value="">Todas</option>
                    <option value="ativo"<?= $statusValue === 'ativo' ? ' selected' : '' ?>>Ativo</option>
                    <option value="inativo"<?= $statusValue === 'inativo' ? ' selected' : '' ?>>Inativo</option>
                </select>
            </label>
            <?php if ($context->tipo === 'cliente'): ?>
                <label>Unidade
                    <select name="subcliente_id">
                        <option value="">Todas</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= $unit['id'] ?>"<?= $unitValue === (string) $unit['id'] ? ' selected' : '' ?>><?= tenantTitularesEscape($unit['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <button type="submit">Filtrar</button>
        </form>
        <?php if ($listing['items'] === []): ?>
            <p class="empty-state">Nenhum titular encontrado. Ajuste os filtros ou cadastre uma pessoa.</p>
        <?php else: ?>
            <div class="titular-table-wrap" role="region" aria-label="Lista de titulares" tabindex="0">
                <table>
                    <thead><tr><th scope="col">Nome</th><th scope="col">CPF</th><th scope="col">Matrícula</th><th scope="col">Unidade</th><th scope="col">Situação</th><th scope="col">Origem</th></tr></thead>
                    <tbody>
                    <?php foreach ($listing['items'] as $item): ?>
                        <tr>
                            <td><?= tenantTitularesEscape($item['nome']) ?></td>
                            <td><?= tenantTitularesEscape($item['cpf_mascarado'] ?? '—') ?></td>
                            <td><?= tenantTitularesEscape($item['matricula'] ?? '—') ?></td>
                            <td><?= tenantTitularesEscape($item['unidade']) ?></td>
                            <td><span class="titular-status status-<?= tenantTitularesEscape($item['situacao']) ?>"><?= $item['situacao'] === 'ativo' ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><?= tenantTitularesEscape(tenantTitularesOrigin($item['origem'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="Paginação de titulares">
                <span>Página <?= number_format($listing['pagina'], 0, ',', '.') ?> de <?= number_format($totalPages, 0, ',', '.') ?></span>
                <?php if ($listing['pagina'] > 1): ?>
                    <a href="?<?= http_build_query(['busca' => $searchValue, 'situacao' => $statusValue, 'subcliente_id' => $unitValue, 'pagina' => $listing['pagina'] - 1]) ?>">Anterior</a>
                <?php endif; ?>
                <?php if ($listing['pagina'] < $totalPages): ?>
                    <a href="?<?= http_build_query(['busca' => $searchValue, 'situacao' => $statusValue, 'subcliente_id' => $unitValue, 'pagina' => $listing['pagina'] + 1]) ?>">Próxima</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
    <?php if ($canCreate): ?>
        <section class="panel titular-form-panel" id="novo-titular" aria-labelledby="new-titular-title">
            <h2 id="new-titular-title">Novo titular</h2>
            <p class="subtle">Informe o nome completo. Os demais campos são opcionais; fotos entram pela importação ZIP.</p>
            <form class="titular-create-form" method="post" action="/tenant/titulares.php#novo-titular" data-disable-on-submit>
                <input type="hidden" name="csrf_token" value="<?= tenantTitularesEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="create_titular">
                <?php if ($identityUnitId === null && $createUnits !== []): ?>
                    <label>Unidade de destino
                        <select name="subcliente_id">
                            <?php if ($canCreate): ?>
                                <option value="">Sede</option>
                            <?php else: ?>
                                <option value="" disabled selected>Selecione uma unidade</option>
                            <?php endif; ?>
                            <?php foreach ($createUnits as $unit): ?>
                                <option value="<?= $unit['id'] ?>"><?= tenantTitularesEscape($unit['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php elseif ($identityUnitId !== null): ?>
                    <p class="scope-note">Destino do cadastro: <strong><?= tenantTitularesEscape($organization) ?></strong>. O escopo é definido pela sessão da unidade.</p>
                <?php endif; ?>
                <label>Nome completo <span aria-hidden="true">*</span>
                    <input type="text" name="nome_completo" maxlength="180" required autocomplete="name">
                </label>
                <label>CPF
                    <input type="text" name="cpf" inputmode="numeric" maxlength="14" autocomplete="off">
                </label>
                <label>Matrícula
                    <input type="text" name="matricula" maxlength="80" autocomplete="off">
                </label>
                <label>Cargo ou função
                    <input type="text" name="cargo_funcao" maxlength="120" autocomplete="organization-title">
                </label>
                <label>Categoria
                    <select name="categoria_id"><option value="">Sem categoria</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= tenantTitularesEscape((string) $category['nome_categoria']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>WhatsApp
                    <input type="tel" name="whatsapp" maxlength="24" autocomplete="tel">
                </label>
                <button type="submit">Salvar titular</button>
            </form>
        </section>
    <?php endif; ?>
    <?php if ($canImport): ?>
        <section class="panel titular-form-panel import-panel" id="importar-lista" aria-labelledby="import-title">
            <h2 id="import-title">Importar lista</h2>
            <?php if ($canManageImport): ?>
            <p class="subtle">CSV, XLS, XLSX ou JSON · até 10 MB e 500 registros. A importação valida e grava o lote inteiro.</p>
            <form class="import-upload-form" method="post" enctype="multipart/form-data" action="/tenant/titulares.php#importar-lista" data-disable-on-submit>
                <input type="hidden" name="csrf_token" value="<?= tenantTitularesEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="stage_import">
                <?php if ($identityUnitId === null): ?>
                    <label>Unidade de destino
                        <select name="subcliente_id">
                            <option value="">Sede</option>
                            <?php foreach ($importUnits as $unit): ?>
                                <option value="<?= $unit['id'] ?>"><?= tenantTitularesEscape($unit['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="scope-note">Escolha Sede ou uma das unidades autorizadas para definir o destino.</p>
                <?php else: ?>
                    <p class="scope-note">Destino da importação: <strong><?= tenantTitularesEscape($organization) ?></strong>. O escopo é definido pela sessão da unidade.</p>
                <?php endif; ?>
                <label>Arquivo da lista <span aria-hidden="true">*</span>
                    <input type="file" name="arquivo" accept=".csv,.xls,.xlsx,.json" required>
                </label>
                <label>ZIP com fotos (opcional)
                    <input type="file" name="fotos_zip" accept=".zip">
                </label>
                <p class="upload-help">As fotos devem estar na raiz do ZIP e usar CPF ou matrícula como nome do arquivo. Cada foto precisa corresponder a um titular sem ambiguidade.</p>
                <button type="submit">Preparar arquivo</button>
            </form>
            <?php else: ?>
            <p class="subtle">Você pode consultar e cancelar lotes existentes, mas não tem permissão para criar titulares neste escopo.</p>
            <?php endif; ?>
            <?php if ($importStateError !== ''): ?><p class="notice" role="alert"><?= tenantTitularesEscape($importStateError) ?></p><?php endif; ?>
            <?php if (is_array($importState)): ?>
                <?php
                $lotStatusLabels = [
                    'aguardando_mapa' => 'Aguardando mapeamento',
                    'concluido' => 'Importação concluída',
                    'cancelado' => 'Importação cancelada',
                    'expirado' => 'Lote expirado',
                    'arquivo_indisponivel' => 'Arquivo indisponível',
                    'na_fila' => 'Em processamento',
                    'falhou' => 'Falha na importação',
                ];
                $lotStatusLabel = $lotStatusLabels[$importState['status']] ?? 'Estado do lote';
                $targetUnitName = 'Sede';
                if ($importTargetUnitId !== null) {
                    foreach ($importUnits as $unit) {
                        if ($unit['id'] === $importTargetUnitId) $targetUnitName = $unit['nome'];
                    }
                    if ($targetUnitName === 'Sede') {
                        $unitNameQuery = $pdo->prepare(
                            "SELECT nome_organizacao FROM tenants WHERE id = :unit AND parent_id = :tenant AND tipo = 'subcliente' LIMIT 1"
                        );
                        $unitNameQuery->execute(['unit' => $importTargetUnitId, 'tenant' => $context->tenantId]);
                        $targetUnitName = (string) ($unitNameQuery->fetchColumn() ?: 'Unidade');
                    }
                }
                ?>
                <div class="import-state" id="lote-<?= (int) $importState['lote_id'] ?>" role="status">
                    <div class="import-state-heading"><div><span class="titular-status status-<?= tenantTitularesEscape($importState['status']) ?>"><?= tenantTitularesEscape($lotStatusLabel) ?></span><h3>Lote #<?= (int) $importState['lote_id'] ?></h3></div><p><?= tenantTitularesEscape($targetUnitName) ?> · <?= number_format($importState['total_registros'], 0, ',', '.') ?> registros</p></div>
                    <?php if ($importState['erros'] !== []): ?>
                        <ul class="import-errors" aria-label="Erros encontrados">
                            <?php foreach ($importState['erros'] as $error): ?>
                                <li><?php if (isset($error['linha'])): ?>Linha <?= (int) $error['linha'] ?><?php endif; ?><?php if (isset($error['campo']) && $error['campo'] !== ''): ?> · <?= tenantTitularesEscape($error['campo']) ?><?php endif; ?> · <code><?= tenantTitularesEscape($error['codigo']) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($importState['status'] === 'aguardando_mapa'): ?>
                        <h3>Mapeie as colunas</h3>
                        <?php if ($importState['colunas'] !== []): ?>
                            <form class="mapping-form" method="post" action="/tenant/titulares.php?lote=<?= (int) $importState['lote_id'] ?>#importacao" data-disable-on-submit data-mapping-form>
                                <input type="hidden" name="csrf_token" value="<?= tenantTitularesEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="confirm_import">
                                <input type="hidden" name="lote_id" value="<?= (int) $importState['lote_id'] ?>">
                                <div class="mapping-grid">
                                    <?php foreach ($importState['colunas'] as $index => $column): ?>
                                        <label><?= tenantTitularesEscape($column) ?>
                                            <select name="mapping[<?= (int) $index ?>]" data-mapping-target>
                                                <option value="">Ignorar coluna</option>
                                                <option value="nome_completo">Nome completo (obrigatório)</option>
                                                <option value="cpf">CPF</option>
                                                <option value="matricula">Matrícula</option>
                                                <option value="cargo_funcao">Cargo ou função</option>
                                                <option value="whatsapp">WhatsApp</option>
                                            </select>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($importState['amostra'] !== []): ?>
                                    <h3>Amostra limitada</h3>
                                    <div class="preview-table-wrap" role="region" aria-label="Amostra do arquivo, até 10 linhas" tabindex="0">
                                        <table>
                                            <thead><tr><?php foreach ($importState['colunas'] as $column): ?><th scope="col"><?= tenantTitularesEscape($column) ?></th><?php endforeach; ?></tr></thead>
                                            <tbody><?php foreach ($importState['amostra'] as $row): ?><tr><?php foreach ($importState['colunas'] as $column): ?><td><?= tenantTitularesEscape((string) ($row[$column] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <p class="atomicity-note"><strong>A importação é atômica:</strong> todos os registros precisam passar pela validação e pela cota; se houver erro, nenhum registro será gravado. A prévia mostra apenas uma amostra.</p>
                                <button type="submit" class="confirm-import">Revisar e confirmar</button>
                            </form>
                            <form class="cancel-import-form" method="post" action="/tenant/titulares.php?lote=<?= (int) $importState['lote_id'] ?>#importacao" data-disable-on-submit>
                                <input type="hidden" name="csrf_token" value="<?= tenantTitularesEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="cancel_import">
                                <input type="hidden" name="lote_id" value="<?= (int) $importState['lote_id'] ?>">
                                <button type="submit" class="secondary-button">Cancelar lote</button>
                            </form>
                        <?php else: ?>
                            <p class="empty-state">O arquivo não contém colunas que possam ser mapeadas. Envie outro arquivo.</p>
                        <?php endif; ?>
                    <?php elseif ($importState['status'] === 'expirado'): ?>
                        <p class="subtle">O prazo de 24 horas terminou. Envie o arquivo novamente para iniciar outra importação.</p>
                    <?php elseif ($importState['status'] === 'arquivo_indisponivel'): ?>
                        <p class="subtle">O staging não está disponível. Envie o arquivo novamente.</p>
                    <?php elseif ($importState['status'] !== 'concluido' && $importState['status'] !== 'cancelado'): ?>
                        <p class="subtle">Este lote já avançou e não pode ser reenviado por esta tela.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
