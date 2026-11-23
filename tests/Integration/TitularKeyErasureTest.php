<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';
require_once dirname(__DIR__, 2) . '/config/titular_privacy_keys.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

final class TitularPrivacyNotFound extends RuntimeException
{
    public function getStatusCode(): int { return 404; }
}

final class TitularPrivacyMockS3
{
    private array $store;
    public static int $failDeleteFor = 0;

    public function __construct(private int $destinationId, array &$store)
    {
        $this->store =& $store;
        $this->store[$this->destinationId] ??= ['versions' => [], 'get_calls' => 0, 'head_calls' => 0];
    }

    public function putObject(array $arguments): array
    {
        $this->store[$this->destinationId]['versions'][] = [
            'Key' => $arguments['Key'],
            'VersionId' => bin2hex(random_bytes(5)),
            'Body' => (string) $arguments['Body'],
        ];
        return ['VersionId' => 'stored'];
    }

    public function listObjectVersions(array $arguments): array
    {
        $entries = array_values(array_filter(
            $this->store[$this->destinationId]['versions'],
            static fn(array $entry): bool => $entry['Key'] === $arguments['Prefix']
        ));
        return [
            'Versions' => array_values(array_filter($entries, static fn(array $entry): bool => empty($entry['Marker']))),
            'DeleteMarkers' => array_values(array_filter($entries, static fn(array $entry): bool => !empty($entry['Marker']))),
            'IsTruncated' => false,
        ];
    }

    public function deleteObjects(array $arguments): array
    {
        if ($this->destinationId === self::$failDeleteFor) {
            throw new RuntimeException('ANONYMIZATION_OBJECT_VERSION_DELETE_FAILED');
        }
        $remove = array_column($arguments['Delete']['Objects'], 'VersionId');
        $this->store[$this->destinationId]['versions'] = array_values(array_filter(
            $this->store[$this->destinationId]['versions'],
            static fn(array $entry): bool => !in_array($entry['VersionId'], $remove, true)
        ));
        return [];
    }

    public function deleteObject(array $arguments): array { return []; }
    public function getObject(array $arguments): array
    {
        ++$this->store[$this->destinationId]['get_calls'];
        $matches = array_values(array_filter(
            $this->store[$this->destinationId]['versions'],
            static fn(array $entry): bool => $entry['Key'] === $arguments['Key'] && empty($entry['Marker'])
        ));
        if ($matches === []) throw new TitularPrivacyNotFound('NotFound');
        return ['Body' => $matches[array_key_last($matches)]['Body']];
    }
    public function headObject(array $arguments): array
    {
        ++$this->store[$this->destinationId]['head_calls'];
        $exists = array_filter(
            $this->store[$this->destinationId]['versions'],
            static fn(array $entry): bool => $entry['Key'] === $arguments['Key'] && empty($entry['Marker'])
        );
        if ($exists === []) throw new TitularPrivacyNotFound('NotFound');
        return ['ContentLength' => 1];
    }
}

$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

try {
    foreach (tenantTestConnections() as $driver => $pdo) {
        $fixture = tenantTestSeed($pdo);
        $suffix = bin2hex(random_bytes(5));
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
            . 'VALUES (:tenant, :name, :cpf, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Crypto Erasure ' . $suffix,
            'cpf' => '52998224725', 'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $holderId = (int) $pdo->lastInsertId();
        schemaAplicarMigracoes($pdo, 'test');
        $destIds = [];
        $insertDestination = $pdo->prepare(
            "INSERT INTO destinos_backup (nome, provedor, bucket, regiao, endpoint, path_style, credentials_cifradas, ativo, criado_em, atualizado_em) "
            . "VALUES (:name, 's3', :bucket, 'us-east-1', 'https://s3.example.test', 1, :credentials, 1, :created, :updated)"
        );
        foreach (['one', 'two'] as $name) {
            $insertDestination->execute([
                'name' => 'Key vault ' . $name . ' ' . $driver . ' ' . $suffix,
                'bucket' => 'keys-' . $name . '-' . $suffix,
                'credentials' => aiGlobalConfigSeal(json_encode(['access_key' => 'synthetic', 'secret_key' => 'synthetic'], JSON_THROW_ON_ERROR)),
                'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
            ]);
            $destIds[] = (int) $pdo->lastInsertId();
        }

        $store = [];
        $factory = static function (array $destination, array $credentials) use (&$store): object {
            $id = (int) $destination['id'];
            $store[$id] ??= ['versions' => [], 'get_calls' => 0, 'head_calls' => 0];
            return new TitularPrivacyMockS3($id, $store);
        };
        $beforeProvision = $pdo->prepare('SELECT nome_completo, cpf FROM titulares WHERE id = :id');
        $beforeProvision->execute(['id' => $holderId]);
        $before = $beforeProvision->fetch(PDO::FETCH_ASSOC);
        if (!is_array($before) || $before['nome_completo'] !== 'Crypto Erasure ' . $suffix || $before['cpf'] !== '52998224725') {
            throw new RuntimeException($driver . ': the additive reference migration must preserve existing business data.');
        }
        titularPrivacyProvisionKey($pdo, $fixture['tenant_id'], $holderId, $factory);
        $referenceQuery = $pdo->prepare('SELECT referencia_privacidade FROM titulares WHERE id = :id');
        $referenceQuery->execute(['id' => $holderId]);
        $reference = (string) $referenceQuery->fetchColumn();
        if (preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1 || count($store) < 2) {
            throw new RuntimeException($driver . ': migration and provisioning must create an opaque holder reference and external key envelopes.');
        }
        $snapshotPlaintext = 'private snapshot fields ' . $suffix;
        $snapshotCiphertext = titularPrivacyEncryptSnapshotData(
            $pdo,
            $fixture['tenant_id'],
            $holderId,
            $snapshotPlaintext,
            $factory
        );
        $keyReads = array_sum(array_column($store, 'get_calls'));
        $keyHeads = array_sum(array_column($store, 'head_calls'));
        if ($keyReads !== 1 || $keyHeads !== 1) {
            throw new RuntimeException($driver . ': snapshot encryption must reuse its successful key-envelope read and verify only the other active destinations.');
        }
        $encryptedBytes = base64_decode(substr($snapshotCiphertext, 3), true);
        $firstEnvelope = json_decode((string) $store[$destIds[0]]['versions'][0]['Body'], true);
        $wrappedDek = is_array($firstEnvelope)
            ? base64_decode((string) ($firstEnvelope['wrapped_dek'] ?? ''), true)
            : false;
        $dataDek = is_string($wrappedDek) ? sodium_crypto_secretbox_open(
            substr($wrappedDek, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($wrappedDek, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            titularAnonymizationEncryptionKey()
        ) : false;
        $decryptedSnapshot = is_string($encryptedBytes) && is_string($dataDek)
            ? sodium_crypto_secretbox_open(
                substr($encryptedBytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($encryptedBytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $dataDek
            )
            : false;
        if (!str_starts_with($snapshotCiphertext, 'v1:') || str_contains($snapshotCiphertext, $snapshotPlaintext)
            || !is_string($decryptedSnapshot) || !hash_equals($snapshotPlaintext, $decryptedSnapshot)) {
            throw new RuntimeException($driver . ': snapshot data must use the recoverable per-holder DEK envelope without exposing plaintext.');
        }
        if (is_string($dataDek)) sodium_memzero($dataDek);
        $pdo->prepare('UPDATE destinos_backup SET ativo = 0 WHERE id = :id')->execute(['id' => $destIds[1]]);
        $insertDestination->execute([
            'name' => 'Key vault later ' . $driver . ' ' . $suffix,
            'bucket' => 'keys-later-' . $suffix,
            'credentials' => aiGlobalConfigSeal(json_encode(['access_key' => 'synthetic', 'secret_key' => 'synthetic'], JSON_THROW_ON_ERROR)),
            'created' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
        ]);
        $destIds[] = (int) $pdo->lastInsertId();
        titularPrivacyProvisionKey($pdo, $fixture['tenant_id'], $holderId, $factory);
        foreach ($destIds as $destinationId) {
            $body = (string) ($store[$destinationId]['versions'][0]['Body'] ?? '');
            $envelope = json_decode($body, true);
            $wrapped = is_array($envelope) ? base64_decode((string) ($envelope['wrapped_dek'] ?? ''), true) : false;
            $dek = is_string($wrapped) ? sodium_crypto_secretbox_open(
                substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                titularAnonymizationEncryptionKey()
            ) : false;
            if (!is_array($envelope) || (int) ($envelope['version'] ?? 0) !== 1
                || !is_string($envelope['wrapped_dek'] ?? null)
                || !hash_equals(hash_hmac('sha256', $reference, titularAnonymizationEncryptionKey()), (string) ($envelope['reference_hmac'] ?? ''))
                || !is_string($dek) || strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                || str_contains($body, 'Crypto Erasure ') || str_contains($body, '52998224725')) {
                throw new RuntimeException($driver . ': external key envelopes must be wrapped and contain no holder data.');
            }
            sodium_memzero($dek);
        }
        $envelopes = array_map(static fn(int $id): string => (string) ($store[$id]['versions'][0]['Body'] ?? ''), $destIds);
        if (count(array_unique($envelopes)) !== 1) {
            throw new RuntimeException($driver . ': adding a destination must reuse the holder DEK envelope already in use.');
        }

        $keyBeforeFailure = getenv('IEPR_ANONYMIZATION_KEY');
        putenv('IEPR_ANONYMIZATION_KEY');
        try {
            titularPrivacyEncryptSnapshotData($pdo, $fixture['tenant_id'], $holderId, 'must remain unavailable', $factory);
            throw new RuntimeException($driver . ': snapshot encryption must fail closed without the master key.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'ANONYMIZATION_KEY_UNAVAILABLE') throw $exception;
        }
        try {
            titularPrivacyEraseKeys($pdo, $reference, $factory);
            throw new RuntimeException($driver . ': key erasure must fail closed without the master key.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'ANONYMIZATION_KEY_UNAVAILABLE') throw $exception;
        } finally {
            if ($keyBeforeFailure === false) putenv('IEPR_ANONYMIZATION_KEY');
            else putenv('IEPR_ANONYMIZATION_KEY=' . $keyBeforeFailure);
        }
        $remainingKeyVersions = array_sum(array_map(
            static fn(int $destinationId): int => count(array_filter(
                $store[$destinationId]['versions'],
                static fn(array $entry): bool => str_contains((string) $entry['Key'], '/keys/')
            )),
            $destIds
        ));
        if ($remainingKeyVersions !== count($destIds)) {
            throw new RuntimeException($driver . ': missing master key must not remove external key envelopes.');
        }

        $key = titularAnonymizationEncryptionKey();
        $keyObject = titularPrivacyObjectKey('keys', $reference, $key);
        $pdo->prepare('UPDATE titulares SET nome_completo = :name WHERE id = :id')
            ->execute(['name' => 'Verified still present ' . $suffix, 'id' => $holderId]);
        $createdVersion = false;
        foreach ($destIds as $destinationId) {
            $factory(['id' => $destinationId], [])
                ->putObject(['Key' => $keyObject, 'Body' => $store[$destinationId]['versions'][0]['Body']]);
            $createdVersion = true;
            $store[$destinationId]['versions'][] = [
                'Key' => $keyObject, 'VersionId' => 'marker-' . bin2hex(random_bytes(3)), 'Marker' => true, 'Body' => '',
            ];
        }
        if (!$createdVersion) throw new RuntimeException('Version fixture was not created.');

        $requestId = bin2hex(random_bytes(20));
        $now = 1_800_000_000;
        titularAnonymizationRequest($pdo, $fixture['tenant_id'], $holderId, $requestId, $now);
        TitularPrivacyMockS3::$failDeleteFor = $destIds[1];
        $failed = titularAnonymizationProcessDue($pdo, $now, 10, $factory);
        $request = $pdo->prepare('SELECT estado FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id');
        $request->execute(['id' => $requestId]);
        $state = $request->fetchColumn();
        $holder = $pdo->prepare('SELECT nome_completo, cpf FROM titulares WHERE id = :id');
        $holder->execute(['id' => $holderId]);
        $holderBeforeRetry = $holder->fetch(PDO::FETCH_ASSOC);
        if (($failed['failed'] ?? 0) !== 1 || $state !== 'falha' || !is_array($holderBeforeRetry)
            || $holderBeforeRetry['cpf'] !== '52998224725') {
            throw new RuntimeException($driver . ': remote purge failure must keep the request pending and business data intact.');
        }
        try {
            $pdo->prepare('DELETE FROM destinos_backup WHERE id = :id')->execute(['id' => $destIds[1]]);
            throw new RuntimeException($driver . ': a destination with a protected holder key was deletable.');
        } catch (PDOException) {
            // The key registry foreign key preserves access to disabled destinations until purge succeeds.
        }

        TitularPrivacyMockS3::$failDeleteFor = 0;
        $completed = titularAnonymizationProcessDue($pdo, $now + 60, 10, $factory);
        $request->execute(['id' => $requestId]);
        $holder->execute(['id' => $holderId]);
        $holderAfterRetry = $holder->fetch(PDO::FETCH_ASSOC);
        if (($completed['processed'] ?? 0) !== 1 || $request->fetchColumn() !== 'concluida'
            || !is_array($holderAfterRetry) || $holderAfterRetry['nome_completo'] !== 'Titular anonimizado'
            || $holderAfterRetry['cpf'] !== null) {
            throw new RuntimeException($driver . ': a successful retry must purge keys before completing anonymization.');
        }
        foreach ($destIds as $destinationId) {
            $tombstoneVersions = array_values(array_filter(
                $store[$destinationId]['versions'],
                static fn(array $entry): bool => str_contains((string) $entry['Key'], '/tombstones/')
            ));
            $keyVersions = array_values(array_filter(
                $store[$destinationId]['versions'],
                static fn(array $entry): bool => str_contains((string) $entry['Key'], '/keys/')
            ));
            if ($tombstoneVersions === [] || $keyVersions !== []) {
                throw new RuntimeException($driver . ': retries must permanently remove key versions and preserve a pseudonymous tombstone.');
            }
            $tombstone = json_decode((string) $tombstoneVersions[0]['Body'], true);
            if (!is_array($tombstone) || !is_string($tombstone['reference_hmac'] ?? null)
                || strlen($tombstone['reference_hmac']) !== 64 || str_contains(json_encode($tombstone), $reference)) {
                throw new RuntimeException($driver . ': tombstones must use only an HMAC reference.');
            }
        }

        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
            . 'VALUES (:tenant, :name, :cpf, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Missing ledger ' . $suffix,
            'cpf' => '11144477735', 'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $untrackedHolderId = (int) $pdo->lastInsertId();
        $untrackedReference = titularPrivacyEnsureReference($pdo, $fixture['tenant_id'], $untrackedHolderId);
        $untrackedErase = titularPrivacyEraseKeys($pdo, $untrackedReference, $factory);
        if (($untrackedErase['destinations'] ?? 0) !== 2) {
            throw new RuntimeException($driver . ': active destinations must receive a tombstone even when the per-holder ledger is absent.');
        }

        $pdo->prepare('DELETE FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id')->execute(['id' => $requestId]);
        $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $holderId]);
        $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $untrackedHolderId]);
        $pdo->prepare('DELETE FROM destinos_backup WHERE id IN (' . implode(',', array_map('intval', $destIds)) . ')')->execute();
        tenantTestCleanup($pdo, $fixture);
        fwrite(STDOUT, 'PASS: ' . $driver . " titular key envelopes, tombstones, permanent version purge and fail-closed retry.\n");
    }
} finally {
    if ($oldAnonymizationKey === false) putenv('IEPR_ANONYMIZATION_KEY');
    else putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
}

return 'pass';
