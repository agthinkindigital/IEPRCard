<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0017'));
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $insertSubject = $pdo->prepare($driver === 'mysql'
        ? 'INSERT IGNORE INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em) '
            . "VALUES (:reference, 'pessoa', :tenant, 'ativo', :created)"
        : 'INSERT INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em) '
            . "VALUES (:reference, 'pessoa', :tenant, 'ativo', :created) ON CONFLICT(referencia) DO NOTHING");
    $findSubject = $pdo->prepare('SELECT id FROM privacy_subjects WHERE referencia = :reference LIMIT 1');
    $insertRecord = $pdo->prepare($driver === 'mysql'
        ? 'INSERT IGNORE INTO privacy_subject_records (privacy_subject_id, tipo_registro, registro_id, criado_em) '
            . "VALUES (:subject, 'titular', :record, :created)"
        : 'INSERT INTO privacy_subject_records (privacy_subject_id, tipo_registro, registro_id, criado_em) '
            . "VALUES (:subject, 'titular', :record, :created) ON CONFLICT(tipo_registro, registro_id) DO NOTHING");
    $holders = $pdo->query(
        'SELECT tenant_id, id, referencia_privacidade, criado_em FROM titulares ORDER BY id'
    );
    while (($holder = $holders->fetch(PDO::FETCH_ASSOC)) !== false) {
        $reference = is_string($holder['referencia_privacidade'])
            && preg_match('/^[a-f0-9]{64}$/D', $holder['referencia_privacidade']) === 1
            ? $holder['referencia_privacidade']
            : bin2hex(random_bytes(32));
        $createdAt = is_string($holder['criado_em']) && $holder['criado_em'] !== ''
            ? $holder['criado_em']
            : gmdate('Y-m-d H:i:s');
        $insertSubject->execute([
            'reference' => $reference,
            'tenant' => (int) $holder['tenant_id'],
            'created' => $createdAt,
        ]);
        $findSubject->execute(['reference' => $reference]);
        $subjectId = $findSubject->fetchColumn();
        if ($subjectId === false) {
            throw new RuntimeException('PRIVACY_SUBJECT_BACKFILL_FAILED');
        }
        $insertRecord->execute([
            'subject' => (int) $subjectId,
            'record' => (int) $holder['id'],
            'created' => $createdAt,
        ]);
    }

    $now = gmdate('Y-m-d H:i:s');
    $recordExists = $pdo->prepare(
        'SELECT privacy_subject_id FROM privacy_subject_records WHERE tipo_registro = :type AND registro_id = :record LIMIT 1'
    );
    $insertGenericSubject = $pdo->prepare($driver === 'mysql'
        ? 'INSERT IGNORE INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em, inativado_em) '
            . 'VALUES (:reference, :type, :tenant, :state, :created, :inactive)'
        : 'INSERT INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em, inativado_em) '
            . 'VALUES (:reference, :type, :tenant, :state, :created, :inactive) ON CONFLICT(referencia) DO NOTHING');
    $findGenericSubject = $pdo->prepare('SELECT id FROM privacy_subjects WHERE referencia = :reference LIMIT 1');
    $insertGenericRecord = $pdo->prepare($driver === 'mysql'
        ? 'INSERT IGNORE INTO privacy_subject_records (privacy_subject_id, tipo_registro, registro_id, criado_em) '
            . 'VALUES (:subject, :type, :record, :created)'
        : 'INSERT INTO privacy_subject_records (privacy_subject_id, tipo_registro, registro_id, criado_em) '
            . 'VALUES (:subject, :type, :record, :created) ON CONFLICT(tipo_registro, registro_id) DO NOTHING');
    $ensureRecord = static function (
        string $recordType,
        int $recordId,
        ?int $tenantId,
        string $subjectType,
        string $createdAt,
        ?string $inactiveAt = null,
        ?string $reference = null
    ) use ($recordExists, $insertGenericSubject, $findGenericSubject, $insertGenericRecord): int {
        $recordExists->execute(['type' => $recordType, 'record' => $recordId]);
        $existingSubject = $recordExists->fetchColumn();
        if ($existingSubject !== false) {
            return (int) $existingSubject;
        }
        $reference ??= bin2hex(random_bytes(32));
        $insertGenericSubject->execute([
            'reference' => $reference,
            'type' => $subjectType,
            'tenant' => $tenantId,
            'state' => $inactiveAt === null ? 'ativo' : 'inativo',
            'created' => $createdAt,
            'inactive' => $inactiveAt,
        ]);
        $findGenericSubject->execute(['reference' => $reference]);
        $subjectId = $findGenericSubject->fetchColumn();
        if ($subjectId === false) {
            throw new RuntimeException('PRIVACY_SUBJECT_BACKFILL_FAILED');
        }
        $insertGenericRecord->execute([
            'subject' => (int) $subjectId,
            'type' => $recordType,
            'record' => $recordId,
            'created' => $createdAt,
        ]);
        return (int) $subjectId;
    };

    $userSubjects = [];
    $users = $pdo->query('SELECT id, tenant_id, ativo, criado_em FROM usuarios ORDER BY id');
    while (($user = $users->fetch(PDO::FETCH_ASSOC)) !== false) {
        $createdAt = is_string($user['criado_em']) && $user['criado_em'] !== '' ? $user['criado_em'] : $now;
        $userSubjects[(int) $user['id']] = $ensureRecord(
            'usuario', (int) $user['id'], $user['tenant_id'] === null ? null : (int) $user['tenant_id'],
            'pessoa', $createdAt, (int) $user['ativo'] === 1 ? null : $now
        );
    }

    $contacts = $pdo->query(
        "SELECT id, nome_responsavel, criado_em FROM tenants "
        . "WHERE nome_responsavel IS NOT NULL AND TRIM(nome_responsavel) <> '' ORDER BY id"
    );
    $findMatchingOwners = $pdo->prepare(
        "SELECT id FROM usuarios WHERE tenant_id = :tenant AND papel_sistema = 'titular_cliente' "
        . 'AND nome = :name ORDER BY id LIMIT 2'
    );
    while (($contact = $contacts->fetch(PDO::FETCH_ASSOC)) !== false) {
        $tenantId = (int) $contact['id'];
        $createdAt = is_string($contact['criado_em']) && $contact['criado_em'] !== '' ? $contact['criado_em'] : $now;
        $findMatchingOwners->execute(['tenant' => $tenantId, 'name' => (string) $contact['nome_responsavel']]);
        $owners = array_map('intval', $findMatchingOwners->fetchAll(PDO::FETCH_COLUMN));
        if (count($owners) === 1 && isset($userSubjects[$owners[0]])) {
            $insertGenericRecord->execute([
                'subject' => $userSubjects[$owners[0]], 'type' => 'tenant_contato',
                'record' => $tenantId, 'created' => $createdAt,
            ]);
            continue;
        }
        $ensureRecord('tenant_contato', $tenantId, $tenantId, 'pessoa', $createdAt);
    }

    $conversations = $pdo->query(
        'SELECT id, tenant_id, titular_id, expira_em, criado_em FROM whatsapp_conversas ORDER BY id'
    );
    $findHolderReference = $pdo->prepare(
        'SELECT referencia_privacidade FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1'
    );
    while (($conversation = $conversations->fetch(PDO::FETCH_ASSOC)) !== false) {
        $conversationId = (int) $conversation['id'];
        $tenantId = (int) $conversation['tenant_id'];
        $createdAt = is_string($conversation['criado_em']) && $conversation['criado_em'] !== '' ? $conversation['criado_em'] : $now;
        $expiresAt = is_string($conversation['expira_em']) && $conversation['expira_em'] !== '' ? $conversation['expira_em'] : null;
        if ($conversation['titular_id'] !== null) {
            $holderId = (int) $conversation['titular_id'];
            $findHolderReference->execute(['id' => $holderId, 'tenant' => $tenantId]);
            $holderReference = $findHolderReference->fetchColumn();
            if (is_string($holderReference) && preg_match('/^[a-f0-9]{64}$/D', $holderReference) === 1) {
                $holderSubjectId = $ensureRecord('titular', $holderId, $tenantId, 'pessoa', $createdAt, null, $holderReference);
                $insertGenericRecord->execute([
                    'subject' => $holderSubjectId, 'type' => 'whatsapp_conversa',
                    'record' => $conversationId, 'created' => $createdAt,
                ]);
                continue;
            }
        }
        $inactiveAt = $expiresAt !== null && $expiresAt <= $now ? $expiresAt : null;
        $ensureRecord('whatsapp_conversa', $conversationId, $tenantId, 'contato', $createdAt, $inactiveAt);
    }

    $cards = $pdo->query(
        'SELECT id, tenant_id, titular_id, emitida_em FROM carteirinhas_emitidas ORDER BY id'
    );
    while (($card = $cards->fetch(PDO::FETCH_ASSOC)) !== false) {
        $createdAt = is_string($card['emitida_em']) && $card['emitida_em'] !== '' ? $card['emitida_em'] : $now;
        $holderId = (int) $card['titular_id'];
        $tenantId = (int) $card['tenant_id'];
        $findHolderReference->execute(['id' => $holderId, 'tenant' => $tenantId]);
        $holderReference = $findHolderReference->fetchColumn();
        if (!is_string($holderReference) || preg_match('/^[a-f0-9]{64}$/D', $holderReference) !== 1) {
            throw new RuntimeException('PRIVACY_SUBJECT_CARD_LINK_FAILED');
        }
        $holderSubjectId = $ensureRecord('titular', $holderId, $tenantId, 'pessoa', $createdAt, null, $holderReference);
        $insertGenericRecord->execute([
            'subject' => $holderSubjectId, 'type' => 'carteirinha_emitida',
            'record' => (int) $card['id'], 'created' => $createdAt,
        ]);
    }

    $messages = $pdo->query('SELECT id, tenant_id, criado_em FROM mensagens_whatsapp ORDER BY id');
    while (($message = $messages->fetch(PDO::FETCH_ASSOC)) !== false) {
        $createdAt = is_string($message['criado_em']) && $message['criado_em'] !== '' ? $message['criado_em'] : $now;
        $ensureRecord('mensagem_whatsapp', (int) $message['id'], (int) $message['tenant_id'], 'contato', $createdAt, $createdAt);
    }

    $accesses = $pdo->query('SELECT id, tenant_id, usuario_id, criado_em, expira_em FROM acessos_suporte ORDER BY id');
    while (($access = $accesses->fetch(PDO::FETCH_ASSOC)) !== false) {
        $createdAt = is_string($access['criado_em']) && $access['criado_em'] !== '' ? $access['criado_em'] : $now;
        $userId = $access['usuario_id'] === null ? 0 : (int) $access['usuario_id'];
        if (isset($userSubjects[$userId])) {
            $insertGenericRecord->execute([
                'subject' => $userSubjects[$userId], 'type' => 'acesso_suporte',
                'record' => (int) $access['id'], 'created' => $createdAt,
            ]);
            continue;
        }
        $inactiveAt = is_string($access['expira_em']) && $access['expira_em'] <= $now ? $access['expira_em'] : null;
        $ensureRecord('acesso_suporte', (int) $access['id'], (int) $access['tenant_id'], 'contato', $createdAt, $inactiveAt);
    }

    $apiKeys = $pdo->query('SELECT id, tenant_id, usuario_id, criado_em FROM mcp_api_keys ORDER BY id');
    while (($apiKey = $apiKeys->fetch(PDO::FETCH_ASSOC)) !== false) {
        $createdAt = is_string($apiKey['criado_em']) && $apiKey['criado_em'] !== '' ? $apiKey['criado_em'] : $now;
        $userId = $apiKey['usuario_id'] === null ? 0 : (int) $apiKey['usuario_id'];
        if (isset($userSubjects[$userId])) {
            $insertGenericRecord->execute([
                'subject' => $userSubjects[$userId], 'type' => 'mcp_api_key',
                'record' => (int) $apiKey['id'], 'created' => $createdAt,
            ]);
            continue;
        }
        $ensureRecord('mcp_api_key', (int) $apiKey['id'], (int) $apiKey['tenant_id'], 'pessoa', $createdAt);
    }
};
