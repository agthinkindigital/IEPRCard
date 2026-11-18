<?php
declare(strict_types=1);

require_once __DIR__ . '/titular_provisioning.php';
require_once __DIR__ . '/batch_import_parser.php';
require_once __DIR__ . '/batch_import_staging_support.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $upload
 *  @return array{lote_id:int,columns:list<string>,preview:list<array<string,string|null>>,errors:list<array{linha:int,campo:string,codigo:string}>,total_rows:int}
 */
function batchImportStageUploadedFile(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    array $upload,
    ?int $now = null
): array {
    $temporaryPath = $upload['tmp_name'] ?? null;
    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath) || !is_string($upload['name'] ?? null)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }

    return batchImportStageFile($pdo, $context, $identity, $tenantId, $subclienteId, $temporaryPath, $upload['name'], $now);
}

/** @param array<string, mixed> $identity
 *  @return array{lote_id:int,columns:list<string>,preview:list<array<string,string|null>>,errors:list<array{linha:int,campo:string,codigo:string}>,total_rows:int}
 */
function batchImportStageFile(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    string $temporaryPath,
    string $originalName,
    ?int $now = null
): array {
    if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
        throw new DomainException('TITULAR_CREATE_DENIED');
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx', 'json'], true)) {
        throw new DomainException('IMPORT_FORMAT_UNSUPPORTED');
    }
    if (!is_file($temporaryPath) || is_link($temporaryPath)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }
    $size = filesize($temporaryPath);
    if (!is_int($size) || $size < 1 || $size > BATCH_IMPORT_MAX_FILE_BYTES) {
        throw new DomainException('IMPORT_FILE_SIZE_INVALID');
    }
    $parseTemporaryPath = tempnam(sys_get_temp_dir(), 'iepr-import-');
    if (!is_string($parseTemporaryPath)) {
        throw new RuntimeException('IMPORT_STAGE_FAILED');
    }
    $parsePath = $parseTemporaryPath . '.' . $extension;
    if (!rename($parseTemporaryPath, $parsePath) || !copy($temporaryPath, $parsePath)) {
        @unlink($parseTemporaryPath);
        @unlink($parsePath);
        throw new RuntimeException('IMPORT_STAGE_FAILED');
    }
    try {
        $parsed = batchImportParse($parsePath);
    } finally {
        @unlink($parsePath);
    }
    $root = batchImportStagingPrivateRoot();
    $tenantDirectory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($tenantDirectory)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    if (!is_dir($tenantDirectory) && !mkdir($tenantDirectory, 0700, true) && !is_dir($tenantDirectory)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    @chmod($tenantDirectory, 0700);
    $realTenantDirectory = realpath($tenantDirectory);
    if (!is_string($realTenantDirectory) || !batchImportStagingPathWithin($realTenantDirectory, $root)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    if (DIRECTORY_SEPARATOR === '/' && ((fileperms($realTenantDirectory) ?: 0) & 0077) !== 0) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    $key = $tenantId . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $destination = $realTenantDirectory . DIRECTORY_SEPARATOR . basename($key);
    if (!copy($temporaryPath, $destination)) {
        @unlink($destination);
        throw new RuntimeException('IMPORT_STAGE_FAILED');
    }
    @chmod($destination, 0600);
    if (DIRECTORY_SEPARATOR === '/' && ((fileperms($destination) ?: 0) & 0077) !== 0) {
        @unlink($destination);
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO lotes_importacao (tenant_id, subcliente_id, usuario_id, origem_tipo, arquivo_original_url, '
            . 'colunas_detectadas_json, total_registros, processados_sucesso, processados_erro, status_lote, criado_em) '
            . 'VALUES (:tenant, :subclient, :user, :origin, :source, :columns, :total, 0, 0, :status, :created_at)'
        );
        $insert->execute([
            'tenant' => $tenantId,
            'subclient' => $subclienteId,
            'user' => (int) $identity['id'],
            'origin' => $extension,
            'source' => 'private://' . $key,
            'columns' => json_encode($parsed['columns'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'total' => $parsed['total_rows'],
            'status' => 'aguardando_mapa',
            'created_at' => gmdate('Y-m-d H:i:s', $now ?? time()),
        ]);
        $lotId = (int) $pdo->lastInsertId();
        if ($lotId < 1) {
            throw new RuntimeException('IMPORT_STAGE_FAILED');
        }
    } catch (Throwable) {
        @unlink($destination);
        throw new RuntimeException('IMPORT_STAGE_FAILED');
    }

    return [
        'lote_id' => $lotId,
        'columns' => $parsed['columns'],
        'preview' => $parsed['preview'],
        'errors' => $parsed['errors'],
        'total_rows' => $parsed['total_rows'],
    ];
}

/** @param array<string, mixed> $identity */
function batchImportStagingCancel(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    int $loteId,
    ?int $now = null
): void {
    $query = $pdo->prepare(
        'SELECT id, tenant_id, subcliente_id, arquivo_original_url, status_lote FROM lotes_importacao '
        . 'WHERE id = :id AND tenant_id = :tenant LIMIT 1'
    );
    $query->execute(['id' => $loteId, 'tenant' => $tenantId]);
    $lot = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($lot) || !titularCreateCanManage(
        $pdo,
        $context,
        $identity,
        $tenantId,
        $lot['subcliente_id'] === null ? null : (int) $lot['subcliente_id']
    )) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }
    if (!in_array($lot['status_lote'], ['aguardando_mapa', 'na_fila'], true)) {
        throw new DomainException('IMPORT_LOT_NOT_CANCELLABLE');
    }
    $update = $pdo->prepare(
        "UPDATE lotes_importacao SET status_lote = 'cancelado', finalizado_em = :finished "
        . 'WHERE id = :id AND status_lote IN (\'aguardando_mapa\', \'na_fila\')'
    );
    $update->execute(['finished' => gmdate('Y-m-d H:i:s', $now ?? time()), 'id' => $loteId]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('IMPORT_LOT_NOT_CANCELLABLE');
    }
    batchImportStagingDeleteStoredFile($tenantId, (string) $lot['arquivo_original_url']);
    $clear = $pdo->prepare('UPDATE lotes_importacao SET arquivo_original_url = NULL WHERE id = :id');
    $clear->execute(['id' => $loteId]);
}

function batchImportStagingCleanupExpired(PDO $pdo, int $now, int $limit = 100): int
{
    if ($limit < 1 || $limit > 500) {
        throw new InvalidArgumentException('IMPORT_CLEANUP_LIMIT_INVALID');
    }
    $cutoff = gmdate('Y-m-d H:i:s', $now - 86400);
    $candidates = $pdo->prepare(
        'SELECT id FROM lotes_importacao WHERE status_lote IN (\'aguardando_mapa\', \'na_fila\') '
        . 'AND criado_em <= :cutoff ORDER BY criado_em, id LIMIT ' . $limit
    );
    $candidates->execute(['cutoff' => $cutoff]);
    $ids = array_map('intval', $candidates->fetchAll(PDO::FETCH_COLUMN));
    $expired = 0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $lockClause = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
            $lotQuery = $pdo->prepare(
                'SELECT tenant_id, arquivo_original_url FROM lotes_importacao '
                . 'WHERE id = :id AND status_lote IN (\'aguardando_mapa\', \'na_fila\') AND criado_em <= :cutoff'
                . $lockClause
            );
            $lotQuery->execute(['id' => $id, 'cutoff' => $cutoff]);
            $lot = $lotQuery->fetch(PDO::FETCH_ASSOC);
            if (is_array($lot)) {
                $update = $pdo->prepare(
                    "UPDATE lotes_importacao SET status_lote = 'expirado', finalizado_em = :finished WHERE id = :id"
                );
                $update->execute(['finished' => gmdate('Y-m-d H:i:s', $now), 'id' => $id]);
                $pdo->commit();
                ++$expired;
                batchImportStagingDeleteStoredFile((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
                $clear = $pdo->prepare(
                    "UPDATE lotes_importacao SET arquivo_original_url = NULL WHERE id = :id AND status_lote = 'expirado'"
                );
                $clear->execute(['id' => $id]);
            } else {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $terminal = $pdo->prepare(
        "SELECT id, tenant_id, arquivo_original_url FROM lotes_importacao "
        . "WHERE (status_lote IN ('cancelado', 'concluido') OR status_lote = 'expirado' AND criado_em <= :cutoff) "
        . 'AND arquivo_original_url IS NOT NULL ORDER BY id LIMIT ' . $limit
    );
    $terminal->execute(['cutoff' => $cutoff]);
    foreach ($terminal->fetchAll(PDO::FETCH_ASSOC) as $lot) {
        batchImportStagingDeleteStoredFile((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
        $clear = $pdo->prepare('UPDATE lotes_importacao SET arquivo_original_url = NULL WHERE id = :id');
        $clear->execute(['id' => (int) $lot['id']]);
    }

    return $expired;
}
