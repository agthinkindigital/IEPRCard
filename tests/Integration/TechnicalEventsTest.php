<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/technical_events.php';
require_once dirname(__DIR__, 2) . '/config/technical_golden_signals.php';

$technicalPageRoute = technicalEventsCanonicalRoute('/admin/eventos-tecnicos.php?categoria=admin', '/admin/eventos-tecnicos.php');
if ($technicalPageRoute !== ['categoria' => 'admin', 'rota' => 'admin.technical_events']) {
    throw new RuntimeException('Technical events query page must use its canonical server-side route key and discard query parameters.');
}

foreach (tenantTestConnections() as $driver => $pdo) {
    $migrations = schemaAplicarMigracoes($pdo, 'test');
    if (end($migrations) !== '0027_issuance_correlation') {
        throw new RuntimeException($driver . ': correlation propagation must use the newest versioned migration.');
    }
    $pdo->exec('DELETE FROM eventos_tecnicos');
    $correlationId = '3d2ac80c-5fb2-4de4-bfea-5e885e2b2318';
    $event = [
        'correlation_id' => $correlationId, 'categoria' => 'admin', 'evento_chave' => 'http.response',
        'rota' => 'admin.telemetry', 'status_http' => 303, 'duracao_ms' => 18,
    ];
    if (!technicalEventsRecord($pdo, $event)) {
        throw new RuntimeException($driver . ': the HTTP response event must be persisted.');
    }
    $workerEvent = [
        'correlation_id' => $correlationId, 'categoria' => 'job', 'evento_chave' => 'issuance.job_started',
        'rota' => 'worker.card_issuance', 'status_http' => 0, 'duracao_ms' => 0,
    ];
    if (!technicalEventsRecord($pdo, $workerEvent)) {
        throw new RuntimeException($driver . ': the same trace must accept a worker event with no HTTP status.');
    }
    $stored = $pdo->prepare('SELECT correlation_id, categoria, evento_chave, rota, status_http, duracao_ms FROM eventos_tecnicos WHERE correlation_id = :id');
    $stored->execute(['id' => $correlationId]);
    $row = $stored->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['correlation_id'] !== $correlationId || $row['categoria'] !== 'admin'
        || $row['evento_chave'] !== 'http.response' || $row['rota'] !== 'admin.telemetry'
        || (int) $row['status_http'] !== 303 || (int) $row['duracao_ms'] !== 18
        || array_intersect(['tenant_id', 'subcliente_id', 'usuario_id', 'ip', 'user_agent', 'request_body'], array_keys($row)) !== []) {
        throw new RuntimeException($driver . ': persisted events must contain only the minimized allowlisted fields.');
    }
    $traceCount = $pdo->prepare('SELECT COUNT(*) FROM eventos_tecnicos WHERE correlation_id = :id');
    $traceCount->execute(['id' => $correlationId]);
    $storedWorkerEvent = $pdo->prepare('SELECT status_http FROM eventos_tecnicos WHERE correlation_id = :id AND evento_chave = :event');
    $storedWorkerEvent->execute(['id' => $correlationId, 'event' => 'issuance.job_started']);
    if ((int) $traceCount->fetchColumn() !== 2 || (int) $storedWorkerEvent->fetchColumn() !== 0) {
        throw new RuntimeException($driver . ': a trace must link multiple allowlisted events and use zero only as non-HTTP status.');
    }

    $pdo->exec('DELETE FROM eventos_tecnicos');
    $metricsNow = strtotime('2026-11-24 12:00:00 UTC');
    $metricSeed = $pdo->prepare(
        'INSERT INTO eventos_tecnicos (correlation_id, categoria, evento_chave, rota, status_http, duracao_ms, criado_em) '
        . 'VALUES (:id, :category, :key, :route, :status, :duration, :created)'
    );
    foreach ([
        ['8b5a2000-0000-4000-8000-000000000010', 'http.response', 'admin', 'admin.telemetry', 599, 100000, '2026-10-24 11:59:59'],
        ['8b5a2000-0000-4000-8000-000000000011', 'http.response', 'admin', 'admin.telemetry', 200, 40, '2026-11-22 23:59:59'],
        ['8b5a2000-0000-4000-8000-000000000017', 'http.response', 'admin', 'admin.telemetry', 200, 50, '2026-10-25 12:00:00'],
        ['8b5a2000-0000-4000-8000-000000000012', 'http.response', 'admin', 'admin.telemetry', 200, 100, '2026-11-23 01:00:00'],
        ['8b5a2000-0000-4000-8000-000000000013', 'http.response', 'admin', 'admin.telemetry', 500, 200, '2026-11-23 05:00:00'],
        ['8b5a2000-0000-4000-8000-000000000014', 'http.response', 'admin', 'admin.telemetry', 503, 400, '2026-11-24 07:00:00'],
        ['8b5a2000-0000-4000-8000-000000000015', 'http.response', 'admin', 'admin.telemetry', 404, 900, '2026-11-24 11:00:00'],
        ['8b5a2000-0000-4000-8000-000000000016', 'issuance.job_failed', 'job', 'worker.card_issuance', 0, 99999, '2026-11-24 10:00:00'],
    ] as [$id, $eventKey, $category, $route, $status, $duration, $created]) {
        $metricSeed->execute([
            'id' => $id, 'key' => $eventKey, 'category' => $category, 'route' => $route,
            'status' => $status, 'duration' => $duration, 'created' => $created,
        ]);
    }
    $signals = technicalEventsGoldenSignals($pdo, '2026-11-23', '2026-11-24', 30, $metricsNow);
    if ($signals['window_from'] !== '2026-11-23 00:00:00' || $signals['window_to'] !== '2026-11-24 12:00:00'
        || $signals['observed_count'] !== 4 || abs(($signals['observed_per_hour'] ?? 0.0) - (4 / 36)) > 0.000001
        || $signals['p95_ms'] !== 900 || $signals['sample_size'] !== 4 || $signals['sampled'] !== false
        || $signals['http_5xx_count'] !== 2 || abs(($signals['http_5xx_rate'] ?? 0.0) - 0.5) > 0.000001) {
        throw new RuntimeException($driver . ': golden signals must use the retained UTC window, HTTP responses only, elapsed hours and observed 5xx/P95.');
    }
    $retainedSignals = technicalEventsGoldenSignals($pdo, '2026-10-01', '2026-11-24', 30, $metricsNow);
    if ($retainedSignals['window_from'] !== '2026-10-25 12:00:00' || $retainedSignals['window_to'] !== '2026-11-24 12:00:00'
        || $retainedSignals['observed_count'] !== 6 || $retainedSignals['sample_size'] !== 6
        || $retainedSignals['p95_ms'] !== 900 || $retainedSignals['http_5xx_count'] !== 2
        || abs(($retainedSignals['observed_per_hour'] ?? 0.0) - (6 / 720)) > 0.000001) {
        throw new RuntimeException($driver . ': a wider selected period must clamp to active retention, including its exact cutoff.');
    }
    $pdo->exec('DELETE FROM eventos_tecnicos');
    for ($index = 1; $index <= 5000; $index++) {
        $metricSeed->execute([
            'id' => sprintf('f1000000-0000-4000-8000-%012d', $index), 'key' => 'http.response',
            'category' => 'system', 'route' => 'system.index', 'status' => 200, 'duration' => 100,
            'created' => '2026-11-24 11:00:00',
        ]);
    }
    $metricSeed->execute([
        'id' => 'f1000000-0000-4000-8000-000000005001', 'key' => 'http.response',
        'category' => 'system', 'route' => 'system.index', 'status' => 200, 'duration' => 9000,
        'created' => '2026-11-24 10:59:59',
    ]);
    $boundedSignals = technicalEventsGoldenSignals($pdo, '2026-11-24', '2026-11-24', 30, $metricsNow);
    if ($boundedSignals['observed_count'] !== 5001 || $boundedSignals['sample_size'] !== 5000
        || $boundedSignals['sampled'] !== true || $boundedSignals['p95_ms'] !== 100) {
        throw new RuntimeException($driver . ': latency percentiles must use only the 5000 most recent HTTP responses and mark a truncated sample.');
    }
    $pdo->exec('DELETE FROM eventos_tecnicos');
    $emptySignals = technicalEventsGoldenSignals($pdo, '2026-11-24', '2026-11-24', 30, $metricsNow);
    if ($emptySignals['observed_count'] !== 0 || $emptySignals['observed_per_hour'] !== null
        || $emptySignals['p95_ms'] !== null || $emptySignals['sample_size'] !== 0
        || $emptySignals['sampled'] !== false || $emptySignals['http_5xx_rate'] !== null) {
        throw new RuntimeException($driver . ': empty retained windows must stay empty without fabricated zero rates or percentiles.');
    }

    $invalidRejected = false;
    try {
        technicalEventsRecord($pdo, $event + ['tenant_id' => 7]);
    } catch (InvalidArgumentException $exception) {
        $invalidRejected = $exception->getMessage() === 'TECHNICAL_EVENT_INVALID';
    }
    if (!$invalidRejected) {
        throw new RuntimeException($driver . ': the recorder must reject fields outside the technical event allowlist.');
    }

    $pdo->exec('DELETE FROM eventos_tecnicos');
    $now = strtotime('2026-11-24 12:00:00 UTC');
    $cutoff = gmdate('Y-m-d H:i:s', $now - 30 * 86400);
    $seed = $pdo->prepare('INSERT INTO eventos_tecnicos (correlation_id, categoria, evento_chave, rota, status_http, duracao_ms, criado_em) VALUES (:id, :category, :key, :route, 200, 1, :created)');
    foreach ([
        ['8b5a2000-0000-4000-8000-000000000001', gmdate('Y-m-d H:i:s', $now - 31 * 86400)],
        ['8b5a2000-0000-4000-8000-000000000002', gmdate('Y-m-d H:i:s', $now - 31 * 86400)],
        ['8b5a2000-0000-4000-8000-000000000003', gmdate('Y-m-d H:i:s', $now - 31 * 86400)],
        ['8b5a2000-0000-4000-8000-000000000004', $cutoff],
        ['8b5a2000-0000-4000-8000-000000000005', gmdate('Y-m-d H:i:s', $now - 29 * 86400)],
    ] as [$id, $created]) {
        $seed->execute(['id' => $id, 'category' => 'admin', 'key' => 'http.response', 'route' => 'admin.telemetry', 'created' => $created]);
    }
    $firstRemoved = technicalEventsPurgeExpired($pdo, 30, 2, $now);
    $remainingExpired = (int) $pdo->query("SELECT COUNT(*) FROM eventos_tecnicos WHERE criado_em < '" . $cutoff . "'")->fetchColumn();
    $secondRemoved = technicalEventsPurgeExpired($pdo, 30, 2, $now);
    $boundary = $pdo->prepare('SELECT COUNT(*) FROM eventos_tecnicos WHERE correlation_id = :id');
    $boundary->execute(['id' => '8b5a2000-0000-4000-8000-000000000004']);
    $keptBoundary = (int) $boundary->fetchColumn();
    if ($firstRemoved !== 2 || $remainingExpired !== 1 || $secondRemoved !== 1 || $keptBoundary !== 1) {
        throw new RuntimeException($driver . ': retention must delete only expired events in bounded batches and retain the exact cutoff.');
    }
}

fwrite(STDOUT, "PASS: technical event allowlist, shared correlation, and bounded retention (SQLite/MySQL when configured).\n");
