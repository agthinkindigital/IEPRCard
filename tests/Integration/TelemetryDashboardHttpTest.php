<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/business_telemetry.php';
require_once dirname(__DIR__, 2) . '/config/operational_batch_metrics.php';
require_once dirname(__DIR__, 2) . '/config/operational_configuration.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$root = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-telemetry-page-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create telemetry dashboard HTTP test storage.');
}
$databasePath = $directory . DIRECTORY_SEPARATOR . 'telemetry.sqlite';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
$oldSessionName = session_name();
$oldSessionId = session_id();
$oldSessionPath = session_save_path();
$oldCookieParams = session_get_cookie_params();
$oldSession = $_SESSION ?? [];
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($directory);
putenv('APP_ENV=test');
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');

$database = ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath];
$mysql = [
    'DB_HOST' => getenv('TEST_MYSQL_HOST'),
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE'),
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME'),
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD'),
];
if (in_array('mysql', PDO::getAvailableDrivers(), true)
    && count(array_filter($mysql, static fn (mixed $value): bool => $value !== false)) === count($mysql)) {
    $database = ['DB_DRIVER' => 'mysql'] + $mysql;
}
foreach (['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) putenv($key);
foreach ($database as $key => $value) putenv($key . '=' . $value);

$pdo = dbConnect($database);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$fixture = tenantTestSeed($pdo);
$suffix = bin2hex(random_bytes(5));
$plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
$plan->execute(['name' => 'Telemetry dashboard ' . $suffix]);
$planId = (int) $pdo->lastInsertId();
$flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
foreach (['telemetria.rede', 'telemetria.unidade', 'admin.telemetria'] as $feature) {
    $flag->execute(['plan' => $planId, 'key' => $feature]);
}
$pdo->prepare('UPDATE tenants SET plano_id = :plan, permissoes_unidade_json = :permissions WHERE id = :tenant')->execute([
    'plan' => $planId, 'permissions' => '["telemetria.unidade"]', 'tenant' => $fixture['tenant_id'],
]);
$unitTwo = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
    . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
);
$unitTwo->execute(['parent' => $fixture['tenant_id'], 'name' => 'Unidade Leste ' . $suffix, 'slug' => 'east-' . $suffix]);
$unitTwoId = (int) $pdo->lastInsertId();
$foreignRoot = $pdo->prepare(
    "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
    . "VALUES (:plan, 'cliente', :name, :slug, 'ativo')"
);
$foreignRoot->execute(['plan' => $planId, 'name' => 'Sede estrangeira ' . $suffix, 'slug' => 'foreign-' . $suffix]);
$foreignTenantId = (int) $pdo->lastInsertId();
$foreignUnit = $pdo->prepare(
    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
    . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
);
$foreignUnit->execute(['parent' => $foreignTenantId, 'name' => 'Unidade alheia ' . $suffix, 'slug' => 'foreign-unit-' . $suffix]);
$foreignUnitId = (int) $pdo->lastInsertId();

$addUser = static function (?int $tenantId, ?int $unitId, string $role, string $name) use ($pdo): int {
    $email = bin2hex(random_bytes(6)) . '@example.test';
    $insert = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, :unit, :role, :name, :email, :password, 1)'
    );
    $insert->execute([
        'tenant' => $tenantId, 'unit' => $unitId, 'role' => $role, 'name' => $name, 'email' => $email,
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    ]);
    return (int) $pdo->lastInsertId();
};
$adminId = $addUser(null, null, 'superadmin', 'Telemetry Admin Fixture');
$clientId = $addUser($fixture['tenant_id'], null, 'titular_cliente', 'Telemetry Client Fixture');
$unitUserId = $addUser($fixture['tenant_id'], $fixture['subcliente_id'], 'responsavel_sub', 'Telemetry Unit Fixture');

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$businessDay = $today->modify('-1 day')->format('Y-m-d');
$insertBusiness = $pdo->prepare(
    'INSERT INTO telemetria_diaria (tenant_id, subcliente_id, subcliente_scope_id, dia, categoria, evento_chave, quantidade, atualizado_em) '
    . 'VALUES (:tenant, :unit, :scope_unit, :day, :category, :event, :quantity, :updated)'
);
foreach ([
    [$fixture['tenant_id'], null, 0, 'issuance', 'card.generated', 4],
    [$fixture['tenant_id'], $fixture['subcliente_id'], $fixture['subcliente_id'], 'issuance', 'card.generated', 2],
    [$fixture['tenant_id'], $unitTwoId, $unitTwoId, 'validation', 'qr.validated', 3],
    [$foreignTenantId, $foreignUnitId, $foreignUnitId, 'issuance', 'card.generated', 99],
    [$fixture['tenant_id'], null, 0, 'private', 'holder.email@example.test', 77],
] as [$tenantId, $unitId, $scopeId, $category, $event, $quantity]) {
    $insertBusiness->execute([
        'tenant' => $tenantId, 'unit' => $unitId, 'scope_unit' => $scopeId, 'day' => $businessDay,
        'category' => $category, 'event' => $event, 'quantity' => $quantity, 'updated' => gmdate('Y-m-d H:i:s'),
    ]);
}

$metricStart = $today->modify('-7 days')->format('Y-m-d');
$metricEnd = $today->modify('-1 day')->format('Y-m-d');
$metricDelete = $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE ambiente = :environment AND dia >= :start AND dia <= :end');
$metricDelete->execute(['environment' => 'test', 'start' => $metricStart, 'end' => $metricEnd]);
for ($offset = 1; $offset <= 3; $offset++) {
    operationalBatchMetricsRecord($pdo, [
        'demand' => $offset * 10, 'processed' => $offset, 'duration_per_record_ms' => $offset * 10,
        'queue_depth' => $offset * 2, 'failures' => $offset % 2,
    ], time() - ($offset * 86400));
}
unset($pdo);

$createSession = static function (string $name, array $values) use ($directory): string {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($directory);
    session_name($name);
    session_id('');
    if (!session_start()) throw new RuntimeException('Could not prepare a telemetry HTTP session fixture.');
    $_SESSION = $values;
    $cookie = session_name() . '=' . session_id();
    session_write_close();
    return $cookie;
};
$adminCsrf = bin2hex(random_bytes(32));
$adminCookie = $createSession('IEPRCARDADMIN', ['admin_user_id' => $adminId, 'last_activity_at' => time(), 'csrf_token' => $adminCsrf]);
$clientCookie = $createSession('IEPRCARDTENANT', [
    'tenant_user_id' => $clientId, 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente', 'last_activity_at' => time(),
]);
$unitCookie = $createSession('IEPRCARDTENANT', [
    'tenant_user_id' => $unitUserId, 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => $fixture['subcliente_id'],
    'papel_sistema' => 'responsavel_sub', 'last_activity_at' => time(),
]);
$rootHost = $fixture['slug'] . '.ieprcard.test';
$unitHost = 'unit-' . substr($fixture['slug'], 7) . '.' . $fixture['slug'] . '.ieprcard.test';
$newPendingConfigId = null;

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($probe)) throw new RuntimeException('Could not reserve a telemetry dashboard test port.');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $directory, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'router.php'],
    [0 => ['file', $nullDevice, 'r'], 1 => ['file', $directory . DIRECTORY_SEPARATOR . 'out.log', 'a'], 2 => ['file', $directory . DIRECTORY_SEPARATOR . 'err.log', 'a']],
    $pipes,
    $root
);
if (!is_resource($process)) throw new RuntimeException('Could not start telemetry dashboard HTTP test server.');

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $request = static function (string $path, string $host, ?string $cookie = null) use ($baseUrl): array {
        $headers = ['Host: ' . $host];
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true,
            'timeout' => 4, 'follow_location' => 0,
        ]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $responseHeaders];
    };
    $postRequest = static function (string $path, string $host, string $cookie, array $values) use ($baseUrl): array {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Host: " . $host . "\r\nCookie: " . $cookie . "\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($values), 'ignore_errors' => true, 'timeout' => 4, 'follow_location' => 0,
        ]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
        return [(int) ($match[1] ?? 0), is_string($body) ? $body : '', $responseHeaders];
    };
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $probeCode, $probeMessage, 0.1);
        if (is_resource($socket)) { fclose($socket); $ready = true; break; }
        usleep(100_000);
    }
    if (!$ready) throw new RuntimeException('Telemetry dashboard HTTP test server did not become ready.');

    [$status, $body, $headers] = $request('/telemetria/', 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'Carteirinhas geradas')
        || !str_contains($body, '<strong class="metric-value">105</strong>')
        || !str_contains($body, 'Histórico insuficiente') || !str_contains($body, 'Fila atual')
        || !str_contains($body, 'Escopo global') || str_contains($body, 'holder.email@example.test')
        || !str_contains($body, 'Configuração de lotes') || !str_contains($body, 'Nenhuma versão ativa')
        || str_contains($body, 'Unidade alheia')
        || !str_contains(implode("\n", $headers), 'no-store')) {
        throw new RuntimeException('Admin dashboard assertion failed: status=' . $status . ', body=' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 1800) . ', cache=' . (telemetryDashboardHeaderValue($headers, 'Cache-Control') ?? 'missing'));
    }
    [$status, $body] = $request('/telemetria/?janela=7', 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'últimos 7 dias UTC completos')
        || !str_contains($body, 'Histórico insuficiente')) {
        throw new RuntimeException('Admin forecast window accepts the supported minimum and reports insufficient observed history.');
    }

    [$status, $body] = $postRequest('/telemetria/', 'admin.ieprcard.test', $adminCookie, [
        'action' => 'save_operational_config',
        'lote_maximo_registros' => '200', 'pico_previsto_diario' => '450', 'janela_previsao_dias' => '21',
        'confirmar_aplicacao' => '1',
    ]);
    if ($status !== 403 || !str_contains($body, 'Solicitação inválida')) {
        throw new RuntimeException('Operational configuration changes must reject missing CSRF tokens.');
    }
    [$status, $body, $headers] = $postRequest('/telemetria/', 'admin.ieprcard.test', '', [
        'action' => 'save_operational_config', 'csrf_token' => $adminCsrf, 'lote_maximo_registros' => '200',
        'pico_previsto_diario' => '450', 'janela_previsao_dias' => '21', 'confirmar_aplicacao' => '1',
    ]);
    if ($status !== 303 || telemetryDashboardHeaderValue($headers, 'Location') !== '/admin/login.php') {
        throw new RuntimeException('An unauthenticated Admin cannot save operational settings.');
    }
    [$status, $body] = $postRequest('/telemetria/', 'admin.ieprcard.test', $adminCookie, [
        'action' => 'save_operational_config', 'csrf_token' => $adminCsrf, 'lote_maximo_registros' => '0', 'pico_previsto_diario' => '450',
        'janela_previsao_dias' => '21', 'confirmar_aplicacao' => '1',
    ]);
    if ($status !== 400 || !str_contains($body, 'Não foi possível validar os ajustes')) {
        throw new RuntimeException('Operational configuration writes must reject out-of-range values generically.');
    }
    [$status, $body] = $postRequest('/telemetria/', 'admin.ieprcard.test', $adminCookie, [
        'action' => 'save_operational_config', 'csrf_token' => $adminCsrf, 'lote_maximo_registros' => '200', 'pico_previsto_diario' => '450',
        'janela_previsao_dias' => '21',
    ]);
    if ($status !== 400 || !str_contains($body, 'Confirme que deseja salvar')) {
        throw new RuntimeException('Operational configuration writes require explicit confirmation before saving.');
    }
    foreach ([[$rootHost, $clientCookie], [$unitHost, $unitCookie]] as [$tenantHost, $tenantCookie]) {
        [$status] = $postRequest('/telemetria/', $tenantHost, $tenantCookie, [
            'action' => 'save_operational_config', 'csrf_token' => $adminCsrf, 'lote_maximo_registros' => '200', 'pico_previsto_diario' => '450',
            'janela_previsao_dias' => '21', 'confirmar_aplicacao' => '1',
        ]);
        if ($status !== 403) throw new RuntimeException('Client and Subclient cannot write Admin operational configuration.');
    }
    [$status, $body, $headers] = $postRequest('/telemetria/', 'admin.ieprcard.test', $adminCookie, [
        'action' => 'save_operational_config', 'csrf_token' => $adminCsrf, 'lote_maximo_registros' => '200', 'pico_previsto_diario' => '450',
        'janela_previsao_dias' => '21', 'confirmar_aplicacao' => '1',
    ]);
    if ($status !== 303 || telemetryDashboardHeaderValue($headers, 'Location') !== '/telemetria/') {
        throw new RuntimeException('A confirmed Admin configuration must save as pending and redirect before displaying the result.');
    }
    [$status, $body] = $request('/telemetria/', 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'Versão pendente 1')
        || !str_contains($body, '200 registros') || !str_contains($body, '450 por dia')
        || !str_contains($body, '21 dias') || !str_contains($body, 'próxima execução agendada do worker')) {
        throw new RuntimeException('The Admin dashboard must show the saved pending version and shared-hosting activation timing.');
    }
    $configPdo = dbConnect($database);
    $configRows = $configPdo->query("SELECT id, estado FROM configuracoes_operacionais WHERE ambiente = 'test' ORDER BY versao DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if (count($configRows) !== 1 || $configRows[0]['estado'] !== 'pendente') {
        throw new RuntimeException('Saving from the dashboard must preserve the active worker configuration and create only a pending version.');
    }
    $newPendingConfigId = (int) $configRows[0]['id'];
    $activatedConfig = operationalConfigWorkerStart($configPdo);
    if (($activatedConfig['id'] ?? null) !== $newPendingConfigId || ($activatedConfig['ativou_pendente'] ?? null) !== true) {
        throw new RuntimeException('A pending dashboard configuration must activate only when the test invokes the worker-start hook.');
    }
    unset($configPdo);
    [$status, $body] = $request('/telemetria/', 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'Versão ativa 1') || str_contains($body, 'Versão pendente 1')) {
        throw new RuntimeException('The Admin dashboard must distinguish the worker-activated version from pending settings.');
    }
    [$status, $body] = $request('/telemetria/?janela=6', 'admin.ieprcard.test', $adminCookie);
    if ($status !== 400 || !str_contains($body, 'Não foi possível aplicar os filtros') || str_contains($body, 'janela=6')) {
        throw new RuntimeException('Admin forecast window below seven days must be rejected without reflecting the value.');
    }
    [$status, $body] = $request('/telemetria/?escopo=tenant&tenant_id=' . $fixture['tenant_id'], 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'Escopo da Sede')
        || !str_contains($body, '<strong class="metric-value">6</strong>')) {
        throw new RuntimeException('Admin may select a tenant-scoped business telemetry view.');
    }
    [$status, $body] = $request('/telemetria/?escopo=unit&tenant_id=' . $fixture['tenant_id'] . '&subcliente_id=' . $fixture['subcliente_id'], 'admin.ieprcard.test', $adminCookie);
    if ($status !== 200 || !str_contains($body, 'Escopo da unidade')
        || !str_contains($body, '<strong class="metric-value">2</strong>')
        || str_contains($body, '<strong class="metric-value">3</strong>')) {
        throw new RuntimeException('Admin may select one unit without leaking sibling-unit business aggregates.');
    }
    [$status, $body] = $request('/telemetria/?escopo=unit&tenant_id=' . $foreignTenantId . '&subcliente_id=' . $fixture['subcliente_id'], 'admin.ieprcard.test', $adminCookie);
    if ($status !== 400 || !str_contains($body, 'Não foi possível aplicar os filtros')) {
        throw new RuntimeException('Invalid admin scope must fail generically.');
    }

    [$status, $body] = $request('/telemetria/', $rootHost, $clientCookie);
    if ($status !== 200 || !str_contains($body, 'Visão da rede')
        || !str_contains($body, '<strong class="metric-value">6</strong>')
        || str_contains($body, 'Fila atual') || str_contains($body, 'telemetria.rede')
        || str_contains($body, 'Configuração de lotes') || str_contains($body, 'name="lote_maximo_registros"')) {
        throw new RuntimeException('Client dashboard must show only its own network and omit all operational metrics.');
    }
    [$status, $body] = $request('/telemetria/?escopo=unit&subcliente_id=' . $fixture['subcliente_id'], $rootHost, $clientCookie);
    if ($status !== 200 || !str_contains($body, 'Filtro de unidade')
        || !str_contains($body, '<strong class="metric-value">2</strong>')
        || str_contains($body, '<strong class="metric-value">3</strong>')) {
        throw new RuntimeException('Client may filter a unit within its authenticated network.');
    }
    [$status, $body] = $request('/telemetria/?escopo=unit&subcliente_id=' . $foreignUnitId, $rootHost, $clientCookie);
    if ($status !== 403 || !str_contains($body, 'Não foi possível aplicar os filtros') || str_contains($body, '99')) {
        throw new RuntimeException('Client unit filters must reject cross-tenant identifiers without returning foreign metrics.');
    }
    [$status, $body] = $request('/telemetria/?escopo=unit&tenant_id=' . $foreignTenantId, $rootHost, $clientCookie);
    if ($status !== 403 || str_contains($body, '99')) {
        throw new RuntimeException('Client query parameters must not expand the authenticated tenant scope.');
    }

    [$status, $body] = $request('/telemetria/', $unitHost, $unitCookie);
    if ($status !== 200 || !str_contains($body, 'Visão da unidade')
        || !str_contains($body, '<strong class="metric-value">2</strong>')
        || str_contains($body, 'Visão da rede') || str_contains($body, 'Unidade Leste')
        || str_contains($body, 'Selecionar unidade') || str_contains($body, 'Fila atual')
        || str_contains($body, 'Configuração de lotes') || str_contains($body, 'name="lote_maximo_registros"')) {
        throw new RuntimeException('Subclient dashboard must remain fixed to its own unit without operational metrics or unit filters.');
    }
    [$status, $body] = $request('/telemetria/?escopo=network', $unitHost, $unitCookie);
    if ($status !== 400 || !str_contains($body, 'Não foi possível aplicar os filtros')) {
        throw new RuntimeException('Subclient scope selectors must be rejected.');
    }

    [$status, $body] = $request('/telemetria/?de=not-a-date', $rootHost, $clientCookie);
    if ($status !== 400 || !str_contains($body, 'Não foi possível aplicar os filtros')
        || str_contains($body, 'not-a-date')) {
        throw new RuntimeException('Invalid period window assertion failed: status=' . $status . ', body=' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 400));
    }
    $emptyFrom = $today->modify('-360 days')->format('Y-m-d');
    $emptyTo = $today->modify('-355 days')->format('Y-m-d');
    [$status, $body] = $request('/telemetria/?de=' . $emptyFrom . '&ate=' . $emptyTo, $rootHost, $clientCookie);
    if ($status !== 200 || !str_contains($body, 'Sem dados neste período')) {
        throw new RuntimeException('An empty business telemetry range must be shown as a readable page state.');
    }

    $issuanceWindowStart = $today->modify('-50 days')->format('Y-m-d');
    $issuanceWindowEnd = $today->modify('-1 day')->format('Y-m-d');
    $issuancePdo = dbConnect($database);
    $issuanceSeed = $issuancePdo->prepare(
        'INSERT INTO telemetria_diaria (tenant_id, subcliente_id, subcliente_scope_id, dia, categoria, evento_chave, quantidade, atualizado_em) '
        . 'VALUES (:tenant, NULL, 0, :day, \'issuance\', \'card.generated\', 1, :updated)'
    );
    for ($offset = 9; $offset <= 40; $offset++) {
        $issuanceSeed->execute([
            'tenant' => $fixture['tenant_id'], 'day' => $today->modify('-' . $offset . ' days')->format('Y-m-d'),
            'updated' => gmdate('Y-m-d H:i:s'),
        ]);
    }
    $totalQuery = $issuancePdo->prepare(
        "SELECT COALESCE(SUM(quantidade), 0) FROM telemetria_diaria WHERE categoria = 'issuance' AND evento_chave = 'card.generated' "
        . 'AND dia >= :window_start AND dia <= :window_end'
    );
    $totalQuery->execute(['window_start' => $issuanceWindowStart, 'window_end' => $issuanceWindowEnd]);
    $expectedIssuedTotal = (int) $totalQuery->fetchColumn();
    unset($issuancePdo);
    [$status, $body] = $request(
        '/telemetria/?de=' . $issuanceWindowStart . '&ate=' . $issuanceWindowEnd,
        'admin.ieprcard.test', $adminCookie
    );
    $visibleEmissionDays = preg_match_all('/<div class="daily-row" role="listitem">/', $body);
    $oldestSeededDay = $today->modify('-40 days')->format('Y-m-d');
    $newestSeededDay = $today->modify('-9 days')->format('Y-m-d');
    if ($status !== 200 || $visibleEmissionDays !== 30
        || str_contains($body, 'datetime="' . $oldestSeededDay . '"')
        || !str_contains($body, 'datetime="' . $newestSeededDay . '"')
        || !str_contains($body, 'class="metric-value">' . number_format($expectedIssuedTotal, 0, ',', '.') . '</strong>')
        || !str_contains($body, '30 dias mais recentes com emissão')
        || !str_contains($body, 'Dias sem emissão não aparecem')) {
        throw new RuntimeException(sprintf(
            'The issuance chart must explain its 30-day data-bearing window while totals retain the full selected period. status=%d rows=%s oldest=%d newest=%d total=%d total-visible=%d caption=%d missing=%d',
            $status, var_export($visibleEmissionDays, true), str_contains($body, 'datetime="' . $oldestSeededDay . '"') ? 1 : 0,
            str_contains($body, 'datetime="' . $newestSeededDay . '"') ? 1 : 0, $expectedIssuedTotal,
            str_contains($body, 'class="metric-value">' . number_format($expectedIssuedTotal, 0, ',', '.') . '</strong>') ? 1 : 0,
            str_contains($body, '30 dias mais recentes com emissão') ? 1 : 0,
            str_contains($body, 'Dias sem emissão não aparecem') ? 1 : 0
        ));
    }

    $backupTable = 'telemetria_diaria_failure_' . bin2hex(random_bytes(4));
    $failurePdo = dbConnect($database);
    if ($database['DB_DRIVER'] === 'mysql') {
        $failurePdo->exec('RENAME TABLE telemetria_diaria TO `' . $backupTable . '`');
    } else {
        $failurePdo->exec('ALTER TABLE telemetria_diaria RENAME TO "' . $backupTable . '"');
    }
    unset($failurePdo);
    try {
        [$status, $body] = $request('/telemetria/', 'admin.ieprcard.test', $adminCookie);
        if ($status !== 503 || !str_contains($body, 'Não foi possível carregar as métricas agora')
            || str_contains($body, 'telemetria_diaria') || str_contains($body, 'SQLSTATE')) {
            throw new RuntimeException('A backend read failure must show a generic status message without schema or SQL details.');
        }
    } finally {
        $restorePdo = dbConnect($database);
        if ($database['DB_DRIVER'] === 'mysql') {
            $restorePdo->exec('RENAME TABLE `' . $backupTable . '` TO telemetria_diaria');
        } else {
            $restorePdo->exec('ALTER TABLE "' . $backupTable . '" RENAME TO telemetria_diaria');
        }
        unset($restorePdo);
    }
    [$status, $body, $headers] = $request('/telemetria/', 'admin.ieprcard.test');
    if ($status !== 303 || telemetryDashboardHeaderValue($headers, 'Location') !== '/admin/login.php') {
        throw new RuntimeException('The Admin dashboard must require an authenticated global session.');
    }
    [$status, $body] = $request('/telemetria/', $rootHost);
    if ($status !== 401 || !str_contains($body, 'Acesso restrito à sessão da instituição')) {
        throw new RuntimeException('The tenant dashboard must reject unauthenticated sessions without redirecting into the titular portal.');
    }
    fwrite(STDOUT, 'PASS: role-scoped telemetry dashboard, scope/privacy checks, operational configuration confirmation and worker activation (' . $database['DB_DRIVER'] . ").\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($oldSessionPath);
    session_name($oldSessionName);
    session_id($oldSessionId);
    session_set_cookie_params($oldCookieParams);
    $_SESSION = $oldSession;
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) putenv($key);
        else putenv($key . '=' . $value);
    }
    $cleanup = dbConnect($database);
    if ($newPendingConfigId !== null) {
        $cleanup->prepare('DELETE FROM configuracoes_operacionais WHERE id = :id')->execute(['id' => $newPendingConfigId]);
    }
    $metricDelete->execute(['environment' => 'test', 'start' => $metricStart, 'end' => $metricEnd]);
    $cleanup->prepare('DELETE FROM telemetria_diaria WHERE tenant_id IN (:tenant, :foreign)')
        ->execute(['tenant' => $fixture['tenant_id'], 'foreign' => $foreignTenantId]);
    $cleanup->prepare('DELETE FROM usuarios WHERE id IN (:admin, :client, :unit)')
        ->execute(['admin' => $adminId, 'client' => $clientId, 'unit' => $unitUserId]);
    $cleanup->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignUnitId]);
    $cleanup->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $foreignTenantId]);
    $cleanup->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $unitTwoId]);
    tenantTestCleanup($cleanup, $fixture);
    $cleanup->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
    $cleanup->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
    unset($cleanup);
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
        elseif (is_dir($path)) {
            foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) if (is_file($child)) @unlink($child);
            @rmdir($path);
        }
    }
    @rmdir($directory);
}

return 'pass';

function telemetryDashboardHeaderValue(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
    }

    return null;
}
