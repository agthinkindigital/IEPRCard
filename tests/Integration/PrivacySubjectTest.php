<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/privacy_subjects.php';
require_once dirname(__DIR__, 2) . '/config/privacy_snapshot.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-privacy-subject-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true)) {
    throw new RuntimeException('Could not create temporary privacy subject storage.');
}
$database = $temporary . DIRECTORY_SEPARATOR . 'subjects.sqlite';
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $database]);

try {
    $migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
    sort($migrationFiles, SORT_STRING);
    foreach ($migrationFiles as $migrationFile) {
        if (basename($migrationFile) > '0013_whatsapp_gateway.php') {
            break;
        }
        $migration = require $migrationFile;
        $migration($pdo, 'test');
    }
    $pdo->exec("INSERT INTO tenants (tipo, nome_organizacao, nome_responsavel, status_conta) VALUES ('cliente', 'Synthetic org', 'Legacy owner', 'ativo')");
    $tenantId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) "
        . "VALUES (:tenant, 'titular_cliente', 'Legacy owner', 'legacy-owner@example.test', 'synthetic-hash', 1, :created)"
    )->execute(['tenant' => $tenantId, 'created' => '2025-01-02 03:04:05']);
    $legacyOwnerId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO mensagens_whatsapp (tenant_id, telefone, direcao, tipo_mensagem, conteudo, criado_em) "
        . "VALUES (:tenant, '+5511999990000', 'entrada', 'texto', 'synthetic message', :created)"
    )->execute(['tenant' => $tenantId, 'created' => '2025-01-02 03:04:05']);
    $legacyMessageId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO acessos_suporte (tenant_id, usuario_id, token_hash, tipo, criado_em, expira_em) "
        . "VALUES (:tenant, :user, 'synthetic-token-hash', 'suporte', :created, :expires)"
    )->execute(['tenant' => $tenantId, 'user' => $legacyOwnerId, 'created' => '2025-01-02 03:04:05', 'expires' => '2025-01-02 04:04:05']);
    $legacyAccessId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO mcp_api_keys (tenant_id, usuario_id, nome, chave_hash, escopos_json, criado_em) '
        . 'VALUES (:tenant, :user, :name, :hash, :scopes, :created)'
    )->execute([
        'tenant' => $tenantId, 'user' => $legacyOwnerId, 'name' => 'Synthetic key',
        'hash' => 'synthetic-key-hash', 'scopes' => '[]', 'created' => '2025-01-02 03:04:05',
    ]);
    $legacyApiKeyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, ativo, criado_em) VALUES (:tenant, :name, 0, :created)'
    )->execute(['tenant' => $tenantId, 'name' => 'Legacy holder', 'created' => '2025-01-02 03:04:05']);
    $legacyHolderId = (int) $pdo->lastInsertId();
    foreach (array_slice($migrationFiles, 13) as $migrationFile) {
        $migration = require $migrationFile;
        $migration($pdo, 'test');
    }
    schemaGarantirEstrutura($pdo, 'test');

    $legacy = $pdo->prepare(
        'SELECT s.referencia, r.privacy_subject_id FROM titulares t '
        . 'JOIN privacy_subject_records r ON r.tipo_registro = \'titular\' AND r.registro_id = t.id '
        . 'JOIN privacy_subjects s ON s.id = r.privacy_subject_id WHERE t.id = :id'
    );
    $legacy->execute(['id' => $legacyHolderId]);
    if (!is_array($legacyRow = $legacy->fetch(PDO::FETCH_ASSOC))
        || !hash_equals((string) $pdo->query('SELECT referencia_privacidade FROM titulares WHERE id = ' . $legacyHolderId)->fetchColumn(), (string) $legacyRow['referencia'])) {
        throw new RuntimeException('Migration must preserve existing holder references and link them to privacy subjects.');
    }
    $ownerSubject = privacySubjectFindForRecord($pdo, 'usuario', $legacyOwnerId);
    $contactSubject = privacySubjectFindForRecord($pdo, 'tenant_contato', $tenantId);
    $supportSubject = privacySubjectFindForRecord($pdo, 'acesso_suporte', $legacyAccessId);
    $apiKeySubject = privacySubjectFindForRecord($pdo, 'mcp_api_key', $legacyApiKeyId);
    $messageSubject = privacySubjectFindForRecord($pdo, 'mensagem_whatsapp', $legacyMessageId);
    if (!is_array($ownerSubject) || !is_array($contactSubject) || !is_array($supportSubject)
        || !is_array($apiKeySubject) || !is_array($messageSubject)
        || $ownerSubject['id'] !== $contactSubject['id'] || $ownerSubject['id'] !== $supportSubject['id']
        || $ownerSubject['id'] !== $apiKeySubject['id'] || $messageSubject['type'] !== 'contato') {
        throw new RuntimeException('Privacy subject migration must group account, tenant contact, support and API key data while scoping legacy WhatsApp PII.');
    }
    $pdo->prepare('UPDATE titulares SET foto_url = :url WHERE id = :id')->execute([
        'url' => '/private/titular-photo.webp', 'id' => $legacyHolderId,
    ]);
    try {
        privacySnapshotBuildPayload($pdo, static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $plaintext));
        throw new RuntimeException('Snapshot generation must fail closed while private artifact bytes are not included.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_ARTIFACT_ENCRYPTION_REQUIRED') {
            throw $exception;
        }
    }
    $pdo->prepare('UPDATE titulares SET foto_url = NULL WHERE id = :id')->execute(['id' => $legacyHolderId]);
    $pdo->prepare(
        'INSERT INTO auditoria_processos (tenant_id, ator_usuario_id, ator_tipo, acao, entidade_id, antes_json, criado_em) '
        . 'VALUES (:tenant, :actor, :actor_type, :action, :entity_id, :before, :created)'
    )->execute([
        'tenant' => $tenantId, 'actor' => $legacyOwnerId, 'actor_type' => 'titular_cliente', 'action' => 'synthetic.audit',
        'entity_id' => $legacyHolderId, 'before' => '{"email":"audit-private@example.test"}', 'created' => '2026-11-22 12:00:00',
    ]);
    $pdo->prepare(
        'INSERT INTO telemetria_eventos (tenant_id, usuario_id, categoria, evento_chave, quantidade, metadados_json, criado_em) '
        . 'VALUES (:tenant, :actor, :category, :event, 1, :metadata, :created)'
    )->execute([
        'tenant' => $tenantId, 'actor' => $legacyOwnerId, 'category' => 'synthetic', 'event' => 'synthetic.event',
        'metadata' => '{"contact":"telemetry-private@example.test"}', 'created' => '2026-11-22 12:00:00',
    ]);
    $pdo->prepare(
        'INSERT INTO outbox_eventos (evento_chave, tipo_evento, payload_json, status, criado_em) '
        . 'VALUES (:event, :type, :payload, :status, :created)'
    )->execute([
        'event' => 'synthetic-outbox', 'type' => 'synthetic.event', 'payload' => '{"email":"outbox-private@example.test"}',
        'status' => 'pending', 'created' => '2026-11-22 12:00:00',
    ]);
    $snapshot = privacySnapshotBuildPayload($pdo, static function (int $subjectId, string $plaintext): string {
        return 'v1:test-cipher:' . hash('sha256', $subjectId . ':' . $plaintext);
    }, '2026-11-22T12:00:00Z');
    $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach ([
        'Legacy owner', 'legacy-owner@example.test', 'synthetic message', '+5511999990000', 'Legacy holder',
        'audit-private@example.test', 'telemetry-private@example.test', 'outbox-private@example.test',
    ] as $pii) {
        if (str_contains($snapshotJson, $pii)) {
            throw new RuntimeException('Snapshot payload must not contain personal data in plaintext.');
        }
    }
    if (($snapshot['manifest']['format'] ?? null) !== 'ieprcard-snapshot-v1'
        || !is_array($snapshot['subject_envelopes'] ?? null) || count($snapshot['subject_envelopes']) < 3) {
        throw new RuntimeException('Snapshot must separate PII into versioned per-subject envelopes.');
    }

    $subject = privacySubjectCreate($pdo, 'pessoa', $tenantId, '2026-11-22 12:00:00');
    $reference = (string) $subject['reference'];
    if (preg_match('/^[a-f0-9]{64}$/D', $reference) !== 1) {
        throw new RuntimeException('Privacy subject references must be random opaque tokens.');
    }

    $subjectId = (int) $subject['id'];
    privacySubjectLinkRecord($pdo, $subjectId, 'usuario', 987654);
    privacySubjectLinkRecord($pdo, $subjectId, 'campo_privado', 987654);
    $records = $pdo->prepare(
        'SELECT tipo_registro, registro_id FROM privacy_subject_records WHERE privacy_subject_id = :subject ORDER BY tipo_registro'
    );
    $records->execute(['subject' => $subjectId]);
    $linked = $records->fetchAll(PDO::FETCH_ASSOC);
    if (count($linked) !== 2 || !in_array(['tipo_registro' => 'usuario', 'registro_id' => 987654], $linked, true)
        || !in_array(['tipo_registro' => 'campo_privado', 'registro_id' => 987654], $linked, true)) {
        throw new RuntimeException('One person must be able to group related identity records under one opaque subject.');
    }

    privacySubjectLinkRecord($pdo, $subjectId, 'usuario', 987654);
    $duplicateCount = $pdo->query(
        "SELECT COUNT(*) FROM privacy_subject_records WHERE tipo_registro = 'usuario' AND registro_id = 987654"
    )->fetchColumn();
    if ((int) $duplicateCount !== 1) {
        throw new RuntimeException('Linking the same record twice must remain idempotent.');
    }

    $columns = $pdo->query('PRAGMA table_info(privacy_subjects)')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    if (array_intersect(['nome', 'email', 'telefone', 'cpf', 'dados_pessoais_json'], $columnNames) !== []) {
        throw new RuntimeException('Privacy subject records must not persist direct personal data.');
    }

    try {
        privacySubjectLinkRecord($pdo, $subjectId, 'campo_arbitrario', 502);
        throw new RuntimeException('Unsupported record types must be rejected.');
    } catch (InvalidArgumentException) {
    }

    fwrite(STDOUT, "PASS: opaque privacy subjects group PII records without persisting PII.\n");
} finally {
    unset($records);
    unset($legacy);
    if ($pdo instanceof PDO) {
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('PRAGMA journal_mode = DELETE');
    }
    $pdo = null;
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($temporary);
}

return 'pass';
