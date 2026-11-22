<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_global_config.php';
require_once __DIR__ . '/s3_compatible_storage.php';

/** @param array<string, mixed> $identity */
function backupDestinationSave(PDO $pdo, array $identity, array $input): array
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $name = trim((string) ($input['name'] ?? ''));
    $bucket = trim((string) ($input['bucket'] ?? ''));
    $region = trim((string) ($input['region'] ?? ''));
    $retentionDays = null;
    $endpoint = backupDestinationValidateEndpoint($input['endpoint'] ?? null);
    $pathStyle = filter_var($input['path_style'] ?? false, FILTER_VALIDATE_BOOL);
    $accessKey = trim((string) ($input['access_key'] ?? ''));
    $secretKey = trim((string) ($input['secret_key'] ?? ''));
    if ($id === false || strlen($name) < 1 || strlen($name) > 100
        || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
        || strlen($bucket) < 3 || strlen($bucket) > 255
        || preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $bucket) !== 1
        || str_contains($bucket, '..') || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/D', $bucket) === 1
        || strlen($region) < 1 || strlen($region) > 100
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $region) !== 1) {
        throw new InvalidArgumentException('Configuração do destino de backup inválida.');
    }
    if ($accessKey !== '' && (strlen($accessKey) > 256 || preg_match('/[\x00-\x1f\x7f]/', $accessKey) === 1)
        || $secretKey !== '' && (strlen($secretKey) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $secretKey) === 1)
        || (($accessKey === '') !== ($secretKey === ''))) {
        throw new InvalidArgumentException('Credenciais do destino de backup inválidas.');
    }

    $existing = null;
    if ($id > 0) {
        $query = $pdo->prepare(
            'SELECT bucket, regiao, endpoint, path_style, credentials_cifradas, retencao_dias FROM destinos_backup WHERE id = :id'
        );
        $query->execute(['id' => $id]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing) || !is_string($existing['credentials_cifradas']) || $existing['credentials_cifradas'] === '') {
            throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
        }
    }
    if ($accessKey !== '' && $secretKey !== '') {
        $encrypted = aiGlobalConfigSeal(json_encode(
            ['access_key' => $accessKey, 'secret_key' => $secretKey],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    } elseif (is_array($existing)) {
        $encrypted = (string) $existing['credentials_cifradas'];
    } else {
        throw new InvalidArgumentException('Credenciais são obrigatórias ao criar um destino.');
    }

    if (array_key_exists('retention_days', $input)) {
        $retentionDays = filter_var($input['retention_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($retentionDays === false) {
            throw new InvalidArgumentException('Período de retenção do backup inválido.');
        }
    } else {
        $retentionDays = is_array($existing) ? (int) $existing['retencao_dias'] : 30;
    }

    if (is_array($existing)) {
        $protectedKeys = $pdo->prepare('SELECT 1 FROM titular_chaves_backup WHERE destino_id = :id LIMIT 1');
        $protectedKeys->execute(['id' => $id]);
        $storageChanged = (string) $existing['bucket'] !== $bucket
            || (string) $existing['regiao'] !== $region
            || ($existing['endpoint'] === null ? null : (string) $existing['endpoint']) !== $endpoint
            || (bool) $existing['path_style'] !== $pathStyle
            || (string) $existing['credentials_cifradas'] !== $encrypted;
        if ($protectedKeys->fetchColumn() !== false && $storageChanged) {
            throw new DomainException('BACKUP_DESTINATION_PRIVACY_KEYS_PRESENT');
        }
    }

    $now = gmdate('Y-m-d H:i:s');
    if ($id > 0) {
        $statement = $pdo->prepare(
            'UPDATE destinos_backup SET nome = :name, provedor = :provider, bucket = :bucket, regiao = :region, '
            . 'endpoint = :endpoint, path_style = :path_style, credentials_cifradas = :credentials, ativo = 0, '
            . 'retencao_dias = :retention_days, ultimo_teste_status = NULL, ultimo_teste_em = NULL, '
            . 'ultimo_erro_codigo = NULL, atualizado_em = :updated '
            . 'WHERE id = :id'
        );
        $statement->execute([
            'name' => $name, 'provider' => 's3', 'bucket' => $bucket, 'region' => $region,
            'endpoint' => $endpoint, 'path_style' => $pathStyle ? 1 : 0, 'credentials' => $encrypted,
            'retention_days' => $retentionDays, 'updated' => $now, 'id' => $id,
        ]);
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO destinos_backup (nome, provedor, bucket, regiao, endpoint, path_style, credentials_cifradas, '
            . 'retencao_dias, ativo, criado_em, atualizado_em) VALUES (:name, :provider, :bucket, :region, :endpoint, '
            . ':path_style, :credentials, :retention_days, 0, :created, :updated)'
        );
        $statement->execute([
            'name' => $name, 'provider' => 's3', 'bucket' => $bucket, 'region' => $region,
            'endpoint' => $endpoint, 'path_style' => $pathStyle ? 1 : 0, 'credentials' => $encrypted,
            'retention_days' => $retentionDays, 'created' => $now, 'updated' => $now,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    return backupDestinationReadOne($pdo, $identity, (int) $id);
}

/** @param array<string, mixed> $identity
 *  @return list<array<string, mixed>>
 */
function backupDestinationReadAll(PDO $pdo, array $identity): array
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    $rows = $pdo->query(
        'SELECT id, nome, provedor, bucket, regiao, endpoint, path_style, retencao_dias, retencao_ultima_execucao_em, '
        . 'retencao_erro_codigo, ativo, ultimo_teste_status, ultimo_teste_em, ultimo_sucesso_em, ultimo_erro_codigo, atualizado_em, '
        . 'EXISTS (SELECT 1 FROM titular_chaves_backup k WHERE k.destino_id = destinos_backup.id) AS privacy_keys_present '
        . 'FROM destinos_backup ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    return array_map('backupDestinationShape', $rows);
}

/** @param array<string, mixed> $identity
 *  @return array<string, mixed>
 */
function backupDestinationReadOne(PDO $pdo, array $identity, int $id): array
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    $statement = $pdo->prepare(
        'SELECT id, nome, provedor, bucket, regiao, endpoint, path_style, retencao_dias, retencao_ultima_execucao_em, '
        . 'retencao_erro_codigo, ativo, ultimo_teste_status, ultimo_teste_em, ultimo_sucesso_em, ultimo_erro_codigo, atualizado_em, '
        . 'EXISTS (SELECT 1 FROM titular_chaves_backup k WHERE k.destino_id = destinos_backup.id) AS privacy_keys_present '
        . 'FROM destinos_backup WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
    }
    $row['credentials_configured'] = true;
    return backupDestinationShape($row);
}

/** @param array<string, mixed> $identity */
function backupDestinationSetEnabled(PDO $pdo, array $identity, int $id, bool $enabled): void
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    if ($enabled) {
        $verified = $pdo->prepare("SELECT ultimo_teste_status FROM destinos_backup WHERE id = :id");
        $verified->execute(['id' => $id]);
        if ($verified->fetchColumn() !== 'success') {
            throw new DomainException('BACKUP_DESTINATION_TEST_REQUIRED');
        }
    }
    $statement = $pdo->prepare('UPDATE destinos_backup SET ativo = :enabled, atualizado_em = :updated WHERE id = :id');
    $statement->execute(['enabled' => $enabled ? 1 : 0, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    if ($statement->rowCount() !== 1) {
        $exists = $pdo->prepare('SELECT 1 FROM destinos_backup WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
    }
}

/** @param array<string, mixed> $identity */
function backupDestinationDelete(PDO $pdo, array $identity, int $id): void
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    $keys = $pdo->prepare('SELECT 1 FROM titular_chaves_backup WHERE destino_id = :id LIMIT 1');
    $keys->execute(['id' => $id]);
    if ($keys->fetchColumn() !== false) {
        throw new DomainException('BACKUP_DESTINATION_PRIVACY_KEYS_PRESENT');
    }
    $statement = $pdo->prepare('DELETE FROM destinos_backup WHERE id = :id');
    $statement->execute(['id' => $id]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
    }
}

require_once __DIR__ . '/backup_destinations_s3.php';
