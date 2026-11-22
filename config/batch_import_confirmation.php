<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging.php';
require_once __DIR__ . '/operational_batch_metrics.php';

/** @param array<string, mixed> $identity @param array<string, string> $mapping
 *  @return array{lote_id:int,imported:int}
 */
function batchImportConfirm(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    int $loteId,
    array $mapping,
    ?int $now = null
): array {
    if ($tenantId < 1 || $loteId < 1 || ($subclienteId !== null && $subclienteId < 1)) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }
    $createdCount = 0;
    $sourceKey = null;
    $photoStoredKeys = [];
    $stagingRows = [];
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $subclienteId,
            $loteId,
            $mapping,
            $now,
            &$createdCount,
            &$sourceKey,
            &$photoStoredKeys,
            &$stagingRows
        ): void {
            $lockClause = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql'
                ? ' FOR UPDATE'
                : '';
            $lotQuery = $pdo->prepare(
                'SELECT id, tenant_id, subcliente_id, arquivo_original_url, colunas_detectadas_json, '
                . 'total_registros, status_lote, criado_em FROM lotes_importacao '
                . 'WHERE id = :id AND tenant_id = :tenant LIMIT 1' . $lockClause
            );
            $lotQuery->execute(['id' => $loteId, 'tenant' => $tenantId]);
            $lot = $lotQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($lot) || ($lot['subcliente_id'] === null ? null : (int) $lot['subcliente_id']) !== $subclienteId
                || !titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('IMPORT_LOT_NOT_FOUND');
            }
            if ($lot['status_lote'] !== 'aguardando_mapa') {
                throw new DomainException('IMPORT_LOT_NOT_CONFIRMABLE');
            }
            $sourceKey = is_string($lot['arquivo_original_url']) ? $lot['arquivo_original_url'] : null;
            if ($sourceKey === null || $sourceKey === '') {
                throw new DomainException('IMPORT_FILE_INVALID');
            }
            $stagingRows = batchImportStagingReadRows($pdo, $tenantId, $loteId);
            $detectedColumns = json_decode((string) $lot['colunas_detectadas_json'], true);
            $parsed = [
                'columns' => is_array($detectedColumns) ? $detectedColumns : [],
                'rows' => array_map(static fn(array $staged): array => $staged['row'], $stagingRows),
                'errors' => [],
                'total_rows' => count($stagingRows),
            ];
            if ($parsed['errors'] !== [] || $parsed['total_rows'] < 1
                || $parsed['total_rows'] !== (int) $lot['total_registros']) {
                throw new DomainException('IMPORT_FILE_INVALID');
            }
            if (!is_array($detectedColumns) || !array_is_list($detectedColumns)
                || $detectedColumns !== $parsed['columns']) {
                throw new DomainException('IMPORT_FILE_INVALID');
            }
            $normalizedMapping = batchImportConfirmNormalizeMapping($mapping, $parsed['columns']);

            $records = [];
            $seenCpfs = [];
            foreach ($parsed['rows'] as $row) {
                $input = [];
                foreach ($normalizedMapping as $source => $target) {
                    $input[$target] = $row[$source] ?? null;
                }
                $record = titularCreateNormalize($input);
                if ($record['cpf'] !== null) {
                    if (isset($seenCpfs[$record['cpf']])) {
                        throw new DomainException('TITULAR_CREATE_CPF_CONFLICT');
                    }
                    $seenCpfs[$record['cpf']] = true;
                }
                $records[] = $record;
            }
            $photoMatches = [];
            foreach ($stagingRows as $rowIndex => $stagedRow) {
                if (is_array($stagedRow['photo'])) $photoMatches[$rowIndex] = $stagedRow['photo'];
            }

            motorPermissoesCacheLimpar($pdo);
            if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('TITULAR_CREATE_DENIED');
            }
            titularCreateBatchValidateScope($pdo, $tenantId, $subclienteId, count($records));
            titularCreateBatchValidateTargets($pdo, $tenantId, $records);

            foreach ($records as $recordIndex => $record) {
                $titularId = titularCreateInsert($pdo, $identity, $tenantId, $subclienteId, $record);
                $unmapped = array_diff_key($parsed['rows'][$recordIndex], array_fill_keys(array_keys($normalizedMapping), true));
                if ($unmapped !== []) {
                    $extras = $pdo->prepare('UPDATE titulares SET dados_extras_json = :extras WHERE id = :id AND tenant_id = :tenant');
                    $extras->execute([
                        'extras' => json_encode($unmapped, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'id' => $titularId, 'tenant' => $tenantId,
                    ]);
                }
                $origin = $pdo->prepare(
                    'UPDATE titulares SET lote_origem_id = :lot WHERE id = :id AND tenant_id = :tenant '
                    . 'AND ((subcliente_id = :subclient_match) OR (subcliente_id IS NULL AND :subclient_null IS NULL))'
                );
                $origin->execute([
                    'lot' => $loteId,
                    'id' => $titularId,
                    'tenant' => $tenantId,
                    'subclient_match' => $subclienteId,
                    'subclient_null' => $subclienteId,
                ]);
                if ($origin->rowCount() !== 1) {
                    throw new RuntimeException('TITULAR_CREATE_FAILED');
                }
                if (isset($photoMatches[$recordIndex])) {
                    $photoKey = batchImportPhotoStorePrivate(
                        $tenantId,
                        $photoMatches[$recordIndex]['extension'],
                        $photoMatches[$recordIndex]['bytes']
                    );
                    $photoStoredKeys[] = $photoKey;
                    $photoUpdate = $pdo->prepare(
                        'UPDATE titulares SET foto_url = :photo WHERE id = :id AND tenant_id = :tenant '
                        . 'AND ((subcliente_id = :subclient_match) OR (subcliente_id IS NULL AND :subclient_null IS NULL))'
                    );
                    $photoUpdate->execute([
                        'photo' => $photoKey,
                        'id' => $titularId,
                        'tenant' => $tenantId,
                        'subclient_match' => $subclienteId,
                        'subclient_null' => $subclienteId,
                    ]);
                    if ($photoUpdate->rowCount() !== 1) {
                        throw new RuntimeException('TITULAR_CREATE_FAILED');
                    }
                }
                $stagedRow = $stagingRows[$recordIndex] ?? null;
                if (!is_array($stagedRow) || (int) ($stagedRow['staging_id'] ?? 0) < 1) {
                    throw new RuntimeException('IMPORT_STAGING_DATA_UNAVAILABLE');
                }
                privacySubjectMergeRecordInto($pdo, 'import_row', (int) $stagedRow['staging_id'], 'titular', $titularId);
                $pdo->prepare("DELETE FROM privacy_subject_records WHERE tipo_registro = 'import_row' AND registro_id = :id")
                    ->execute(['id' => $stagedRow['staging_id']]);
                $pdo->prepare('DELETE FROM importacao_linhas_staging WHERE id = :id AND lote_id = :lot')
                    ->execute(['id' => $stagedRow['staging_id'], 'lot' => $loteId]);
            }

            $mappingJson = json_encode($normalizedMapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $update = $pdo->prepare(
                "UPDATE lotes_importacao SET mapa_de_para_usado_json = :mapping, processados_sucesso = :count, "
                . "processados_erro = 0, status_lote = 'concluido', finalizado_em = :finished "
                . "WHERE id = :id AND status_lote = 'aguardando_mapa'"
            );
            $update->execute([
                'mapping' => $mappingJson,
                'count' => count($records),
                'finished' => gmdate('Y-m-d H:i:s', $now ?? time()),
                'id' => $loteId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('IMPORT_LOT_NOT_CONFIRMABLE');
            }
            $createdCount = count($records);
            operationalBatchMetricsRecord($pdo, [
                'demand' => (int) $lot['total_registros'],
                'processed' => $createdCount,
                'duration_per_record_ms' => operationalBatchMetricsDurationPerRecord(
                    (string) $lot['criado_em'],
                    $createdCount,
                    $now ?? time()
                ),
                'queue_depth' => operationalBatchMetricsQueueDepth($pdo),
                'failures' => 0,
            ], $now);
        });
    } catch (Throwable $exception) {
        foreach ($photoStoredKeys as $photoKey) {
            try {
                batchImportPhotoDeletePrivate($tenantId, $photoKey);
            } catch (Throwable) {
                // Keep the original failure; orphan cleanup remains an operational follow-up.
            }
        }
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TITULAR_CREATE_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);
    if (is_string($sourceKey)) {
        try {
            batchImportStagingDeleteStoredFile($tenantId, $sourceKey);
            batchImportPhotoArchiveDeleteForSource($tenantId, $sourceKey);
            $clear = $pdo->prepare(
                "UPDATE lotes_importacao SET arquivo_original_url = NULL "
                . "WHERE id = :id AND tenant_id = :tenant AND status_lote = 'concluido' AND arquivo_original_url = :source"
            );
            $clear->execute(['id' => $loteId, 'tenant' => $tenantId, 'source' => $sourceKey]);
        } catch (Throwable) {
            // The terminal-lot cleanup retries file removal without changing import success.
        }
    }

    return ['lote_id' => $loteId, 'imported' => $createdCount];
}

/** @param array<string, mixed> $identity @return list<int> */
function batchImportLotTitularIds(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    int $loteId
): array {
    if ($tenantId < 1 || $loteId < 1 || ($subclienteId !== null && $subclienteId < 1)
        || !titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }

    $lot = $pdo->prepare(
        'SELECT subcliente_id, status_lote FROM lotes_importacao WHERE id = :id AND tenant_id = :tenant LIMIT 1'
    );
    $lot->execute(['id' => $loteId, 'tenant' => $tenantId]);
    $lotRecord = $lot->fetch(PDO::FETCH_ASSOC);
    if (!is_array($lotRecord)
        || ($lotRecord['subcliente_id'] === null ? null : (int) $lotRecord['subcliente_id']) !== $subclienteId
        || $lotRecord['status_lote'] !== 'concluido') {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }

    $members = $pdo->prepare(
        'SELECT id FROM titulares WHERE lote_origem_id = :lot AND tenant_id = :tenant '
        . 'AND ((subcliente_id = :subclient_match) OR (subcliente_id IS NULL AND :subclient_null IS NULL)) ORDER BY id'
    );
    $members->execute([
        'lot' => $loteId,
        'tenant' => $tenantId,
        'subclient_match' => $subclienteId,
        'subclient_null' => $subclienteId,
    ]);

    return array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
}

/** @param array<string, string> $mapping @param list<string> $columns @return array<string, string> */
function batchImportConfirmNormalizeMapping(array $mapping, array $columns): array
{
    $allowedTargets = ['nome_completo', 'cpf', 'matricula', 'cargo_funcao', 'whatsapp'];
    if ($mapping === [] || count($mapping) > count($allowedTargets)) {
        throw new DomainException('IMPORT_MAPPING_INVALID');
    }
    $normalized = [];
    $seenTargets = [];
    foreach ($mapping as $source => $target) {
        if (!is_string($source) || !in_array($source, $columns, true)
            || !is_string($target) || !in_array($target, $allowedTargets, true)
            || isset($seenTargets[$target])) {
            throw new DomainException('IMPORT_MAPPING_INVALID');
        }
        $normalized[$source] = $target;
        $seenTargets[$target] = true;
    }
    if (!in_array('nome_completo', $normalized, true)) {
        throw new DomainException('IMPORT_MAPPING_INVALID');
    }

    return $normalized;
}
