<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging.php';

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
            &$sourceKey
        ): void {
            $lockClause = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql'
                ? ' FOR UPDATE'
                : '';
            $lotQuery = $pdo->prepare(
                'SELECT id, tenant_id, subcliente_id, arquivo_original_url, colunas_detectadas_json, '
                . 'total_registros, status_lote FROM lotes_importacao '
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

            $path = batchImportStagingResolveStoredPath($tenantId, $sourceKey);
            if (!is_string($path) || !is_file($path)) {
                throw new DomainException('IMPORT_FILE_INVALID');
            }
            try {
                $parsed = batchImportParse($path);
            } catch (DomainException $exception) {
                throw new DomainException('IMPORT_FILE_INVALID', 0, $exception);
            }
            if ($parsed['errors'] !== [] || $parsed['total_rows'] < 1
                || $parsed['total_rows'] !== (int) $lot['total_registros']) {
                throw new DomainException('IMPORT_FILE_INVALID');
            }
            $detectedColumns = json_decode((string) $lot['colunas_detectadas_json'], true);
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

            motorPermissoesCacheLimpar($pdo);
            if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('TITULAR_CREATE_DENIED');
            }
            titularCreateBatchValidateScope($pdo, $tenantId, $subclienteId, count($records));
            titularCreateBatchValidateTargets($pdo, $tenantId, $records);

            foreach ($records as $record) {
                titularCreateInsert($pdo, $identity, $tenantId, $subclienteId, $record);
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
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TITULAR_CREATE_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);
    if (is_string($sourceKey)) {
        try {
            batchImportStagingDeleteStoredFile($tenantId, $sourceKey);
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
