<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/technical_events.php';
require_once dirname(__DIR__, 2) . '/config/operational_configuration.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'none'; style-src 'self'");
if (authSessionRequestIsHttps()) header('Strict-Transport-Security: max-age=31536000');

function technicalEventsPageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function technicalEventsPageQueryString(array $values): string
{
    return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
}

$httpStatus = 200;
$errorMessage = '';
$result = ['rows' => [], 'total' => 0, 'total_retained' => 0, 'page' => 1, 'pages' => 1];
$filters = ['periodo' => '1', 'categoria' => '', 'rota' => '', 'correlation_id' => '', 'pagina' => '1'];
$retentionDays = 30;
$categories = array_values(array_unique(technicalEventsRouteAllowlist()));
sort($categories, SORT_STRING);
$routes = technicalEventsRouteAllowlist();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método não permitido.');
}

try {
    adminAuthSendSecurityHeaders();
    adminExigirHostDaPlataforma();
    if (!adminAuthStartSession()) {
        http_response_code(503);
        exit('Não foi possível iniciar a sessão administrativa.');
    }
    $pdo = dbConnection();
    if (adminAuthCurrentUser($pdo) === null) {
        header('Location: /admin/login.php', true, 303);
        exit;
    }
    foreach ($_GET as $key => $value) {
        if (!array_key_exists($key, $filters) || !is_string($value)) {
            throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
        }
        $filters[$key] = $value;
    }
    if (preg_match('/^[1-9][0-9]{0,3}$/D', $filters['periodo']) !== 1) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    }
    $configuration = operationalConfigReadState($pdo);
    $retentionDays = (int) ($configuration['active']['configuracao']['retencao_eventos_tecnicos_dias'] ?? 30);
    $result = technicalEventsQuery($pdo, $filters, $retentionDays);
} catch (InvalidArgumentException) {
    $httpStatus = 400;
    $errorMessage = 'Não foi possível aplicar os filtros. Revise os valores e tente novamente.';
} catch (Throwable) {
    $httpStatus = 503;
    $errorMessage = 'Não foi possível carregar os eventos técnicos agora. Tente novamente mais tarde.';
}

$pageStart = $result['total'] === 0 ? 0 : (($result['page'] - 1) * 50) + 1;
$pageEnd = min($result['page'] * 50, $result['total']);
$baseFilters = array_filter($filters, static fn(string $value, string $key): bool => $value !== '' && $key !== 'pagina', ARRAY_FILTER_USE_BOTH);
$periodOptions = [1 => 'Últimas 24 horas', 7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias', 365 => 'Últimos 365 dias'];
$periodOptions = array_filter($periodOptions, static fn(string $label, int $days): bool => $days <= $retentionDays, ARRAY_FILTER_USE_BOTH);
if (!isset($periodOptions[$retentionDays])) $periodOptions[$retentionDays] = 'Todo o período de retenção (' . $retentionDays . ' dias)';
ksort($periodOptions, SORT_NUMERIC);
http_response_code($httpStatus);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Consulta de eventos técnicos · IEPRCard</title>
    <link rel="stylesheet" href="/assets/telemetria.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="IEPRCard, início"><span class="brand-mark" aria-hidden="true">I</span>IEPRCard</a>
        <a class="header-note" href="/telemetria/">Administração · Telemetria</a>
    </header>
    <main class="page-shell">
        <section class="page-heading">
            <p class="eyebrow">ADMINISTRAÇÃO · ACESSO EXCLUSIVO DO SUPERADMIN</p>
            <h1>Consulta de eventos técnicos</h1>
            <p class="intro">Rastreabilidade minimizada · período e datas em UTC · retenção ativa de <?= $retentionDays ?> dias.</p>
            <a class="technical-events-back" href="/telemetria/">Voltar à telemetria</a>
        </section>

        <?php if ($errorMessage !== ''): ?>
            <p class="notice notice-error" role="alert"><?= technicalEventsPageEscape($errorMessage) ?></p>
        <?php else: ?>
            <form class="filters technical-events-filters" method="get" action="/admin/eventos-tecnicos.php" aria-label="Filtros de eventos técnicos">
                <label for="technical-period">Período
                    <select id="technical-period" name="periodo">
                        <?php foreach ($periodOptions as $days => $label): ?><option value="<?= $days ?>" <?= $filters['periodo'] === (string) $days ? 'selected' : '' ?>><?= technicalEventsPageEscape($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label for="technical-category">Categoria
                    <select id="technical-category" name="categoria">
                        <option value="">Todas</option>
                        <?php foreach ($categories as $category): ?><option value="<?= technicalEventsPageEscape($category) ?>" <?= $filters['categoria'] === $category ? 'selected' : '' ?>><?= technicalEventsPageEscape($category) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label for="technical-route">Rota
                    <select id="technical-route" name="rota">
                        <option value="">Todas as rotas</option>
                        <?php foreach ($routes as $route => $category): ?><option value="<?= technicalEventsPageEscape($route) ?>" <?= $filters['rota'] === $route ? 'selected' : '' ?>><?= technicalEventsPageEscape($route) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label for="technical-correlation">Correlation ID
                    <input id="technical-correlation" name="correlation_id" type="text" inputmode="text" autocomplete="off" maxlength="36" pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}" value="<?= technicalEventsPageEscape($filters['correlation_id']) ?>" placeholder="UUID completo">
                </label>
                <button class="primary-button" type="submit">Atualizar resultados</button>
            </form>

            <section class="panel technical-events-panel" aria-labelledby="technical-results-title">
                <div class="technical-events-summary">
                    <div><h2 id="technical-results-title">Eventos encontrados</h2><p class="muted" aria-live="polite"><?= $pageStart ?>–<?= $pageEnd ?> de <?= $result['total'] ?> eventos · mais recentes primeiro</p></div>
                    <span class="technical-events-limit">Até 50 registros por página</span>
                </div>
                <?php if ($result['total'] === 0): ?>
                    <p class="notice" role="status"><?= $result['total_retained'] === 0 ? 'Nenhum evento registrado dentro do período de retenção.' : 'Nenhum evento corresponde aos filtros selecionados.' ?></p>
                <?php else: ?>
                    <div class="technical-events-table-wrap">
                        <table class="technical-events-table">
                            <thead><tr><th scope="col">Data e hora UTC</th><th scope="col">Evento</th><th scope="col">Rota</th><th scope="col">Resultado</th><th scope="col">Duração</th><th scope="col">Correlation ID</th></tr></thead>
                            <tbody>
                            <?php foreach ($result['rows'] as $row): ?>
                                <?php $status = (int) $row['status_http']; $severity = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'success'); $correlation = (string) $row['correlation_id']; ?>
                                <tr>
                                    <td data-label="Data e hora UTC"><time datetime="<?= technicalEventsPageEscape(gmdate('Y-m-d\TH:i:s\Z', strtotime($row['criado_em'] . ' UTC'))) ?>"><?= technicalEventsPageEscape(gmdate('Y-m-d H:i:s', strtotime($row['criado_em'] . ' UTC'))) ?> UTC</time></td>
                                    <td data-label="Evento"><?= technicalEventsPageEscape($row['evento_chave']) ?></td>
                                    <td data-label="Rota"><code><?= technicalEventsPageEscape($row['rota']) ?></code></td>
                                    <td data-label="Resultado"><span class="technical-result technical-result-<?= $severity ?>">HTTP <?= $status ?></span></td>
                                    <td data-label="Duração"><?= number_format((int) $row['duracao_ms'], 0, ',', '.') ?> ms</td>
                                    <td data-label="Correlation ID"><a class="technical-correlation-link" href="/admin/eventos-tecnicos.php?<?= technicalEventsPageEscape(technicalEventsPageQueryString(['periodo' => $filters['periodo'], 'correlation_id' => $correlation])) ?>" aria-label="Filtrar por este correlation ID"><code><?= technicalEventsPageEscape(substr($correlation, 0, 8) . '…' . substr($correlation, -4)) ?></code></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <nav class="technical-events-pagination" aria-label="Paginação de eventos técnicos">
                        <?php if ($result['page'] > 1): ?><a class="secondary-button" href="/admin/eventos-tecnicos.php?<?= technicalEventsPageEscape(technicalEventsPageQueryString($baseFilters + ['pagina' => (string) ($result['page'] - 1)])) ?>">‹ Anterior</a><?php else: ?><span class="secondary-button disabled" aria-disabled="true">‹ Anterior</span><?php endif; ?>
                        <span>Página <?= $result['page'] ?> de <?= $result['pages'] ?></span>
                        <?php if ($result['page'] < $result['pages']): ?><a class="secondary-button" href="/admin/eventos-tecnicos.php?<?= technicalEventsPageEscape(technicalEventsPageQueryString($baseFilters + ['pagina' => (string) ($result['page'] + 1)])) ?>">Próxima ›</a><?php else: ?><span class="secondary-button disabled" aria-disabled="true">Próxima ›</span><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
            <p class="privacy-note">A consulta mostra apenas chaves e códigos técnicos permitidos. Ela não inclui IP, user agent, identificadores de cliente, query, headers, bodies ou texto livre de falhas.</p>
        <?php endif; ?>
    </main>
</body>
</html>
