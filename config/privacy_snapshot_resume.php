<?php
declare(strict_types=1);

/**
 * Resumable snapshot staging. Checkpoint files contain minimized table rows and
 * subject-encrypted records only; they are stored below private application storage.
 */

function privacySnapshotResumeDirectory(int $destinationId, string $snapshotId): string
{
    if ($destinationId < 1 || preg_match('/^[a-f0-9]{32}$/D', $snapshotId) !== 1) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_RESUME_ID_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $destinationId . DIRECTORY_SEPARATOR . $snapshotId;
    if (is_link($directory)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STORAGE_INVALID');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STORAGE_UNAVAILABLE');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STORAGE_INVALID');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        @chmod($realDirectory, 0700);
    }
    return $realDirectory;
}

/** @return array<string,mixed> */
function privacySnapshotResumeReadState(string $directory, string $createdAt, string $snapshotId): array
{
    $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
    if (is_link($path)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STATE_INVALID');
    }
    if (!is_file($path)) {
        $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
        $inventory = privacySnapshotAssertReviewedSchema($catalog);
        $state = [
            'version' => 1,
            'snapshot_id' => $snapshotId,
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', strtotime($createdAt) ?: 0),
            'schema_version' => privacySnapshotCurrentSchemaVersion(),
            'schema_inventory' => $inventory,
            'schema_inventory_sha256' => PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256,
            'table_index' => 0,
            'cursor' => 0,
            'high_water_mark' => null,
            'parts' => [],
        ];
        privacySnapshotResumeWriteState($directory, $state);
        return $state;
    }
    $raw = file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    $reviewedInventory = privacySnapshotAssertReviewedSchema($catalog);
    $reviewedTables = array_keys($catalog);
    $validParts = is_array($state['parts'] ?? null);
    foreach ($validParts ? $state['parts'] : [] as $name => $part) {
        if (!is_string($name) || preg_match('/^part-[0-9]{4}-[0-9]+-[0-9]+\.json$/D', $name) !== 1
            || !is_array($part) || !is_int($part['table_index'] ?? null)
            || $part['table_index'] < 0 || $part['table_index'] >= count($reviewedTables)
            || $reviewedTables[$part['table_index']] !== ($part['table'] ?? null)
            || !isset($reviewedInventory[$part['table'] ?? ''])
            || !is_int($part['first_id'] ?? null) || !is_int($part['last_id'] ?? null)
            || $part['first_id'] < 1 || $part['last_id'] < $part['first_id']
            || $name !== sprintf('part-%04d-%010d-%010d.json', $part['table_index'], $part['first_id'], $part['last_id'])
            || !is_int($part['high_water_mark'] ?? null) || $part['high_water_mark'] < $part['last_id']
            || !is_int($part['rows'] ?? null) || $part['rows'] < 1
            || !is_string($part['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $part['sha256']) !== 1) {
            $validParts = false;
            break;
        }
    }
    if (!is_array($state) || ($state['version'] ?? null) !== 1
        || ($state['snapshot_id'] ?? null) !== $snapshotId
        || ($state['created_at'] ?? null) !== gmdate('Y-m-d\\TH:i:s\\Z', strtotime($createdAt) ?: 0)
        || ($state['schema_version'] ?? null) !== privacySnapshotCurrentSchemaVersion()
        || ($state['schema_inventory'] ?? null) !== $reviewedInventory
        || ($state['schema_inventory_sha256'] ?? null) !== PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256
        || !is_int($state['table_index'] ?? null) || $state['table_index'] < 0
        || $state['table_index'] > count($catalog) || !is_int($state['cursor'] ?? null) || $state['cursor'] < 0
        || !(is_int($state['high_water_mark'] ?? null) || ($state['high_water_mark'] ?? null) === null)
        || (is_int($state['high_water_mark'] ?? null) && $state['high_water_mark'] < $state['cursor'])
        || !$validParts) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STATE_INVALID');
    }
    return $state;
}

/** @param array<string,mixed> $state */
function privacySnapshotResumeWriteState(string $directory, array $state): void
{
    $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
        @unlink($temporary);
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STATE_WRITE_FAILED');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        @chmod($temporary, 0600);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STATE_WRITE_FAILED');
    }
}

/** Persist a bounded group of transformed rows as one resumable part. */
function privacySnapshotResumeWritePart(
    string $directory,
    array &$state,
    int $tableIndex,
    string $table,
    array $rows,
    array $envelopes
): int {
    if ($rows === []) {
        return 0;
    }
    $firstId = (int) ($rows[0]['id'] ?? 0);
    $lastId = (int) ($rows[array_key_last($rows)]['id'] ?? 0);
    if ($firstId < 1 || $lastId < $firstId || $firstId <= (int) $state['cursor']) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_CURSOR_INVALID');
    }
    $partName = sprintf('part-%04d-%010d-%010d.json', $tableIndex, $firstId, $lastId);
    $partPath = $directory . DIRECTORY_SEPARATOR . $partName;
    if (is_link($partPath)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
    }
    $partJson = json_encode(
        ['table' => $table, 'rows' => $rows, 'subject_envelopes' => $envelopes],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (strlen($partJson) > 67_108_864) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_PART_TOO_LARGE');
    }
    // A crash after the atomic part rename but before state.json is replaced leaves an orphan.
    // The current cursor proves that this range was not committed, so it is safe to overwrite.
    if (is_file($partPath) && !unlink($partPath)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
    }
    $temporary = $partPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temporary, $partJson, LOCK_EX) !== strlen($partJson)) {
        @unlink($temporary);
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        @chmod($temporary, 0600);
    }
    if (!rename($temporary, $partPath)) {
        @unlink($temporary);
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
    }
    $state['parts'][$partName] = [
        'table_index' => $tableIndex,
        'table' => $table,
        'first_id' => $firstId,
        'last_id' => $lastId,
        'high_water_mark' => (int) $state['high_water_mark'],
        'rows' => count($rows),
        'sha256' => hash('sha256', $partJson),
    ];
    $state['cursor'] = $lastId;
    privacySnapshotResumeWriteState($directory, $state);
    return count($rows);
}

/** @param array<string,mixed> $row @return array{row:array<string,mixed>,envelope:?array{reference:string,ciphertext:string}} */
function privacySnapshotResumeTransformRow(
    PDO $pdo,
    string $table,
    array $row,
    array $policies,
    callable $encryptSubject,
    ?array $subjectLookupMap = null
): array {
    privacySnapshotAssertSupportedArtifacts($table, $row);
    privacySnapshotStripUnrecoverableSecrets($table, $row);
    privacySnapshotMinimizeOperationalRows($table, $row);
    privacySnapshotAssertNoPiiLabel($table, $row);
    if ($table === 'designs_carteirinha') {
        $row['bg_frente_url'] = null;
        $row['bg_verso_url'] = null;
    }
    if ($table === 'lotes_importacao') {
        $row['arquivo_original_url'] = null;
        $row['pdf_consolidado_url'] = null;
    }
    $fields = $policies[$table] ?? [];
    $artifactFields = privacySnapshotArtifactFields()[$table] ?? [];
    $values = [];
    foreach ($fields as $field) {
        if (!in_array($field, $artifactFields, true) && array_key_exists($field, $row)
            && $row[$field] !== null && $row[$field] !== '') {
            $values[$field] = $row[$field];
        }
    }
    $scrubFields = array_keys($values);
    $hasArtifacts = false;
    foreach ($artifactFields as $artifactField) {
        if (isset($row[$artifactField]) && trim((string) $row[$artifactField]) !== '') {
            $scrubFields[] = $artifactField;
            $hasArtifacts = true;
        }
    }
    if ($values === [] && !$hasArtifacts) {
        return ['row' => $row, 'envelope' => null];
    }
    $rowId = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $subjectRecordId = $table === 'dominios_tenant'
        ? filter_var($row['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : $rowId;
    if ($rowId === false || $subjectRecordId === false) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_MISSING');
    }
    $recordType = privacySnapshotRecordType($table);
    if ($subjectLookupMap !== null) {
        $subject = $subjectLookupMap[(int) $subjectRecordId] ?? null;
    } else {
        $lookup = $pdo->prepare(
            'SELECT s.id, s.referencia, s.estado FROM privacy_subject_records r '
            . 'JOIN privacy_subjects s ON s.id = r.privacy_subject_id '
            . 'WHERE r.tipo_registro = :type AND r.registro_id = :record LIMIT 1'
        );
        $lookup->execute(['type' => $recordType, 'record' => $subjectRecordId]);
        $subject = $lookup->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($subject)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_MISSING');
    }
    if (($subject['estado'] ?? null) === 'anonimizado') {
        privacySnapshotReplacePersonalValues($table, (int) $rowId, $row, $scrubFields);
        return ['row' => $row, 'envelope' => null];
    }
    $artifacts = privacySnapshotLoadSubjectArtifacts($table, $row);
    $record = [
        'table' => $table,
        'record_id' => (int) $rowId,
        'fields' => $values,
        'artifacts' => $artifacts,
    ];
    $plaintext = json_encode([
        'version' => 1,
        'reference' => (string) $subject['referencia'],
        'records' => [$record],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($plaintext) > 67_108_864) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_PAYLOAD_TOO_LARGE');
    }
    $ciphertext = $encryptSubject((int) $subject['id'], $plaintext);
    if (!str_starts_with($ciphertext, 'v1:') || str_contains($ciphertext, $plaintext)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_ENCRYPTION_INVALID');
    }
    privacySnapshotReplacePersonalValues($table, (int) $rowId, $row, $scrubFields);
    return [
        'row' => $row,
        'envelope' => ['reference' => (string) $subject['referencia'], 'ciphertext' => $ciphertext],
    ];
}

/**
 * Process a bounded number of rows. Returns true only when every canonical table
 * is staged and the final gzip is ready for upload.
 */
function privacySnapshotResumeStage(
    PDO $pdo,
    string $directory,
    array &$state,
    callable $encryptSubject,
    int $rowLimit = 50,
    float $timeLimitSeconds = 8.0
): bool {
    if ($rowLimit < 1 || $rowLimit > 4000 || $timeLimitSeconds < 1 || $timeLimitSeconds > 30) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_BATCH_LIMIT_INVALID');
    }
    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    $inventory = privacySnapshotAssertReviewedSchema($catalog);
    privacySnapshotAssertDatabaseSchema($pdo, $inventory);
    $tableNames = array_keys($catalog);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $policies = privacySnapshotPersonalFields();
    $artifactPolicies = privacySnapshotArtifactFields();
    $used = 0;
    $deadline = microtime(true) + $timeLimitSeconds;
    while ($state['table_index'] < count($tableNames) && $used < $rowLimit && microtime(true) < $deadline) {
        $tableIndex = (int) $state['table_index'];
        $table = $tableNames[$tableIndex];
        if (in_array($table, privacySnapshotOmittedTables(), true)) {
            ++$state['table_index'];
            $state['cursor'] = 0;
            $state['high_water_mark'] = null;
            continue;
        }
        if ($state['high_water_mark'] === null) {
            $maximum = $pdo->query('SELECT MAX(id) FROM ' . privacySnapshotQuoteIdentifier($table, $driver))->fetchColumn();
            if ($maximum === false || $maximum === null) {
                ++$state['table_index'];
                $state['cursor'] = 0;
                $state['high_water_mark'] = null;
                privacySnapshotResumeWriteState($directory, $state);
                continue;
            }
            $state['high_water_mark'] = (int) $maximum;
            privacySnapshotResumeWriteState($directory, $state);
        }
        $queryLimit = min(50, $rowLimit - $used);
        $subjectLookupMap = [];
        if (($policies[$table] ?? []) !== [] || ($artifactPolicies[$table] ?? []) !== []) {
            $identifierColumns = $table === 'dominios_tenant' ? 'id, tenant_id' : 'id';
            $identifierSql = 'SELECT ' . $identifierColumns . ' FROM ' . privacySnapshotQuoteIdentifier($table, $driver)
                . ' WHERE id > :cursor AND id <= :high_water ORDER BY id LIMIT ' . $queryLimit;
            $identifierQuery = $pdo->prepare($identifierSql);
            $identifierQuery->execute(['cursor' => (int) $state['cursor'], 'high_water' => (int) $state['high_water_mark']]);
            $subjectRecordIds = [];
            while (($identifier = $identifierQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
                $recordId = $table === 'dominios_tenant' ? ($identifier['tenant_id'] ?? null) : ($identifier['id'] ?? null);
                $validRecordId = filter_var($recordId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($validRecordId === false) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_MISSING');
                }
                $subjectRecordIds[] = (int) $validRecordId;
            }
            $subjectRecordIds = array_values(array_unique($subjectRecordIds, SORT_NUMERIC));
            if ($subjectRecordIds !== []) {
                $parameters = ['type' => privacySnapshotRecordType($table)];
                $placeholders = [];
                foreach ($subjectRecordIds as $index => $recordId) {
                    $parameter = 'record_' . $index;
                    $placeholders[] = ':' . $parameter;
                    $parameters[$parameter] = $recordId;
                }
                $subjectSql = 'SELECT r.registro_id, s.id, s.referencia, s.estado FROM privacy_subject_records r '
                    . 'JOIN privacy_subjects s ON s.id = r.privacy_subject_id '
                    . 'WHERE r.tipo_registro = :type AND r.registro_id IN (' . implode(', ', $placeholders) . ')';
                $subjectQuery = $pdo->prepare($subjectSql);
                $subjectQuery->execute($parameters);
                while (($subject = $subjectQuery->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $recordId = (int) $subject['registro_id'];
                    if (isset($subjectLookupMap[$recordId])) {
                        throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_DUPLICATE');
                    }
                    $subjectLookupMap[$recordId] = [
                        'id' => (int) $subject['id'],
                        'referencia' => (string) $subject['referencia'],
                        'estado' => (string) $subject['estado'],
                    ];
                }
            }
        }
        $sql = 'SELECT * FROM ' . privacySnapshotQuoteIdentifier($table, $driver)
            . ' WHERE id > :cursor AND id <= :high_water ORDER BY id LIMIT ' . $queryLimit;
        $query = $pdo->prepare($sql);
        $query->execute(['cursor' => (int) $state['cursor'], 'high_water' => (int) $state['high_water_mark']]);
        $firstRow = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($firstRow)) {
            ++$state['table_index'];
            $state['cursor'] = 0;
            $state['high_water_mark'] = null;
            privacySnapshotResumeWriteState($directory, $state);
            continue;
        }
        $rows = [];
        $envelopes = [];
        $chunkBytes = 0;
        $currentRow = $firstRow;
        do {
            $row = $currentRow;
            $transformed = privacySnapshotResumeTransformRow($pdo, $table, $row, $policies, $encryptSubject, $subjectLookupMap);
            $entryBytes = strlen(json_encode(
                ['row' => $transformed['row'], 'envelope' => $transformed['envelope']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            if ($rows !== [] && ($chunkBytes + $entryBytes > 8_388_608 || count($rows) >= 50)) {
                $used += privacySnapshotResumeWritePart($directory, $state, $tableIndex, $table, $rows, $envelopes);
                $rows = [];
                $envelopes = [];
                $chunkBytes = 0;
            }
            $rows[] = $transformed['row'];
            if ($transformed['envelope'] !== null) {
                $envelopes[] = $transformed['envelope'];
            }
            $chunkBytes += $entryBytes;
            if (count($rows) >= 50 || $chunkBytes >= 8_388_608 || $used + count($rows) >= $rowLimit
                || microtime(true) >= $deadline) {
                $used += privacySnapshotResumeWritePart($directory, $state, $tableIndex, $table, $rows, $envelopes);
                $rows = [];
                $envelopes = [];
                $chunkBytes = 0;
                if ($used >= $rowLimit || microtime(true) >= $deadline) {
                    break;
                }
            }
            $currentRow = $query->fetch(PDO::FETCH_ASSOC);
        } while (is_array($currentRow));
        if ($rows !== []) {
            $used += privacySnapshotResumeWritePart($directory, $state, $tableIndex, $table, $rows, $envelopes);
        }
    }
    return $state['table_index'] >= count($tableNames);
}

/** Stream staged parts into a complete gzip without assembling the database in memory. */
function privacySnapshotResumeFinalize(string $directory, array $state): array
{
    $parts = $state['parts'];
    uasort($parts, static fn(array $a, array $b): int => [$a['table_index'], $a['first_id']] <=> [$b['table_index'], $b['first_id']]);
    $manifest = [
        'format' => 'ieprcard-snapshot-v3',
        'schema_version' => $state['schema_version'],
        'snapshot_id' => $state['snapshot_id'],
        'created_at' => $state['created_at'],
        'table_count' => count($state['schema_inventory']),
        'schema_inventory' => $state['schema_inventory'],
        'schema_inventory_sha256' => $state['schema_inventory_sha256'],
        'ledger_version' => 1,
        'parts' => array_values($parts),
        'part_count' => count($parts),
    ];
    $manifest['parts_sha256'] = hash('sha256', json_encode(array_map(
        static fn(array $part): array => [$part['table'], $part['first_id'], $part['last_id'], $part['sha256']],
        array_values($parts)
    ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $payloadPath = $directory . DIRECTORY_SEPARATOR . 'payload.json';
    if (is_link($payloadPath)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
    }
    $payload = fopen($payloadPath, 'wb');
    if (!is_resource($payload)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        @chmod($payloadPath, 0600);
    }
    $writePayload = static function (string $bytes) use ($payload): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($payload, substr($bytes, $offset, 65_536));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
            }
            $offset += $written;
        }
    };
    try {
        $writePayload('"tables":{');
        $tables = array_keys($state['schema_inventory']);
        foreach ($tables as $tableIndex => $table) {
            if ($tableIndex > 0) $writePayload(',');
            $writePayload(json_encode($table, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ':[');
            $first = true;
            foreach ($parts as $partMeta) {
                if ($partMeta['table'] !== $table) continue;
                $matchingName = array_search($partMeta, $state['parts'], true);
                $path = is_string($matchingName) ? $directory . DIRECTORY_SEPARATOR . basename($matchingName) : '';
                $partJson = $path !== '' && is_file($path) && !is_link($path) ? file_get_contents($path) : false;
                if (!is_string($partJson) || !hash_equals((string) $partMeta['sha256'], hash('sha256', $partJson))) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
                }
                $part = json_decode($partJson, true);
                if (!is_array($part) || !is_array($part['rows'] ?? null)) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
                }
                foreach ($part['rows'] as $row) {
                    if (!$first) $writePayload(',');
                    $writePayload(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
            }
            $writePayload(']');
        }
        $writePayload('},"subject_envelopes":[');
        $first = true;
        foreach ($parts as $partMeta) {
            $matchingName = array_search($partMeta, $state['parts'], true);
            $path = is_string($matchingName) ? $directory . DIRECTORY_SEPARATOR . basename($matchingName) : '';
            $partJson = $path !== '' && is_file($path) && !is_link($path) ? file_get_contents($path) : false;
            if (!is_string($partJson) || !hash_equals((string) $partMeta['sha256'], hash('sha256', $partJson))) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
            }
            $part = json_decode($partJson, true);
            foreach (($part['subject_envelopes'] ?? []) as $envelope) {
                if (!$first) $writePayload(',');
                $writePayload(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $first = false;
            }
        }
        $writePayload(']');
    } finally {
        fclose($payload);
    }
    $payloadSize = filesize($payloadPath);
    $payloadHashContext = hash_init('sha256');
    hash_update($payloadHashContext, '{');
    $payloadStream = fopen($payloadPath, 'rb');
    if (!is_resource($payloadStream)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
    }
    try {
        while (!feof($payloadStream)) {
            $chunk = fread($payloadStream, 65_536);
            if (!is_string($chunk) || ($chunk === '' && !feof($payloadStream))) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_WRITE_FAILED');
            }
            if ($chunk !== '') hash_update($payloadHashContext, $chunk);
        }
    } finally {
        fclose($payloadStream);
    }
    hash_update($payloadHashContext, '}');
    $payloadHash = hash_final($payloadHashContext);
    if (!is_int($payloadSize) || $payloadSize < 1 || $payloadSize > 536_870_912) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_TOO_LARGE');
    }
    $manifest['payload_sha256'] = $payloadHash;
    $gzipPath = $directory . DIRECTORY_SEPARATOR . 'snapshot.json.gz';
    if (is_link($gzipPath)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_PART_INVALID');
    }
    $output = gzopen($gzipPath, 'wb6');
    if (!is_resource($output)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
    }
    $write = static function (string $bytes) use ($output): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = gzwrite($output, substr($bytes, $offset, 65_536));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
            }
            $offset += $written;
        }
    };
    try {
        $write('{"manifest":' . json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',');
        $payload = fopen($payloadPath, 'rb');
        if (!is_resource($payload)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
        }
        try {
            while (!feof($payload)) {
                $chunk = fread($payload, 65_536);
                if (!is_string($chunk) || ($chunk === '' && !feof($payload))) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
                }
                if ($chunk !== '') $write($chunk);
            }
        } finally {
            fclose($payload);
        }
        $write('}');
    } finally {
        if (!gzclose($output)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
        }
    }
    $size = filesize($gzipPath);
    if (!is_int($size) || $size < 1 || $size > 536_870_912) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_TOO_LARGE');
    }
    $gzipHash = hash_file('sha256', $gzipPath);
    if (!is_string($gzipHash)) throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
    return ['path' => $gzipPath, 'sha256' => $gzipHash, 'size' => $size, 'manifest' => $manifest];
}

function privacySnapshotResumeDelete(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) return;
    $root = batchImportStagingPrivateRoot();
    $real = realpath($directory);
    if (!is_string($real) || !batchImportStagingPathWithin($real, $root) || $real === $root) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STORAGE_INVALID');
    }
    $entries = scandir($real);
    if (!is_array($entries)) throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_STORAGE_INVALID');
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $real . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_CLEANUP_FAILED');
        }
    }
    if (!rmdir($real)) throw new RuntimeException('PRIVACY_SNAPSHOT_RESUME_CLEANUP_FAILED');
}
