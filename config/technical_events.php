<?php
declare(strict_types=1);

/** @param array<string, mixed> $event */
function technicalEventsRecord(PDO $pdo, array $event): bool
{
    $keys = array_keys($event);
    sort($keys, SORT_STRING);
    if ($keys !== ['categoria', 'correlation_id', 'duracao_ms', 'evento_chave', 'rota', 'status_http']
        || !is_string($event['correlation_id'] ?? null)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $event['correlation_id']) !== 1
        || !is_string($event['categoria'] ?? null)
        || !is_string($event['rota'] ?? null)
        || !is_string($event['evento_chave'] ?? null) || $event['evento_chave'] !== 'http.response'
        || !is_int($event['status_http'] ?? null) || $event['status_http'] < 100 || $event['status_http'] > 599
        || !is_int($event['duracao_ms'] ?? null) || $event['duracao_ms'] < 0 || $event['duracao_ms'] > 2_147_483_647) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_INVALID');
    }

    $routes = technicalEventsRouteAllowlist();
    if (!isset($routes[$event['rota']]) || $routes[$event['rota']] !== $event['categoria']) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_INVALID');
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO eventos_tecnicos (correlation_id, categoria, evento_chave, rota, status_http, duracao_ms, criado_em) '
            . 'VALUES (:correlation, :category, :event_key, :route, :status, :duration, :created)'
        );
        $insert->execute([
            'correlation' => strtolower($event['correlation_id']),
            'category' => $event['categoria'],
            'event_key' => $event['evento_chave'],
            'route' => $event['rota'],
            'status' => $event['status_http'],
            'duration' => $event['duracao_ms'],
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (PDOException $exception) {
        $driverCode = isset($exception->errorInfo[1]) ? (string) $exception->errorInfo[1] : '';
        if ((string) $exception->getCode() === '23000' || $driverCode === '19' || $driverCode === '1062') {
            return false;
        }
        throw $exception;
    }
}

/** @return array<string, string> Route key => category. */
function technicalEventsRouteAllowlist(): array
{
    return [
        'system.index' => 'system', 'system.router' => 'system', 'public.validation' => 'public',
        'admin.mcp' => 'admin', 'admin.ai_instances' => 'admin', 'admin.ai' => 'admin',
        'admin.schema_status' => 'admin', 'admin.schema_update' => 'admin', 'admin.backup_destinations' => 'admin',
        'admin.installation' => 'admin', 'admin.login' => 'admin', 'admin.logout' => 'admin',
        'admin.database_migration' => 'admin', 'admin.database_migration_status' => 'admin',
        'admin.telemetry' => 'admin', 'admin.technical_events' => 'admin', 'tenant.quotas' => 'tenant', 'tenant.designs' => 'tenant',
        'tenant.domains' => 'tenant', 'tenant.issuance' => 'tenant', 'tenant.team' => 'tenant',
        'tenant.holders' => 'tenant', 'tenant.units' => 'tenant', 'portal.index' => 'portal',
        'portal.card' => 'portal', 'mcp.endpoint' => 'mcp', 'oauth.token' => 'oauth',
        'oauth.metadata' => 'oauth', 'oauth.protected_resource' => 'oauth', 'integration.gowa_validate' => 'integration',
        'integration.gowa_webhook' => 'integration', 'system.unmapped' => 'system',
    ];
}

/**
 * Read one bounded, allowlisted page of technical events for the superadmin UI.
 * @param array<string, mixed> $filters
 * @return array{rows:list<array{correlation_id:string,categoria:string,evento_chave:string,rota:string,status_http:int,duracao_ms:int,criado_em:string}>,total:int,total_retained:int,page:int,pages:int}
 */
function technicalEventsQuery(PDO $pdo, array $filters, int $retentionDays, ?int $now = null): array
{
    $allowedFilterKeys = ['categoria', 'correlation_id', 'pagina', 'periodo', 'rota'];
    if ($retentionDays < 1 || $retentionDays > 3650 || array_diff(array_keys($filters), $allowedFilterKeys) !== []) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    }
    foreach ($filters as $value) {
        if (!is_string($value)) throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    }

    $periodDays = 1;
    if (isset($filters['periodo']) && $filters['periodo'] !== '') {
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $filters['periodo']) !== 1) {
            throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
        }
        $periodDays = (int) $filters['periodo'];
    }
    if ($periodDays > $retentionDays) throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');

    $categories = array_values(array_unique(technicalEventsRouteAllowlist()));
    sort($categories, SORT_STRING);
    $category = $filters['categoria'] ?? '';
    if ($category !== '' && !in_array($category, $categories, true)) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    }
    $routes = technicalEventsRouteAllowlist();
    $route = $filters['rota'] ?? '';
    if ($route !== '' && !isset($routes[$route])) throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');

    $correlationId = $filters['correlation_id'] ?? '';
    if ($correlationId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $correlationId) !== 1) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    }
    $pageValue = $filters['pagina'] ?? '1';
    if (preg_match('/^[1-9][0-9]{0,5}$/D', $pageValue) !== 1) throw new InvalidArgumentException('TECHNICAL_EVENT_FILTER_INVALID');
    $requestedPage = (int) $pageValue;

    $now ??= time();
    $retentionCutoff = gmdate('Y-m-d H:i:s', $now - ($retentionDays * 86400));
    $start = gmdate('Y-m-d H:i:s', $now - ($periodDays * 86400));
    $end = gmdate('Y-m-d H:i:s', $now);
    $where = ['criado_em >= :start', 'criado_em <= :end', 'criado_em >= :retention_cutoff'];
    $parameters = ['start' => $start, 'end' => $end, 'retention_cutoff' => $retentionCutoff];
    if ($category !== '') { $where[] = 'categoria = :category'; $parameters['category'] = $category; }
    if ($route !== '') { $where[] = 'rota = :route'; $parameters['route'] = $route; }
    if ($correlationId !== '') { $where[] = 'correlation_id = :correlation'; $parameters['correlation'] = strtolower($correlationId); }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) FROM eventos_tecnicos WHERE ' . $whereSql);
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();
    $retained = $pdo->prepare('SELECT COUNT(*) FROM eventos_tecnicos WHERE criado_em >= :cutoff AND criado_em <= :now');
    $retained->execute(['cutoff' => $retentionCutoff, 'now' => $end]);
    $totalRetained = (int) $retained->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $page = min($requestedPage, $pages);
    $offset = ($page - 1) * 50;
    $query = $pdo->prepare(
        'SELECT correlation_id, categoria, evento_chave, rota, status_http, duracao_ms, criado_em '
        . 'FROM eventos_tecnicos WHERE ' . $whereSql . ' ORDER BY criado_em DESC, id DESC LIMIT 50 OFFSET ' . $offset
    );
    $query->execute($parameters);
    $rows = array_map(static fn(array $row): array => [
        'correlation_id' => (string) $row['correlation_id'],
        'categoria' => (string) $row['categoria'],
        'evento_chave' => (string) $row['evento_chave'],
        'rota' => (string) $row['rota'],
        'status_http' => (int) $row['status_http'],
        'duracao_ms' => (int) $row['duracao_ms'],
        'criado_em' => (string) $row['criado_em'],
    ], $query->fetchAll(PDO::FETCH_ASSOC));

    return ['rows' => $rows, 'total' => $total, 'total_retained' => $totalRetained, 'page' => $page, 'pages' => $pages];
}

/** @return array{categoria:string,rota:string} */
function technicalEventsCanonicalRoute(string $requestPath, string $scriptName): array
{
    $path = parse_url($requestPath, PHP_URL_PATH);
    $path = is_string($path) ? $path : '';
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    $exact = [
        '/' => ['system', 'system.index'], '/index.php' => ['system', 'system.index'],
        '/mcp' => ['mcp', 'mcp.endpoint'], '/mcp.php' => ['mcp', 'mcp.endpoint'],
        '/oauth/token' => ['oauth', 'oauth.token'], '/oauth/token.php' => ['oauth', 'oauth.token'],
        '/.well-known/oauth-authorization-server' => ['oauth', 'oauth.metadata'],
        '/.well-known/oauth-authorization-server.php' => ['oauth', 'oauth.metadata'],
        '/.well-known/oauth-protected-resource/mcp' => ['oauth', 'oauth.protected_resource'],
        '/.well-known/oauth-protected-resource-mcp.php' => ['oauth', 'oauth.protected_resource'],
        '/admin/eventos-tecnicos.php' => ['admin', 'admin.technical_events'],
        '/telemetria' => ['admin', 'admin.telemetry'], '/telemetria.php' => ['admin', 'admin.telemetry'],
        '/portal' => ['portal', 'portal.index'], '/portal/index.php' => ['portal', 'portal.index'],
        '/validar' => ['public', 'public.validation'], '/validar.php' => ['public', 'public.validation'],
    ];
    if (isset($exact[$path])) return ['categoria' => $exact[$path][0], 'rota' => $exact[$path][1]];
    if (preg_match('~^/validar/[^/]+/?$~D', $path) === 1) return ['categoria' => 'public', 'rota' => 'public.validation'];
    if (preg_match('~^/portal/carteirinha/[1-9][0-9]*/[^/]+$~D', $path) === 1 || $path === '/portal/carteirinha.php') {
        return ['categoria' => 'portal', 'rota' => 'portal.card'];
    }

    $script = str_replace('\\', '/', $scriptName);
    $scriptRoutes = [
        'admin/acesso-mcp.php' => ['admin', 'admin.mcp'], 'admin/atendimento-ia-instances.php' => ['admin', 'admin.ai_instances'],
        'admin/atendimento-ia.php' => ['admin', 'admin.ai'], 'admin/atualizar-schema-status.php' => ['admin', 'admin.schema_status'],
        'admin/atualizar-schema.php' => ['admin', 'admin.schema_update'], 'admin/backup-destinos.php' => ['admin', 'admin.backup_destinations'],
        'admin/instalacao.php' => ['admin', 'admin.installation'], 'admin/login.php' => ['admin', 'admin.login'],
        'admin/logout.php' => ['admin', 'admin.logout'], 'admin/eventos-tecnicos.php' => ['admin', 'admin.technical_events'],
        'admin/migracao-banco.php' => ['admin', 'admin.database_migration'],
        'admin/migracao-status.php' => ['admin', 'admin.database_migration_status'], 'tenant/cotas.php' => ['tenant', 'tenant.quotas'],
        'tenant/designs.php' => ['tenant', 'tenant.designs'], 'tenant/dominios.php' => ['tenant', 'tenant.domains'],
        'tenant/emitir.php' => ['tenant', 'tenant.issuance'], 'tenant/equipe.php' => ['tenant', 'tenant.team'],
        'tenant/titulares.php' => ['tenant', 'tenant.holders'], 'tenant/unidades.php' => ['tenant', 'tenant.units'],
        'webhook-gowa-validate.php' => ['integration', 'integration.gowa_validate'],
        'webhook-gowa.php' => ['integration', 'integration.gowa_webhook'], 'router.php' => ['system', 'system.router'],
    ];
    $suffix = ltrim($script, '/');
    foreach ($scriptRoutes as $entrypoint => [$category, $route]) {
        if ($suffix === $entrypoint || str_ends_with($suffix, '/' . $entrypoint)) return ['categoria' => $category, 'rota' => $route];
    }
    return ['categoria' => 'system', 'rota' => 'system.unmapped'];
}

/** Delete no more than one bounded batch, leaving later records for the next cron run. */
function technicalEventsPurgeExpired(PDO $pdo, int $retentionDays, int $limit = 100, ?int $now = null): int
{
    if ($retentionDays < 1 || $retentionDays > 3650 || $limit < 1 || $limit > 200) {
        throw new InvalidArgumentException('TECHNICAL_EVENT_RETENTION_LIMIT_INVALID');
    }
    $cutoff = gmdate('Y-m-d H:i:s', ($now ?? time()) - ($retentionDays * 86400));
    $select = $pdo->prepare('SELECT id FROM eventos_tecnicos WHERE criado_em < :cutoff ORDER BY id LIMIT ' . $limit);
    $select->execute(['cutoff' => $cutoff]);
    $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare('DELETE FROM eventos_tecnicos WHERE id IN (' . $placeholders . ')');
    $delete->execute($ids);
    return $delete->rowCount();
}
