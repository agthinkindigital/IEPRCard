<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_destinations_s3.php';

/** @return list<array<string,mixed>> */
function titularPrivacyActiveDestinations(PDO $pdo): array
{
    $rows = $pdo->query("SELECT * FROM destinos_backup WHERE ativo = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    return titularPrivacyOpenDestinationCredentials($rows);
}

/** @return list<array<string,mixed>> */
function titularPrivacyDestinationsForErasure(PDO $pdo, string $referenceHmac): array
{
    $query = $pdo->prepare(
        'SELECT DISTINCT d.* FROM destinos_backup d LEFT JOIN titular_chaves_backup k ON k.destino_id = d.id '
        . 'AND k.referencia_hmac = :reference WHERE d.ativo = 1 OR k.id IS NOT NULL ORDER BY d.id'
    );
    $query->execute(['reference' => $referenceHmac]);
    return titularPrivacyOpenDestinationCredentials($query->fetchAll(PDO::FETCH_ASSOC));
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function titularPrivacyOpenDestinationCredentials(array $rows): array
{
    foreach ($rows as &$row) {
        $credentials = json_decode(aiGlobalConfigOpen((string) $row['credentials_cifradas']), true, 8);
        if (!is_array($credentials) || !is_string($credentials['access_key'] ?? null)
            || !is_string($credentials['secret_key'] ?? null)) {
            throw new RuntimeException('ANONYMIZATION_DESTINATION_CREDENTIALS_INVALID');
        }
        $row['_credentials'] = $credentials;
    }
    unset($row);
    return $rows;
}

/** @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory */
function titularPrivacyCreateClient(array $destination, ?callable $clientFactory): object
{
    $credentials = $destination['_credentials'] ?? null;
    if (!is_array($credentials)) {
        throw new RuntimeException('ANONYMIZATION_DESTINATION_CREDENTIALS_INVALID');
    }
    return $clientFactory !== null
        ? $clientFactory($destination, $credentials)
        : backupDestinationS3Client($destination, $credentials);
}

function titularPrivacyObjectKey(string $kind, string $reference, string $key): string
{
    $hmac = hash_hmac('sha256', $reference, $key);
    return 'ieprcard-privacy/' . $kind . '/' . $hmac . '.json';
}
