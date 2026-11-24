<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/technical_events.php';
require_once dirname(__DIR__, 2) . '/config/scheduled_tasks.php';

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
schemaAplicarMigracoes($pdo, 'test');

$successfulRun = scheduledTasksRun([
    'operational_configuration' => static fn (): array => ['activated_pending' => 0],
], $pdo);
$successfulTrace = $pdo->query(
    "SELECT correlation_id, categoria, evento_chave, rota, status_http, duracao_ms FROM eventos_tecnicos "
    . "WHERE rota = 'worker.scheduled_tasks' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($successfulRun['ok'] !== true
    || array_column($successfulTrace, 'evento_chave') !== ['scheduled.run_started', 'scheduled.run_completed']
    || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', (string) ($successfulTrace[0]['correlation_id'] ?? ''))
    || ($successfulTrace[0]['correlation_id'] ?? null) !== ($successfulTrace[1]['correlation_id'] ?? null)
    || array_filter($successfulTrace, static fn (array $event): bool => $event['categoria'] !== 'job'
        || (int) $event['status_http'] !== 0 || (int) $event['duracao_ms'] < 0) !== []) {
    throw new RuntimeException('A successful scheduled run must persist a bounded UUIDv4 start/completion trace.');
}

$failedRun = scheduledTasksRun([
    'titular_anonymization' => static function (): array {
        throw new RuntimeException('PRIVATE_FAILURE_DETAIL');
    },
], $pdo);
$failedTrace = $pdo->query(
    "SELECT correlation_id, evento_chave, rota, status_http FROM eventos_tecnicos "
    . "WHERE rota IN ('worker.scheduled_tasks', 'worker.scheduled_task.titular_anonymization') ORDER BY id DESC LIMIT 3"
)->fetchAll(PDO::FETCH_ASSOC);
if ($failedRun['ok'] !== false
    || array_column($failedTrace, 'evento_chave') !== ['scheduled.run_failed', 'scheduled.task_failed', 'scheduled.run_started']
    || ($failedTrace[0]['correlation_id'] ?? null) !== ($failedTrace[1]['correlation_id'] ?? null)
    || ($failedTrace[1]['correlation_id'] ?? null) !== ($failedTrace[2]['correlation_id'] ?? null)
    || ($failedTrace[1]['rota'] ?? null) !== 'worker.scheduled_task.titular_anonymization'
    || array_filter($failedTrace, static fn (array $event): bool => (int) $event['status_http'] !== 0) !== []
    || str_contains(json_encode($failedTrace, JSON_THROW_ON_ERROR), 'PRIVATE_FAILURE_DETAIL')) {
    throw new RuntimeException('A failed task must be identified by an allowlisted route on the same minimized run trace.');
}

$telemetryUnavailablePdo = new PDO('sqlite::memory:');
$taskExecuted = false;
$resultWithoutTelemetry = scheduledTasksRun([
    'independent_task' => static function () use (&$taskExecuted): array {
        $taskExecuted = true;
        return ['processed' => 1];
    },
], $telemetryUnavailablePdo);
if (!$taskExecuted || $resultWithoutTelemetry !== [
    'ok' => true,
    'tasks' => ['independent_task' => ['ok' => true, 'counts' => ['processed' => 1]]],
]) {
    throw new RuntimeException('Technical event storage failure must not change scheduled task execution or its result.');
}

fwrite(STDOUT, "PASS: scheduled task run and failure traces are correlated, allowlisted, minimized, and best-effort.\n");
