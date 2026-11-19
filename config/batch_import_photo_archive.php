<?php
declare(strict_types=1);

const BATCH_IMPORT_PHOTO_MAX_ARCHIVE_BYTES = 10_485_760;
const BATCH_IMPORT_PHOTO_MAX_ENTRIES = 500;
const BATCH_IMPORT_PHOTO_MAX_EXPANDED_BYTES = 31_457_280;
const BATCH_IMPORT_PHOTO_MAX_FILE_BYTES = 5_242_880;
const BATCH_IMPORT_PHOTO_MAX_PIXELS = 12_000_000;

function batchImportPhotoArchiveKey(int $tenantId, string $sourceKey): string
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(csv|xlsx|json)$/D', $sourceKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }

    return 'private://' . $tenantId . '/' . $matches[2] . '.photos.zip';
}

function batchImportPhotoArchiveResolvePath(int $tenantId, string $sourceKey): string
{
    $archiveKey = batchImportPhotoArchiveKey($tenantId, $sourceKey);
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.photos\.zip$/D', $archiveKey, $matches) !== 1) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($directory)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.photos.zip';
    if (is_link($path)) {
        throw new RuntimeException('IMPORT_STORAGE_KEY_INVALID');
    }

    return $path;
}

function batchImportPhotoArchiveDeleteForSource(int $tenantId, string $sourceKey): void
{
    $path = batchImportPhotoArchiveResolvePath($tenantId, $sourceKey);
    if (!file_exists($path)) {
        return;
    }
    $realPath = realpath($path);
    $root = batchImportStagingPrivateRoot();
    if (is_link($path) || !is_string($realPath) || !is_file($realPath)
        || !batchImportStagingPathWithin($realPath, $root) || !unlink($realPath)) {
        throw new RuntimeException('IMPORT_STORAGE_DELETE_FAILED');
    }
}

/** @param list<array{cpf:?string,matricula:?string}> $records @return array<int,array{extension:string,bytes:string}> */
function batchImportPhotoArchiveReadMatches(string $archivePath, array $records): array
{
    if (!class_exists(ZipArchive::class) || !is_file($archivePath) || is_link($archivePath)) {
        throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
    }
    $archiveSize = filesize($archivePath);
    if (!is_int($archiveSize) || $archiveSize < 1 || $archiveSize > BATCH_IMPORT_PHOTO_MAX_ARCHIVE_BYTES) {
        throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
        throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
    }

    $cpfIndexes = [];
    $registrationIndexes = [];
    foreach ($records as $index => $record) {
        if (is_string($record['cpf'] ?? null)) {
            $cpfIndexes[$record['cpf']][] = $index;
        }
        if (is_string($record['matricula'] ?? null)) {
            $registrationIndexes[mb_strtolower(trim($record['matricula']), 'UTF-8')][] = $index;
        }
    }

    $matches = [];
    $expandedBytes = 0;
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > BATCH_IMPORT_PHOTO_MAX_ENTRIES) {
            throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
        }
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entry = $zip->statIndex($index);
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $name = $entry['name'];
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
                || str_starts_with($name, '.') || str_contains($name, ':')) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
                }
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $size = $entry['size'] ?? null;
            if (!is_int($size) || $size < 1 || $size > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $expandedBytes += $size;
            if ($expandedBytes > BATCH_IMPORT_PHOTO_MAX_EXPANDED_BYTES) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }

            $stem = pathinfo($name, PATHINFO_FILENAME);
            if ($stem === '' || preg_match('//u', $stem) !== 1) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $matchesForStem = [];
            $cpf = preg_match('/^[0-9.\-\s]+$/D', $stem) === 1
                ? preg_replace('/[.\-\s]/', '', $stem)
                : null;
            if (is_string($cpf) && strlen($cpf) === 11) {
                $matchesForStem = array_merge($matchesForStem, $cpfIndexes[$cpf] ?? []);
            }
            $registration = mb_strtolower(trim($stem), 'UTF-8');
            $matchesForStem = array_merge($matchesForStem, $registrationIndexes[$registration] ?? []);
            $matchesForStem = array_values(array_unique($matchesForStem));
            if (count($matchesForStem) !== 1 || isset($matches[$matchesForStem[0]])) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }

            $bytes = $zip->getFromIndex($index, BATCH_IMPORT_PHOTO_MAX_FILE_BYTES + 1);
            if (!is_string($bytes) || strlen($bytes) !== $size || strlen($bytes) > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $imageInfo = @getimagesizefromstring($bytes);
            if (!is_array($imageInfo) || !is_int($imageInfo[0] ?? null) || !is_int($imageInfo[1] ?? null)
                || $imageInfo[0] < 1 || $imageInfo[1] < 1
                || $imageInfo[0] * $imageInfo[1] > BATCH_IMPORT_PHOTO_MAX_PIXELS) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $expectedTypes = [
                'jpg' => IMAGETYPE_JPEG,
                'jpeg' => IMAGETYPE_JPEG,
                'png' => IMAGETYPE_PNG,
                'webp' => IMAGETYPE_WEBP,
            ];
            if (($imageInfo[2] ?? null) !== $expectedTypes[$extension]) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            $decoded = @imagecreatefromstring($bytes);
            if (!$decoded instanceof GdImage) {
                throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
            }
            imagedestroy($decoded);
            $matches[$matchesForStem[0]] = ['extension' => $extension, 'bytes' => $bytes];
        }
    } finally {
        $zip->close();
    }

    return $matches;
}

function batchImportPhotoStorePrivate(int $tenantId, string $extension, string $bytes): string
{
    if ($tenantId < 1 || !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
        || strlen($bytes) < 1 || strlen($bytes) > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_FAILED');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($directory) || !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_FAILED');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_FAILED');
    }
    $token = bin2hex(random_bytes(24));
    $path = $realDirectory . DIRECTORY_SEPARATOR . $token . '.' . $extension;
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_FAILED');
    }
    @chmod($path, 0600);

    return 'private://' . $tenantId . '/' . $token . '.' . $extension;
}

function batchImportPhotoDeletePrivate(int $tenantId, string $storedKey): void
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp)$/D', $storedKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!file_exists($directory) && !is_link($directory)) {
        return;
    }
    if (is_link($directory)) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_KEY_INVALID');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_KEY_INVALID');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (!file_exists($path)) {
        return;
    }
    $realPath = realpath($path);
    if (is_link($path) || !is_string($realPath) || !is_file($realPath)
        || !batchImportStagingPathWithin($realPath, $root) || !unlink($realPath)) {
        throw new RuntimeException('IMPORT_PHOTO_STORAGE_DELETE_FAILED');
    }
}
