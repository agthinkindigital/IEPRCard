<?php
declare(strict_types=1);

require_once __DIR__ . '/titular_provisioning.php';
require_once __DIR__ . '/batch_import_parser.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/batch_import_photo_archive.php';
require_once __DIR__ . '/operational_batch_metrics.php';
require_once __DIR__ . '/ai_global_config.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/titular_privacy_keys.php';

/** @param array<string,mixed> $payload */
function batchImportStagingSealPayload(array $payload): string
{
    return aiGlobalConfigSeal(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @return array<string,mixed> */
function batchImportStagingOpenPayload(string $ciphertext): array
{
    try {
        $payload = json_decode(aiGlobalConfigOpen($ciphertext), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new DomainException('IMPORT_STAGING_DATA_UNAVAILABLE', 0, $exception);
    }
    if (!is_array($payload)) throw new DomainException('IMPORT_STAGING_DATA_UNAVAILABLE');
    return $payload;
}

/** @return list<array{staging_id:int,subject_id:int,row:array<string,string|null>,photo:?array{extension:string,bytes:string}}> */
function batchImportStagingReadRows(PDO $pdo, int $tenantId, int $lotId): array
{
    $query = $pdo->prepare('SELECT id, privacy_subject_id, dados_cifrados, foto_cifrada, foto_extensao FROM importacao_linhas_staging WHERE tenant_id = :tenant AND lote_id = :lot ORDER BY indice_linha');
    $query->execute(['tenant' => $tenantId, 'lot' => $lotId]);
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $stored) {
        $payload = batchImportStagingOpenPayload((string) $stored['dados_cifrados']);
        $row = $payload['row'] ?? null;
        if (!is_array($row)) throw new DomainException('IMPORT_STAGING_DATA_UNAVAILABLE');
        $photo = null;
        if (is_string($stored['foto_cifrada'] ?? null) && $stored['foto_cifrada'] !== '') {
            $photoBytes = base64_decode(aiGlobalConfigOpen($stored['foto_cifrada']), true);
            $extension = $stored['foto_extensao'] ?? null;
            if (!is_string($photoBytes) || !is_string($extension) || !in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new DomainException('IMPORT_STAGING_DATA_UNAVAILABLE');
            }
            $photo = ['extension' => $extension, 'bytes' => $photoBytes];
        }
        $rows[] = ['staging_id' => (int) $stored['id'], 'subject_id' => (int) $stored['privacy_subject_id'], 'row' => $row, 'photo' => $photo];
    }
    return $rows;
}

function batchImportStagingEraseRows(PDO $pdo, int $tenantId, int $lotId): void
{
    $query = $pdo->prepare('SELECT id, privacy_subject_id FROM importacao_linhas_staging WHERE tenant_id = :tenant AND lote_id = :lot ORDER BY id');
    $query->execute(['tenant' => $tenantId, 'lot' => $lotId]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $subjectId = (int) $row['privacy_subject_id'];
        privacySubjectEraseBackupKey($pdo, $subjectId);
        $pdo->prepare("DELETE FROM privacy_subject_records WHERE tipo_registro = 'import_row' AND registro_id = :id")
            ->execute(['id' => $row['id']]);
        $pdo->prepare("UPDATE privacy_subjects SET estado = 'anonimizado', anonimizado_em = :at WHERE id = :id")
            ->execute(['at' => gmdate('Y-m-d H:i:s'), 'id' => $subjectId]);
    }
    $pdo->prepare('DELETE FROM importacao_linhas_staging WHERE tenant_id = :tenant AND lote_id = :lot')
        ->execute(['tenant' => $tenantId, 'lot' => $lotId]);
}

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
    ?int $now = null,
    ?array $photoUpload = null
): array {
    $temporaryPath = $upload['tmp_name'] ?? null;
    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath) || !is_string($upload['name'] ?? null)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }

    $photoTemporaryPath = null;
    if ($photoUpload !== null) {
        $photoTemporaryPath = $photoUpload['tmp_name'] ?? null;
        if (($photoUpload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($photoTemporaryPath)
            || !is_uploaded_file($photoTemporaryPath) || !is_string($photoUpload['name'] ?? null)
            || strtolower(pathinfo($photoUpload['name'], PATHINFO_EXTENSION)) !== 'zip') {
            throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
        }
    }

    return batchImportStageFile(
        $pdo,
        $context,
        $identity,
        $tenantId,
        $subclienteId,
        $temporaryPath,
        $upload['name'],
        $now,
        $photoTemporaryPath,
        is_string($photoUpload['name'] ?? null) ? $photoUpload['name'] : null
    );
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
    ?int $now = null,
    ?string $photoArchivePath = null,
    ?string $photoArchiveOriginalName = null
): array {
    if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
        throw new DomainException('TITULAR_CREATE_DENIED');
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xls', 'xlsx', 'json'], true)) {
        throw new DomainException('IMPORT_FORMAT_UNSUPPORTED');
    }
    if (!is_file($temporaryPath) || is_link($temporaryPath)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }
    $size = filesize($temporaryPath);
    if (!is_int($size) || $size < 1 || $size > BATCH_IMPORT_MAX_FILE_BYTES) {
        throw new DomainException('IMPORT_FILE_SIZE_INVALID');
    }
    if ($photoArchivePath !== null) {
        $photoSize = is_file($photoArchivePath) && !is_link($photoArchivePath) ? filesize($photoArchivePath) : false;
        $photoExtension = is_string($photoArchiveOriginalName)
            ? pathinfo($photoArchiveOriginalName, PATHINFO_EXTENSION)
            : pathinfo($photoArchivePath, PATHINFO_EXTENSION);
        if (strtolower($photoExtension) !== 'zip'
            || !is_int($photoSize) || $photoSize < 1 || $photoSize > BATCH_IMPORT_PHOTO_MAX_ARCHIVE_BYTES) {
            throw new DomainException('IMPORT_PHOTO_ARCHIVE_INVALID');
        }
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
    if (is_link($tenantDirectory) || !is_dir($tenantDirectory) && !mkdir($tenantDirectory, 0700, true) && !is_dir($tenantDirectory)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    @chmod($tenantDirectory, 0700);
    $realTenantDirectory = realpath($tenantDirectory);
    if (!is_string($realTenantDirectory) || !batchImportStagingPathWithin($realTenantDirectory, $root)) {
        throw new RuntimeException('IMPORT_STORAGE_UNAVAILABLE');
    }
    $photoMatches = $photoArchivePath === null ? [] : batchImportPhotoArchiveReadMatches($photoArchivePath, $parsed['rows']);
    $token = bin2hex(random_bytes(24));
    $key = $tenantId . '/' . $token . '.' . $extension;
    $storedSourceKey = 'private://' . $key;

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
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
            'source' => $storedSourceKey,
            'columns' => json_encode($parsed['columns'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'total' => $parsed['total_rows'],
            'status' => 'aguardando_mapa',
            'created_at' => gmdate('Y-m-d H:i:s', $now ?? time()),
        ]);
        $lotId = (int) $pdo->lastInsertId();
        if ($lotId < 1) {
            throw new RuntimeException('IMPORT_STAGE_FAILED');
        }
        $insertRow = $pdo->prepare(
            'INSERT INTO importacao_linhas_staging (tenant_id, lote_id, indice_linha, privacy_subject_id, dados_cifrados, foto_cifrada, foto_extensao, criado_em) '
            . 'VALUES (:tenant, :lot, :index, :subject, :payload, :photo, :extension, :created)'
        );
        $createdAt = gmdate('Y-m-d H:i:s', $now ?? time());
        foreach ($parsed['rows'] as $index => $row) {
            $subject = privacySubjectCreate($pdo, 'pessoa', $tenantId, $createdAt);
            $insertRow->execute([
                'tenant' => $tenantId, 'lot' => $lotId, 'index' => $index + 1,
                'subject' => $subject['id'], 'payload' => batchImportStagingSealPayload(['row' => $row]),
                'photo' => isset($photoMatches[$index]) ? aiGlobalConfigSeal(base64_encode($photoMatches[$index]['bytes'])) : null,
                'extension' => $photoMatches[$index]['extension'] ?? null, 'created' => $createdAt,
            ]);
            privacySubjectLinkRecord($pdo, (int) $subject['id'], 'import_row', (int) $pdo->lastInsertId());
        }
        operationalBatchMetricsRecord($pdo, [
            'demand' => $parsed['total_rows'],
            'processed' => 0,
            'duration_per_record_ms' => 0,
            'queue_depth' => operationalBatchMetricsQueueDepth($pdo),
            'failures' => count($parsed['errors']),
        ], $now);
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('IMPORT_STAGE_FAILED', 0, $exception);
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
    batchImportStagingEraseRows($pdo, $tenantId, $loteId);
    $update = $pdo->prepare(
        "UPDATE lotes_importacao SET status_lote = 'cancelado', finalizado_em = :finished "
        . 'WHERE id = :id AND status_lote IN (\'aguardando_mapa\', \'na_fila\')'
    );
    $update->execute(['finished' => gmdate('Y-m-d H:i:s', $now ?? time()), 'id' => $loteId]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('IMPORT_LOT_NOT_CANCELLABLE');
    }
    batchImportStagingDeleteStoredFile($tenantId, (string) $lot['arquivo_original_url']);
    batchImportPhotoArchiveDeleteForSource($tenantId, (string) $lot['arquivo_original_url']);
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
        'SELECT id FROM lotes_importacao WHERE origem_tipo IN (\'csv\', \'xls\', \'xlsx\', \'json\') '
        . 'AND status_lote IN (\'aguardando_mapa\', \'na_fila\') '
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
                . 'WHERE id = :id AND origem_tipo IN (\'csv\', \'xls\', \'xlsx\', \'json\') '
                . 'AND status_lote IN (\'aguardando_mapa\', \'na_fila\') AND criado_em <= :cutoff'
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
                batchImportStagingEraseRows($pdo, (int) $lot['tenant_id'], $id);
                batchImportStagingDeleteStoredFile((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
                batchImportPhotoArchiveDeleteForSource((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
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
        batchImportStagingEraseRows($pdo, (int) $lot['tenant_id'], (int) $lot['id']);
        batchImportStagingDeleteStoredFile((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
        batchImportPhotoArchiveDeleteForSource((int) $lot['tenant_id'], (string) $lot['arquivo_original_url']);
        $clear = $pdo->prepare('UPDATE lotes_importacao SET arquivo_original_url = NULL WHERE id = :id');
        $clear->execute(['id' => (int) $lot['id']]);
    }

    return $expired;
}
