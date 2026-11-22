<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/schema/catalog.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/titular_privacy_keys.php';

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
        'whatsapp_conversas' => [
            'contato_hmac', 'consentimento_finalidade', 'consentimento_em', 'revogado_em', 'titular_id', 'job_id', 'dados_cifrados',
        ],
        'mensagens_whatsapp' => ['telefone', 'conteudo', 'id_mensagem_api', 'arquivo_url', 'criado_em'],
        'acessos_suporte' => ['token_hash', 'ip_usado', 'usado_em'],
        'mcp_api_keys' => ['nome', 'chave_hash', 'ultimo_uso_em'],
        'carteirinhas_emitidas' => [
            'codigo_validacao_qr', 'snapshot_dados_json', 'webp_frente_url', 'webp_verso_url', 'pdf_individual_url',
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
        'mensagens_whatsapp' => ['arquivo_url'],
        'carteirinhas_emitidas' => ['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'],
    ];
}

/** @param callable(int,string):string|null $encryptSubject
 *  @return array{manifest:array<string,mixed>,tables:array<string,list<array<string,mixed>>>,subject_envelopes:array<string,string>}
 */
function privacySnapshotBuildPayload(PDO $pdo, ?callable $encryptSubject = null, ?string $createdAt = null): array
{
    $encryptSubject ??= static fn(int $subjectId, string $plaintext): string => privacySubjectEncryptSnapshotData($pdo, $subjectId, $plaintext);
    $createdAt ??= gmdate('Y-m-d\TH:i:s\Z');
    if (strtotime($createdAt) === false) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_TIMESTAMP_INVALID');
    }

    $recordsByKey = [];
    foreach ($pdo->query(
        'SELECT privacy_subject_id, tipo_registro, registro_id FROM privacy_subject_records ORDER BY privacy_subject_id, tipo_registro, registro_id'
    )->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $recordsByKey[(string) $record['tipo_registro'] . ':' . (string) $record['registro_id']] = (int) $record['privacy_subject_id'];
    }

    $policies = privacySnapshotPersonalFields();
    $tableData = [];
    $subjectData = [];
    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    foreach ($catalog as $table => $_definition) {
        if (in_array($table, ['titular_chaves_backup'], true)) {
            $tableData[$table] = [];
            continue;
        }
        $rows = $pdo->query('SELECT * FROM ' . privacySnapshotQuoteIdentifier($table, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            privacySnapshotAssertSupportedArtifacts($table, $row);
            privacySnapshotStripUnrecoverableSecrets($table, $row);
            privacySnapshotMinimizeOperationalRows($table, $row);
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
                $subjectId = $rowId === false ? false : ($recordsByKey[$recordType . ':' . $rowId] ?? false);
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
        'schema_version' => '0018',
        'snapshot_id' => bin2hex(random_bytes(16)),
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($createdAt)),
        'table_count' => count($tableData),
        'subject_count' => count($envelopes),
        'ledger_version' => 1,
    ];
    $manifest['payload_sha256'] = hash('sha256', json_encode([
        'tables' => $tableData,
        'subject_envelopes' => $envelopes,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ['manifest' => $manifest, 'tables' => $tableData, 'subject_envelopes' => $envelopes];
}

/** @param list<int>|null $destinationIds @param callable(int,string):string|null $encryptSubject
 *  @param callable(array<string,mixed>,array<string,string>):object|null $clientFactory
 *  @return array{processed:int,failed:int,skipped:int}
 */
function privacySnapshotUpload(PDO $pdo, ?array $destinationIds = null, ?callable $encryptSubject = null, ?callable $clientFactory = null): array
{
    $destinations = titularPrivacyActiveDestinations($pdo);
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

    try {
    try {
        $snapshot = privacySnapshotBuildPayload($pdo, $encryptSubject);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 536_870_912) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_TOO_LARGE');
        }
        $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed)) {
            throw new RuntimeException('PRIVACY_SNAPSHOT_COMPRESSION_FAILED');
        }
    } catch (Throwable) {
        foreach ($destinations as $destination) {
            $pdo->prepare(
                'UPDATE destinos_backup SET ultimo_erro_codigo = :error, atualizado_em = :updated WHERE id = :id'
            )->execute([
                'error' => 'PRIVACY_SNAPSHOT_BUILD_FAILED', 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $destination['id'],
            ]);
        }
        return ['processed' => 0, 'failed' => count($destinations), 'skipped' => 0];
    }
    $checksum = hash('sha256', $compressed);
    $created = new DateTimeImmutable((string) $snapshot['manifest']['created_at']);
    $objectKey = 'ieprcard-snapshots/v2/' . $created->format('Y/m/d') . '/'
        . $snapshot['manifest']['snapshot_id'] . '.json.gz';
    $processed = 0;
    $failed = 0;
    foreach ($destinations as $destination) {
        try {
            $credentials = $destination['_credentials'] ?? [];
            if (!is_array($credentials)) {
                throw new RuntimeException('PRIVACY_SNAPSHOT_DESTINATION_CREDENTIALS_INVALID');
            }
            $client = $clientFactory !== null
                ? $clientFactory($destination, $credentials)
                : backupDestinationS3Client($destination, $credentials);
            $client->putObject([
                'Bucket' => $destination['bucket'],
                'Key' => $objectKey,
                'Body' => $compressed,
                'ContentType' => 'application/gzip',
                'Metadata' => ['sha256' => $checksum, 'format' => 'ieprcard-snapshot-v2'],
                'ServerSideEncryption' => 'AES256',
            ]);
            $update = $pdo->prepare(
                'UPDATE destinos_backup SET ultimo_sucesso_em = :success, ultimo_erro_codigo = NULL, atualizado_em = :updated WHERE id = :id'
            );
            $update->execute([
                'success' => gmdate('Y-m-d H:i:s'), 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $destination['id'],
            ]);
            ++$processed;
        } catch (Throwable) {
            $pdo->prepare(
                'UPDATE destinos_backup SET ultimo_erro_codigo = :error, atualizado_em = :updated WHERE id = :id'
            )->execute([
                'error' => 'PRIVACY_SNAPSHOT_UPLOAD_FAILED', 'updated' => gmdate('Y-m-d H:i:s'), 'id' => $destination['id'],
            ]);
            ++$failed;
        }
    }
    return ['processed' => $processed, 'failed' => $failed, 'skipped' => 0];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/** @return array{processed:int,failed:int,skipped:int} */
function privacySnapshotRunDue(PDO $pdo, ?int $now = null, int $intervalSeconds = 900): array
{
    if ($intervalSeconds < 900 || $intervalSeconds > 3600) {
        throw new InvalidArgumentException('PRIVACY_SNAPSHOT_INTERVAL_INVALID');
    }
    $now ??= time();
    $cutoff = gmdate('Y-m-d H:i:s', $now - $intervalSeconds);
    $query = $pdo->prepare(
        'SELECT id FROM destinos_backup WHERE ativo = 1 AND (ultimo_sucesso_em IS NULL OR ultimo_sucesso_em <= :cutoff) ORDER BY id'
    );
    $query->execute(['cutoff' => $cutoff]);
    $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    return $ids === [] ? ['processed' => 0, 'failed' => 0, 'skipped' => 1] : privacySnapshotUpload($pdo, $ids);
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
        'whatsapp_conversas' => 'whatsapp_conversa',
        'mensagens_whatsapp' => 'mensagem_whatsapp',
        'acessos_suporte' => 'acesso_suporte',
        'mcp_api_keys' => 'mcp_api_key',
        'carteirinhas_emitidas' => 'carteirinha_emitida',
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
        'ia_config' => ['chave_cifrada'],
        'ia_config_global' => ['chave_cifrada'],
        'whatsapp_config_global' => ['api_token_cifrado'],
        default => [],
    };
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = in_array($table . '.' . $field, [
                'ia_config.chave_cifrada', 'ia_config_global.chave_cifrada', 'whatsapp_config_global.api_token_cifrado',
            ], true) ? '' : null;
        }
    }
    if ($table === 'destinos_backup' && array_key_exists('credentials_cifradas', $row)) {
        $row['credentials_cifradas'] = '';
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
}

/** Fail closed for artifacts without an envelope format tied to a privacy subject. */
function privacySnapshotAssertSupportedArtifacts(string $table, array $row): void
{
    $privateArtifacts = match ($table) {
        'titulares' => ['foto_url'],
        'tenants' => ['assinatura_responsavel_url'],
        'designs_carteirinha' => ['bg_frente_url', 'bg_verso_url'],
        'lotes_importacao' => ['arquivo_original_url', 'pdf_consolidado_url'],
        'mensagens_whatsapp' => ['arquivo_url'],
        'carteirinhas_emitidas' => ['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'],
        default => [],
    };
    foreach ($privateArtifacts as $field) {
        if (isset($row[$field]) && trim((string) $row[$field]) !== '') {
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
        'configuracoes_instalacao' => ['ator_usuario_id'],
        default => [],
    };
    foreach ($redacted as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = null;
        }
    }
    if ($table === 'outbox_eventos') {
        $row['payload_json'] = null;
        $row['status'] = 'cancelled';
        $row['tentativas'] = 0;
        $row['proxima_tentativa_em'] = null;
        $row['correlation_id'] = null;
    }
    if ($table === 'lotes_importacao') {
        $row['colunas_detectadas_json'] = null;
        $row['mapa_de_para_usado_json'] = null;
    }
    if ($table === 'whatsapp_instancias') {
        $row['nome_instancia'] = 'Instância protegida';
    }
}
