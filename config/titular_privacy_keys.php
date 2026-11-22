<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_destinations.php';
require_once __DIR__ . '/s3_compatible_storage.php';

function titularPrivacyReference(int $tenantId, int $titularId): string
{
    if ($tenantId < 1 || $titularId < 1) {
        throw new InvalidArgumentException('ANONYMIZATION_REFERENCE_INVALID');
    }
    return bin2hex(random_bytes(32));
}

function titularPrivacyEnsureReference(PDO $pdo, int $tenantId, int $titularId): string
{
    $query = $pdo->prepare(
        'SELECT referencia_privacidade FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1'
    );
    $query->execute(['id' => $titularId, 'tenant' => $tenantId]);
    $reference = $query->fetchColumn();
    if ($reference === false) {
        throw new DomainException('ANONYMIZATION_TITULAR_NOT_FOUND');
    }
    if (is_string($reference) && preg_match('/^[a-f0-9]{64}$/D', $reference) === 1) {
        return $reference;
    }

    $reference = titularPrivacyReference($tenantId, $titularId);
    $update = $pdo->prepare(
        'UPDATE titulares SET referencia_privacidade = :reference '
        . 'WHERE id = :id AND tenant_id = :tenant AND referencia_privacidade IS NULL'
    );
    $update->execute(['reference' => $reference, 'id' => $titularId, 'tenant' => $tenantId]);
    if ($update->rowCount() !== 1) {
        $query->execute(['id' => $titularId, 'tenant' => $tenantId]);
        $stored = $query->fetchColumn();
        if (!is_string($stored) || preg_match('/^[a-f0-9]{64}$/D', $stored) !== 1) {
            throw new RuntimeException('ANONYMIZATION_REFERENCE_PERSIST_FAILED');
        }
        return $stored;
    }
    return $reference;
}

/** @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory */
function titularPrivacyProvisionKey(
    PDO $pdo,
    int $tenantId,
    int $titularId,
    ?callable $clientFactory = null
): void {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lockHolder = $pdo->prepare(
            'UPDATE titulares SET referencia_privacidade = referencia_privacidade WHERE id = :id AND tenant_id = :tenant'
        );
        $lockHolder->execute(['id' => $titularId, 'tenant' => $tenantId]);
        $key = titularAnonymizationEncryptionKey();
        $reference = titularPrivacyEnsureReference($pdo, $tenantId, $titularId);
        $destinations = titularPrivacyActiveDestinations($pdo);
        if ($destinations === []) {
            throw new RuntimeException('ANONYMIZATION_KEY_DESTINATION_UNAVAILABLE');
        }
        $referenceHmac = hash_hmac('sha256', $reference, $key);
        $envelope = titularPrivacyLoadExistingEnvelope($pdo, $reference, $referenceHmac, $key, $clientFactory);
        if ($envelope === null) {
            $dek = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $envelope = json_encode([
                'version' => 1,
                'reference_hmac' => $referenceHmac,
                'wrapped_dek' => base64_encode($nonce . sodium_crypto_secretbox($dek, $nonce, $key)),
            ], JSON_THROW_ON_ERROR);
            sodium_memzero($dek);
        }
        foreach ($destinations as $destination) {
            $existing = $pdo->prepare(
                'SELECT estado FROM titular_chaves_backup WHERE destino_id = :destination AND referencia_hmac = :reference LIMIT 1'
            );
            $existing->execute(['destination' => $destination['id'], 'reference' => $referenceHmac]);
            if ($existing->fetchColumn() === 'ativa') {
                $client = titularPrivacyCreateClient($destination, $clientFactory);
                $client->headObject([
                    'Bucket' => $destination['bucket'],
                    'Key' => titularPrivacyObjectKey('keys', $reference, $key),
                ]);
                continue;
            }
            $now = gmdate('Y-m-d H:i:s');
            $reserve = $pdo->prepare(
                "INSERT INTO titular_chaves_backup (destino_id, referencia_hmac, estado, criado_em, atualizado_em) "
                . "VALUES (:destination, :reference, 'pendente', :created, :updated)"
            );
            try {
                $reserve->execute([
                    'destination' => $destination['id'], 'reference' => $referenceHmac,
                    'created' => $now, 'updated' => $now,
                ]);
            } catch (PDOException $exception) {
                if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $exception;
            }
            $client = titularPrivacyCreateClient($destination, $clientFactory);
            $client->putObject([
                'Bucket' => $destination['bucket'],
                'Key' => titularPrivacyObjectKey('keys', $reference, $key),
                'Body' => $envelope,
                'ContentType' => 'application/json',
                'ServerSideEncryption' => 'AES256',
            ]);
            $activate = $pdo->prepare(
                "UPDATE titular_chaves_backup SET estado = 'ativa', atualizado_em = :updated "
                . 'WHERE destino_id = :destination AND referencia_hmac = :reference'
            );
            $activate->execute([
                'updated' => gmdate('Y-m-d H:i:s'), 'destination' => $destination['id'],
                'reference' => $referenceHmac,
            ]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Encrypt one holder's snapshot payload without exposing the DEK to callers.
 *  @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 */
function titularPrivacyEncryptSnapshotData(
    PDO $pdo,
    int $tenantId,
    int $titularId,
    string $plaintext,
    ?callable $clientFactory = null
): string {
    if (strlen($plaintext) > 67_108_864) {
        throw new InvalidArgumentException('ANONYMIZATION_SNAPSHOT_PAYLOAD_TOO_LARGE');
    }
    titularPrivacyProvisionKey($pdo, $tenantId, $titularId, $clientFactory);
    $reference = titularPrivacyEnsureReference($pdo, $tenantId, $titularId);
    $key = titularAnonymizationEncryptionKey();
    $referenceHmac = hash_hmac('sha256', $reference, $key);
    $envelope = titularPrivacyLoadExistingEnvelope($pdo, $reference, $referenceHmac, $key, $clientFactory);
    if (!is_string($envelope)) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_MISSING');
    }
    $decoded = json_decode($envelope, true, 8);
    $wrapped = is_array($decoded) ? base64_decode((string) ($decoded['wrapped_dek'] ?? ''), true) : false;
    if (!is_string($wrapped) || strlen($wrapped) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_INVALID');
    }
    $dek = sodium_crypto_secretbox_open(
        substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $key
    );
    if (!is_string($dek) || strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_INVALID');
    }
    try {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $dek));
    } finally {
        sodium_memzero($dek);
    }
}

/** @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory */
function titularPrivacyLoadExistingEnvelope(
    PDO $pdo,
    string $reference,
    string $referenceHmac,
    string $key,
    ?callable $clientFactory
): ?string {
    $query = $pdo->prepare(
        "SELECT d.* FROM destinos_backup d JOIN titular_chaves_backup k ON k.destino_id = d.id "
        . "WHERE k.referencia_hmac = :reference AND k.estado = 'ativa' ORDER BY d.id LIMIT 1"
    );
    $query->execute(['reference' => $referenceHmac]);
    $destination = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($destination)) {
        return null;
    }
    $opened = titularPrivacyOpenDestinationCredentials([$destination]);
    $destination = $opened[0];
    $client = titularPrivacyCreateClient($destination, $clientFactory);
    try {
        $result = $client->getObject([
            'Bucket' => $destination['bucket'],
            'Key' => titularPrivacyObjectKey('keys', $reference, $key),
        ]);
        $envelope = (string) ($result['Body'] ?? '');
    } catch (Throwable) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_MISSING');
    }
    titularPrivacyValidateEnvelope($envelope, $referenceHmac, $key);
    return $envelope;
}

function titularPrivacyValidateEnvelope(string $envelope, string $referenceHmac, string $key): void
{
    $decoded = json_decode($envelope, true, 8);
    $wrapped = is_array($decoded) ? base64_decode((string) ($decoded['wrapped_dek'] ?? ''), true) : false;
    if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1
        || !is_string($decoded['reference_hmac'] ?? null) || !hash_equals($referenceHmac, $decoded['reference_hmac'])
        || !is_string($wrapped) || strlen($wrapped) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_INVALID');
    }
    $dek = sodium_crypto_secretbox_open(
        substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        $key
    );
    if (!is_string($dek) || strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('ANONYMIZATION_KEY_ENVELOPE_INVALID');
    }
    sodium_memzero($dek);
}

/** Publish pseudonymous tombstones everywhere before permanently purging every key version.
 *  @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 *  @return array{destinations:int}
 */
function titularPrivacyEraseKeys(PDO $pdo, string $reference, ?callable $clientFactory = null): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1) {
        throw new RuntimeException('ANONYMIZATION_REFERENCE_INVALID');
    }
    $key = titularAnonymizationEncryptionKey();
    $referenceHmac = hash_hmac('sha256', $reference, $key);
    $destinations = titularPrivacyDestinationsForErasure($pdo, $referenceHmac);
    $tombstone = json_encode([
        'version' => 1,
        'reference_hmac' => $referenceHmac,
        'deleted_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_THROW_ON_ERROR);
    foreach ($destinations as $destination) {
        $client = titularPrivacyCreateClient($destination, $clientFactory);
        $client->putObject([
            'Bucket' => $destination['bucket'],
            'Key' => titularPrivacyObjectKey('tombstones', $reference, $key),
            'Body' => $tombstone,
            'ContentType' => 'application/json',
            'ServerSideEncryption' => 'AES256',
        ]);
    }
    foreach ($destinations as $destination) {
        $client = titularPrivacyCreateClient($destination, $clientFactory);
        s3CompatibleDeleteObjectPermanently(
            $client,
            (string) $destination['bucket'],
            titularPrivacyObjectKey('keys', $reference, $key)
        );
        $remove = $pdo->prepare(
            'DELETE FROM titular_chaves_backup WHERE destino_id = :destination AND referencia_hmac = :reference'
        );
        $remove->execute(['destination' => $destination['id'], 'reference' => $referenceHmac]);
    }
    return ['destinations' => count($destinations)];
}

require_once __DIR__ . '/titular_privacy_destinations.php';
