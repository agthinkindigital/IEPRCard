<?php
declare(strict_types=1);

function cardIssuanceWorkerReadAsset(int $tenantId, string $storedKey): string
{
    $path = cardIssuanceWorkerResolvePath($tenantId, $storedKey);
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 20_000_000) { throw new RuntimeException('ISSUANCE_ASSET_UNAVAILABLE'); }
    return $bytes;
}

function cardIssuanceWorkerStore(int $tenantId, int $jobId, int $item, string $extension, string $bytes): string
{
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_UNAVAILABLE'); }
    if (is_link($dir) || !is_string(realpath($dir)) || !batchImportStagingPathWithin((string) realpath($dir), $root)) {
        throw new RuntimeException('ISSUANCE_STORAGE_UNAVAILABLE');
    }
    $token = substr(hash('sha256', $jobId . ':' . $item . ':' . $extension), 0, 48);
    $path = $dir . DIRECTORY_SEPARATOR . $token . '.' . $extension;
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('ISSUANCE_STORAGE_WRITE_FAILED');
    }
    @chmod($path, 0600);
    return 'private://' . $tenantId . '/' . $token . '.' . $extension;
}

function cardIssuanceWorkerResolvePath(int $tenantId, string $key): string
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $key, $matches) !== 1
        || (int) $matches[1] !== $tenantId) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . $tenantId;
    if (is_link($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $realDir = realpath($dir);
    if (!is_string($realDir) || !batchImportStagingPathWithin($realDir, $root)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $path = $realDir . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path) || !is_file($path) || !batchImportStagingPathWithin((string) realpath($path), $root)) { throw new RuntimeException('ISSUANCE_ASSET_UNAVAILABLE'); }
    return $path;
}

function cardIssuanceWorkerDeleteAsset(int $tenantId, string $key): void
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $key, $matches) !== 1
        || (int) $matches[1] !== $tenantId) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $root = batchImportStagingPrivateRoot();
    $dir = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($dir)) { throw new RuntimeException('ISSUANCE_STORAGE_KEY_INVALID'); }
    $realDir = realpath($dir);
    if (!is_string($realDir) || !batchImportStagingPathWithin($realDir, $root)) { return; }
    $path = $realDir . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (!file_exists($path)) { return; }
    if (is_link($path) || !is_file($path) || !batchImportStagingPathWithin((string) realpath($path), $root) || !unlink($path)) {
        throw new RuntimeException('ISSUANCE_STORAGE_DELETE_FAILED');
    }
}

function cardIssuanceWorkerValidationUrl(PDO $pdo, int $tenantId, string $code): string
{
    $domain = $pdo->prepare(
        "SELECT hostname FROM dominios_tenant WHERE tenant_id = :tenant AND estado = 'ativo' "
        . 'AND verificado_em IS NOT NULL AND tls_validado_em IS NOT NULL ORDER BY tls_validado_em DESC LIMIT 1'
    );
    $domain->execute(['tenant' => $tenantId]);
    $hostname = $domain->fetchColumn();
    $base = is_string($hostname) && preg_match('/^[a-z0-9.-]+$/iD', $hostname) === 1
        ? 'https://' . $hostname
        : rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');
    if ($base === '') { throw new RuntimeException('ISSUANCE_VALIDATION_URL_UNCONFIGURED'); }
    return $base . '/validar/' . $code;
}
