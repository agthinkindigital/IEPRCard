<?php
declare(strict_types=1);

function technicalEventsNewCorrelationId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** @return array<string, string> Scheduled task key => fixed technical route. */
function technicalEventsScheduledTaskRoutes(): array
{
    return [
        'operational_configuration' => 'worker.scheduled_task.operational_configuration',
        'technical_event_retention' => 'worker.scheduled_task.technical_event_retention',
        'card_issuance' => 'worker.scheduled_task.card_issuance',
        'whatsapp_delivery' => 'worker.scheduled_task.whatsapp_delivery',
        'import_staging_cleanup' => 'worker.scheduled_task.import_staging_cleanup',
        'titular_anonymization' => 'worker.scheduled_task.titular_anonymization',
        'privacy_subject_retention' => 'worker.scheduled_task.privacy_subject_retention',
        'whatsapp_conversation_cleanup' => 'worker.scheduled_task.whatsapp_conversation_cleanup',
        'privacy_backup' => 'worker.scheduled_task.privacy_backup',
        'privacy_snapshot_retention' => 'worker.scheduled_task.privacy_snapshot_retention',
        'other' => 'worker.scheduled_task.other',
    ];
}
