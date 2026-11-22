<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/backup_destinations.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

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
        schemaAplicarMigracoes($pdo, 'test');
        schemaGarantirEstrutura($pdo, 'test');
        $superadminEmail = 'backup-test-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
            . "VALUES ('superadmin', 'Backup Test', :email, :hash, 1)"
        )->execute(['email' => $superadminEmail, 'hash' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
        $superadminId = (int) $pdo->lastInsertId();
        $identity = ['id' => $superadminId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
        $input = [
            'name' => 'Local S3 ' . $driver,
            'bucket' => 'backup-test',
            'region' => 'us-east-1',
            'endpoint' => $backupDestinationEndpoint,
            'path_style' => true,
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
        $rows = backupDestinationReadAll($pdo, $identity);
        $stored = $pdo->query('SELECT credentials_cifradas, ativo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stored) || $stored['credentials_cifradas'] === 'synthetic-secret-key'
            || str_contains((string) json_encode($rows), 'synthetic-secret-key')
            || ($rows[0]['credentials_configured'] ?? false) !== true
            || (int) $stored['ativo'] !== 0) {
            throw new RuntimeException($driver . ': backup credentials must be encrypted and omitted from read APIs.');
        }

        $tested = backupDestinationTest($pdo, $identity, (int) $saved['id']);
        $persisted = $pdo->query('SELECT ultimo_teste_status, ultimo_sucesso_em, ultimo_erro_codigo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetch(PDO::FETCH_ASSOC);
        if (($tested['status'] ?? null) !== 'success' || !is_array($persisted)
            || $persisted['ultimo_teste_status'] !== 'success' || !is_string($persisted['ultimo_sucesso_em'])
            || $persisted['ultimo_erro_codigo'] !== null) {
            throw new RuntimeException($driver . ': S3 probe result was invalid: ' . json_encode($tested) . ' ' . json_encode($persisted));
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
        try {
            backupDestinationSave($pdo, $identity, array_merge($input, [
                'id' => (int) $saved['id'], 'bucket' => 'changed-bucket', 'access_key' => '', 'secret_key' => '',
            ]));
            throw new RuntimeException($driver . ': storage settings changed while holder key envelopes were registered.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'BACKUP_DESTINATION_PRIVACY_KEYS_PRESENT') throw $exception;
        }
        $pdo->prepare('DELETE FROM titular_chaves_backup WHERE destino_id = :id')->execute(['id' => $saved['id']]);
        $updated = backupDestinationSave($pdo, $identity, array_merge($input, [
            'id' => (int) $saved['id'], 'access_key' => '', 'secret_key' => '',
        ]));
        $updatedCiphertext = (string) $pdo->query(
            'SELECT credentials_cifradas FROM destinos_backup WHERE id = ' . (int) $saved['id']
        )->fetchColumn();
        if ($updated['id'] !== $saved['id'] || $updatedCiphertext !== $originalCiphertext
            || $updated['test_status'] !== null || $updated['tested_at'] !== null) {
            throw new RuntimeException($driver . ': updating metadata without a new secret must preserve encrypted credentials.');
        }
        if ((backupDestinationTest($pdo, $identity, (int) $saved['id'])['status'] ?? null) !== 'success') {
            throw new RuntimeException($driver . ': destination changes must be tested before re-enabling it.');
        }

        $second = backupDestinationSave($pdo, $identity, array_merge($input, ['name' => 'Second S3 ' . $driver]));
        if ($second['id'] === $saved['id'] || count(backupDestinationReadAll($pdo, $identity)) !== 2) {
            throw new RuntimeException($driver . ': multiple independent backup destinations must be supported.');
        }

        backupDestinationSetEnabled($pdo, $identity, (int) $saved['id'], true);
        if ((int) $pdo->query('SELECT ativo FROM destinos_backup WHERE id = ' . (int) $saved['id'])->fetchColumn() !== 1) {
            throw new RuntimeException($driver . ': destinations must be independently enableable.');
        }

        $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', :name, 'ativo')"
        )->execute(['name' => 'S3 privacy test tenant ' . $driver]);
        $privacyTenantId = (int) $pdo->lastInsertId();
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
        }
    }
    @rmdir($backupDestinationStorage);
}

fwrite(STDOUT, "PASS: global backup destinations are encrypted and connect to an S3-compatible endpoint.\n");
return 'pass';
