<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/scheduled_tasks.php';

$events = [];
$result = scheduledTasksRun([
    'first' => static function () use (&$events): array {
        $events[] = 'first';
        return ['processed' => 2];
    },
    'second' => static function () use (&$events): array {
        $events[] = 'second';
        return ['processed' => 1];
    },
]);

if ($events !== ['first', 'second']
    || $result !== [
        'ok' => true,
        'tasks' => [
            'first' => ['ok' => true, 'counts' => ['processed' => 2]],
            'second' => ['ok' => true, 'counts' => ['processed' => 1]],
        ],
    ]) {
    throw new RuntimeException('Scheduled tasks must run in order and preserve bounded numeric summaries.');
}

fwrite(STDOUT, "PASS: scheduled task order and summaries.\n");

$events = [];
$failure = scheduledTasksRun([
    'broken' => static function () use (&$events): array {
        $events[] = 'broken';
        throw new RuntimeException('TOP_SECRET_123');
    },
    'after_failure' => static function () use (&$events): array {
        $events[] = 'after_failure';
        return ['processed' => 1];
    },
]);
$failureJson = json_encode($failure, JSON_THROW_ON_ERROR);
if ($events !== ['broken', 'after_failure']
    || $failure['ok'] !== false
    || $failure['tasks']['broken'] !== ['ok' => false, 'error_code' => 'SCHEDULED_TASK_FAILED']
    || !str_contains($failureJson, 'after_failure')
    || str_contains($failureJson, 'TOP_SECRET_123')) {
    throw new RuntimeException('Scheduled task failures must be isolated and redacted.');
}

fwrite(STDOUT, "PASS: scheduled task failure isolation and redaction.\n");

$partialFailure = scheduledTasksRun([
    'anonymization' => static fn (): array => ['processed' => 4, 'failed' => 2],
]);
if ($partialFailure !== [
    'ok' => false,
    'tasks' => [
        'anonymization' => [
            'ok' => false,
            'counts' => ['processed' => 4, 'failed' => 2],
            'error_code' => 'SCHEDULED_TASK_ERRORS',
        ],
    ],
]) {
    throw new RuntimeException('A task-reported failure count must make the coordinator fail with a safe summary.');
}

fwrite(STDOUT, "PASS: scheduled task reported failure summary.\n");

$pdo = new PDO('sqlite::memory:');
$definitions = scheduledTasksBuild($pdo);
if (array_keys($definitions) !== [
    'card_issuance', 'whatsapp_delivery', 'import_staging_cleanup', 'titular_anonymization', 'privacy_subject_retention', 'whatsapp_conversation_cleanup', 'privacy_backup',
]) {
    throw new RuntimeException('The coordinator must register only the implemented tasks in deterministic order.');
}
$invalidLimits = false;
try {
    scheduledTasksBuild($pdo, 1, 201);
} catch (InvalidArgumentException $exception) {
    $invalidLimits = $exception->getMessage() === 'SCHEDULED_TASK_LIMIT_INVALID';
}
if (!$invalidLimits) {
    throw new RuntimeException('Scheduled task batch limits must be bounded.');
}

fwrite(STDOUT, "PASS: scheduled task registry and bounded limits.\n");
