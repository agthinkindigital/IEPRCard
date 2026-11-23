<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/backup_destinations.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';
require_once dirname(__DIR__, 2) . '/config/titular_privacy_keys.php';
require_once dirname(__DIR__, 2) . '/config/privacy_snapshot.php';

$backupDestinationsConnections = ['SQLite' => dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:'])];
$backupDestinationsMysql = [
    'DB_HOST' => getenv('TEST_MYSQL_HOST'),
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE'),
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME'),
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD'),
];
if (in_array('mysql', PDO::getAvailableDrivers(), true)
    && count(array_filter($backupDestinationsMysql, static fn(mixed $value): bool => $value !== false)) === count($backupDestinationsMysql)) {
    $backupDestinationsConnections['MySQL/MariaDB'] = dbConnect(['DB_DRIVER' => 'mysql'] + $backupDestinationsMysql);
}

$backupDestinationServer = null;
$backupDestinationPortSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($backupDestinationPortSocket)) {
    throw new RuntimeException('Could not reserve a local S3 contract-test port.');
}
$backupDestinationAddress = stream_socket_get_name($backupDestinationPortSocket, false);
$backupDestinationPort = (int) substr((string) strrchr((string) $backupDestinationAddress, ':'), 1);
fclose($backupDestinationPortSocket);
$backupDestinationStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-s3-test-' . bin2hex(random_bytes(6));
if (!mkdir($backupDestinationStorage, 0700, true)) {
    throw new RuntimeException('Could not create local S3 contract-test storage.');
}
$oldPrivateStorage = getenv('IEPR_PRIVATE_STORAGE_PATH');
$oldBackupHostingProfile = getenv('IEPR_BACKUP_HOSTING_PROFILE');
$oldBackupActiveLimit = getenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
$backupDestinationPrivateStorage = $backupDestinationStorage . '-private';
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $backupDestinationPrivateStorage);
putenv('IEPR_BACKUP_HOSTING_PROFILE=shared');
putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
$backupDestinationNullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$backupDestinationStdout = fopen($backupDestinationNullDevice, 'wb');
$backupDestinationStderr = fopen($backupDestinationNullDevice, 'wb');
putenv('IEPR_S3_TEST_STORAGE=' . $backupDestinationStorage);
$backupDestinationProcess = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $backupDestinationPort, dirname(__DIR__) . '/fixtures/s3-contract-server.php'],
    [0 => ['pipe', 'r'], 1 => $backupDestinationStdout, 2 => $backupDestinationStderr],
    $backupDestinationPipes,
    dirname(__DIR__, 2)
);
putenv('IEPR_S3_TEST_STORAGE');
if (!is_resource($backupDestinationProcess)) {
    throw new RuntimeException('Could not start local S3 contract server.');
}
if (isset($backupDestinationPipes[0]) && is_resource($backupDestinationPipes[0])) {
    fclose($backupDestinationPipes[0]);
}
$backupDestinationEndpoint = 'http://127.0.0.1:' . $backupDestinationPort;
$backupDestinationServerReady = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $backupDestinationPort, $connectError, $connectMessage, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $backupDestinationServerReady = true;
        break;
    }
    usleep(100_000);
}
if (!$backupDestinationServerReady) {
    proc_terminate($backupDestinationProcess);
    throw new RuntimeException('The local S3 contract server did not start.');
}

$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x61", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(str_repeat("\x62", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
try {
    foreach ($backupDestinationsConnections as $driver => $pdo) {
        putenv('IEPR_BACKUP_HOSTING_PROFILE=shared');
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
        schemaAplicarMigracoes($pdo, 'test');
        schemaGarantirEstrutura($pdo, 'test');
        $superadminEmail = 'backup-test-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
            . "VALUES ('superadmin', 'Backup Test', :email, :hash, 1)"
        )->execute(['email' => $superadminEmail, 'hash' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
        $superadminId = (int) $pdo->lastInsertId();
        $superadminSubject = privacySubjectCreate($pdo, 'pessoa');
        privacySubjectLinkRecord($pdo, $superadminSubject['id'], 'usuario', $superadminId);
        $secondSnapshotEmail = 'backup-checkpoint-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES ('admin', 'Checkpoint User', :email, :hash, 1)"
        )->execute(['email' => $secondSnapshotEmail, 'hash' => password_hash('synthetic-checkpoint-password', PASSWORD_DEFAULT)]);
        $secondSnapshotUserId = (int) $pdo->lastInsertId();
        $secondSnapshotSubject = privacySubjectCreate($pdo, 'pessoa');
        privacySubjectLinkRecord($pdo, $secondSnapshotSubject['id'], 'usuario', $secondSnapshotUserId);
        $identity = ['id' => $superadminId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
        $input = [
            'name' => 'Local S3 ' . $driver,
            'bucket' => 'backup-test',
            'region' => 'us-east-1',
            'endpoint' => $backupDestinationEndpoint,
            'path_style' => true,
            'retention_days' => 45,
            'access_key' => 'synthetic-access-key',
            'secret_key' => 'synthetic-secret-key',
        ];

        try {
            backupDestinationSave($pdo, ['id' => $superadminId, 'papel_sistema' => 'tenant_admin'], $input);
            throw new RuntimeException($driver . ': tenant identity was allowed to manage global backups.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_FORBIDDEN') {
                throw $exception;
            }
        }

        $saved = backupDestinationSave($pdo, $identity, $input);
        if (($saved['retention_days'] ?? null) !== 45) {
            throw new RuntimeException($driver . ': a backup destination must persist its configured retention days.');
        }
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, ['name' => 'backup-owner@example.test']));
            throw new RuntimeException($driver . ': backup destination labels containing a personal email were accepted.');
        } catch (InvalidArgumentException) {
        }
        $rows = backupDestinationReadAll($pdo, $identity);
        $stored = $pdo->query('SELECT credentials_cifradas, ativo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stored) || $stored['credentials_cifradas'] === 'synthetic-secret-key'
            || str_contains((string) json_encode($rows), 'synthetic-secret-key')
            || ($rows[0]['credentials_configured'] ?? false) !== true
            || (int) $stored['ativo'] !== 0) {
            throw new RuntimeException($driver . ': backup credentials must be encrypted and omitted from read APIs.');
        }
        foreach ([0, -1, 3651, '1.5', 'invalid'] as $invalidRetention) {
            try {
                backupDestinationSave($pdo, $identity, array_merge($input, [
                    'id' => (int) $saved['id'], 'retention_days' => $invalidRetention,
                    'access_key' => '', 'secret_key' => '',
                ]));
                throw new RuntimeException($driver . ': invalid destination retention was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $tested = backupDestinationTest($pdo, $identity, (int) $saved['id']);
        $persisted = $pdo->query('SELECT ultimo_teste_status, ultimo_sucesso_em, ultimo_erro_codigo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetch(PDO::FETCH_ASSOC);
        if (($tested['status'] ?? null) !== 'success' || !is_array($persisted)
            || $persisted['ultimo_teste_status'] !== 'success' || !is_string($persisted['ultimo_sucesso_em'])
            || $persisted['ultimo_erro_codigo'] !== null) {
            throw new RuntimeException($driver . ': S3 probe result was invalid: ' . json_encode($tested) . ' ' . json_encode($persisted));
        }
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, ['name' => 'Duplicate storage ' . $driver]));
            throw new RuntimeException($driver . ': the same bucket and endpoint were accepted as a second backup destination.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_DUPLICATE_STORAGE') throw $exception;
        }
        if ((glob($backupDestinationStorage . DIRECTORY_SEPARATOR . '*') ?: []) !== []) {
            throw new RuntimeException($driver . ': the S3 connection probe must remove its temporary object.');
        }

        $originalCiphertext = (string) $stored['credentials_cifradas'];
        $protectedReference = hash('sha256', 'synthetic-holder-' . bin2hex(random_bytes(8)));
        $pdo->prepare(
            "INSERT INTO titular_chaves_backup (destino_id, referencia_hmac, estado, criado_em, atualizado_em) "
            . "VALUES (:destination, :reference, 'ativa', :created, :updated)"
        )->execute([
            'destination' => $saved['id'], 'reference' => $protectedReference,
            'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
        ]);
        if ((backupDestinationReadOne($pdo, $identity, (int) $saved['id'])['privacy_keys_present'] ?? false) !== true) {
            throw new RuntimeException($driver . ': the admin read model must signal when holder key envelopes lock a destination.');
        }
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, [
                'id' => (int) $saved['id'], 'bucket' => 'changed-bucket', 'access_key' => '', 'secret_key' => '',
            ]));
            throw new RuntimeException($driver . ': storage settings changed while holder key envelopes were registered.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_PRIVACY_KEYS_PRESENT') throw $exception;
        }
        $pdo->prepare('DELETE FROM titular_chaves_backup WHERE destino_id = :id')->execute(['id' => $saved['id']]);
        $updateInput = $input;
        unset($updateInput['retention_days']);
        $updated = backupDestinationSave($pdo, $identity, array_merge($updateInput, [
            'id' => (int) $saved['id'], 'access_key' => '', 'secret_key' => '',
        ]));
        $updatedCiphertext = (string) $pdo->query(
            'SELECT credentials_cifradas FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetchColumn();
        if ($updated['id'] !== $saved['id'] || $updated['retention_days'] !== 45 || $updatedCiphertext !== $originalCiphertext
            || $updated['test_status'] !== null || $updated['tested_at'] !== null) {
            throw new RuntimeException($driver . ': updating metadata without a new secret must preserve encrypted credentials.');
        }
        if ((backupDestinationTest($pdo, $identity, (int) $saved['id'])['status'] ?? null) !== 'success') {
            throw new RuntimeException($driver . ': destination changes must be tested before re-enabling it.');
        }

        $secondInput = $input;
        unset($secondInput['retention_days']);
        $driverBucketSuffix = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $driver));
        $second = backupDestinationSave($pdo, $identity, array_merge($secondInput, [
            'name' => 'Second S3 ' . $driver, 'bucket' => 'iepr-second-backup-' . $driverBucketSuffix,
        ]));
        if ($second['id'] === $saved['id'] || $second['retention_days'] !== 30
            || count(backupDestinationReadAll($pdo, $identity)) !== 2) {
            throw new RuntimeException($driver . ': multiple independent backup destinations must be supported.');
        }

        backupDestinationSetEnabled($pdo, $identity, (int) $saved['id'], true);
        if ((int) $pdo->query('SELECT ativo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': destinations must be independently enableable.');
        }
        if ((backupDestinationTest($pdo, $identity, (int) $second['id'])['status'] ?? null) !== 'success') {
            throw new RuntimeException($driver . ': the second independent destination did not pass its S3 contract probe.');
        }
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT=2');
        try {
            backupDestinationSetEnabled($pdo, $identity, (int) $second['id'], true);
            throw new RuntimeException($driver . ': shared hosting must reject a second active backup destination.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_ACTIVE_LIMIT_REACHED') throw $exception;
        }
        if ((int) $pdo->query('SELECT ativo FROM destinos_backup WHERE id = ' . (int) $second['id'])->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': a rejected shared-host destination must remain inactive.');
        }

        $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', :name, 'ativo')"
        )->execute(['name' => 'S3 privacy test tenant ' . $driver]);
        $privacyTenantId = (int) $pdo->lastInsertId();
        privacySubjectEnsureForRecord($pdo, 'tenant_contato', $privacyTenantId, $privacyTenantId);
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
            . 'VALUES (:tenant, :name, :cpf, 1, :created)'
        )->execute([
            'tenant' => $privacyTenantId,
            'name' => 'Synthetic S3 privacy holder',
            'cpf' => '52998224725',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $privacyHolderId = (int) $pdo->lastInsertId();
        $privacyReference = titularPrivacyEnsureReference($pdo, $privacyTenantId, $privacyHolderId);
        titularPrivacyProvisionKey($pdo, $privacyTenantId, $privacyHolderId);
        $snapshotPlaintext = 'Synthetic private S3 snapshot payload';
        $snapshotCiphertext = titularPrivacyEncryptSnapshotData(
            $pdo,
            $privacyTenantId,
            $privacyHolderId,
            $snapshotPlaintext
        );
        $activeDestinations = titularPrivacyActiveDestinations($pdo);
        $privacyClient = titularPrivacyCreateClient($activeDestinations[0], null);
        $keyObject = titularPrivacyObjectKey('keys', $privacyReference, titularAnonymizationEncryptionKey());
        $envelopeObject = $privacyClient->getObject(['Bucket' => $saved['bucket'], 'Key' => $keyObject]);
        $envelope = json_decode((string) $envelopeObject['Body'], true);
        $wrappedDek = is_array($envelope)
            ? base64_decode((string) ($envelope['wrapped_dek'] ?? ''), true)
            : false;
        $dataDek = is_string($wrappedDek) ? sodium_crypto_secretbox_open(
            substr($wrappedDek, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($wrappedDek, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            titularAnonymizationEncryptionKey()
        ) : false;
        $encryptedBytes = base64_decode(substr($snapshotCiphertext, 3), true);
        $decryptedSnapshot = is_string($dataDek) && is_string($encryptedBytes)
            ? sodium_crypto_secretbox_open(
                substr($encryptedBytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($encryptedBytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $dataDek
            )
            : false;
        if (!str_starts_with($snapshotCiphertext, 'v1:') || str_contains($snapshotCiphertext, $snapshotPlaintext)
            || !is_string($decryptedSnapshot) || !hash_equals($snapshotPlaintext, $decryptedSnapshot)) {
            throw new RuntimeException($driver . ': snapshot payload must be encrypted using the external holder key envelope.');
        }
        if (is_string($dataDek)) sodium_memzero($dataDek);
        $keyVersionsBefore = $privacyClient->listObjectVersions([
            'Bucket' => $saved['bucket'], 'Prefix' => $keyObject, 'MaxKeys' => 100,
        ]);
        if (count($keyVersionsBefore['Versions'] ?? []) !== 1) {
            throw new RuntimeException($driver . ': AWS SDK must store a versioned privacy key envelope in the S3 fixture.');
        }
        $erasure = titularPrivacyEraseKeys($pdo, $privacyReference);
        $keyVersionsAfter = $privacyClient->listObjectVersions([
            'Bucket' => $saved['bucket'], 'Prefix' => $keyObject, 'MaxKeys' => 100,
        ]);
        $tombstoneKey = titularPrivacyObjectKey('tombstones', $privacyReference, titularAnonymizationEncryptionKey());
        $tombstone = json_decode((string) $privacyClient->getObject([
            'Bucket' => $saved['bucket'], 'Key' => $tombstoneKey,
        ])['Body'], true);
        if (($erasure['destinations'] ?? 0) !== 1 || ($keyVersionsAfter['Versions'] ?? []) !== []
            || ($keyVersionsAfter['DeleteMarkers'] ?? []) !== [] || !is_array($tombstone)
            || strlen((string) ($tombstone['reference_hmac'] ?? '')) !== 64
            || str_contains(json_encode($tombstone), $privacyReference)) {
            throw new RuntimeException($driver . ': SDK-backed erasure must permanently purge key versions and retain only a pseudonymous tombstone.');
        }
        s3CompatibleDeleteObjectPermanently($privacyClient, (string) $saved['bucket'], $tombstoneKey);

        $genericSubject = privacySubjectCreate($pdo, 'pessoa', $privacyTenantId);
        privacySubjectLinkRecord($pdo, $genericSubject['id'], 'usuario', 987654);
        $genericPlaintext = 'Synthetic private account PII';
        $genericCiphertext = privacySubjectEncryptSnapshotData($pdo, $genericSubject['id'], $genericPlaintext);
        $genericKey = titularPrivacyObjectKey('keys', $genericSubject['reference'], titularAnonymizationEncryptionKey());
        if (!str_starts_with($genericCiphertext, 'v1:') || str_contains($genericCiphertext, $genericPlaintext)
            || $privacyClient->headObject(['Bucket' => $saved['bucket'], 'Key' => $genericKey]) === null) {
            throw new RuntimeException($driver . ': generic privacy subjects must receive independent external keys and ciphertext.');
        }
        $genericErasure = privacySubjectEraseBackupKey($pdo, $genericSubject['id']);
        $genericKeyVersions = $privacyClient->listObjectVersions([
            'Bucket' => $saved['bucket'], 'Prefix' => $genericKey, 'MaxKeys' => 100,
        ]);
        $genericTombstoneKey = titularPrivacyObjectKey('tombstones', $genericSubject['reference'], titularAnonymizationEncryptionKey());
        s3CompatibleDeleteObjectPermanently($privacyClient, (string) $saved['bucket'], $genericTombstoneKey);
        if (($genericErasure['destinations'] ?? 0) !== 1 || ($genericKeyVersions['Versions'] ?? []) !== []
            || ($genericKeyVersions['DeleteMarkers'] ?? []) !== []) {
            throw new RuntimeException($driver . ': generic privacy subject erasure must permanently remove every key version.');
        }
        try {
            privacySubjectEncryptSnapshotData($pdo, $genericSubject['id'], 'must not be re-keyed');
            throw new RuntimeException($driver . ': erased privacy subjects must not be re-keyed.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'ANONYMIZATION_SUBJECT_ERASED') {
                throw $exception;
            }
        }

        $checkpointFirst = privacySnapshotUpload(
            $pdo,
            [(int) $saved['id']],
            static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $subjectId . ':' . $plaintext),
            static function (): object {
                return new class {
                    public function headObject(array $input): mixed {
                        throw new class('NoSuchKey', 404) extends RuntimeException {
                            public function getStatusCode(): int { return 404; }
                        };
                    }
                    public function putObject(array $input): mixed { throw new RuntimeException('An incomplete checkpoint must never publish an object.'); }
                };
            },
            1
        );
        if (($checkpointFirst['processed'] ?? 0) !== 0 || ($checkpointFirst['failed'] ?? 0) !== 0
            || ($checkpointFirst['skipped'] ?? 0) !== 1) {
            throw new RuntimeException($driver . ': an incomplete bounded snapshot must persist progress without publishing a partial object.');
        }
        $checkpointState = $pdo->query(
            'SELECT snapshot_pendente_id FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetchColumn();
        $checkpointDirectory = privacySnapshotResumeDirectory((int) $saved['id'], (string) $checkpointState);
        foreach (glob($checkpointDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $checkpointFile) {
            $checkpointBytes = file_get_contents($checkpointFile);
            if (!is_string($checkpointBytes) || str_contains($checkpointBytes, $superadminEmail)
                || str_contains($checkpointBytes, $secondSnapshotEmail)) {
                throw new RuntimeException($driver . ': resumable private parts must not persist plaintext identity fields.');
            }
        }

        $privacySnapshotClient = null;
        if (!is_dir($backupDestinationPrivateStorage) && !mkdir($backupDestinationPrivateStorage, 0700, true)) {
            throw new RuntimeException($driver . ': could not create private snapshot lock directory.');
        }
        $snapshotLockPath = $backupDestinationPrivateStorage . DIRECTORY_SEPARATOR . 'privacy-snapshot.lock';
        $snapshotLock = fopen($snapshotLockPath, 'c');
        if (!is_resource($snapshotLock) || !flock($snapshotLock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException($driver . ': could not acquire the snapshot lock fixture.');
        }
        $overlapUpload = privacySnapshotUpload(
            $pdo,
            [(int) $saved['id']],
            static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $plaintext),
            static function (): object { throw new RuntimeException('An overlapping snapshot must not upload.'); }
        );
        flock($snapshotLock, LOCK_UN);
        fclose($snapshotLock);
        if (($overlapUpload['skipped'] ?? 0) !== 1 || ($overlapUpload['processed'] ?? 0) !== 0) {
            throw new RuntimeException($driver . ': overlapping snapshot runs must skip without publishing a second copy.');
        }
        $snapshotUpload = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
        for ($attempt = 0; $attempt < 1000 && ($snapshotUpload['processed'] ?? 0) === 0; $attempt++) {
            $snapshotUpload = privacySnapshotUpload(
                $pdo,
                [(int) $saved['id']],
                static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $subjectId . ':' . $plaintext),
                static function (array $destination, array $credentials) use (&$privacySnapshotClient, $privacyClient): object {
                    $privacySnapshotClient = new class($privacyClient) {
                        public array $uploads = [];
                        public function __construct(private object $client) {}
                        public function putObject(array $input): mixed {
                            $this->uploads[] = $input;
                            return $this->client->putObject($input);
                        }
                        public function __call(string $name, array $arguments): mixed { return $this->client->{$name}(...$arguments); }
                    };
                    return $privacySnapshotClient;
                },
                50
            );
            if (($snapshotUpload['failed'] ?? 0) > 0) break;
        }
        $snapshotObjectKey = (string) ($privacySnapshotClient?->uploads[0]['Key'] ?? '');
        $snapshotObject = $snapshotObjectKey === '' ? null : $privacyClient->getObject([
            'Bucket' => (string) $saved['bucket'], 'Key' => $snapshotObjectKey,
        ]);
        $snapshotCompressed = $snapshotObject !== null ? (string) ($snapshotObject['Body'] ?? '') : false;
        $snapshotBytes = is_string($snapshotCompressed) ? gzdecode($snapshotCompressed) : false;
        $snapshotPayload = is_string($snapshotBytes) ? json_decode($snapshotBytes, true) : null;
        $snapshotManifest = is_array($snapshotPayload) ? ($snapshotPayload['manifest'] ?? []) : [];
        $manifestPartsHash = hash('sha256', json_encode(array_map(
            static fn(array $part): array => [$part['table'], $part['first_id'], $part['last_id'], $part['sha256']],
            $snapshotManifest['parts'] ?? []
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $snapshotPayloadHash = is_array($snapshotPayload) ? hash('sha256', json_encode([
            'tables' => $snapshotPayload['tables'] ?? [],
            'subject_envelopes' => $snapshotPayload['subject_envelopes'] ?? [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '';
        if (($snapshotUpload['processed'] ?? 0) !== 1 || ($snapshotUpload['failed'] ?? 0) !== 0
            || !is_string($snapshotBytes) || !str_contains($snapshotBytes, 'ieprcard-snapshot-v3')
            || ($snapshotManifest['format'] ?? null) !== 'ieprcard-snapshot-v3'
            || !hash_equals($manifestPartsHash, (string) ($snapshotManifest['parts_sha256'] ?? ''))
            || !hash_equals($snapshotPayloadHash, (string) ($snapshotManifest['payload_sha256'] ?? ''))
            || !is_array($snapshotPayload['subject_envelopes'] ?? null)
            || !isset($snapshotPayload['subject_envelopes'][0]['reference'], $snapshotPayload['subject_envelopes'][0]['ciphertext'])
            || is_dir($checkpointDirectory)
            || str_contains($snapshotBytes, 'Synthetic S3 privacy holder')
            || str_contains($snapshotBytes, $superadminEmail)) {
            throw new RuntimeException($driver . ': S3 snapshot upload must store a checksummed gzip payload without plaintext PII ('
                . 'processed=' . (int) ($snapshotUpload['processed'] ?? -1)
                . ', failed=' . (int) ($snapshotUpload['failed'] ?? -1)
                . ', bytes=' . (is_string($snapshotBytes) ? strlen($snapshotBytes) : 0)
                . ', holder_plain=' . (is_string($snapshotBytes) && str_contains($snapshotBytes, 'Synthetic S3 privacy holder') ? 'yes' : 'no')
                . ', admin_plain=' . (is_string($snapshotBytes) && str_contains($snapshotBytes, $superadminEmail) ? 'yes' : 'no') . ').');
        }
        $snapshotKey = (string) ($privacySnapshotClient?->uploads[0]['Key'] ?? '');
        if ($snapshotKey === '') {
            throw new RuntimeException($driver . ': snapshot upload must use an object key.');
        }
        s3CompatibleDeleteObjectPermanently($privacyClient, (string) $saved['bucket'], $snapshotKey);

        $pdo->prepare(
            'UPDATE destinos_backup SET ultimo_sucesso_em = NULL, snapshot_pendente_id = NULL, snapshot_pendente_em = NULL '
            . 'WHERE id = :id'
        )->execute(['id' => $saved['id']]);
        $ambiguousSnapshotClient = new class($privacyClient) {
            public int $putAttempts = 0;
            public array $uploadedKeys = [];
            public function __construct(private object $client) {}
            public function putObject(array $input): mixed {
                ++$this->putAttempts;
                $this->uploadedKeys[] = (string) $input['Key'];
                $result = $this->client->putObject($input);
                if ($this->putAttempts === 1) {
                    throw new RuntimeException('Synthetic acknowledgement loss after object creation.');
                }
                return $result;
            }
            public function __call(string $name, array $arguments): mixed { return $this->client->{$name}(...$arguments); }
        };
        $snapshotFactory = static fn(array $destination, array $credentials): object => $ambiguousSnapshotClient;
        $snapshotEncryptor = static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $subjectId . ':' . $plaintext);
        $snapshotTime = strtotime('2026-11-22T12:00:00Z');
        $ambiguousFirst = ['processed' => 0, 'failed' => 0, 'skipped' => 0];
        for ($attempt = 0; $attempt < 100 && ($ambiguousFirst['failed'] ?? 0) === 0; $attempt++) {
            $ambiguousFirst = privacySnapshotRunDue($pdo, $snapshotTime + ($attempt * 60), 900, $snapshotEncryptor, $snapshotFactory);
        }
        $pendingAfterAmbiguous = $pdo->query(
            'SELECT snapshot_pendente_id, snapshot_pendente_em FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetch(PDO::FETCH_ASSOC);
        if (($ambiguousFirst['failed'] ?? 0) !== 1 || !is_array($pendingAfterAmbiguous)
            || !is_string($pendingAfterAmbiguous['snapshot_pendente_id'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $pendingAfterAmbiguous['snapshot_pendente_id'])) {
            throw new RuntimeException($driver . ': a failed acknowledgement must retain the pending snapshot identity for retry.');
        }
        $pendingSnapshotId = $pendingAfterAmbiguous['snapshot_pendente_id'];
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, [
                'id' => (int) $saved['id'], 'bucket' => 'replacement-bucket', 'access_key' => '', 'secret_key' => '',
            ]));
            throw new RuntimeException($driver . ': a destination with a pending snapshot allowed its storage to change.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_SNAPSHOT_PENDING') throw $exception;
        }
        try {
            backupDestinationDelete($pdo, $identity, (int) $saved['id']);
            throw new RuntimeException($driver . ': a destination with a pending snapshot was removed.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_SNAPSHOT_PENDING') throw $exception;
        }
        $ambiguousRetry = privacySnapshotRunDue($pdo, $snapshotTime + 60, 900, $snapshotEncryptor, $snapshotFactory);
        $pendingAfterRetry = $pdo->query(
            'SELECT snapshot_pendente_id, ultimo_sucesso_em FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetch(PDO::FETCH_ASSOC);
        $retriedSnapshotKey = 'ieprcard-snapshots/v3/' . gmdate('Y/m/d', strtotime((string) $pendingAfterAmbiguous['snapshot_pendente_em']))
            . '/' . $pendingSnapshotId . '.json.gz';
        $retriedSnapshotVersions = $privacyClient->listObjectVersions([
            'Bucket' => $saved['bucket'], 'Prefix' => $retriedSnapshotKey, 'MaxKeys' => 100,
        ]);
        $retriedSnapshotPayload = json_decode(
            gzdecode((string) $privacyClient->getObject(['Bucket' => $saved['bucket'], 'Key' => $retriedSnapshotKey])['Body']) ?: '',
            true
        );
        $snapshotDestinationRows = is_array($retriedSnapshotPayload)
            ? ($retriedSnapshotPayload['tables']['destinos_backup'] ?? []) : [];
        $snapshotDestination = is_array($snapshotDestinationRows[0] ?? null) ? $snapshotDestinationRows[0] : [];
        if (($ambiguousRetry['processed'] ?? 0) !== 1 || $ambiguousSnapshotClient->putAttempts !== 1
            || $ambiguousSnapshotClient->uploadedKeys !== [$retriedSnapshotKey]
            || !is_array($pendingAfterRetry) || $pendingAfterRetry['snapshot_pendente_id'] !== null
            || !is_string($pendingAfterRetry['ultimo_sucesso_em'] ?? null)
            || count($retriedSnapshotVersions['Versions'] ?? []) !== 1
            || ($retriedSnapshotPayload['manifest']['snapshot_id'] ?? null) !== $pendingSnapshotId
            || ($retriedSnapshotPayload['manifest']['schema_version'] ?? null) !== '0024'
            || !array_key_exists('snapshot_pendente_id', $snapshotDestination) || $snapshotDestination['snapshot_pendente_id'] !== null
            || !array_key_exists('snapshot_pendente_em', $snapshotDestination) || $snapshotDestination['snapshot_pendente_em'] !== null) {
            throw new RuntimeException($driver . ': retry after an ambiguous S3 acknowledgement must confirm the same complete object without creating another version.');
        }
        s3CompatibleDeleteObjectPermanently($privacyClient, (string) $saved['bucket'], $retriedSnapshotKey);

        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT=2');
        putenv('IEPR_BACKUP_HOSTING_PROFILE=vps');
        backupDestinationSetEnabled($pdo, $identity, (int) $second['id'], true);
        putenv('IEPR_BACKUP_HOSTING_PROFILE=shared');
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
        $overLimitFactoryCalls = 0;
        $overLimitResult = privacySnapshotUpload(
            $pdo,
            [(int) $saved['id'], (int) $second['id']],
            $snapshotEncryptor,
            static function () use (&$overLimitFactoryCalls, $privacyClient): object {
                ++$overLimitFactoryCalls;
                return $privacyClient;
            }
        );
        $overLimitErrors = $pdo->query(
            'SELECT ultimo_erro_codigo FROM destinos_backup WHERE ativo = 1 ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);
        if (($overLimitResult['processed'] ?? -1) !== 0 || ($overLimitResult['failed'] ?? -1) !== 2
            || $overLimitFactoryCalls !== 0 || $overLimitErrors !== ['active_destination_limit_exceeded', 'active_destination_limit_exceeded']) {
            throw new RuntimeException($driver . ': snapshots must fail closed and mark every destination when active count exceeds the configured limit.');
        }
        putenv('IEPR_BACKUP_HOSTING_PROFILE=vps');
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT=2');
        $multipleDestinationUploads = [];
        $multipleDestinationResult = privacySnapshotUpload(
            $pdo,
            [(int) $saved['id'], (int) $second['id']],
            $snapshotEncryptor,
            static function (array $destination, array $credentials) use (&$multipleDestinationUploads, $privacyClient): object {
                return new class($privacyClient, $destination, $multipleDestinationUploads) {
                    public function __construct(
                        private object $client,
                        private array $destination,
                        private array &$uploads
                    ) {}
                    public function putObject(array $input): mixed {
                        $this->uploads[(int) $this->destination['id']] = $input;
                        return $this->client->putObject($input);
                    }
                    public function __call(string $name, array $arguments): mixed { return $this->client->{$name}(...$arguments); }
                };
            }
        );
        $multipleDestinationKeys = array_map(
            static fn(array $upload): string => (string) ($upload['Key'] ?? ''),
            array_values($multipleDestinationUploads)
        );
        if (($multipleDestinationResult['processed'] ?? 0) !== 2 || ($multipleDestinationResult['failed'] ?? 0) !== 0
            || count($multipleDestinationKeys) !== 2 || $multipleDestinationKeys[0] === $multipleDestinationKeys[1]) {
            throw new RuntimeException($driver . ': independent S3 destinations must receive separate, complete snapshot identities.');
        }
        foreach ($multipleDestinationUploads as $destinationId => $upload) {
            $destinationRow = $pdo->query(
                'SELECT bucket FROM destinos_backup WHERE id = ' . (int) $destinationId
            )->fetch(PDO::FETCH_ASSOC);
            s3CompatibleDeleteObjectPermanently($privacyClient, (string) $destinationRow['bucket'], (string) $upload['Key']);
        }

        $oldSnapshotKey = 'ieprcard-snapshots/v2/2026/10/01/' . str_repeat('a', 32) . '.json.gz';
        $olderSnapshotKey = 'ieprcard-snapshots/v2/2026/09/01/' . str_repeat('c', 32) . '.json.gz';
        $pendingSnapshotKey = 'ieprcard-snapshots/v2/2026/09/01/' . str_repeat('d', 32) . '.json.gz';
        $recentSnapshotKey = 'ieprcard-snapshots/v2/2026/11/01/' . str_repeat('b', 32) . '.json.gz';
        $pdo->prepare(
            'UPDATE destinos_backup SET snapshot_pendente_id = :pending_id, snapshot_pendente_em = :pending_at WHERE id = :id'
        )->execute([
            'pending_id' => str_repeat('d', 32), 'pending_at' => '2026-09-01 00:00:00', 'id' => $saved['id'],
        ]);
        $retentionClient = new class([
            $oldSnapshotKey => strtotime('2026-10-01T00:00:00Z'),
            $olderSnapshotKey => strtotime('2026-09-01T00:00:00Z'),
            $pendingSnapshotKey => strtotime('2026-09-01T00:00:00Z'),
            $recentSnapshotKey => strtotime('2026-11-01T00:00:00Z'),
            'ieprcard-privacy/keys/synthetic.json' => strtotime('2026-01-01T00:00:00Z'),
        ]) {
            public function __construct(public array $objects) {}
            public function listObjectsV2(array $input): array {
                $contents = [];
                foreach ($this->objects as $key => $timestamp) {
                    if (str_starts_with($key, (string) ($input['Prefix'] ?? ''))) {
                        $contents[] = ['Key' => $key, 'LastModified' => new DateTimeImmutable('@' . $timestamp)];
                    }
                }
                return ['Contents' => $contents, 'IsTruncated' => false];
            }
            public function listObjectVersions(array $input): array {
                $key = (string) ($input['Prefix'] ?? '');
                return isset($this->objects[$key])
                    ? ['Versions' => [['Key' => $key, 'VersionId' => 'version-1']], 'DeleteMarkers' => [], 'IsTruncated' => false]
                    : ['Versions' => [], 'DeleteMarkers' => [], 'IsTruncated' => false];
            }
            public function deleteObjects(array $input): array {
                foreach ($input['Delete']['Objects'] ?? [] as $object) unset($this->objects[$object['Key']]);
                return ['Errors' => []];
            }
            public function deleteObject(array $input): void { unset($this->objects[$input['Key']]); }
            public function headObject(array $input): void {
                if (isset($this->objects[$input['Key']])) throw new RuntimeException('Object still exists.', 200);
                throw new RuntimeException('NoSuchKey', 404);
            }
        };
        $emptyRetentionClient = new class {
            public function listObjectsV2(array $input): array { return ['Contents' => [], 'IsTruncated' => false]; }
        };
        $retentionClientFactory = static fn(array $destination, array $credentials): object => (int) $destination['id'] === (int) $saved['id']
            ? $retentionClient : $emptyRetentionClient;
        $retentionResult = privacySnapshotPurgeExpired(
            $pdo,
            strtotime('2026-11-22T12:00:00Z'),
            1,
            $retentionClientFactory
        );
        if (($retentionResult['processed'] ?? 0) !== 1 || ($retentionResult['failed'] ?? 0) !== 0
            || isset($retentionClient->objects[$oldSnapshotKey]) || !isset($retentionClient->objects[$olderSnapshotKey])
            || !isset($retentionClient->objects[$pendingSnapshotKey]) || !isset($retentionClient->objects[$recentSnapshotKey])
            || !isset($retentionClient->objects['ieprcard-privacy/keys/synthetic.json'])) {
            throw new RuntimeException($driver . ': snapshot retention must permanently remove only expired snapshots on every configured destination.');
        }
        $removedInLastRun = $pdo->query('SELECT retencao_itens_removidos FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetchColumn();
        if ((int) $removedInLastRun !== 1) {
            throw new RuntimeException($driver . ': retention must expose the number of snapshots removed in the latest run.');
        }
        $retentionRemainder = privacySnapshotPurgeExpired(
            $pdo,
            strtotime('2026-11-22T12:05:00Z'),
            25,
            $retentionClientFactory
        );
        if (($retentionRemainder['processed'] ?? 0) !== 1 || isset($retentionClient->objects[$olderSnapshotKey])
            || !isset($retentionClient->objects[$pendingSnapshotKey])) {
            throw new RuntimeException($driver . ': retention must not purge the object attached to an unfinished snapshot retry.');
        }
        $pdo->prepare('UPDATE destinos_backup SET ativo = 0 WHERE id = :id')->execute(['id' => $saved['id']]);
        $inactivePendingRetention = privacySnapshotPurgeExpired(
            $pdo,
            strtotime('2026-11-22T12:10:00Z'),
            25,
            $retentionClientFactory
        );
        $inactivePendingState = $pdo->query(
            'SELECT snapshot_pendente_id, snapshot_pendente_em FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetch(PDO::FETCH_ASSOC);
        if (($inactivePendingRetention['failed'] ?? 0) !== 0 || isset($retentionClient->objects[$pendingSnapshotKey])
            || !is_array($inactivePendingState) || $inactivePendingState['snapshot_pendente_id'] !== null
            || $inactivePendingState['snapshot_pendente_em'] !== null) {
            throw new RuntimeException($driver . ': retention must release an expired pending snapshot when its destination is inactive.');
        }
        $pdo->prepare('UPDATE destinos_backup SET ativo = 1 WHERE id = :id')->execute(['id' => $saved['id']]);
        $pdo->prepare(
            'UPDATE destinos_backup SET snapshot_pendente_id = NULL, snapshot_pendente_em = NULL WHERE id = :id'
        )->execute(['id' => $saved['id']]);
        $retentionState = $pdo->query(
            'SELECT retencao_ultima_execucao_em, retencao_erro_codigo FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($retentionState) || $retentionState['retencao_ultima_execucao_em'] !== gmdate('Y-m-d H:i:s', strtotime('2026-11-22T12:10:00Z'))
            || $retentionState['retencao_erro_codigo'] !== null) {
            throw new RuntimeException($driver . ': retention state must be tracked independently per destination.');
        }
        $retentionFailure = privacySnapshotPurgeExpired(
            $pdo,
            strtotime('2026-11-22T12:15:00Z'),
            25,
            static function (): object { throw new RuntimeException('Synthetic retention provider failure.'); }
        );
        $retentionFailureState = $pdo->query(
            'SELECT retencao_erro_codigo FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetchColumn();
        if (($retentionFailure['failed'] ?? 0) !== 2 || $retentionFailureState !== 'PRIVACY_SNAPSHOT_RETENTION_FAILED') {
            throw new RuntimeException($driver . ': retention provider failures must remain isolated and visible per destination.');
        }
        $retentionRetry = privacySnapshotPurgeExpired(
            $pdo,
            strtotime('2026-11-22T12:30:00Z'),
            25,
            static fn(array $destination, array $credentials): object => $retentionClient
        );
        if (($retentionRetry['failed'] ?? 0) !== 0
            || $pdo->query('SELECT retencao_erro_codigo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetchColumn() !== null) {
            throw new RuntimeException($driver . ': a successful retention retry must clear only the retention error.');
        }

        $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $privacyHolderId]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $privacyTenantId]);

        backupDestinationSave($pdo, $identity, array_merge($input, [
            'id' => (int) $saved['id'], 'endpoint' => 'http://127.0.0.1:1', 'access_key' => '', 'secret_key' => '',
        ]));
        $lastSuccessfulAtBeforeFailure = $pdo->query(
            'SELECT ultimo_sucesso_em FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetchColumn();
        $failedTest = backupDestinationTest($pdo, $identity, (int) $saved['id']);
        $afterFailure = $pdo->query(
            'SELECT ultimo_teste_status, ultimo_sucesso_em, ativo FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetch(PDO::FETCH_ASSOC);
        if (($failedTest['status'] ?? null) !== 'failed' || !is_array($afterFailure)
            || $afterFailure['ultimo_teste_status'] !== 'failed'
            || (int) $afterFailure['ativo'] !== 0
            || $afterFailure['ultimo_sucesso_em'] !== $lastSuccessfulAtBeforeFailure) {
            throw new RuntimeException($driver . ': failed tests must preserve the previous successful timestamp.');
        }
        try {
            backupDestinationSetEnabled($pdo, $identity, (int) $saved['id'], true);
            throw new RuntimeException($driver . ': a destination that failed permanent deletion checks was enabled.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_TEST_REQUIRED') throw $exception;
        }
        backupDestinationDelete($pdo, $identity, (int) $second['id']);
        if (count(backupDestinationReadAll($pdo, $identity)) !== 1) {
            throw new RuntimeException($driver . ': a superadmin must be able to remove one destination independently.');
        }
        backupDestinationSetEnabled($pdo, $identity, (int) $saved['id'], false);
        backupDestinationDelete($pdo, $identity, (int) $saved['id']);

        try {
            backupDestinationSave($pdo, $identity, array_merge($input, ['endpoint' => 'https://user:pass@example.com']));
            throw new RuntimeException($driver . ': endpoint userinfo was accepted.');
        } catch (InvalidArgumentException) {
        }

        foreach ([
            'http://storage.example.com',
            'https://storage.example.com/a/../b',
            'https://storage.example.com/?token=test',
            'https://storage.example.com/%2e%2e/admin',
        ] as $unsafeEndpoint) {
            try {
                backupDestinationSave($pdo, $identity, array_merge($input, ['endpoint' => $unsafeEndpoint]));
                throw new RuntimeException($driver . ': unsafe endpoint was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $oldKeyForFailure = getenv('IEPR_INTEGRATION_KEY');
        putenv('IEPR_INTEGRATION_KEY');
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, ['name' => 'Key Failure ' . $driver]));
            throw new RuntimeException($driver . ': missing encryption key was accepted.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_KEY_UNAVAILABLE') {
                throw $exception;
            }
        } finally {
            if ($oldKeyForFailure === false) {
                putenv('IEPR_INTEGRATION_KEY');
            } else {
                putenv('IEPR_INTEGRATION_KEY=' . $oldKeyForFailure);
            }
        }

        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $superadminId]);
    }
} finally {
    if ($oldBackupHostingProfile === false) {
        putenv('IEPR_BACKUP_HOSTING_PROFILE');
    } else {
        putenv('IEPR_BACKUP_HOSTING_PROFILE=' . $oldBackupHostingProfile);
    }
    if ($oldBackupActiveLimit === false) {
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
    } else {
        putenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT=' . $oldBackupActiveLimit);
    }
    if ($oldPrivateStorage === false) {
        putenv('IEPR_PRIVATE_STORAGE_PATH');
    } else {
        putenv('IEPR_PRIVATE_STORAGE_PATH=' . $oldPrivateStorage);
    }
    if ($oldIntegrationKey === false) {
        putenv('IEPR_INTEGRATION_KEY');
    } else {
        putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
    }
    if ($oldAnonymizationKey === false) {
        putenv('IEPR_ANONYMIZATION_KEY');
    } else {
        putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
    }
    if (is_resource($backupDestinationProcess)) {
        proc_terminate($backupDestinationProcess);
        proc_close($backupDestinationProcess);
    }
    $files = glob($backupDestinationStorage . DIRECTORY_SEPARATOR . '*') ?: [];
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file)) {
            foreach (glob($file . DIRECTORY_SEPARATOR . '*') ?: [] as $nestedFile) {
                if (is_file($nestedFile)) {
                    unlink($nestedFile);
                }
            }
            @rmdir($file);
        }
    }
    @rmdir($backupDestinationStorage);
    if (is_dir($backupDestinationPrivateStorage)) {
        foreach (glob($backupDestinationPrivateStorage . DIRECTORY_SEPARATOR . '*') ?: [] as $privateFile) {
            if (is_file($privateFile)) {
                unlink($privateFile);
            }
        }
        @rmdir($backupDestinationPrivateStorage);
    }
}

fwrite(STDOUT, "PASS: global backup destinations are encrypted and connect to an S3-compatible endpoint.\n");
return 'pass';
