<?php
declare(strict_types=1);

function batchImportStagingResolveStoredPath(int $tenantId, string $storedKey): ?string
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(csv|xlsx|json)$/D', $storedKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $tenantDirectory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($tenantDirectory)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $realTenantDirectory = realpath($tenantDirectory);
    if (!is_string($realTenantDirectory) || !batchImportStagingPathWithin($realTenantDirectory, $root)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $path = $realTenantDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }

    return $path;
}

function batchImportStagingDeleteStoredFile(int $tenantId, string $storedKey): bool
{
    $path = batchImportStagingResolveStoredPath($tenantId, $storedKey);
    if ($path === null || !file_exists($path)) {
        return true;
    }
    $realPath = realpath($path);
    $root = batchImportStagingPrivateRoot();
    if (!is_string($realPath) || !is_file($realPath) || is_link($path)
        || !batchImportStagingPathWithin($realPath, $root) || !unlink($realPath)) {
        throw new RuntimeException('IMPORT_STORAGE_DELETE_FAILED');
    }

    return true;
}

function batchImportStagingPrivateRoot(): string
{
    $configured = getenv('IEPR_PRIVATE_STORAGE_PATH');
    $path = $configured === false || trim($configured) === ''
        ? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'private'
        : trim($configured);
    if (!batchImportStagingPathIsAbsolute($path) || is_link($path)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    $root = realpath($path);
    $public = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if (!is_string($root) || !is_string($public) || !is_writable($root)
        || batchImportStagingPathWithin($root, $public)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    @chmod($root, 0700);
    if (DIRECTORY_SEPARATOR === '/' && ((fileperms($root) ?: 0) & 0077) !== 0) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }

    return $root;
}

function batchImportStagingPathWithin(string $path, string $parent): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $parent = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $parent = strtolower($parent);
    }

    return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
}

function batchImportStagingPathIsAbsolute(string $path): bool
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
}
