<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/privacy_subjects.php';
require_once dirname(__DIR__, 2) . '/config/privacy_snapshot.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-privacy-subject-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true)) {
    throw new RuntimeException('Could not create temporary privacy subject storage.');
}
$oldPrivateStorage = getenv('IEPR_PRIVATE_STORAGE_PATH');
$privateStorage = $temporary . DIRECTORY_SEPARATOR . 'private';
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $privateStorage);
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
    $pdo->prepare('UPDATE tenants SET nome_organizacao = :name, slug_subdominio = :slug WHERE id = :id')->execute([
        'name' => 'Synthetic privacy organization', 'slug' => 'synthetic-private-subdomain', 'id' => $tenantId,
    ]);
    $pdo->prepare(
        "INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) "
        . "VALUES (:tenant, 'titular_cliente', 'Legacy owner', 'legacy-owner@example.test', 'synthetic-hash', 1, :created)"
    )->execute(['tenant' => $tenantId, 'created' => '2025-01-02 03:04:05']);
    $legacyOwnerId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE usuarios SET ultimo_login_em = :last_login WHERE id = :id')->execute([
        'last_login' => '2026-11-22 11:59:00', 'id' => $legacyOwnerId,
    ]);
    $pdo->prepare(
        "INSERT INTO mensagens_whatsapp (tenant_id, telefone, direcao, tipo_mensagem, conteudo, id_mensagem_api, criado_em) "
        . "VALUES (:tenant, '+5511999990000', 'entrada', 'texto', 'synthetic message', 'external-private-message-id', :created)"
    )->execute(['tenant' => $tenantId, 'created' => '2025-01-02 03:04:05']);
    $legacyMessageId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO acessos_suporte (tenant_id, usuario_id, token_hash, tipo, criado_em, expira_em, usado_em) "
        . "VALUES (:tenant, :user, 'synthetic-token-hash', 'suporte', :created, :expires, :used)"
    )->execute([
        'tenant' => $tenantId, 'user' => $legacyOwnerId, 'created' => '2025-01-02 03:04:05',
        'expires' => '2025-01-02 04:04:05', 'used' => '2026-11-22 11:43:10',
    ]);
    $legacyAccessId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO mcp_api_keys (tenant_id, usuario_id, nome, chave_hash, escopos_json, criado_em) '
        . 'VALUES (:tenant, :user, :name, :hash, :scopes, :created)'
    )->execute([
        'tenant' => $tenantId, 'user' => $legacyOwnerId, 'name' => 'Synthetic key',
        'hash' => 'synthetic-key-hash', 'scopes' => '[]', 'created' => '2025-01-02 03:04:05',
    ]);
    $legacyApiKeyId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE mcp_api_keys SET ultimo_uso_em = :used WHERE id = :id')->execute([
        'used' => '2026-11-22 11:44:11', 'id' => $legacyApiKeyId,
    ]);
    $pdo->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, ativo, criado_em) VALUES (:tenant, :name, 0, :created)'
    )->execute(['tenant' => $tenantId, 'name' => 'Legacy holder', 'created' => '2025-01-02 03:04:05']);
    $legacyHolderId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (tipo, nome_organizacao, status_conta, criado_em) VALUES ('cliente', 'Synthetic tenant without contact', 'ativo', '2025-03-04 05:06:07')");
    $tenantWithoutContactId = (int) $pdo->lastInsertId();
    foreach (array_slice($migrationFiles, 13) as $migrationFile) {
        $migration = require $migrationFile;
        $migration($pdo, 'test');
    }
    schemaGarantirEstrutura($pdo, 'test');
    $pdo->prepare('UPDATE mensagens_whatsapp SET criado_em = :created WHERE id = :id')->execute([
        'created' => '2026-11-22 11:42:09', 'id' => $legacyMessageId,
    ]);
    $pdo->prepare('UPDATE titulares SET ultima_interacao_em = :last_seen WHERE id = :id')->execute([
        'last_seen' => '2026-11-22 11:47:14', 'id' => $legacyHolderId,
    ]);
    $pdo->prepare(
        'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) '
        . 'VALUES (:tenant, :name, :orientation, :created)'
    )->execute([
        'tenant' => $tenantId, 'name' => 'Synthetic privacy design', 'orientation' => 'horizontal',
        'created' => '2026-11-22 11:48:15',
    ]);
    $designId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas (tenant_id, titular_id, design_id, codigo_validacao_qr, '
        . 'status_carteirinha, data_validade, snapshot_dados_json, total_leituras_qr, emitida_em) '
        . 'VALUES (:tenant, :holder, :design, :code, :status, :valid, :snapshot, :reads, :issued)'
    )->execute([
        'tenant' => $tenantId, 'holder' => $legacyHolderId, 'design' => $designId,
        'code' => 'synthetic-card-code', 'status' => 'ativa', 'valid' => '2027-11-22',
        'snapshot' => '{"name":"Legacy holder"}', 'reads' => 7, 'issued' => '2026-11-22 11:49:16',
    ]);
    $legacyCardId = (int) $pdo->lastInsertId();
    $holderPrivacySubject = privacySubjectEnsureTitular($pdo, $legacyHolderId, $tenantId);
    privacySubjectLinkRecord($pdo, (int) $holderPrivacySubject['id'], 'carteirinha_emitida', $legacyCardId);
    $pdo->prepare(
        "INSERT INTO lotes_importacao (tenant_id, origem_tipo, status_lote, criado_em) "
        . "VALUES (:tenant, 'manual', 'concluido', :created)"
    )->execute(['tenant' => $tenantId, 'created' => '2026-11-22 11:50:17']);
    $linkedJobId = (int) $pdo->lastInsertId();

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
    $tenantWithoutContactSubject = privacySubjectFindForRecord($pdo, 'tenant_contato', $tenantWithoutContactId);
    $supportSubject = privacySubjectFindForRecord($pdo, 'acesso_suporte', $legacyAccessId);
    $apiKeySubject = privacySubjectFindForRecord($pdo, 'mcp_api_key', $legacyApiKeyId);
    $messageSubject = privacySubjectFindForRecord($pdo, 'mensagem_whatsapp', $legacyMessageId);
    $pdo->prepare(
        'INSERT INTO dominios_tenant (tenant_id, hostname, estado, token_verificacao_hash, verificado_em, tls_validado_em, criado_em) '
        . 'VALUES (:tenant, :host, :state, :token, :verified, :tls, :created)'
    )->execute([
        'tenant' => $tenantId, 'host' => 'person-owned-private-domain.example.test', 'state' => 'ativo',
        'token' => hash('sha256', 'synthetic-domain-token'), 'verified' => '2026-11-22 11:51:18',
        'tls' => '2026-11-22 11:52:19', 'created' => '2026-11-22 11:50:17',
    ]);
    $legacyDomainId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE privacy_subjects SET criado_em = :created WHERE id = :id')->execute([
        'created' => '2026-11-22 11:45:12', 'id' => $messageSubject['id'],
    ]);
    $pdo->prepare(
        'UPDATE privacy_subject_records SET criado_em = :created WHERE privacy_subject_id = :subject AND tipo_registro = :type AND registro_id = :record'
    )->execute([
        'created' => '2026-11-22 11:46:13', 'subject' => $messageSubject['id'],
        'type' => 'mensagem_whatsapp', 'record' => $legacyMessageId,
    ]);
    if (!is_array($ownerSubject) || !is_array($contactSubject) || !is_array($supportSubject)
        || !is_array($tenantWithoutContactSubject) || !is_array($apiKeySubject) || !is_array($messageSubject)
        || $ownerSubject['id'] !== $contactSubject['id'] || $ownerSubject['id'] !== $supportSubject['id']
        || $ownerSubject['id'] !== $apiKeySubject['id'] || $messageSubject['type'] !== 'contato') {
        throw new RuntimeException('Privacy subject migration must group account, tenant contact, support and API key data while scoping legacy WhatsApp PII.');
    }
    $pdo->prepare(
        'INSERT INTO whatsapp_instancias (tenant_id, escopo_chave, nome_instancia, webhook_token_hash, estado, criado_em, atualizado_em) '
        . 'VALUES (:tenant, :scope, :name, :token, :state, :created, :updated)'
    )->execute([
        'tenant' => $tenantId, 'scope' => 'synthetic-scope', 'name' => 'Synthetic instance',
        'token' => hash('sha256', 'synthetic-token'), 'state' => 'ativa',
        'created' => '2026-11-22 12:00:00', 'updated' => '2026-11-22 12:00:00',
    ]);
    $instanceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO whatsapp_conversas (instancia_id, tenant_id, contato_hmac, estado, consentimento_finalidade, '
        . 'consentimento_em, revogado_em, titular_id, job_id, expira_em, criado_em, atualizado_em) '
        . 'VALUES (:instance, :tenant, :contact, :state, :purpose, :consented, :revoked, :holder, :job, :expires, :created, :updated)'
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'contact' => hash('sha256', 'synthetic-contact'),
        'state' => 'concluida', 'purpose' => 'synthetic-consent-purpose', 'holder' => $legacyHolderId,
        'job' => $linkedJobId,
        'consented' => '2026-11-22 11:13:14', 'revoked' => '2026-11-22 11:23:45',
        'expires' => '2026-11-22 13:00:00', 'created' => '2026-11-22 10:00:00', 'updated' => '2026-11-22 11:30:00',
    ]);
    $conversationId = (int) $pdo->lastInsertId();
    $conversationSubject = privacySubjectCreate($pdo, 'contato', $tenantId, '2026-11-22 12:00:00');
    privacySubjectLinkRecord($pdo, (int) $conversationSubject['id'], 'whatsapp_conversa', $conversationId);
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
    $artifactDirectory = $privateStorage . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!mkdir($artifactDirectory, 0700, true)) {
        throw new RuntimeException('Could not create private artifact fixture.');
    }
    $artifactBytes = 'synthetic-private-photo-bytes';
    $artifactName = str_repeat('a', 48) . '.webp';
    file_put_contents($artifactDirectory . DIRECTORY_SEPARATOR . $artifactName, $artifactBytes);
    $artifactKey = 'private://' . $tenantId . '/' . $artifactName;
    $individualPdfBytes = "%PDF-1.4\nsynthetic individual card\n";
    $individualPdfName = str_repeat('c', 48) . '.pdf';
    file_put_contents($artifactDirectory . DIRECTORY_SEPARATOR . $individualPdfName, $individualPdfBytes);
    $individualPdfKey = 'private://' . $tenantId . '/' . $individualPdfName;
    $pdo->prepare('UPDATE carteirinhas_emitidas SET pdf_individual_url = :pdf WHERE id = :id')->execute([
        'pdf' => $individualPdfKey, 'id' => $legacyCardId,
    ]);
    $pdo->prepare('UPDATE lotes_importacao SET pdf_consolidado_url = :pdf WHERE id = :id')->execute([
        'pdf' => 'private://' . $tenantId . '/' . str_repeat('d', 48) . '.pdf', 'id' => $linkedJobId,
    ]);
    $pdo->prepare('UPDATE titulares SET foto_url = :url WHERE id = :id')->execute([
        'url' => $artifactKey, 'id' => $legacyHolderId,
    ]);
    $pdo->prepare('UPDATE designs_carteirinha SET bg_frente_url = :url WHERE id = :id')->execute([
        'url' => $artifactKey, 'id' => $designId,
    ]);
    $pdo->prepare(
        "INSERT INTO design_fundos_privacidade (tenant_id, design_id, lado, classificacao, titular_id, asset_url, criado_em, atualizado_em) "
        . "VALUES (:tenant, :design, 'frente', 'titular', :holder, :asset, :created, :updated)"
    )->execute([
        'tenant' => $tenantId, 'design' => $designId, 'holder' => $legacyHolderId, 'asset' => $artifactKey,
        'created' => '2026-11-22 11:55:00', 'updated' => '2026-11-22 11:55:00',
    ]);
    $designBackgroundId = (int) $pdo->lastInsertId();
    privacySubjectLinkRecord($pdo, (int) $holderPrivacySubject['id'], 'design_fundo', $designBackgroundId);
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
        'event' => 'outbox-event-private@example.test', 'type' => 'synthetic.event', 'payload' => '{"email":"outbox-private@example.test"}',
        'status' => 'pending', 'created' => '2026-11-22 12:00:00',
    ]);
    $webhookEventId = 'webhook-event-private@example.test';
    $webhookPayloadHash = hash('sha256', 'payload containing webhook-event-private@example.test');
    $pdo->prepare(
        'INSERT INTO webhooks_recebidos (evento_id, origem, payload_hash, estado, recebido_em) '
        . 'VALUES (:event, :origin, :hash, :state, :received)'
    )->execute([
        'event' => $webhookEventId, 'origin' => 'synthetic', 'hash' => $webhookPayloadHash,
        'state' => 'recebido', 'received' => '2026-11-22 12:00:00',
    ]);
    $webhookRowId = (int) $pdo->lastInsertId();
    $importMetadata = [
        'usuario_id' => 19,
        'titular_origem_id' => 23,
        'colunas_detectadas_json' => '["Nome de Maria Silva", "email"]',
        'mapa_de_para_usado_json' => '{"Nome de Maria Silva":"nome_completo"}',
    ];
    try {
        privacySnapshotAssertNoPiiLabel('designs_carteirinha', ['nome_layout' => 'Layout Maria Silva joao@example.test']);
        throw new RuntimeException('A snapshot label containing a personal contact must be rejected.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_LABEL_PII') {
            throw $exception;
        }
    }
    try {
        privacySnapshotAssertNoPiiLabel('perfis_acesso', ['nome_perfil' => 'suporte joao@example.test']);
        throw new RuntimeException('A profile label containing a personal contact must be rejected.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_LABEL_PII') {
            throw $exception;
        }
    }
    foreach ([
        ['planos', ['descricao' => 'Contato joao@example.test']],
        ['designs_carteirinha', ['mapeamento_fixo_json' => '{"nome":{"tipo":"valor","valor":"joao@example.test"}}']],
    ] as [$labelTable, $labelRow]) {
        try {
            privacySnapshotAssertNoPiiLabel($labelTable, $labelRow);
            throw new RuntimeException('Free plan/design configuration containing a personal contact must be rejected.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_LABEL_PII') {
                throw $exception;
            }
        }
    }
    privacySnapshotMinimizeOperationalRows('lotes_importacao', $importMetadata);
    if ($importMetadata['usuario_id'] !== null || $importMetadata['titular_origem_id'] !== null
        || $importMetadata['colunas_detectadas_json'] !== null || $importMetadata['mapa_de_para_usado_json'] !== null) {
        throw new RuntimeException('Import labels and actor/holder links must be excluded from snapshots when they lack a removable subject envelope.');
    }
    $actorMetadata = ['alterado_por_usuario_id' => 19];
    privacySnapshotMinimizeOperationalRows('configuracoes_operacionais', $actorMetadata);
    if ($actorMetadata['alterado_por_usuario_id'] !== null) {
        throw new RuntimeException('Operational configuration must not retain a direct user link without its own subject envelope.');
    }
    $supportGrant = [
        'id' => 41, 'segredo_hash' => password_hash('live-support-secret', PASSWORD_DEFAULT),
        'rotulo' => 'Temporary support by owner@example.test', 'criado_por_usuario_id' => 7,
        'capacidades_json' => '["repo.publish"]', 'revogado_em' => null, 'ultimo_uso_em' => '2026-11-24 12:00:00',
    ];
    privacySnapshotStripUnrecoverableSecrets('mcp_support_grants', $supportGrant);
    privacySnapshotMinimizeOperationalRows('mcp_support_grants', $supportGrant);
    if ($supportGrant['rotulo'] !== 'Grant revogado no snapshot' || $supportGrant['criado_por_usuario_id'] !== null
        || $supportGrant['capacidades_json'] !== '[]' || $supportGrant['revogado_em'] === null
        || password_verify('live-support-secret', $supportGrant['segredo_hash'])) {
        throw new RuntimeException('Snapshots must revoke MCP grants, remove their personal labels and disable their stored secrets.');
    }
    $supportAudit = [
        'actor_user_id' => 7, 'grant_id' => 41, 'target_tenant_id' => 9, 'target_subcliente_id' => 10,
        'operation_key' => 'support-operation-key', 'commit_sha' => str_repeat('a', 40), 'correlation_id' => 'support-correlation',
    ];
    privacySnapshotMinimizeOperationalRows('mcp_support_audit_logs', $supportAudit);
    if (array_filter($supportAudit, static fn(mixed $value): bool => $value !== null) !== []) {
        throw new RuntimeException('Support audit snapshots must remove actor, target and repository-operation identifiers.');
    }
    if (!in_array('mcp_support_grant_targets', privacySnapshotOmittedTables(), true)
        || !in_array('mcp_support_github_config', privacySnapshotOmittedTables(), true)
        || !in_array('tenant_owner_invites', privacySnapshotOmittedTables(), true)) {
        throw new RuntimeException('Snapshot policy must omit frozen support targets, encrypted GitHub credentials, and one-time Sede invitations.');
    }
    $integrationConfiguration = [
        'IEPR_INTEGRATION_KEY' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        'IEPR_ANONYMIZATION_KEY' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
    ];
    $tenantAiSecretPlaintext = 'synthetic-tenant-ai-secret-do-not-expose';
    $tenantAiSecretCiphertext = aiGlobalConfigSeal($tenantAiSecretPlaintext, $integrationConfiguration);
    $pdo->prepare(
        'INSERT INTO ia_config (tenant_id, provedor, chave_cifrada, modelo, ativo, criado_em, atualizado_em) '
        . 'VALUES (:tenant, :provider, :secret, :model, 1, :created, :updated)'
    )->execute([
        'tenant' => $tenantId, 'provider' => 'openai', 'secret' => $tenantAiSecretCiphertext,
        'model' => 'synthetic-model', 'created' => '2026-11-22 12:00:00', 'updated' => '2026-11-22 12:00:00',
    ]);
    $globalAiSecretPlaintext = 'synthetic-global-ai-secret-do-not-expose';
    $globalAiSecretCiphertext = aiGlobalConfigSeal($globalAiSecretPlaintext, $integrationConfiguration);
    $pdo->prepare(
        'INSERT INTO ia_config_global (provedor, chave_cifrada, modelo, ativo, criado_em, atualizado_em) '
        . 'VALUES (:provider, :secret, :model, 1, :created, :updated)'
    )->execute([
        'provider' => 'gemini', 'secret' => $globalAiSecretCiphertext, 'model' => 'synthetic-global-model',
        'created' => '2026-11-22 12:00:00', 'updated' => '2026-11-22 12:00:00',
    ]);
    $whatsappSecretPlaintext = 'synthetic-whatsapp-secret-do-not-expose';
    $whatsappSecretCiphertext = aiGlobalConfigSeal($whatsappSecretPlaintext, $integrationConfiguration);
    $pdo->prepare(
        'INSERT INTO whatsapp_config_global (chave, api_base_url, api_token_cifrado, criado_em, atualizado_em) '
        . 'VALUES (:key, :url, :secret, :created, :updated)'
    )->execute([
        'key' => 'global', 'url' => 'https://gateway.example.test', 'secret' => $whatsappSecretCiphertext,
        'created' => '2026-11-22 12:00:00', 'updated' => '2026-11-22 12:00:00',
    ]);
    $s3SecretPlaintext = json_encode(
        ['access_key' => 'synthetic-s3-access', 'secret_key' => 'synthetic-s3-secret-do-not-expose'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    $s3SecretCiphertext = aiGlobalConfigSeal($s3SecretPlaintext, $integrationConfiguration);
    $pdo->prepare(
        'INSERT INTO destinos_backup (nome, provedor, bucket, regiao, credentials_cifradas, ativo, criado_em, atualizado_em) '
        . 'VALUES (:name, :provider, :bucket, :region, :secret, 1, :created, :updated)'
    )->execute([
        'name' => 'Synthetic backup destination', 'provider' => 's3', 'bucket' => 'iepr-test-snapshot',
        'region' => 'us-east-1', 'secret' => $s3SecretCiphertext,
        'created' => '2026-11-22 12:00:00', 'updated' => '2026-11-22 12:00:00',
    ]);
    $oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
    putenv('IEPR_ANONYMIZATION_KEY=');
    try {
        privacySnapshotBuildPayload($pdo);
        throw new RuntimeException('Snapshot generation must fail closed when its subject key is unavailable.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'ANONYMIZATION_KEY_UNAVAILABLE') {
            throw $exception;
        }
    } finally {
        putenv('IEPR_ANONYMIZATION_KEY=' . ($oldAnonymizationKey === false ? '' : $oldAnonymizationKey));
    }
    $subjectPlaintexts = [];
    $snapshot = privacySnapshotBuildPayload($pdo, static function (int $subjectId, string $plaintext) use (&$subjectPlaintexts): string {
        $subjectPlaintexts[$subjectId] = $plaintext;
        return 'v1:test-cipher:' . hash('sha256', $subjectId . ':' . $plaintext);
    }, '2026-11-22T12:00:00Z');
    $expectedSchemaInventory = [];
    foreach (\IEPRCard\Database\Schema\catalogoCanonico() as $table => $definition) {
        $columns = $definition['columns'] ?? [];
        ksort($columns, SORT_STRING);
        $expectedSchemaInventory[$table] = array_keys($columns);
    }
    ksort($expectedSchemaInventory, SORT_STRING);
    if (($snapshot['manifest']['schema_inventory'] ?? null) !== $expectedSchemaInventory) {
        throw new RuntimeException('Snapshot manifest must enumerate every reviewed table and field in the canonical schema.');
    }
    $expectedPayloadChecksum = hash('sha256', json_encode([
        'tables' => $snapshot['tables'],
        'subject_envelopes' => $snapshot['subject_envelopes'],
        'schema_inventory' => $snapshot['manifest']['schema_inventory'],
        'schema_inventory_sha256' => $snapshot['manifest']['schema_inventory_sha256'] ?? null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!hash_equals($expectedPayloadChecksum, (string) ($snapshot['manifest']['payload_sha256'] ?? ''))) {
        throw new RuntimeException('Snapshot integrity checksum must cover the schema inventory and encrypted data.');
    }
    $unreviewedCatalog = \IEPRCard\Database\Schema\catalogoCanonico();
    $unreviewedCatalog['titulares']['columns']['campo_pessoal_novo'] = ['type' => 'text'];
    try {
        privacySnapshotAssertReviewedSchema($unreviewedCatalog);
        throw new RuntimeException('A snapshot must fail closed when its schema inventory changes without review.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_SCHEMA_REVIEW_REQUIRED') {
            throw $exception;
        }
    }
    $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach ([
        'Legacy owner', 'legacy-owner@example.test', 'synthetic message', '+5511999990000', 'Legacy holder',
        'external-private-message-id', '2026-11-22 11:59:00',
        '2026-11-22 11:42:09', '2026-11-22 11:43:10', '2026-11-22 11:44:11',
        '2026-11-22 11:47:14', '2027-11-22', '2026-11-22 11:49:16',
        'person-owned-private-domain.example.test',
        'audit-private@example.test', 'telemetry-private@example.test', 'outbox-private@example.test',
        'outbox-event-private@example.test', $webhookEventId, $webhookPayloadHash,
        'Synthetic privacy organization', 'Synthetic tenant without contact', 'synthetic-private-subdomain',
        'synthetic-consent-purpose', '2026-11-22 11:13:14', '2026-11-22 11:23:45',
        $artifactKey, $artifactBytes, $tenantAiSecretPlaintext, $globalAiSecretPlaintext,
        $whatsappSecretPlaintext, 'synthetic-s3-access', 'synthetic-s3-secret-do-not-expose',
    ] as $pii) {
        if (str_contains($snapshotJson, $pii)) {
            $containingTables = [];
            foreach ($snapshot['tables'] as $snapshotTable => $snapshotRows) {
                if (str_contains(json_encode($snapshotRows, JSON_THROW_ON_ERROR), $pii)) {
                    $containingTables[] = $snapshotTable;
                }
            }
            throw new RuntimeException('Snapshot payload must not contain personal data in plaintext: ' . $pii
                . ' (tables=' . implode(',', $containingTables) . ').');
        }
    }
    if (($snapshot['manifest']['format'] ?? null) !== 'ieprcard-snapshot-v2'
        || !is_array($snapshot['subject_envelopes'] ?? null) || count($snapshot['subject_envelopes']) < 3) {
        throw new RuntimeException('Snapshot must separate PII into versioned per-subject envelopes.');
    }
    $outboxSnapshot = $snapshot['tables']['outbox_eventos'][0] ?? [];
    $webhookSnapshot = array_values(array_filter(
        $snapshot['tables']['webhooks_recebidos'],
        static fn(array $row): bool => (int) ($row['id'] ?? 0) === $webhookRowId
    ))[0] ?? [];
    if (($outboxSnapshot['evento_chave'] ?? null) !== 'snapshot-redacted-' . (string) ($outboxSnapshot['id'] ?? '')
        || ($webhookSnapshot['evento_id'] ?? null) !== 'snapshot-redacted-' . $webhookRowId
        || ($webhookSnapshot['payload_hash'] ?? null) === $webhookPayloadHash) {
        throw new RuntimeException('Webhook and outbox identifiers or payload fingerprints must be redacted before snapshot publication.');
    }
    $snapshotConfigRows = [
        'ia_config' => $snapshot['tables']['ia_config'][0] ?? [],
        'ia_config_global' => $snapshot['tables']['ia_config_global'][0] ?? [],
        'whatsapp_config_global' => $snapshot['tables']['whatsapp_config_global'][0] ?? [],
        'destinos_backup' => $snapshot['tables']['destinos_backup'][0] ?? [],
    ];
    if (($snapshotConfigRows['ia_config']['chave_cifrada'] ?? null) !== $tenantAiSecretCiphertext
        || ($snapshotConfigRows['ia_config_global']['chave_cifrada'] ?? null) !== $globalAiSecretCiphertext
        || ($snapshotConfigRows['whatsapp_config_global']['api_token_cifrado'] ?? null) !== $whatsappSecretCiphertext
        || ($snapshotConfigRows['destinos_backup']['credentials_cifradas'] ?? null) !== $s3SecretCiphertext
        || ($snapshotConfigRows['ia_config']['ativo'] ?? null) !== 0
        || ($snapshotConfigRows['ia_config_global']['ativo'] ?? null) !== 0
        || ($snapshotConfigRows['destinos_backup']['ativo'] ?? null) !== 0
        || ($snapshotConfigRows['destinos_backup']['ultimo_teste_status'] ?? null) !== null) {
        throw new RuntimeException('Snapshots must preserve integration credentials as ciphertext while disabling integrations for restore.');
    }
    if (aiGlobalConfigOpen((string) $snapshotConfigRows['ia_config']['chave_cifrada'], $integrationConfiguration) !== $tenantAiSecretPlaintext
        || aiGlobalConfigOpen((string) $snapshotConfigRows['ia_config_global']['chave_cifrada'], $integrationConfiguration) !== $globalAiSecretPlaintext
        || aiGlobalConfigOpen((string) $snapshotConfigRows['whatsapp_config_global']['api_token_cifrado'], $integrationConfiguration) !== $whatsappSecretPlaintext
        || aiGlobalConfigOpen((string) $snapshotConfigRows['destinos_backup']['credentials_cifradas'], $integrationConfiguration) !== $s3SecretPlaintext) {
        throw new RuntimeException('Preserved integration ciphertext must remain recoverable with the separately managed integration key.');
    }
    $conversationSubjectPlaintext = $subjectPlaintexts[(int) $conversationSubject['id']] ?? '';
    if (!str_contains($conversationSubjectPlaintext, 'synthetic-consent-purpose')
        || !str_contains($conversationSubjectPlaintext, '2026-11-22 11:13:14')
        || !str_contains($conversationSubjectPlaintext, '2026-11-22 11:23:45')
        || !str_contains($conversationSubjectPlaintext, '"titular_id":' . $legacyHolderId)
        || !str_contains($conversationSubjectPlaintext, '"job_id":' . $linkedJobId)
        || ($snapshot['tables']['whatsapp_conversas'][0]['titular_id'] ?? null) !== null
        || ($snapshot['tables']['whatsapp_conversas'][0]['job_id'] ?? null) !== null) {
        throw new RuntimeException('WhatsApp consent, revocation and holder linkage must be encrypted in its removable subject envelope.');
    }
    $ownerSubjectPlaintext = $subjectPlaintexts[(int) $ownerSubject['id']] ?? '';
    if (!str_contains($ownerSubjectPlaintext, 'Synthetic privacy organization')
        || !str_contains($ownerSubjectPlaintext, 'synthetic-private-subdomain')
        || !str_contains($ownerSubjectPlaintext, 'person-owned-private-domain.example.test')) {
        throw new RuntimeException('Tenant identity and custom hostnames must remain recoverable only from the removable subject envelope.');
    }
    $ownerEnvelope = json_decode($ownerSubjectPlaintext, true);
    $apiKeyEnvelopeRow = null;
    foreach ($ownerEnvelope['records'] ?? [] as $record) {
        if (($record['table'] ?? null) === 'mcp_api_keys' && ($record['record_id'] ?? null) === $legacyApiKeyId) {
            $apiKeyEnvelopeRow = $record;
            break;
        }
    }
    if (($snapshot['tables']['acessos_suporte'][0]['usuario_id'] ?? null) !== null
        || ($snapshot['tables']['mcp_api_keys'][0]['usuario_id'] ?? null) !== null
        || !str_contains($ownerSubjectPlaintext, '"usuario_id":' . $legacyOwnerId)
        || ($apiKeyEnvelopeRow['fields']['usuario_id'] ?? null) !== $legacyOwnerId) {
        throw new RuntimeException('Support and API ownership links must remain recoverable only inside the owning person subject envelope.');
    }
    $unownedTenantPlaintext = $subjectPlaintexts[(int) $tenantWithoutContactSubject['id']] ?? '';
    if (!str_contains($unownedTenantPlaintext, 'Synthetic tenant without contact')) {
        throw new RuntimeException('An organization without a responsible contact must remain recoverable only from its removable privacy subject envelope.');
    }
    $snapshotDomains = array_values(array_filter(
        $snapshot['tables']['dominios_tenant'],
        static fn(array $row): bool => (int) ($row['id'] ?? 0) === $legacyDomainId
    ));
    $snapshotDomain = $snapshotDomains[0] ?? [];
    if (($snapshotDomain['hostname'] ?? null) !== 'snapshot-disabled-domain-' . $legacyDomainId . '.invalid'
        || ($snapshotDomain['estado'] ?? null) !== 'pendente_verificacao') {
        throw new RuntimeException('Restored custom hostnames must stay disabled until they are verified again.');
    }
    foreach (['2026-11-22 11:43:10', '2026-11-22 11:44:11'] as $activityTimestamp) {
        if (!str_contains($ownerSubjectPlaintext, $activityTimestamp)) {
            throw new RuntimeException('Support and MCP access timestamps must remain recoverable only from the removable subject envelope.');
        }
    }
    $messageSubjectPlaintext = $subjectPlaintexts[(int) $messageSubject['id']] ?? '';
    if (!str_contains($messageSubjectPlaintext, '2026-11-22 11:42:09')) {
        throw new RuntimeException('WhatsApp message timestamps must remain recoverable only from the removable subject envelope.');
    }
    $holderSubjectId = (int) $legacyRow['privacy_subject_id'];
    $holderEnvelope = json_decode((string) ($subjectPlaintexts[$holderSubjectId] ?? ''), true);
    $holderEnvelopeJson = json_encode($holderEnvelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!str_contains($holderEnvelopeJson, '2026-11-22 11:47:14')
        || !str_contains($holderEnvelopeJson, '2027-11-22')
        || !str_contains($holderEnvelopeJson, '2026-11-22 11:49:16')
        || ($snapshot['tables']['carteirinhas_emitidas'][0]['titular_id'] ?? null) !== null
        || !str_contains($holderEnvelopeJson, '"total_leituras_qr":7')
        || ($snapshot['tables']['carteirinhas_emitidas'][0]['total_leituras_qr'] ?? null) !== 0) {
        throw new RuntimeException('Titular interaction and card lifecycle metrics must be recoverable only from the removable subject envelope.');
    }
    $holderEnvelopeRecords = $holderEnvelope['records'] ?? [];
    $cardEnvelopeRow = null;
    foreach ($holderEnvelopeRecords as $record) {
        if (($record['table'] ?? null) === 'carteirinhas_emitidas' && ($record['record_id'] ?? null) === $legacyCardId) {
            $cardEnvelopeRow = $record;
            break;
        }
    }
    if (($cardEnvelopeRow['fields']['titular_id'] ?? null) !== $legacyHolderId) {
        throw new RuntimeException('Card ownership links must remain recoverable only inside the holder subject envelope.');
    }
    $artifactRecord = null;
    foreach ($holderEnvelope['records'] ?? [] as $record) {
        if (($record['table'] ?? null) === 'titulares' && ($record['record_id'] ?? null) === $legacyHolderId) {
            $artifactRecord = $record;
            break;
        }
    }
    $artifact = is_array($artifactRecord) ? ($artifactRecord['artifacts'][0] ?? null) : null;
    if (!is_array($artifact) || ($artifact['field'] ?? null) !== 'foto_url'
        || ($artifact['media_type'] ?? null) !== 'image/webp'
        || !hash_equals(hash('sha256', $artifactBytes), (string) ($artifact['sha256'] ?? ''))
        || base64_decode((string) ($artifact['contents_base64'] ?? ''), true) !== $artifactBytes
        || str_contains((string) ($snapshot['tables']['titulares'][0]['foto_url'] ?? ''), $artifactName)) {
        throw new RuntimeException('Private artifact bytes must be encrypted inside the linked subject envelope, without retaining storage paths.');
    }
    $designArtifactRecord = array_values(array_filter($holderEnvelope['records'] ?? [], static fn(array $record): bool => ($record['table'] ?? null) === 'design_fundos_privacidade'))[0] ?? null;
    $designArtifact = is_array($designArtifactRecord) ? ($designArtifactRecord['artifacts'][0] ?? null) : null;
    $designRows = array_values(array_filter($snapshot['tables']['designs_carteirinha'], static fn(array $row): bool => (int) ($row['id'] ?? 0) === $designId));
    if (!is_array($designArtifact) || ($designArtifact['field'] ?? null) !== 'asset_url'
        || base64_decode((string) ($designArtifact['contents_base64'] ?? ''), true) !== $artifactBytes
        || ($designRows[0]['bg_frente_url'] ?? null) !== null) {
        throw new RuntimeException('Titular-linked design backgrounds must live only in the titular envelope and lose the live storage path in snapshots.');
    }
    $cardRecord = array_values(array_filter($holderEnvelope['records'] ?? [], static fn(array $record): bool => ($record['table'] ?? null) === 'carteirinhas_emitidas'))[0] ?? null;
    $individualPdfArtifact = is_array($cardRecord)
        ? array_values(array_filter($cardRecord['artifacts'] ?? [], static fn(array $item): bool => ($item['field'] ?? null) === 'pdf_individual_url'))[0] ?? null
        : null;
    if (!is_array($individualPdfArtifact)
        || ($individualPdfArtifact['media_type'] ?? null) !== 'application/pdf'
        || !hash_equals(hash('sha256', $individualPdfBytes), (string) ($individualPdfArtifact['sha256'] ?? ''))
        || base64_decode((string) ($individualPdfArtifact['contents_base64'] ?? ''), true) !== $individualPdfBytes
        || ($snapshot['tables']['carteirinhas_emitidas'][0]['pdf_individual_url'] ?? null) !== null
        || ($snapshot['tables']['lotes_importacao'][0]['pdf_consolidado_url'] ?? null) !== null) {
        throw new RuntimeException('Snapshots must preserve each holder PDF in the subject envelope and omit derived consolidated PDFs.');
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

    $pdo->exec('CREATE TABLE tabela_privada_extension (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    try {
        privacySnapshotBuildPayload($pdo, static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $subjectId . ':' . $plaintext));
        throw new RuntimeException('Snapshots must fail closed when the active database has an unreviewed extra table.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_DATABASE_SCHEMA_UNREVIEWED') {
            throw $exception;
        }
    }

    $pdo->exec('ALTER TABLE titulares ADD COLUMN campo_privado_nao_inventariado TEXT');
    try {
        privacySnapshotBuildPayload($pdo, static fn(int $subjectId, string $plaintext): string => 'v1:test:' . hash('sha256', $subjectId . ':' . $plaintext));
        throw new RuntimeException('Snapshots must fail closed when the active database has columns outside the reviewed schema.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'PRIVACY_SNAPSHOT_DATABASE_SCHEMA_UNREVIEWED') {
            throw $exception;
        }
    }

    fwrite(STDOUT, "PASS: opaque privacy subjects group PII records without persisting PII.\n");
} finally {
    foreach ([$artifactName ?? null, $individualPdfName ?? null] as $storedArtifactName) {
        if (is_string($storedArtifactName) && isset($artifactDirectory)
            && is_file($artifactDirectory . DIRECTORY_SEPARATOR . $storedArtifactName)) {
            unlink($artifactDirectory . DIRECTORY_SEPARATOR . $storedArtifactName);
        }
    }
    if (isset($artifactDirectory) && is_dir($artifactDirectory)) {
        @rmdir($artifactDirectory);
    }
    if (is_dir($privateStorage)) {
        @rmdir($privateStorage);
    }
    if ($oldPrivateStorage === false) {
        putenv('IEPR_PRIVATE_STORAGE_PATH');
    } else {
        putenv('IEPR_PRIVATE_STORAGE_PATH=' . $oldPrivateStorage);
    }
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
