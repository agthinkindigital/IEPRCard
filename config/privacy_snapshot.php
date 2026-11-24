<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/schema/catalog.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/titular_privacy_keys.php';
require_once __DIR__ . '/privacy_snapshot_labels.php';
require_once __DIR__ . '/privacy_snapshot_resume.php';

const PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256 = '423f44509a5bfb5707e884ffdd13df1dd11aace11e14249a39ae6ccb2a3ba503';

/** Tables containing snapshot-external key material or support authorization state. */
function privacySnapshotOmittedTables(): array
{
    return ['titular_chaves_backup', 'mcp_support_grant_targets', 'mcp_support_github_config'];
}

function privacySnapshotCurrentSchemaVersion(): string
{
    $versions = glob(dirname(__DIR__) . '/database/schema/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [];
    sort($versions, SORT_STRING);
    $latest = $versions === [] ? null : basename($versions[array_key_last($versions)]);
    if (!is_string($latest) || preg_match('/^\d{4}$/D', $latest) !== 1) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_SCHEMA_VERSION_UNAVAILABLE');
    }
    return $latest;
}

/** @param array<string,array<string,mixed>> $catalog @return array<string,list<string>> */
function privacySnapshotAssertReviewedSchema(array $catalog): array
{
    ksort($catalog, SORT_STRING);
    $canonicalColumns = [];
    $inventory = [];
    foreach ($catalog as $table => $definition) {
        $columns = $definition['columns'] ?? null;
        if (!is_array($columns) || $columns === []) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED');
        }
        ksort($columns, SORT_STRING);
        $canonicalColumns[$table] = $columns;
        $inventory[$table] = array_keys($columns);
    }
    $signature = hash('sha256', json_encode(
        $canonicalColumns,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    if (!hash_equals(PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256, $signature)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED');
    }
    foreach (privacySnapshotPersonalFields() as $table => $fields) {
        if (!isset($inventory[$table]) || array_diff($fields, $inventory[$table]) !== []) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED');
        }
    }
    foreach (privacySnapshotArtifactFields() as $table => $fields) {
        if (!isset($inventory[$table]) || array_diff($fields, $inventory[$table]) !== []) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED');
        }
    }
    return $inventory;
}

/** @param array<string,list<string>> $inventory */
function privacySnapshotAssertDatabaseSchema(PDO $pdo, array $inventory): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach ($inventory as $table => $expectedColumns) {
        if ($driver === 'sqlite') {
            $statement = $pdo->query('PRAGMA table_info(' . privacySnapshotQuoteIdentifier($table, $driver) . ')');
            $actualColumns = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name');
        } elseif ($driver === 'mysql') {
            $statement = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            $actualColumns = $statement->fetchAll(PDO::FETCH_COLUMN);
        } else {
            throw new RuntimeException('PRIVACY_SNAPSHOT_DATABASE_SCHEMA_UNREVIEWED');
        }
        sort($actualColumns, SORT_STRING);
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_DATABASE_SCHEMA_UNREVIEWED');
        }
    }
}

/** @return array<string,list<string>> */
function privacySnapshotPersonalFields(): array
{
    return [
        'titulares' => [
            'nome_completo', 'cpf', 'rg', 'cargo_funcao', 'matricula', 'data_nascimento',
            'data_batismo_ou_admissao', 'whatsapp', 'email_login', 'senha_portal_hash',
            'token_unico_carteira', 'foto_url', 'dados_extras_json', 'ultima_interacao_em',
        ],
        'usuarios' => ['nome', 'email', 'senha_hash', 'ultimo_login_em'],
        'tenants' => [
            'nome_organizacao', 'slug_subdominio', 'nome_responsavel', 'cargo_responsavel', 'assinatura_responsavel_url',
        ],
        'dominios_tenant' => ['hostname'],
        'whatsapp_conversas' => [
            'contato_hmac', 'consentimento_finalidade', 'consentimento_em', 'revogado_em', 'titular_id', 'job_id', 'dados_cifrados',
        ],
        'mensagens_whatsapp' => ['telefone', 'conteudo', 'id_mensagem_api', 'arquivo_url', 'criado_em'],
        'acessos_suporte' => ['usuario_id', 'ip_usado', 'usado_em'],
        'mcp_api_keys' => ['usuario_id', 'nome', 'ultimo_uso_em'],
        'design_fundos_privacidade' => ['classificacao', 'titular_id', 'asset_url'],
        'importacao_linhas_staging' => ['dados_cifrados', 'foto_cifrada'],
        'carteirinhas_emitidas' => [
            'titular_id', 'codigo_validacao_qr', 'snapshot_dados_json', 'webp_frente_url', 'webp_verso_url', 'pdf_individual_url',
            'data_validade', 'total_leituras_qr', 'emitida_em',
        ],
    ];
}

/** @return array<string,list<string>> */
function privacySnapshotArtifactFields(): array
{
    return [
        'titulares' => ['foto_url'],
        'tenants' => ['assinatura_responsavel_url'],
        'design_fundos_privacidade' => ['asset_url'],
        'mensagens_whatsapp' => ['arquivo_url'],
        'carteirinhas_emitidas' => ['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'],
    ];
}

/** @param callable(int,string):string|null $encryptSubject
 *  @return array{manifest:array<string,mixed>,tables:array<string,list<array<string,mixed>>>,subject_envelopes:array<string,string>}
 */
function privacySnapshotBuildPayload(
    PDO $pdo,
    ?callable $encryptSubject = null,
    ?string $createdAt = null,
    ?string $snapshotId = null
): array
{
    $encryptSubject ??= static fn(int $subjectId, string $plaintext): string => privacySubjectEncryptSnapshotData($pdo, $subjectId, $plaintext);
    $createdAt ??= gmdate('Y-m-d\TH:i:s\Z');
    $snapshotId ??= bin2hex(random_bytes(16));
    if (strtotime($createdAt) === false) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_TIMESTAMP_INVALID');
    }
    if (preg_match('/^[a-f0-9]{32}$/D', $snapshotId) !== 1) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_ID_INVALID');
    }

    $recordsByKey = [];
    foreach ($pdo->query(
        'SELECT privacy_subject_id, tipo_registro, registro_id FROM privacy_subject_records ORDER BY privacy_subject_id, tipo_registro, registro_id'
    )->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $recordsByKey[(string) $record['tipo_registro'] . ':' . (string) $record['registro_id']] = (int) $record['privacy_subject_id'];
    }

    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    $schemaInventory = privacySnapshotAssertReviewedSchema($catalog);
    privacySnapshotAssertDatabaseSchema($pdo, $schemaInventory);
    $policies = privacySnapshotPersonalFields();
    $tableData = [];
    $subjectData = [];
    foreach ($catalog as $table => $_definition) {
        if (in_array($table, privacySnapshotOmittedTables(), true)) {
            $tableData[$table] = [];
            continue;
        }
        $rows = $pdo->query('SELECT * FROM ' . privacySnapshotQuoteIdentifier($table, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
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
            }
            if ($table === 'lotes_importacao') {
                $row['arquivo_original_url'] = null;
                $row['pdf_consolidado_url'] = null;
            }
            $fields = $policies[$table] ?? [];
            $artifactFields = privacySnapshotArtifactFields()[$table] ?? [];
            $values = [];
            foreach ($fields as $field) {
                if (!in_array($field, $artifactFields, true)
                    && array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                    $values[$field] = $row[$field];
                }
            }
            $personalFieldsToScrub = array_keys($values);
            $hasArtifacts = false;
            foreach ($artifactFields as $artifactField) {
                if (isset($row[$artifactField]) && trim((string) $row[$artifactField]) !== '') {
                    $personalFieldsToScrub[] = $artifactField;
                    $hasArtifacts = true;
                }
            }
            if ($values !== [] || $hasArtifacts) {
                $recordType = privacySnapshotRecordType($table);
                $rowId = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $subjectRecordId = $table === 'dominios_tenant'
                    ? filter_var($row['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : $rowId;
                $subjectId = $subjectRecordId === false
                    ? false
                    : ($recordsByKey[$recordType . ':' . $subjectRecordId] ?? false);
                if ($subjectId === false) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_MISSING');
                }
                $subject = $pdo->prepare('SELECT referencia, estado FROM privacy_subjects WHERE id = :id LIMIT 1');
                $subject->execute(['id' => $subjectId]);
                $subjectRow = $subject->fetch(PDO::FETCH_ASSOC);
                if (!is_array($subjectRow)) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_INVALID');
                }
                if (($subjectRow['estado'] ?? null) === 'anonimizado') {
                    privacySnapshotReplacePersonalValues($table, (int) $rowId, $row, $personalFieldsToScrub);
                    continue;
                }
                $artifacts = privacySnapshotLoadSubjectArtifacts($table, $row);
                $reference = (string) $subjectRow['referencia'];
                $subjectData[$subjectId]['reference'] = $reference;
                $subjectData[$subjectId]['records'][] = [
                    'table' => $table,
                    'record_id' => (int) $rowId,
                    'fields' => $values,
                    'artifacts' => $artifacts,
                ];
                privacySnapshotReplacePersonalValues($table, (int) $rowId, $row, $personalFieldsToScrub);
            }
        }
        unset($row);
        $tableData[$table] = $rows;
    }

    $envelopes = [];
    foreach ($subjectData as $subjectId => $subject) {
        $plaintext = json_encode([
            'version' => 1,
            'reference' => $subject['reference'],
            'records' => $subject['records'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($plaintext) > 67_108_864) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_SUBJECT_PAYLOAD_TOO_LARGE');
        }
        $ciphertext = $encryptSubject((int) $subjectId, $plaintext);
        if (!str_starts_with($ciphertext, 'v1:') || str_contains($ciphertext, $plaintext)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ENCRYPTION_INVALID');
        }
        $envelopes[$subject['reference']] = $ciphertext;
    }
    ksort($envelopes, SORT_STRING);
    $manifest = [
        'format' => 'ieprcard-snapshot-v2',
        'schema_version' => privacySnapshotCurrentSchemaVersion(),
        'snapshot_id' => $snapshotId,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($createdAt)),
        'table_count' => count($tableData),
        'schema_inventory' => $schemaInventory,
        'schema_inventory_sha256' => PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256,
        'subject_count' => count($envelopes),
        'ledger_version' => 1,
    ];
    $manifest['payload_sha256'] = hash('sha256', json_encode([
        'tables' => $tableData,
        'subject_envelopes' => $envelopes,
        'schema_inventory' => $schemaInventory,
        'schema_inventory_sha256' => PRIVACY_SNAPSHOT_REVIEWED_SCHEMA_SHA256,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ['manifest' => $manifest, 'tables' => $tableData, 'subject_envelopes' => $envelopes];
}

/** @param list<int>|null $destinationIds @param callable(int,string):string|null $encryptSubject
 *  @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 *  @param int $rowLimit
 *  @param float $timeLimitSeconds Maximum staging time before a resumable checkpoint.
 *  @return array{processed:int,failed:int,skipped:int}
 */
function privacySnapshotUpload(
    PDO $pdo,
    ?array $destinationIds = null,
    ?callable $encryptSubject = null,
    ?callable $clientFactory = null,
    int $rowLimit = 4000,
    float $timeLimitSeconds = 30.0
): array
{
    if ($rowLimit < 1 || $rowLimit > 4000 || $timeLimitSeconds < 1 || $timeLimitSeconds > 30) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_BATCH_LIMIT_INVALID');
    }
    $destinations = titularPrivacyActiveDestinations($pdo);
    $activeLimit = backupDestinationActiveLimit();
    if (count($destinations) > $activeLimit) {
        $markFailure = $pdo->prepare(
            "UPDATE destinos_backup SET ultimo_erro_codigo = 'active_destination_limit_exceeded', atualizado_em = :updated "
            . 'WHERE ativo = 1'
        );
        $markFailure->execute(['updated' => gmdate('Y-m-d H:i:s')]);
        return ['processed' => 0, 'failed' => count($destinations), 'skipped' => 0];
    }
    if ($destinationIds !== null) {
        $wanted = array_fill_keys(array_map('intval', $destinationIds), true);
        $destinations = array_values(array_filter($destinations, static fn(array $row): bool => isset($wanted[(int) $row['id']])));
    }
    if ($destinations === []) {
        return ['processed' => 0, 'failed' => 0, 'skipped' => 1];
    }

    $lockPath = batchImportStagingPrivateRoot() . DIRECTORY_SEPARATOR . 'privacy-snapshot.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_LOCK_UNAVAILABLE');
    }
    $lockHandle = @fopen($lockPath, 'c');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_LOCK_UNAVAILABLE');
    }
    if (DIRECTORY_SEPARATOR === '/') {
        @chmod($lockPath, 0600);
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return ['processed' => 0, 'failed' => 0, 'skipped' => 1];
    }

    $dekCache = [];
    $resolvedEncryptSubject = $encryptSubject ?? static function (int $subjectId, string $plaintext) use ($pdo, &$dekCache): string {
        return privacySubjectEncryptSnapshotData($pdo, $subjectId, $plaintext, null, $dekCache);
    };

    try {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($destinations as $destination) {
            if (is_string($destination['snapshot_pendente_id'] ?? null)
                && $destination['snapshot_pendente_id'] !== '') {
                continue;
            }
            $pendingId = bin2hex(random_bytes(16));
            $assign = $pdo->prepare(
                'UPDATE destinos_backup SET snapshot_pendente_id = :pending_id, snapshot_pendente_em = :pending_at '
                . 'WHERE id = :id AND ativo = 1 AND snapshot_pendente_id IS NULL'
            );
            $assign->execute(['pending_id' => $pendingId, 'pending_at' => $now, 'id' => $destination['id']]);
        }
        $activeIds = array_map(static fn(array $row): int => (int) $row['id'], $destinations);
        $destinations = titularPrivacyActiveDestinations($pdo);
        $wanted = array_fill_keys($activeIds, true);
        $destinations = array_values(array_filter($destinations, static fn(array $row): bool => isset($wanted[(int) $row['id']])));

        $processed = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($destinations as $destination) {
            $destinationId = (int) $destination['id'];
            try {
                $pendingId = (string) ($destination['snapshot_pendente_id'] ?? '');
                $pendingAt = (string) ($destination['snapshot_pendente_em'] ?? '');
                $pendingTimestamp = strtotime($pendingAt);
                if (preg_match('/^[a-f0-9]{32}$/D', $pendingId) !== 1 || $pendingTimestamp === false) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_PENDING_STATE_INVALID');
                }
                $createdAt = gmdate('Y-m-d\\TH:i:s\\Z', $pendingTimestamp);
                $created = new DateTimeImmutable($createdAt);
                $objectKey = 'ieprcard-snapshots/v3/' . $created->format('Y/m/d') . '/' . $pendingId . '.json.gz';
                $credentials = $destination['_credentials'] ?? [];
                if (!is_array($credentials)) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_DESTINATION_CREDENTIALS_INVALID');
                }
                $client = $clientFactory !== null
                    ? $clientFactory($destination, $credentials)
                    : backupDestinationS3Client($destination, $credentials);

                $resumeDirectory = privacySnapshotResumeDirectory($destinationId, $pendingId);
                $legacyObjectKey = 'ieprcard-snapshots/v2/' . $created->format('Y/m/d') . '/' . $pendingId . '.json.gz';
                $legacyExists = privacySnapshotExistingObjectMatches(
                    $client, (string) $destination['bucket'], $legacyObjectKey, null, $pendingId, $createdAt
                );
                if ($legacyExists) {
                    $objectKey = $legacyObjectKey;
                }
                if (!privacySnapshotExistingObjectMatches(
                    $client, (string) $destination['bucket'], $objectKey, null, $pendingId, $createdAt
                )) {
                    $state = privacySnapshotResumeReadState($resumeDirectory, $createdAt, $pendingId);
                    $complete = privacySnapshotResumeStage(
                        $pdo,
                        $resumeDirectory,
                        $state,
                        $resolvedEncryptSubject,
                        $rowLimit,
                        $timeLimitSeconds
                    );
                    if (!$complete) {
                        $skipped++;
                        continue;
                    }
                    $final = privacySnapshotResumeFinalize($resumeDirectory, $state);
                    $checksum = (string) $final['sha256'];
                    $body = fopen((string) $final['path'], 'rb');
                    if (!is_resource($body)) {
                        throw new RuntimeException('PRIVACY_SNAPSHOT_UPLOAD_UNVERIFIED');
                    }
                    try {
                        $client->putObject([
                            'Bucket' => $destination['bucket'],
                            'Key' => $objectKey,
                            'Body' => $body,
                            'ContentType' => 'application/gzip',
                            'Metadata' => ['sha256' => $checksum, 'format' => $legacyExists ? 'ieprcard-snapshot-v2' : 'ieprcard-snapshot-v3'],
                            'ServerSideEncryption' => 'AES256',
                        ]);
                    } finally {
                        fclose($body);
                    }
                    if (!privacySnapshotExistingObjectMatches($client, (string) $destination['bucket'], $objectKey, $checksum)) {
                        throw new RuntimeException('PRIVACY_SNAPSHOT_UPLOAD_UNVERIFIED');
                    }
                }

                privacySnapshotResumeDelete($resumeDirectory);
                $update = $pdo->prepare(
                    'UPDATE destinos_backup SET ultimo_sucesso_em = :success, ultimo_erro_codigo = NULL, '
                    . 'snapshot_pendente_id = NULL, snapshot_pendente_em = NULL, atualizado_em = :updated '
                    . 'WHERE id = :id AND snapshot_pendente_id = :pending_id'
                );
                $update->execute([
                    'success' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'),
                    'id' => $destinationId, 'pending_id' => $pendingId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_PENDING_STATE_CHANGED');
                }
                ++$processed;
            } catch (Throwable) {
                $pdo->prepare(
                    'UPDATE destinos_backup SET ultimo_erro_codigo = :error, atualizado_em = :updated WHERE id = :id'
                )->execute([
                    'error' => 'PRIVACY_SNAPSHOT_UPLOAD_FAILED', 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $destinationId,
                ]);
                ++$failed;
            }
        }
        return ['processed' => $processed, 'failed' => $failed, 'skipped' => $skipped];
    } finally {
        if ($dekCache !== []) {
            titularPrivacyClearDekCache($dekCache);
        }
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function privacySnapshotExistingObjectMatches(
    object $client,
    string $bucket,
    string $objectKey,
    ?string $expectedChecksum = null,
    ?string $expectedSnapshotId = null,
    ?string $expectedCreatedAt = null
): bool
{
    try {
        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $objectKey]);
    } catch (Throwable $exception) {
        $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : 0;
        if ($status === 404 || $exception->getCode() === 'NoSuchKey') {
            return false;
        }
        throw $exception;
    }
    $metadata = $head['Metadata'] ?? [];
    if (!is_array($metadata)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_INVALID');
    }
    $metadata = array_change_key_case($metadata, CASE_LOWER);
    $storedChecksum = $metadata['sha256'] ?? null;
    if (!in_array(($metadata['format'] ?? null), ['ieprcard-snapshot-v2', 'ieprcard-snapshot-v3'], true)
        || !is_string($storedChecksum) || preg_match('/^[a-f0-9]{64}$/D', $storedChecksum) !== 1) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_INVALID');
    }
    if ($expectedChecksum !== null) {
        if (!hash_equals($expectedChecksum, $storedChecksum)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_CHECKSUM_INVALID');
        }
        return true;
    }
    $object = $client->getObject(['Bucket' => $bucket, 'Key' => $objectKey]);
    $body = (string) ($object['Body'] ?? '');
    if ($body === '' || !hash_equals($storedChecksum, hash('sha256', $body))) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_CHECKSUM_INVALID');
    }
    if ($expectedSnapshotId !== null || $expectedCreatedAt !== null) {
        $json = gzdecode($body);
        if (!is_string($json)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_INVALID');
        }
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $manifest = is_array($snapshot) && is_array($snapshot['manifest'] ?? null) ? $snapshot['manifest'] : [];
        if (($manifest['format'] ?? null) !== $metadata['format']
            || ($expectedSnapshotId !== null && !hash_equals($expectedSnapshotId, (string) ($manifest['snapshot_id'] ?? '')))
            || ($expectedCreatedAt !== null && !hash_equals($expectedCreatedAt, (string) ($manifest['created_at'] ?? '')))) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_EXISTING_OBJECT_IDENTITY_INVALID');
        }
    }
    return true;
}

/** @param callable(int,string):string|null $encryptSubject
 *  @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 *  @return array{processed:int,failed:int,skipped:int}
 */
function privacySnapshotRunDue(
    PDO $pdo,
    ?int $now = null,
    int $intervalSeconds = 900,
    ?callable $encryptSubject = null,
    ?callable $clientFactory = null
): array
{
    if ($intervalSeconds < 900 || $intervalSeconds > 3600) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_INTERVAL_INVALID');
    }
    $now ??= time();
    $cutoff = gmdate('Y-m-d H:i:s', $now - $intervalSeconds);
    $query = $pdo->prepare(
        'SELECT id FROM destinos_backup WHERE ativo = 1 AND (snapshot_pendente_id IS NOT NULL '
        . 'OR ultimo_sucesso_em IS NULL OR ultimo_sucesso_em <= :cutoff) ORDER BY id'
    );
    $query->execute(['cutoff' => $cutoff]);
    $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    return $ids === [] ? ['processed' => 0, 'failed' => 0, 'skipped' => 1]
        : privacySnapshotUpload($pdo, $ids, $encryptSubject, $clientFactory, 4000, 30.0);
}

/** @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 *  @return array{processed:int,failed:int,skipped:int}
 */
function privacySnapshotPurgeExpired(
    PDO $pdo,
    ?int $now = null,
    int $limit = 25,
    ?callable $clientFactory = null
): array {
    if ($limit < 1 || $limit > 200) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_RETENTION_LIMIT_INVALID');
    }
    $now ??= time();
    $destinations = $pdo->query('SELECT * FROM destinos_backup ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($destinations === []) {
        return ['processed' => 0, 'failed' => 0, 'skipped' => 1];
    }

    $processed = 0;
    $failed = 0;
    foreach ($destinations as $destinationRow) {
        if ($processed >= $limit) {
            break;
        }
        $destinationId = (int) ($destinationRow['id'] ?? 0);
        $errorCode = null;
        $destinationRemoved = 0;
        try {
            $retentionDays = filter_var($destinationRow['retencao_dias'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 3650],
            ]);
            if ($destinationId < 1 || $retentionDays === false) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_CONFIGURATION_INVALID');
            }
            $pendingSnapshotId = $destinationRow['snapshot_pendente_id'] ?? null;
            $pendingSnapshotAt = $destinationRow['snapshot_pendente_em'] ?? null;
            $pendingTimestamp = null;
            $pendingSnapshotKey = null;
            if ($pendingSnapshotId !== null && $pendingSnapshotId !== '') {
                $pendingTimestamp = is_string($pendingSnapshotAt) ? strtotime($pendingSnapshotAt) : false;
                if (!is_string($pendingSnapshotId) || preg_match('/^[a-f0-9]{32}$/D', $pendingSnapshotId) !== 1
                    || $pendingTimestamp === false) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_PENDING_STATE_INVALID');
                }
                if ((int) ($destinationRow['ativo'] ?? 0) === 1) {
                    $pendingSnapshotKey = [
                        'ieprcard-snapshots/v2/' . gmdate('Y/m/d', $pendingTimestamp) . '/' . $pendingSnapshotId . '.json.gz',
                        'ieprcard-snapshots/v3/' . gmdate('Y/m/d', $pendingTimestamp) . '/' . $pendingSnapshotId . '.json.gz',
                    ];
                }
            }
            $destination = titularPrivacyOpenDestinationCredentials([$destinationRow])[0] ?? null;
            if (!is_array($destination) || !is_array($destination['_credentials'] ?? null)) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_DESTINATION_INVALID');
            }
            $client = $clientFactory !== null
                ? $clientFactory($destination, $destination['_credentials'])
                : backupDestinationS3Client($destination, $destination['_credentials']);
            $cutoff = $now - ($retentionDays * 86400);
            $continuationToken = null;
            $previousToken = null;
            $scanned = 0;
            $pages = 0;
            do {
                if (++$pages > 50) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_PAGE_LIMIT');
                }
                $arguments = ['Bucket' => (string) $destination['bucket'], 'Prefix' => 'ieprcard-snapshots/', 'MaxKeys' => 1000];
                if (is_string($continuationToken)) {
                    $arguments['ContinuationToken'] = $continuationToken;
                }
                $page = $client->listObjectsV2($arguments);
                $contents = $page['Contents'] ?? [];
                if (!is_array($contents)) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_LIST_INVALID');
                }
                foreach ($contents as $object) {
                    if (!is_array($object) || !is_string($object['Key'] ?? null)) {
                        throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_LIST_INVALID');
                    }
                    if (++$scanned > 1000) {
                        break 2;
                    }
                    $objectKey = $object['Key'];
                    if (is_array($pendingSnapshotKey) && in_array($objectKey, $pendingSnapshotKey, true)) {
                        continue;
                    }
                    if (preg_match('~^ieprcard-snapshots/v[0-9]+/[0-9]{4}/[0-9]{2}/[0-9]{2}/[a-f0-9]{32}\.json\.gz$~D', $objectKey) !== 1) {
                        continue;
                    }
                    $lastModified = $object['LastModified'] ?? null;
                    if ($lastModified instanceof DateTimeInterface) {
                        $modifiedAt = $lastModified->getTimestamp();
                    } elseif (is_string($lastModified) && ($parsed = strtotime($lastModified)) !== false) {
                        $modifiedAt = $parsed;
                    } else {
                        throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_TIMESTAMP_INVALID');
                    }
                    if ($modifiedAt <= $cutoff) {
                        s3CompatibleDeleteObjectPermanently($client, (string) $destination['bucket'], $objectKey);
                        ++$processed;
                        ++$destinationRemoved;
                        if ($processed >= $limit) {
                            break 2;
                        }
                    }
                }
                $truncated = (bool) ($page['IsTruncated'] ?? false);
                $nextToken = $truncated ? ($page['NextContinuationToken'] ?? null) : null;
                if ($truncated && (!is_string($nextToken) || $nextToken === '' || $nextToken === $continuationToken
                    || $nextToken === $previousToken)) {
                    throw new RuntimeException('PRIVACY_SNAPSHOT_RETENTION_PAGINATION_INVALID');
                }
                $previousToken = $continuationToken;
                $continuationToken = $nextToken;
            } while ($truncated);
            if ((int) ($destinationRow['ativo'] ?? 0) !== 1 && is_int($pendingTimestamp)
                && $pendingTimestamp <= $cutoff) {
                $clearPending = $pdo->prepare(
                    'UPDATE destinos_backup SET snapshot_pendente_id = NULL, snapshot_pendente_em = NULL '
                    . 'WHERE id = :id AND snapshot_pendente_id = :pending_id'
                );
                $clearPending->execute(['id' => $destinationId, 'pending_id' => $pendingSnapshotId]);
            }
        } catch (Throwable) {
            $errorCode = 'PRIVACY_SNAPSHOT_RETENTION_FAILED';
            ++$failed;
        }
        $update = $pdo->prepare(
            'UPDATE destinos_backup SET retencao_ultima_execucao_em = :run_at, retencao_itens_removidos = :removed, retencao_erro_codigo = :error, '
            . 'atualizado_em = :updated WHERE id = :id'
        );
        $update->execute([
            'run_at' => gmdate('Y-m-d H:i:s', $now), 'removed' => $destinationRemoved, 'error' => $errorCode,
            'updated' => gmdate('Y-m-d H:i:s', $now), 'id' => $destinationId,
        ]);
    }
    return ['processed' => $processed, 'failed' => $failed, 'skipped' => 0];
}

function privacySnapshotQuoteIdentifier(string $identifier, string $driver): string
{
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $identifier) !== 1) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_IDENTIFIER_INVALID');
    }
    return strtolower($driver) === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
}

function privacySnapshotRecordType(string $table): string
{
    return match ($table) {
        'titulares' => 'titular',
        'usuarios' => 'usuario',
        'tenants' => 'tenant_contato',
        'dominios_tenant' => 'tenant_contato',
        'whatsapp_conversas' => 'whatsapp_conversa',
        'mensagens_whatsapp' => 'mensagem_whatsapp',
        'acessos_suporte' => 'acesso_suporte',
        'mcp_api_keys' => 'mcp_api_key',
        'carteirinhas_emitidas' => 'carteirinha_emitida',
        'design_fundos_privacidade' => 'design_fundo',
        'importacao_linhas_staging' => 'import_row',
        default => throw new InvalidArgumentException('PRIVACY_SNAPSHOT_TABLE_POLICY_MISSING'),
    };
}

/** @param array<string,mixed> $row */
function privacySnapshotReplacePersonalValues(string $table, int $id, array &$row, array $fields): void
{
    foreach ($fields as $field) {
        $row[$field] = null;
    }
    if ($table === 'titulares') {
        $row['nome_completo'] = 'Titular protegido';
        $row['ativo'] = 0;
        $row['acesso_app_liberado'] = 0;
        $row['ia_pausada'] = 1;
    } elseif ($table === 'usuarios') {
        $tenant = (string) ($row['tenant_id'] ?? 'global');
        $row['nome'] = 'Usuário protegido';
        $row['email'] = 'snapshot-disabled-' . $tenant . '-' . $id . '@invalid.example';
        $row['senha_hash'] = '!disabled-snapshot-' . $id;
        $row['ativo'] = 0;
    } elseif ($table === 'tenants') {
        $row['nome_organizacao'] = 'Organização protegida';
        $row['slug_subdominio'] = null;
    } elseif ($table === 'dominios_tenant') {
        $row['hostname'] = 'snapshot-disabled-domain-' . $id . '.invalid';
        $row['estado'] = 'pendente_verificacao';
        $row['token_verificacao_hash'] = hash('sha256', 'snapshot-disabled-domain-token-' . $id);
        $row['verificado_em'] = null;
        $row['tls_validado_em'] = null;
    } elseif ($table === 'acessos_suporte') {
        $row['token_hash'] = hash('sha256', 'snapshot-disabled-support-' . $id);
    } elseif ($table === 'mcp_api_keys') {
        $row['nome'] = 'Chave revogada';
        $row['chave_hash'] = hash('sha256', 'snapshot-disabled-mcp-' . $id);
        $row['ativo'] = 0;
    } elseif ($table === 'carteirinhas_emitidas') {
        $row['codigo_validacao_qr'] = 'snapshot-disabled-' . $id;
        $row['status_carteirinha'] = 'revogada';
        $row['total_leituras_qr'] = 0;
    } elseif ($table === 'whatsapp_conversas') {
        $row['contato_hmac'] = null;
    }
}

/** @param array<string,mixed> $row */
function privacySnapshotStripUnrecoverableSecrets(string $table, array &$row): void
{
    $fields = match ($table) {
        'destinos_backup' => ['ultimo_erro_codigo'],
        default => [],
    };
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = null;
        }
    }
    if ($table === 'destinos_backup' && array_key_exists('ativo', $row)) {
        $row['ativo'] = 0;
        $row['ultimo_teste_status'] = null;
    }
    if ($table === 'whatsapp_config_global' && array_key_exists('ativo', $row)) {
        $row['ativo'] = 0;
    }
    if ($table === 'ia_config_global' && array_key_exists('ativo', $row)) {
        $row['ativo'] = 0;
    }
    if ($table === 'ia_config' && array_key_exists('ativo', $row)) {
        $row['ativo'] = 0;
    }
    if ($table === 'whatsapp_instancias') {
        $row['webhook_token_hash'] = hash('sha256', 'snapshot-disabled-whatsapp-' . (string) ($row['id'] ?? ''));
        $row['device_id'] = null;
        $row['estado'] = 'inativa';
    }
    if ($table === 'acessos_suporte') {
        $row['token_hash'] = hash('sha256', 'snapshot-disabled-support-' . (string) ($row['id'] ?? ''));
    }
    if ($table === 'mcp_api_keys') {
        $row['chave_hash'] = hash('sha256', 'snapshot-disabled-mcp-' . (string) ($row['id'] ?? ''));
        $row['ativo'] = 0;
    }
    if ($table === 'mcp_support_grants') {
        $id = (string) ($row['id'] ?? 'unknown');
        $row['segredo_hash'] = password_hash('snapshot-disabled-support-grant-' . $id, PASSWORD_DEFAULT);
        $row['rotulo'] = 'Grant revogado no snapshot';
        $row['criado_por_usuario_id'] = null;
        $row['capacidades_json'] = '[]';
        $row['revogado_em'] = gmdate('Y-m-d H:i:s');
        $row['ultimo_uso_em'] = null;
    }
    if ($table === 'dominios_tenant') {
        $row['token_verificacao_hash'] = hash('sha256', 'snapshot-disabled-domain-' . (string) ($row['id'] ?? ''));
        $row['estado'] = 'pendente_verificacao';
        $row['verificado_em'] = null;
        $row['tls_validado_em'] = null;
    }
}

/** Fail closed for artifacts without an envelope format tied to a privacy subject. */
function privacySnapshotAssertSupportedArtifacts(string $table, array $row): void
{
    if ($table === 'design_fundos_privacidade' && isset($row['asset_url'])
        && !in_array($row['classificacao'] ?? null, ['decorativo', 'titular'], true)) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_DESIGN_CLASSIFICATION_REQUIRED');
    }
    $privateArtifacts = match ($table) {
        'titulares' => ['foto_url'],
        'tenants' => ['assinatura_responsavel_url'],
        'lotes_importacao' => ['pdf_consolidado_url'],
        'design_fundos_privacidade' => ['asset_url'],
        'mensagens_whatsapp' => ['arquivo_url'],
        'carteirinhas_emitidas' => ['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'],
        default => [],
    };
    foreach ($privateArtifacts as $field) {
        if (isset($row[$field]) && trim((string) $row[$field]) !== '') {
            if ($table === 'lotes_importacao' && $field === 'pdf_consolidado_url') {
                continue;
            }
            if (!in_array($field, privacySnapshotArtifactFields()[$table] ?? [], true)) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_ENCRYPTION_REQUIRED');
            }
            privacySnapshotArtifactKey((int) ($row['tenant_id'] ?? 0), (string) $row[$field]);
        }
    }
}

/** @return array{key:string,extension:string} */
function privacySnapshotArtifactKey(int $tenantId, string $storedKey): array
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $storedKey, $matches) !== 1
        || $tenantId < 1 || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_ENCRYPTION_REQUIRED');
    }
    return ['key' => $matches[2], 'extension' => $matches[3]];
}

/** @param array<string,mixed> $row @return list<array{field:string,media_type:string,sha256:string,contents_base64:string}> */
function privacySnapshotLoadSubjectArtifacts(string $table, array $row): array
{
    $fields = privacySnapshotArtifactFields()[$table] ?? [];
    if ($fields === []) {
        return [];
    }
    $tenantId = (int) ($row['tenant_id'] ?? 0);
    $artifacts = [];
    foreach ($fields as $field) {
        $storedKey = $row[$field] ?? null;
        if (!is_string($storedKey) || trim($storedKey) === '') {
            continue;
        }
        $identifier = privacySnapshotArtifactKey($tenantId, $storedKey);
        $root = batchImportStagingPrivateRoot();
        $tenantDirectory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
        if (is_link($tenantDirectory)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_UNAVAILABLE');
        }
        $realTenantDirectory = realpath($tenantDirectory);
        if (!is_string($realTenantDirectory) || !batchImportStagingPathWithin($realTenantDirectory, $root)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_UNAVAILABLE');
        }
        $path = $realTenantDirectory . DIRECTORY_SEPARATOR . $identifier['key'] . '.' . $identifier['extension'];
        if (is_link($path)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_UNAVAILABLE');
        }
        $realPath = realpath($path);
        $size = is_string($realPath) ? filesize($realPath) : false;
        if (!is_string($realPath) || !is_file($realPath) || !batchImportStagingPathWithin($realPath, $root)
            || !is_int($size) || $size < 1 || $size > 25_000_000) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_UNAVAILABLE');
        }
        $contents = file_get_contents($realPath);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('PRIVACY_SNAPSHOT_ARTIFACT_UNAVAILABLE');
        }
        $mediaType = match ($identifier['extension']) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        };
        $artifacts[] = [
            'field' => $field,
            'media_type' => $mediaType,
            'sha256' => hash('sha256', $contents),
            'contents_base64' => base64_encode($contents),
        ];
        sodium_memzero($contents);
    }
    return $artifacts;
}

/** Keep operational history useful without preserving actor, target, or free-form personal data. */
function privacySnapshotMinimizeOperationalRows(string $table, array &$row): void
{
    $redacted = match ($table) {
        'auditoria_processos' => [
            'subcliente_id', 'ator_usuario_id', 'entidade_id', 'antes_json', 'depois_json', 'correlation_id',
        ],
        'telemetria_eventos' => ['subcliente_id', 'usuario_id', 'metadados_json'],
        'mcp_audit_logs' => ['api_key_id', 'correlation_id'],
        'mcp_support_audit_logs' => [
            'actor_user_id', 'grant_id', 'target_tenant_id', 'target_subcliente_id', 'operation_key', 'commit_sha', 'correlation_id',
        ],
        'configuracoes_instalacao' => ['ator_usuario_id'],
        'configuracoes_operacionais' => ['alterado_por_usuario_id'],
        'usuarios' => ['criado_por_usuario_id'],
        'usuario_permissoes' => ['criado_por_usuario_id'],
        'lotes_importacao' => ['usuario_id', 'titular_origem_id', 'colunas_detectadas_json', 'mapa_de_para_usado_json'],
        'solicitacoes_anonimizacao' => ['correlation_id'],
        default => [],
    };
    foreach ($redacted as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = null;
        }
    }
    if ($table === 'outbox_eventos') {
        $row['evento_chave'] = 'snapshot-redacted-' . (string) ($row['id'] ?? 'unknown');
        $row['payload_json'] = null;
        $row['status'] = 'cancelled';
        $row['tentativas'] = 0;
        $row['proxima_tentativa_em'] = null;
        $row['correlation_id'] = null;
    }
    if ($table === 'webhooks_recebidos') {
        $row['evento_id'] = 'snapshot-redacted-' . (string) ($row['id'] ?? 'unknown');
        $row['payload_hash'] = hash('sha256', 'snapshot-redacted-webhook-' . (string) ($row['id'] ?? 'unknown'));
    }
    if ($table === 'webhook_gowa_eventos') {
        $row['evento_hash'] = hash('sha256', 'snapshot-redacted-gowa-' . (string) ($row['id'] ?? 'unknown'));
        $row['estado'] = 'processado';
        $row['processando_em'] = null;
        $row['tentativas'] = 0;
    }
    if ($table === 'whatsapp_conversas' && array_key_exists('ultimo_erro_entrega', $row)) {
        $row['ultimo_erro_entrega'] = null;
    }
    if ($table === 'destinos_backup') {
        $row['snapshot_pendente_id'] = null;
        $row['snapshot_pendente_em'] = null;
    }
    if ($table === 'whatsapp_instancias') {
        $row['nome_instancia'] = 'Instância protegida';
    }
}
