<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/config/tenant_resolver.php';
require_once dirname(__DIR__) . '/config/admin_auth.php';
require_once dirname(__DIR__) . '/config/tenant_auth.php';
require_once dirname(__DIR__) . '/config/motor_permissoes.php';
require_once dirname(__DIR__) . '/config/business_telemetry.php';
require_once dirname(__DIR__) . '/config/operational_batch_metrics.php';
require_once dirname(__DIR__) . '/config/operational_configuration.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'none'; style-src 'self'");
if (authSessionRequestIsHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function telemetryPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function telemetryPageQueryId(string $key): ?int
{
    if (!array_key_exists($key, $_GET)) return null;
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
    return (int) $value;
}

function telemetryPageDate(string $key, string $fallback): string
{
    if (!array_key_exists($key, $_GET)) return $fallback;
    if (!is_string($_GET[$key])) throw new InvalidArgumentException('TELEMETRIA_JANELA_INVALIDA');
    return $_GET[$key];
}

function telemetryPageCount(array $rows, string $category, string $event): int
{
    $total = 0;
    foreach ($rows as $row) {
        if (($row['categoria'] ?? null) === $category && ($row['evento_chave'] ?? null) === $event) {
            $total += (int) $row['quantidade'];
        }
    }
    return $total;
}

function telemetryPageHasId(array $rows, int $id): bool
{
    foreach ($rows as $row) if ((int) ($row['id'] ?? 0) === $id) return true;
    return false;
}

function telemetryPageDateLabel(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    return $parsed ? $parsed->format('d/m') : '';
}

function telemetryPagePostedInteger(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) return null;
    $canonical = ltrim($value, '0');
    $canonical = $canonical === '' ? '0' : $canonical;
    if (strlen($canonical) > 10) return null;
    $integer = filter_var($canonical, FILTER_VALIDATE_INT);
    return $integer === false ? null : $integer;
}

$httpStatus = 200;
$errorMessage = '';
$identity = null;
$context = null;
$scope = null;
$rows = [];
$daily = [];
$units = [];
$tenants = [];
$operations = null;
$operationalConfigState = null;
$configurationNotice = '';
$csrfToken = '';
$operationsEnvironment = '';
$operationsFailed = false;
$scopeLabel = '';
$role = '';
$from = '';
$to = '';
$forecastDays = 30;
$todayUtc = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$defaultFrom = $todayUtc->modify('-29 days')->format('Y-m-d');
$defaultTo = $todayUtc->format('Y-m-d');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

try {
    $pdo = dbConnection();
    $context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!$context instanceof TenantContext) {
        http_response_code(404);
        exit;
    }

    if ($context->tipo === 'admin') {
        if (!adminAuthStartSession()) {
            http_response_code(503);
            exit;
        }
        $identity = adminAuthCurrentUser($pdo);
        if ($identity === null) {
            header('Location: /admin/login.php', true, 303);
            exit;
        }
        $role = 'superadmin';
        if (!pode($pdo, $context, $identity, 'admin.telemetria')) {
            http_response_code(403);
            exit;
        }
    } else {
        if (!tenantAuthStartSession()) {
            http_response_code(503);
            exit;
        }
        $identity = tenantAuthCurrentUser($pdo, $context);
        if ($identity === null) {
            http_response_code(401);
            exit('Acesso restrito à sessão da instituição.');
        }
        $role = (string) $identity['papel_sistema'];
    }

    if ($method === 'POST') {
        if ($role !== 'superadmin') {
            http_response_code(403);
            exit('Acesso restrito à administração.');
        }
        if (!adminAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('Solicitação inválida.');
        }
        if (($_POST['action'] ?? null) !== 'save_operational_config') {
            http_response_code(400);
            exit('Não foi possível validar os ajustes.');
        }
        if (($_POST['confirmar_aplicacao'] ?? null) !== '1') {
            http_response_code(400);
            exit('Confirme que deseja salvar os ajustes.');
        }
        $settings = [
            'lote_maximo_registros' => telemetryPagePostedInteger('lote_maximo_registros'),
            'pico_previsto_diario' => telemetryPagePostedInteger('pico_previsto_diario'),
            'janela_previsao_dias' => telemetryPagePostedInteger('janela_previsao_dias'),
        ];
        if (in_array(null, $settings, true)) {
            http_response_code(400);
            exit('Não foi possível validar os ajustes.');
        }
        try {
            $validatedSettings = operationalConfigValidateSettings($settings);
            operationalConfigSavePending($pdo, $validatedSettings, (int) $identity['id']);
        } catch (InvalidArgumentException) {
            http_response_code(400);
            exit('Não foi possível validar os ajustes.');
        } catch (Throwable) {
            http_response_code(503);
            exit('Não foi possível salvar os ajustes agora. Tente novamente mais tarde.');
        }
        $_SESSION['telemetry_config_notice'] = 'A configuração foi salva como pendente. Ela será aplicada na próxima execução agendada do worker; o lote em andamento manterá a configuração que já carregou.';
        header('Location: /telemetria/', true, 303);
        exit;
    }

    if ($role === 'superadmin') {
        $csrfToken = adminAuthCsrfToken($_SESSION);
        $operationalConfigState = operationalConfigReadState($pdo);
        $configurationNotice = is_string($_SESSION['telemetry_config_notice'] ?? null)
            ? $_SESSION['telemetry_config_notice'] : '';
        unset($_SESSION['telemetry_config_notice']);
    }

    $from = telemetryPageDate('de', $defaultFrom);
    $to = telemetryPageDate('ate', $defaultTo);
    telemetryBusinessValidateWindow(['from' => $from, 'to' => $to]);
    $storedSettings = $operationalConfigState['pending']['configuracao']
        ?? $operationalConfigState['active']['configuracao'] ?? null;
    if (!array_key_exists('janela', $_GET) && is_array($storedSettings)) {
        $forecastDays = $storedSettings['janela_previsao_dias'];
    }
    if (array_key_exists('janela', $_GET)) {
        $requestedForecastDays = filter_var($_GET['janela'], FILTER_VALIDATE_INT);
        if ($requestedForecastDays === false || $requestedForecastDays < 7 || $requestedForecastDays > 365) {
            throw new InvalidArgumentException('TELEMETRIA_JANELA_PREVISAO_INVALIDA');
        }
        $forecastDays = $requestedForecastDays;
    }
    $requestedTenantId = telemetryPageQueryId('tenant_id');
    $requestedUnitId = telemetryPageQueryId('subcliente_id');
    $requestedScope = $_GET['escopo'] ?? null;
    if ($requestedScope !== null && !is_string($requestedScope)) {
        throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
    }

    if ($role === 'superadmin') {
        $mode = $requestedScope ?? 'global';
        if ($mode === 'global') {
            if ($requestedUnitId !== null) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
            $scope = ['mode' => 'global'];
            $scopeLabel = 'Escopo global';
            $tenantStatement = $pdo->query("SELECT id, nome_organizacao FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' ORDER BY nome_organizacao, id");
            $tenants = $tenantStatement->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($mode === 'tenant' && $requestedTenantId !== null) {
            $scope = ['mode' => 'tenant', 'tenant_id' => $requestedTenantId];
            $scopeLabel = 'Escopo da Sede';
            $tenantStatement = $pdo->query("SELECT id, nome_organizacao FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' ORDER BY nome_organizacao, id");
            $tenants = $tenantStatement->fetchAll(PDO::FETCH_ASSOC);
            if (!telemetryPageHasId($tenants, $requestedTenantId)) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
            $units = telemetryPageLoadUnits($pdo, $requestedTenantId);
        } elseif ($mode === 'unit' && $requestedTenantId !== null && $requestedUnitId !== null) {
            $scope = ['mode' => 'unit', 'tenant_id' => $requestedTenantId, 'subcliente_id' => $requestedUnitId];
            $scopeLabel = 'Escopo da unidade';
            $tenantStatement = $pdo->query("SELECT id, nome_organizacao FROM tenants WHERE parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' ORDER BY nome_organizacao, id");
            $tenants = $tenantStatement->fetchAll(PDO::FETCH_ASSOC);
            if (!telemetryPageHasId($tenants, $requestedTenantId)) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
            $units = telemetryPageLoadUnits($pdo, $requestedTenantId);
            if (!telemetryPageHasId($units, $requestedUnitId)) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
        } else {
            throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
        }
        $businessPermission = null;
    } elseif ($role === 'titular_cliente') {
        if ($requestedTenantId !== null) {
            http_response_code(403);
            throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
        }
        if ($requestedScope === null || $requestedScope === 'network') {
            $scope = ['mode' => 'network'];
            $scopeLabel = 'Visão da rede';
            $businessPermission = 'telemetria.rede';
            if ($requestedUnitId !== null) {
                $unitStatement = $pdo->prepare("SELECT id, nome_organizacao FROM tenants WHERE id = :unit AND parent_id = :tenant AND tipo = 'subcliente' AND status_conta = 'ativo' LIMIT 1");
                $unitStatement->execute(['unit' => $requestedUnitId, 'tenant' => $identity['tenant_id']]);
                $unit = $unitStatement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($unit)) throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
            }
        } elseif ($requestedScope === 'unit' && $requestedUnitId !== null) {
            $scope = ['mode' => 'unit', 'subcliente_id' => $requestedUnitId];
            $scopeLabel = 'Filtro de unidade';
            $businessPermission = 'telemetria.unidade';
        } else {
            throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
        }
        if (!pode($pdo, $context, $identity, $businessPermission)
            || ($requestedScope === 'unit' && !pode($pdo, $context, $identity, 'telemetria.unidade', $requestedUnitId))) {
            http_response_code(403);
            $errorMessage = 'Não foi possível aplicar os filtros.';
        }
        $units = array_values(array_filter(
            telemetryPageLoadUnits($pdo, (int) $identity['tenant_id']),
            static fn (array $unit): bool => pode($pdo, $context, $identity, 'telemetria.unidade', (int) $unit['id'])
        ));
        if ($requestedUnitId !== null && !in_array($requestedUnitId, array_map(static fn (array $unit): int => (int) $unit['id'], $units), true)) {
            http_response_code(403);
            $errorMessage = 'Não foi possível aplicar os filtros.';
        }
    } elseif (in_array($role, ['responsavel_sub', 'subacesso'], true) && $context->subclienteId !== null) {
        if ($requestedTenantId !== null || $requestedUnitId !== null || ($requestedScope !== null && $requestedScope !== 'unit')) {
            throw new DomainException('TELEMETRIA_FILTRO_INVALIDO');
        }
        $scope = ['mode' => 'unit'];
        $scopeLabel = 'Visão da unidade';
        if (!pode($pdo, $context, $identity, 'telemetria.unidade', $context->subclienteId)) {
            http_response_code(403);
            $errorMessage = 'Não foi possível aplicar os filtros.';
        }
    } else {
        http_response_code(403);
        exit;
    }

    if ($errorMessage === '') {
        $rows = telemetryBusinessRead($pdo, $identity, $scope, ['from' => $from, 'to' => $to]);
        $allowed = [
            'issuance:card.generated' => ['Carteirinhas geradas', 'issuance', 'card.generated'],
            'messaging:whatsapp.sent' => ['Mensagens enviadas', 'messaging', 'whatsapp.sent'],
            'messaging:whatsapp.failed' => ['Mensagens com falha', 'messaging', 'whatsapp.failed'],
            'import:rows.accepted' => ['Linhas aceitas', 'import', 'rows.accepted'],
            'import:rows.rejected' => ['Linhas rejeitadas', 'import', 'rows.rejected'],
            'validation:qr.validated' => ['Validações QR', 'validation', 'qr.validated'],
        ];
        foreach ($allowed as $metric) {
            $daily[$metric[0]] = telemetryPageCount($rows, $metric[1], $metric[2]);
        }
        if ($role === 'superadmin') {
            try {
                $operationsEnvironment = operationalBatchMetricsEnvironment();
                $operations = operationalBatchMetricsSummary($pdo, $forecastDays);
            } catch (Throwable) {
                $operationsFailed = true;
            }
        }
    }
} catch (InvalidArgumentException|DomainException) {
    if (http_response_code() === 200) http_response_code(400);
    $from = $defaultFrom;
    $to = $defaultTo;
    $errorMessage = 'Não foi possível aplicar os filtros.';
} catch (Throwable) {
    http_response_code(503);
    $errorMessage = 'Não foi possível carregar as métricas agora. Tente novamente mais tarde.';
}

function telemetryPageLoadUnits(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare("SELECT id, nome_organizacao FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente' AND status_conta = 'ativo' ORDER BY nome_organizacao, id");
    $statement->execute(['tenant' => $tenantId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function telemetryPageOption(array $rows, int $selectedId, string $idField = 'id'): ?array
{
    foreach ($rows as $row) if ((int) $row[$idField] === $selectedId) return $row;
    return null;
}

$labels = [
    'Carteirinhas geradas', 'Mensagens enviadas', 'Mensagens com falha',
    'Linhas aceitas', 'Linhas rejeitadas', 'Validações QR',
];
$hasBusinessData = false;
foreach ($daily as $value) if ($value > 0) $hasBusinessData = true;
$number = static fn (int $value): string => number_format($value, 0, ',', '.');
$activeConfiguration = $operationalConfigState['active'] ?? null;
$pendingConfiguration = $operationalConfigState['pending'] ?? null;
$configurationFormValues = $pendingConfiguration['configuracao']
    ?? $activeConfiguration['configuracao']
    ?? [
        'lote_maximo_registros' => 300,
        'pico_previsto_diario' => $operations['recommended_lot_size'] ?? 0,
        'janela_previsao_dias' => $forecastDays,
    ];

http_response_code(http_response_code() ?: $httpStatus);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Telemetria · IEPRCard</title>
    <link rel="stylesheet" href="/assets/telemetria.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="IEPRCard, início"><span class="brand-mark" aria-hidden="true">I</span>IEPRCard</a>
        <span class="header-note"><?= $role === 'superadmin' ? 'Administração' : 'Área da organização' ?></span>
    </header>
    <main class="page-shell">
        <section class="page-heading">
            <p class="eyebrow">DADOS AGREGADOS · <?= telemetryPageEscape($scopeLabel) ?></p>
            <h1><?= $role === 'superadmin' ? 'Telemetria e operação' : ($role === 'titular_cliente' ? 'Atividade da rede' : 'Atividade da unidade') ?></h1>
            <p class="intro">Período em UTC: <?= telemetryPageEscape($from) ?> a <?= telemetryPageEscape($to) ?>.</p>
        </section>

        <?php if ($errorMessage !== ''): ?>
            <p class="notice notice-error" role="alert"><?= telemetryPageEscape($errorMessage) ?></p>
        <?php else: ?>
            <?php if ($configurationNotice !== ''): ?><p class="notice notice-success" role="status"><?= telemetryPageEscape($configurationNotice) ?></p><?php endif; ?>
            <form class="filters" method="get" action="/telemetria/" aria-label="Filtros de telemetria">
                <?php if ($role === 'superadmin'): ?>
                    <label>Escopo
                        <select name="escopo">
                            <option value="global" <?= ($scope['mode'] ?? '') === 'global' ? 'selected' : '' ?>>Todos os clientes</option>
                            <option value="tenant" <?= ($scope['mode'] ?? '') === 'tenant' ? 'selected' : '' ?>>Cliente selecionado</option>
                            <option value="unit" <?= ($scope['mode'] ?? '') === 'unit' ? 'selected' : '' ?>>Unidade selecionada</option>
                        </select>
                    </label>
                    <label>Cliente
                        <select name="tenant_id">
                            <?php foreach ($tenants as $tenant): ?><option value="<?= (int) $tenant['id'] ?>" <?= (int) $tenant['id'] === ($requestedTenantId ?? (int) ($tenants[0]['id'] ?? 0)) ? 'selected' : '' ?>><?= telemetryPageEscape((string) $tenant['nome_organizacao']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <?php if (($scope['mode'] ?? '') !== 'global'): ?>
                        <label>Unidade
                            <select name="subcliente_id">
                                <?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= (int) $unit['id'] === $requestedUnitId ? 'selected' : '' ?>><?= telemetryPageEscape((string) $unit['nome_organizacao']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                <?php elseif ($role === 'titular_cliente'): ?>
                    <label>Visão
                        <select name="escopo">
                            <option value="network" <?= ($scope['mode'] ?? '') === 'network' ? 'selected' : '' ?>>Toda a rede</option>
                            <option value="unit" <?= ($scope['mode'] ?? '') === 'unit' ? 'selected' : '' ?>>Uma unidade</option>
                        </select>
                    </label>
                    <?php if ($units !== []): ?>
                        <label>Unidade
                            <select name="subcliente_id">
                                <?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= (int) $unit['id'] === $requestedUnitId ? 'selected' : '' ?>><?= telemetryPageEscape((string) $unit['nome_organizacao']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                <?php endif; ?>
                <label>De <input type="date" name="de" value="<?= telemetryPageEscape($from) ?>" required></label>
                <label>Até <input type="date" name="ate" value="<?= telemetryPageEscape($to) ?>" required></label>
                <?php if ($role === 'superadmin'): ?><label>Janela da previsão (7 a 365 dias) <input type="number" name="janela" min="7" max="365" value="<?= $forecastDays ?>" required></label><?php endif; ?>
                <button class="primary-button" type="submit">Aplicar filtros</button>
            </form>

            <?php if (!$hasBusinessData): ?><p class="notice" role="status">Sem dados neste período.</p><?php endif; ?>
            <section class="metric-grid" aria-label="Métricas agregadas">
                <?php foreach ($labels as $label): ?>
                    <?php if ($role !== 'superadmin' && in_array($label, ['Mensagens enviadas', 'Mensagens com falha', 'Linhas rejeitadas'], true) && $daily[$label] === 0) continue; ?>
                    <article class="metric-card"><h2><?= telemetryPageEscape($label) ?></h2><strong class="metric-value"><?= $number($daily[$label] ?? 0) ?></strong><span>No período selecionado</span></article>
                <?php endforeach; ?>
            </section>

            <section class="panel" aria-labelledby="daily-title">
                <h2 id="daily-title">Demanda diária de emissão</h2>
                <p>Carteirinhas solicitadas por dia. Os valores são agregados.</p>
                <?php $series = []; foreach ($rows as $row) if ($row['categoria'] === 'issuance' && $row['evento_chave'] === 'card.generated') $series[$row['dia']] = (int) $row['quantidade']; ksort($series); ?>
                <?php if ($series === []): ?><p class="muted">Sem registros de emissão neste período.</p><?php else: ?>
                    <div class="daily-list" role="list" aria-label="Emissões diárias">
                        <?php foreach (array_slice($series, -30, null, true) as $day => $quantity): ?><div class="daily-row" role="listitem"><time datetime="<?= telemetryPageEscape((string) $day) ?>"><?= telemetryPageEscape(telemetryPageDateLabel((string) $day)) ?></time><progress value="<?= (int) min(100, max(2, round($quantity / max($series) * 100))) ?>" max="100" aria-label="<?= $number($quantity) ?> carteirinhas geradas em <?= telemetryPageEscape(telemetryPageDateLabel((string) $day)) ?>"></progress><strong><?= $number($quantity) ?></strong></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($role === 'superadmin'): ?>
                <section class="panel operations" aria-labelledby="operations-title">
                    <h2 id="operations-title">Previsão operacional</h2>
                <p>Métricas agregadas<?= $operationsEnvironment !== '' ? ' do ambiente ' . telemetryPageEscape($operationsEnvironment) : '' ?>; previsão baseada nos últimos <?= $forecastDays ?> dias UTC completos.</p>
                    <?php if ($operationsFailed): ?><p class="notice notice-error" role="status">Não foi possível carregar as métricas operacionais agora.</p>
                    <?php elseif (is_array($operations)): ?>
                        <div class="metric-grid operational-grid">
                            <article class="metric-card"><h3>Pico previsto · últimos <?= $forecastDays ?> dias completos</h3><strong class="metric-value"><?= $operations['forecast_peak'] === null ? '—' : $number($operations['forecast_peak']) ?></strong><span><?= $operations['forecast_status'] === 'insufficient_history' ? 'Histórico insuficiente' : 'P95 por dia' ?></span></article>
                            <article class="metric-card"><h3>Lote recomendado</h3><strong class="metric-value"><?= $operations['recommended_lot_size'] === null ? '—' : $number($operations['recommended_lot_size']) ?></strong><span><?= $operations['observed_days'] ?> dias observados</span></article>
                            <article class="metric-card"><h3>Fila atual</h3><strong class="metric-value"><?= $number($operations['current_queue_depth']) ?></strong><span>Lotes aguardando processamento</span></article>
                            <article class="metric-card"><h3>Falhas de lote</h3><strong class="metric-value"><?= $number($operations['failure_total']) ?></strong><span>Na janela operacional</span></article>
                            <article class="metric-card"><h3>Duração média por registro</h3><strong class="metric-value"><?= $number($operations['average_duration_per_record_ms']) ?> ms</strong><span><?= $number($operations['processed_total']) ?> registros processados</span></article>
                            <article class="metric-card"><h3>Maior fila observada</h3><strong class="metric-value"><?= $number($operations['maximum_queue_depth']) ?></strong><span>Na janela operacional</span></article>
                        </div>
                    <?php endif; ?>
                </section>
                <section class="panel configuration-panel" aria-labelledby="configuration-title">
                    <h2 id="configuration-title">Configuração de lotes</h2>
                    <p>Valores operacionais são versionados e só passam a valer quando um worker inicia. A configuração carregada por um lote em andamento permanece fixa.</p>
                    <div class="configuration-state-grid">
                        <article class="configuration-state">
                            <h3>Configuração ativa</h3>
                            <?php if (is_array($activeConfiguration)): ?>
                                <p><strong>Versão ativa <?= (int) $activeConfiguration['versao'] ?></strong></p>
                                <p>Lote máximo: <?= $number($activeConfiguration['configuracao']['lote_maximo_registros']) ?> registros<br>Pico esperado: <?= $number($activeConfiguration['configuracao']['pico_previsto_diario']) ?> por dia<br>Janela: <?= $activeConfiguration['configuracao']['janela_previsao_dias'] ?> dias</p>
                            <?php else: ?>
                                <p>Nenhuma versão ativa. Esta instalação ainda não carregou uma configuração pelo worker.</p>
                            <?php endif; ?>
                        </article>
                        <article class="configuration-state pending-state">
                            <h3>Alteração pendente</h3>
                            <?php if (is_array($pendingConfiguration)): ?>
                                <p><strong>Versão pendente <?= (int) $pendingConfiguration['versao'] ?></strong></p>
                                <p><?= $number($pendingConfiguration['configuracao']['lote_maximo_registros']) ?> registros · <?= $number($pendingConfiguration['configuracao']['pico_previsto_diario']) ?> por dia · <?= $pendingConfiguration['configuracao']['janela_previsao_dias'] ?> dias</p>
                                <p>Em hospedagem compartilhada, será ativada na próxima execução agendada do worker.</p>
                            <?php else: ?>
                                <p>Não há alterações aguardando o worker.</p>
                            <?php endif; ?>
                        </article>
                    </div>
                    <form class="configuration-form" method="post" action="/telemetria/">
                        <input type="hidden" name="action" value="save_operational_config">
                        <input type="hidden" name="csrf_token" value="<?= telemetryPageEscape($csrfToken) ?>">
                        <div class="configuration-fields">
                            <label for="lote-maximo">Lote máximo · registros (1 a 500)</label>
                            <input id="lote-maximo" name="lote_maximo_registros" type="number" min="1" max="500" step="1" value="<?= (int) $configurationFormValues['lote_maximo_registros'] ?>" required>
                            <label for="pico-esperado">Pico esperado · registros por dia</label>
                            <input id="pico-esperado" name="pico_previsto_diario" type="number" min="0" max="1000000000" step="1" value="<?= (int) $configurationFormValues['pico_previsto_diario'] ?>" required>
                            <label for="janela-config">Janela da previsão · dias (7 a 365)</label>
                            <input id="janela-config" name="janela_previsao_dias" type="number" min="7" max="365" step="1" value="<?= (int) $configurationFormValues['janela_previsao_dias'] ?>" required>
                        </div>
                        <label class="confirmation-check"><input type="checkbox" name="confirmar_aplicacao" value="1" required> Confirmo que desejo salvar estes ajustes como pendentes.</label>
                        <p class="configuration-guidance">Salvar não reinicia PHP-FPM nem altera um lote atual. O worker carrega esta versão na próxima inicialização; em hospedagem compartilhada, isso ocorre na próxima execução agendada.</p>
                        <button class="primary-button" type="submit">Salvar ajustes</button>
                    </form>
                </section>
            <?php endif; ?>
            <p class="privacy-note">Este painel apresenta apenas contagens agregadas e não exibe dados pessoais nem identificadores de titulares.</p>
        <?php endif; ?>
    </main>
</body>
</html>
