<?php
declare(strict_types=1);

/** @return array{fixture:array{tenant_id:int,subcliente_id:int,slug:string,hostname:string},plan_id:int,owner_id:int,context:TenantContext,identity:array<string,mixed>} */
function batchImportStagingTestCreateTenant(PDO $pdo, string $suffix): array
{
    $fixture = tenantTestSeed($pdo);
    $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')
        ->execute(['name' => 'Import staging ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)')
        ->execute(['plan' => $planId, 'key' => 'titular.criar']);
    $pdo->prepare('UPDATE tenants SET plano_id = :plan, limite_titulares = 20 WHERE id = :tenant')
        ->execute(['plan' => $planId, 'tenant' => $fixture['tenant_id']]);
    $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    )->execute([
        'tenant' => $fixture['tenant_id'], 'role' => 'titular_cliente', 'name' => 'Import owner',
        'email' => 'import-owner-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
    ]);
    $ownerId = (int) $pdo->lastInsertId();

    return [
        'fixture' => $fixture,
        'plan_id' => $planId,
        'owner_id' => $ownerId,
        'context' => new TenantContext('cliente', $fixture['tenant_id'], null, []),
        'identity' => [
            'id' => $ownerId,
            'tenant_id' => $fixture['tenant_id'],
            'subcliente_id' => null,
            'papel_sistema' => 'titular_cliente',
        ],
    ];
}

function batchImportStagingAssertFailure(callable $operation, string $expectedCode): void
{
    try {
        $operation();
    } catch (DomainException|RuntimeException $exception) {
        if ($exception->getMessage() !== $expectedCode) {
            throw $exception;
        }

        return;
    }

    throw new RuntimeException('The import staging operation was expected to fail with ' . $expectedCode . '.');
}

function batchImportStagingTestAssertNoSymlinkFollowed(
    int $tenantId,
    string $storedKey,
    string $storedPath,
    string $outsidePath,
    string $backupPath,
    string $driver
): void {
    batchImportStagingAssertFailure(
        static fn() => batchImportStagingResolveStoredPath(
            $tenantId,
            'private://' . $tenantId . '/../outside.csv'
        ),
        'IMPORT_STORAGE_KEY_INVALID'
    );
    file_put_contents($outsidePath, 'must not be deleted');
    if (!rename($storedPath, $backupPath)) {
        throw new RuntimeException($driver . ': could not prepare the symlink safety test.');
    }
    try {
        if (function_exists('symlink') && @symlink($outsidePath, $storedPath)) {
            batchImportStagingAssertFailure(
                static fn() => batchImportStagingDeleteStoredFile($tenantId, $storedKey),
                'IMPORT_STORAGE_KEY_INVALID'
            );
            if (file_get_contents($outsidePath) !== 'must not be deleted') {
                throw new RuntimeException($driver . ': storage cleanup must not follow symlinks.');
            }
        }
    } finally {
        if (is_link($storedPath)) {
            unlink($storedPath);
        }
        if (is_file($backupPath)) {
            rename($backupPath, $storedPath);
        }
    }
}

function batchImportStagingTestCreateOversizeFile(string $path, string $driver): void
{
    $handle = fopen($path, 'wb');
    if (!is_resource($handle) || !ftruncate($handle, BATCH_IMPORT_MAX_FILE_BYTES + 1)) {
        throw new RuntimeException($driver . ': could not prepare the oversized import test file.');
    }
    fclose($handle);
}

function batchImportStagingTestRemoveTree(string $path): void
{
    $temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $normalized = rtrim($path, DIRECTORY_SEPARATOR);
    if (!str_starts_with($normalized, $temporaryRoot . 'ieprcard-stage') || !is_dir($normalized) || is_link($normalized)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($normalized);
}
