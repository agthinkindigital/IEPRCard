<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/technical_events.php';

$technicalPageRoute = technicalEventsCanonicalRoute('/admin/eventos-tecnicos.php?categoria=admin', '/admin/eventos-tecnicos.php');
if ($technicalPageRoute !== ['categoria' => 'admin', 'rota' => 'admin.technical_events']) {
    throw new RuntimeException('Technical events query page must use its canonical server-side route key and discard query parameters.');
}

foreach (tenantTestConnections() as $driver => $pdo) {
    $migrations = schemaAplicarMigracoes($pdo, 'test');
    if (end($migrations) !== '0026_technical_events') {
        throw new RuntimeException($driver . ': the technical event table must be introduced by its versioned migration.');
    }
    $pdo->exec('DELETE FROM eventos_tecnicos');
    $correlationId = '3d2ac80c-5fb2-4de4-bfea-5e885e2b2318';
    $event = [
        'correlation_id' => $correlationId, 'categoria' => 'admin', 'evento_chave' => 'http.response',
        'rota' => 'admin.telemetry', 'status_http' => 303, 'duracao_ms' => 18,
    ];
    if (!technicalEventsRecord($pdo, $event) || technicalEventsRecord($pdo, $event)) {
        throw new RuntimeException($driver . ': one server correlation ID must persist at most one technical event.');
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

    $invalidRejected = false;
    try {
        technicalEventsRecord($pdo, $event + ['tenant_id' => 7]);
    } catch (InvalidArgumentException $exception) {
        $invalidRejected = $exception->getMessage() === 'TECHNICAL_EVENT_INVALID';
    }
    if (!$invalidRejected) {
        throw new RuntimeException($driver . ': the recorder must reject fields outside the technical event allowlist.');
    }

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

fwrite(STDOUT, "PASS: technical event allowlist, deduplication and bounded retention (SQLite/MySQL when configured).\n");
