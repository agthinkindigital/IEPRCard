<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

final class TitularKeyProvisionRaceS3
{
    public function __construct(private string $storage)
    {
    }

    public function headObject(array $arguments): array
    {
        if (!is_file($this->path((string) $arguments['Key']))) {
            throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_MISSING');
        }
        return [];
    }

    public function getObject(array $arguments): array
    {
        $body = file_get_contents($this->path((string) $arguments['Key']));
        if (!is_string($body)) {
            throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_MISSING');
        }
        return ['Body' => $body];
    }

    public function putObject(array $arguments): array
    {
        usleep(400_000);
        $path = $this->path((string) $arguments['Key']);
        $file = fopen($path, 'x+b');
        if (!is_resource($file)) {
            throw new RuntimeException('TITULAR_KEY_PROVISION_RACE_OVERWROTE_ENVELOPE');
        }
        try {
            if (fwrite($file, (string) $arguments['Body']) === false || !fflush($file)) {
                throw new RuntimeException('TITULAR_KEY_PROVISION_RACE_STORAGE_FAILED');
            }
        } finally {
            fclose($file);
        }
        return [];
    }

    private function path(string $key): string
    {
        return $this->storage . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}

$databasePath = $argv[1] ?? '';
$storage = $argv[2] ?? '';
$barrier = $argv[3] ?? '';
$tenantId = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$holderId = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($databasePath === '' || $storage === '' || $barrier === '' || $tenantId === false || $holderId === false) {
    throw new InvalidArgumentException('TITULAR_KEY_PROVISION_RACE_ARGUMENTS_INVALID');
}
$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($barrier)) {
    throw new RuntimeException('TITULAR_KEY_PROVISION_RACE_BARRIER_TIMEOUT');
}
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
$factory = static fn(array $destination, array $credentials): object => new TitularKeyProvisionRaceS3($storage);
titularPrivacyProvisionKey($pdo, (int) $tenantId, (int) $holderId, $factory);
fwrite(STDOUT, "PASS: serialized titular key provision completed.\n");
