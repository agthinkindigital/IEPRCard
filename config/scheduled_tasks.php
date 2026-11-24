<?php
declare(strict_types=1);

require_once __DIR__ . '/privacy_snapshot.php';
require_once __DIR__ . '/privacy_subject_lifecycle.php';
require_once __DIR__ . '/operational_configuration.php';
require_once __DIR__ . '/technical_events.php';

/** @param array<string, callable():array<string, int>> $tasks @return array{ok:bool,tasks:array<string, array<string, mixed>>} */
function scheduledTasksRun(array $tasks, ?PDO $technicalEventsPdo = null): array
{
    foreach ($tasks as $name => $task) {
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', (string) $name) !== 1 || !is_callable($task)) {
            throw new InvalidArgumentException('SCHEDULED_TASK_CONFIGURATION_INVALID');
        }
    }

    $ok = true;
    $results = [];
    $correlationId = null;
    if ($technicalEventsPdo !== null) {
        try {
            $correlationId = technicalEventsNewCorrelationId();
        } catch (Throwable) {
            // A correlation ID is telemetry state; entropy failure must not prevent scheduled work.
        }
    }
    $runStartedAt = hrtime(true);
    if ($technicalEventsPdo !== null && $correlationId !== null) {
        scheduledTasksRecordTechnicalEvent($technicalEventsPdo, $correlationId, 'scheduled.run_started', 'worker.scheduled_tasks', 0);
    }
    foreach ($tasks as $name => $task) {
        $taskStartedAt = hrtime(true);
        $taskFailed = false;
        try {
            $counts = scheduledTasksNormalizeCounts($task());
            $taskOk = ($counts['failed'] ?? 0) === 0;
            $taskFailed = !$taskOk;
            $results[$name] = ['ok' => $taskOk, 'counts' => $counts];
            if (!$taskOk) {
                $results[$name]['error_code'] = 'SCHEDULED_TASK_ERRORS';
                $ok = false;
            }
        } catch (Throwable $exception) {
            $ok = false;
            $taskFailed = true;
            $results[$name] = ['ok' => false, 'error_code' => scheduledTasksSafeErrorCode($exception)];
        }
        if ($taskFailed && $technicalEventsPdo !== null && $correlationId !== null) {
            $route = technicalEventsScheduledTaskRoutes()[(string) $name] ?? technicalEventsScheduledTaskRoutes()['other'];
            scheduledTasksRecordTechnicalEvent(
                $technicalEventsPdo,
                $correlationId,
                'scheduled.task_failed',
                $route,
                scheduledTasksElapsedMilliseconds($taskStartedAt)
            );
        }
    }

    if ($technicalEventsPdo !== null && $correlationId !== null) {
        scheduledTasksRecordTechnicalEvent(
            $technicalEventsPdo,
            $correlationId,
            $ok ? 'scheduled.run_completed' : 'scheduled.run_failed',
            'worker.scheduled_tasks',
            scheduledTasksElapsedMilliseconds($runStartedAt)
        );
    }

    return ['ok' => $ok, 'tasks' => $results];
}

function scheduledTasksRecordTechnicalEvent(PDO $pdo, string $correlationId, string $eventKey, string $route, int $durationMs): void
{
    try {
        technicalEventsRecord($pdo, [
            'correlation_id' => $correlationId,
            'categoria' => 'job',
            'evento_chave' => $eventKey,
            'rota' => $route,
            'status_http' => 0,
            'duracao_ms' => $durationMs,
        ]);
    } catch (Throwable) {
        // Scheduled task telemetry is best-effort and cannot alter the CLI run.
    }
}

function scheduledTasksElapsedMilliseconds(int $startedAt): int
{
    return max(0, (int) floor((hrtime(true) - $startedAt) / 1_000_000));
}

function scheduledTasksSafeErrorCode(Throwable $exception): string
{
    $safeCodes = [
        'ANONYMIZATION_BATCH_LIMIT_INVALID',
        'ANONYMIZATION_KEY_UNAVAILABLE',
        'IMPORT_CLEANUP_LIMIT_INVALID',
        'IMPORT_STORAGE_UNAVAILABLE',
        'ISSUANCE_WORKER_LIMIT_INVALID',
        'ISSUANCE_WORKER_LOCK_UNAVAILABLE',
        'PRIVACY_SUBJECT_BATCH_LIMIT_INVALID',
        'PRIVACY_SNAPSHOT_LOCK_UNAVAILABLE',
        'PRIVACY_SNAPSHOT_ID_INVALID',
        'PRIVACY_SNAPSHOT_PENDING_STATE_INVALID',
        'PRIVACY_SNAPSHOT_DATABASE_SCHEMA_UNREVIEWED',
        'PRIVACY_SNAPSHOT_RETENTION_LIMIT_INVALID',
        'PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED',
        'SCHEDULED_TASK_CONFIGURATION_INVALID',
        'SCHEDULED_TASK_LIMIT_INVALID',
        'SCHEDULED_TASK_RESULT_INVALID',
        'TECHNICAL_EVENT_RETENTION_LIMIT_INVALID',
        'WHATSAPP_CONVERSATION_CLEANUP_LIMIT_INVALID',
        'WHATSAPP_DELIVERY_LIMIT_INVALID',
    ];
    $message = $exception->getMessage();
    return in_array($message, $safeCodes, true) ? $message : 'SCHEDULED_TASK_FAILED';
}

/** @param mixed $counts @return array<string, int> */
function scheduledTasksNormalizeCounts(mixed $counts): array
{
    if (!is_array($counts)) {
        throw new UnexpectedValueException('SCHEDULED_TASK_RESULT_INVALID');
    }
    $normalized = [];
    foreach ($counts as $name => $value) {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $name) !== 1
            || !is_int($value) || $value < 0) {
            throw new UnexpectedValueException('SCHEDULED_TASK_RESULT_INVALID');
        }
        $normalized[$name] = $value;
    }

    return $normalized;
}

/** @return array<string, callable():array<string, int>> */
function scheduledTasksBuild(PDO $pdo, int $maxIssuanceJobs = 1, int $maintenanceLimit = 100): array
{
    if ($maxIssuanceJobs < 1 || $maxIssuanceJobs > 20
        || $maintenanceLimit < 1 || $maintenanceLimit > 200) {
        throw new InvalidArgumentException('SCHEDULED_TASK_LIMIT_INVALID');
    }

    $activeOperationalConfiguration = null;
    $operationalConfigurationAvailable = false;

    return [
        'operational_configuration' => static function () use ($pdo, &$activeOperationalConfiguration, &$operationalConfigurationAvailable): array {
            $activeOperationalConfiguration = operationalConfigWorkerStart($pdo);
            $operationalConfigurationAvailable = true;
            return ['activated_pending' => $activeOperationalConfiguration !== null && $activeOperationalConfiguration['ativou_pendente'] ? 1 : 0];
        },
        'technical_event_retention' => static function () use ($pdo, $maintenanceLimit, &$activeOperationalConfiguration, &$operationalConfigurationAvailable): array {
            if (!$operationalConfigurationAvailable) {
                return ['failed' => 1];
            }
            $retentionDays = $activeOperationalConfiguration['configuracao']['retencao_eventos_tecnicos_dias'] ?? 30;
            return ['deleted' => technicalEventsPurgeExpired($pdo, $retentionDays, $maintenanceLimit)];
        },
        'card_issuance' => static function () use ($pdo, $maxIssuanceJobs): array {
            $result = cardIssuanceWorkerRunBatch($pdo, $maxIssuanceJobs);
            return ['processed_jobs' => $result['processed'], 'skipped_lock' => $result['skipped'] ? 1 : 0];
        },
        'whatsapp_delivery' => static function () use ($pdo): array {
            return whatsappConversationProcessPendingDeliveries($pdo);
        },
        'import_staging_cleanup' => static function () use ($pdo, $maintenanceLimit): array {
            return ['expired_lots' => batchImportStagingCleanupExpired($pdo, time(), $maintenanceLimit)];
        },
        'titular_anonymization' => static function () use ($pdo, $maintenanceLimit): array {
            $result = titularAnonymizationProcessDue($pdo, null, $maintenanceLimit);
            return ['processed' => $result['processed'], 'failed' => $result['failed']];
        },
        'privacy_subject_retention' => static function () use ($pdo, $maintenanceLimit): array {
            $result = privacySubjectLifecycleProcessDue($pdo, null, $maintenanceLimit);
            return ['processed' => $result['processed'], 'failed' => $result['failed']];
        },
        'whatsapp_conversation_cleanup' => static function () use ($pdo, $maintenanceLimit): array {
            return ['deleted' => whatsappConversationCleanupExpired($pdo, $maintenanceLimit)];
        },
        'privacy_backup' => static function () use ($pdo): array {
            return privacySnapshotRunDue($pdo);
        },
        'privacy_snapshot_retention' => static function () use ($pdo, $maintenanceLimit): array {
            return privacySnapshotPurgeExpired($pdo, null, $maintenanceLimit);
        },
    ];
}
