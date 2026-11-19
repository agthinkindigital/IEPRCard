<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/schema_update.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-schema-update-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not prepare an isolated schema update state directory.');
}
$statePath = $directory . DIRECTORY_SEPARATOR . 'job.json';

try {
    $manifest = schemaUpdateReleaseManifest();
    if (($manifest['migrations'] ?? []) === []
        || !preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['release_id'] ?? ''))) {
        throw new RuntimeException('The release manifest must identify the shipped versioned migrations.');
    }
    foreach ($manifest['migrations'] as $migration) {
        if (!preg_match('/^\d{4}_[a-z0-9_]+$/D', (string) ($migration['id'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($migration['sha256'] ?? ''))) {
            throw new RuntimeException('The release manifest must contain only numbered migrations and content hashes.');
        }
    }

    if ((schemaUpdateStateRead($statePath)['status'] ?? null) !== 'idle') {
        throw new RuntimeException('A missing schema update job must be idle.');
    }
    if (schemaUpdateQueueCreate(42, false, 1_800_000_000, $statePath) !== null) {
        throw new RuntimeException('The schema update job must require explicit backup confirmation.');
    }

    $job = schemaUpdateQueueCreate(42, true, 1_800_000_001, $statePath);
    if (!is_array($job) || ($job['status'] ?? null) !== 'queued'
        || ($job['requested_by'] ?? null) !== 42
        || ($job['created_at'] ?? null) !== 1_800_000_001
        || ($job['release_id'] ?? null) !== $manifest['release_id']
        || ($job['migrations'] ?? null) !== $manifest['migrations']
        || ($job['backup_confirmed'] ?? null) !== true) {
        throw new RuntimeException('A queued job must pin the approved backup confirmation and current release manifest.');
    }
    if (schemaUpdateQueueCreate(84, true, 1_800_000_002, $statePath) !== null) {
        throw new RuntimeException('A queued or running schema update must not be duplicated.');
    }
    $corruptStatePath = $directory . DIRECTORY_SEPARATOR . 'corrupt-job.json';
    file_put_contents($corruptStatePath, '{invalid-json');
    if (schemaUpdateQueueCreate(84, true, 1_800_000_003, $corruptStatePath) !== null) {
        throw new RuntimeException('A corrupt operational state must block enqueueing instead of overwriting its history.');
    }
    if (schemaUpdateStateTransition('wrong-job', 'queued', ['status' => 'running'], $statePath)) {
        throw new RuntimeException('A schema update transition must match the active job ID.');
    }

    $serialized = json_encode(schemaUpdateStateRead($statePath), JSON_THROW_ON_ERROR);
    if (str_contains($serialized, 'password') || str_contains($serialized, 'sql')
        || str_contains($serialized, $directory) || str_contains($serialized, 'command')) {
        throw new RuntimeException('The schema update job must not persist secrets, SQL, commands, or local paths.');
    }

    fwrite(STDOUT, "PASS: schema update jobs require backup confirmation and pin the server-side release manifest.\n");
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

return 'pass';
