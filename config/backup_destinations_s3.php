<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';
require_once __DIR__ . '/s3_compatible_storage.php';

/** @param array<string, mixed> $identity
 *  @return array{status: 'success'|'failed', error_code: ?string}
 */
function backupDestinationTest(PDO $pdo, array $identity, int $id): array
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    $statement = $pdo->prepare('SELECT * FROM destinos_backup WHERE id = :id');
    $statement->execute(['id' => $id]);
    $destination = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($destination)) {
        throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
    }

    $errorCode = null;
    try {
        $credentialsJson = aiGlobalConfigOpen((string) $destination['credentials_cifradas']);
        $credentials = json_decode($credentialsJson, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($credentials) || !is_string($credentials['access_key'] ?? null)
            || !is_string($credentials['secret_key'] ?? null)) {
            throw new RuntimeException('BACKUP_DESTINATION_CREDENTIALS_INVALID');
        }
        $client = backupDestinationS3Client($destination, $credentials);
        $key = 'ieprcard-connection-probes/' . bin2hex(random_bytes(20)) . '.txt';
        $payload = bin2hex(random_bytes(24));
        $written = true;
        try {
            $client->putObject(['Bucket' => $destination['bucket'], 'Key' => $key, 'Body' => $payload]);
            $object = $client->getObject(['Bucket' => $destination['bucket'], 'Key' => $key]);
            $body = (string) $object['Body'];
            if (!hash_equals($payload, $body)) {
                throw new RuntimeException('BACKUP_DESTINATION_PROBE_MISMATCH');
            }
            s3CompatibleDeleteObjectPermanently($client, (string) $destination['bucket'], $key);
            $written = false;
        } finally {
            if ($written) {
                try {
                    s3CompatibleDeleteObjectPermanently($client, (string) $destination['bucket'], $key);
                } catch (Throwable) {
                    throw new RuntimeException('BACKUP_DESTINATION_PROBE_CLEANUP_FAILED');
                }
            }
        }
    } catch (Throwable $exception) {
        $errorCode = match ($exception->getMessage()) {
            'BACKUP_DESTINATION_PROBE_CLEANUP_FAILED' => 'cleanup_failed',
            'BACKUP_DESTINATION_PROBE_MISMATCH' => 'probe_mismatch',
            'AI_CONFIG_KEY_UNAVAILABLE', 'AI_CONFIG_KEY_INVALID', 'AI_CONFIG_SECRET_UNAVAILABLE' => 'encryption_key_unavailable',
            default => 'connection_failed',
        };
        $result = ['status' => 'failed', 'error_code' => $errorCode];
    }

    $now = gmdate('Y-m-d H:i:s');
    if ($errorCode === null) {
        $result = ['status' => 'success', 'error_code' => null];
        $update = $pdo->prepare(
            'UPDATE destinos_backup SET ultimo_teste_status = :status, ultimo_teste_em = :tested, '
            . 'ultimo_sucesso_em = :success, ultimo_erro_codigo = NULL, atualizado_em = :updated WHERE id = :id'
        );
        $update->execute(['status' => 'success', 'tested' => $now, 'success' => $now, 'updated' => $now, 'id' => $id]);
    } else {
        $update = $pdo->prepare(
            'UPDATE destinos_backup SET ultimo_teste_status = :status, ultimo_teste_em = :tested, '
            . 'ultimo_erro_codigo = :error, ativo = 0, atualizado_em = :updated WHERE id = :id'
        );
        $update->execute(['status' => 'failed', 'tested' => $now, 'error' => $errorCode, 'updated' => $now, 'id' => $id]);
    }
    return $result;
}

/** @param array<string, mixed> $destination @param array{access_key:string,secret_key:string} $credentials */
function backupDestinationS3Client(array $destination, array $credentials): \Aws\S3\S3Client
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    if (!class_exists(\Aws\S3\S3Client::class)) {
        throw new RuntimeException('BACKUP_DESTINATION_SDK_UNAVAILABLE');
    }
    $options = [
        'version' => '2006-03-01',
        'region' => (string) $destination['regiao'],
        'signature_version' => 'v4',
        'credentials' => ['key' => $credentials['access_key'], 'secret' => $credentials['secret_key']],
        'use_path_style_endpoint' => (bool) $destination['path_style'],
        'http' => ['connect_timeout' => 5, 'timeout' => 12, 'verify' => true, 'allow_redirects' => false],
        'retries' => 0,
    ];
    if (is_string($destination['endpoint']) && $destination['endpoint'] !== '') {
        $options['endpoint'] = $destination['endpoint'];
    }
    return new \Aws\S3\S3Client($options);
}

function backupDestinationValidateEndpoint(mixed $endpoint): ?string
{
    if ($endpoint === null || trim((string) $endpoint) === '') {
        return null;
    }
    if (!is_string($endpoint) || strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
        throw new InvalidArgumentException('Endpoint de backup inválido.');
    }
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
        || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
        || isset($parts['fragment']) || (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))) {
        throw new InvalidArgumentException('Endpoint de backup inválido.');
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $hostWithoutBrackets = trim($host, '[]');
    $isLoopback = in_array($hostWithoutBrackets, ['localhost', '127.0.0.1', '::1'], true);
    if (filter_var($hostWithoutBrackets, FILTER_VALIDATE_IP) === false
        && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $host) !== 1) {
        throw new InvalidArgumentException('Host do endpoint de backup inválido.');
    }
    if ($scheme !== 'https' && !$isLoopback) {
        throw new InvalidArgumentException('Endpoints de backup precisam usar HTTPS.');
    }
    $path = (string) ($parts['path'] ?? '');
    if (str_contains($path, '\\') || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1
        || str_contains($path, '//') || str_contains($path, '%')) {
        throw new InvalidArgumentException('Caminho do endpoint de backup inválido.');
    }
    return $endpoint;
}

/** @param array<string, mixed> $identity */
function backupDestinationAssertSuperadmin(PDO $pdo, array $identity): void
{
    $id = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || ($identity['papel_sistema'] ?? null) !== 'superadmin'
        || ($identity['tenant_id'] ?? null) !== null || ($identity['subcliente_id'] ?? null) !== null) {
        throw new DomainException('BACKUP_DESTINATION_FORBIDDEN');
    }
    $query = $pdo->prepare(
        "SELECT 1 FROM usuarios WHERE id = :id AND tenant_id IS NULL AND subcliente_id IS NULL "
        . "AND papel_sistema = 'superadmin' AND ativo = 1 LIMIT 1"
    );
    $query->execute(['id' => $id]);
    if ($query->fetchColumn() === false) {
        throw new DomainException('BACKUP_DESTINATION_FORBIDDEN');
    }
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function backupDestinationShape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['nome'],
        'provider' => (string) $row['provedor'],
        'bucket' => (string) $row['bucket'],
        'region' => (string) $row['regiao'],
        'endpoint' => $row['endpoint'] === null ? null : (string) $row['endpoint'],
        'path_style' => (bool) $row['path_style'],
        'enabled' => (bool) $row['ativo'],
        'credentials_configured' => (bool) ($row['credentials_configured'] ?? true),
        'test_status' => $row['ultimo_teste_status'] === null ? null : (string) $row['ultimo_teste_status'],
        'tested_at' => $row['ultimo_teste_em'] === null ? null : (string) $row['ultimo_teste_em'],
        'last_success_at' => $row['ultimo_sucesso_em'] === null ? null : (string) $row['ultimo_sucesso_em'],
        'last_error_code' => $row['ultimo_erro_codigo'] === null ? null : (string) $row['ultimo_erro_codigo'],
        'updated_at' => $row['atualizado_em'] === null ? null : (string) $row['atualizado_em'],
    ];
}
