<?php
declare(strict_types=1);

function privacySubjectCreate(PDO $pdo, string $type, ?int $tenantId = null, ?string $createdAt = null): array
{
    if (!in_array($type, ['pessoa', 'contato'], true) || ($tenantId !== null && $tenantId < 1)) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_INVALID');
    }
    $reference = bin2hex(random_bytes(32));
    $insert = $pdo->prepare(
        'INSERT INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em) '
        . "VALUES (:reference, :type, :tenant, 'ativo', :created)"
    );
    $insert->execute([
        'reference' => $reference,
        'type' => $type,
        'tenant' => $tenantId,
        'created' => $createdAt ?? gmdate('Y-m-d H:i:s'),
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'reference' => $reference, 'type' => $type];
}

function privacySubjectLinkRecord(PDO $pdo, int $subjectId, string $recordType, int $recordId): void
{
    if ($subjectId < 1 || $recordId < 1 || !privacySubjectRecordTypeAllowed($recordType)) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_RECORD_INVALID');
    }
    $subject = $pdo->prepare('SELECT id FROM privacy_subjects WHERE id = :id LIMIT 1');
    $subject->execute(['id' => $subjectId]);
    if ($subject->fetchColumn() === false) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_NOT_FOUND');
    }

    $existing = $pdo->prepare(
        'SELECT privacy_subject_id FROM privacy_subject_records WHERE tipo_registro = :type AND registro_id = :record LIMIT 1'
    );
    $existing->execute(['type' => $recordType, 'record' => $recordId]);
    $existingSubject = $existing->fetchColumn();
    if ($existingSubject !== false) {
        if ((int) $existingSubject !== $subjectId) {
            throw new LogicException('PRIVACY_SUBJECT_RECORD_ALREADY_LINKED');
        }
        return;
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO privacy_subject_records (privacy_subject_id, tipo_registro, registro_id, criado_em) '
            . 'VALUES (:subject, :type, :record, :created)'
        );
        $insert->execute([
            'subject' => $subjectId,
            'type' => $recordType,
            'record' => $recordId,
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $exception) {
        $existing->execute(['type' => $recordType, 'record' => $recordId]);
        if ((int) $existing->fetchColumn() === $subjectId) {
            return;
        }
        throw $exception;
    }
}

function privacySubjectReference(PDO $pdo, int $subjectId): ?string
{
    if ($subjectId < 1) {
        return null;
    }
    $query = $pdo->prepare('SELECT referencia FROM privacy_subjects WHERE id = :id LIMIT 1');
    $query->execute(['id' => $subjectId]);
    $reference = $query->fetchColumn();
    return is_string($reference) ? $reference : null;
}

/** @return array{id:int,reference:string,type:string}|null */
function privacySubjectFindForRecord(PDO $pdo, string $recordType, int $recordId): ?array
{
    if ($recordId < 1 || !privacySubjectRecordTypeAllowed($recordType)) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_RECORD_INVALID');
    }
    $query = $pdo->prepare(
        'SELECT s.id, s.referencia, s.tipo FROM privacy_subjects s '
        . 'JOIN privacy_subject_records r ON r.privacy_subject_id = s.id '
        . 'WHERE r.tipo_registro = :type AND r.registro_id = :record LIMIT 1'
    );
    $query->execute(['type' => $recordType, 'record' => $recordId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? [
        'id' => (int) $row['id'], 'reference' => (string) $row['referencia'], 'type' => (string) $row['tipo'],
    ] : null;
}

/** @return array{id:int,reference:string,type:string} */
function privacySubjectEnsureForRecord(PDO $pdo, string $recordType, int $recordId, ?int $tenantId = null): array
{
    if ($recordType === 'titular') {
        return privacySubjectEnsureTitular($pdo, $recordId, $tenantId);
    }
    $existing = privacySubjectFindForRecord($pdo, $recordType, $recordId);
    if ($existing !== null) {
        return $existing;
    }
    $type = in_array($recordType, ['whatsapp_contato', 'whatsapp_conversa', 'mensagem_whatsapp'], true)
        ? 'contato'
        : 'pessoa';
    $subject = privacySubjectCreate($pdo, $type, $tenantId);
    privacySubjectLinkRecord($pdo, $subject['id'], $recordType, $recordId);
    return $subject;
}

/** @return array{id:int,reference:string,type:string} */
function privacySubjectEnsureTitular(PDO $pdo, int $titularId, ?int $tenantId = null): array
{
    if ($titularId < 1) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_RECORD_INVALID');
    }
    $holderQuery = $pdo->prepare(
        'SELECT tenant_id, referencia_privacidade FROM titulares WHERE id = :id LIMIT 1'
    );
    $holderQuery->execute(['id' => $titularId]);
    $holder = $holderQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($holder) || ($tenantId !== null && (int) $holder['tenant_id'] !== $tenantId)) {
        throw new DomainException('PRIVACY_SUBJECT_RECORD_NOT_FOUND');
    }
    $tenantId = (int) $holder['tenant_id'];
    $reference = is_string($holder['referencia_privacidade'])
        && preg_match('/^[a-f0-9]{64}$/D', $holder['referencia_privacidade']) === 1
        ? $holder['referencia_privacidade']
        : bin2hex(random_bytes(32));
    $find = $pdo->prepare('SELECT id FROM privacy_subjects WHERE referencia = :reference LIMIT 1');
    $find->execute(['reference' => $reference]);
    $subjectId = $find->fetchColumn();
    if ($subjectId === false) {
        $insert = $pdo->prepare(
            'INSERT INTO privacy_subjects (referencia, tipo, tenant_id, estado, criado_em) '
            . "VALUES (:reference, 'pessoa', :tenant, 'ativo', :created)"
        );
        try {
            $insert->execute([
                'reference' => $reference,
                'tenant' => $tenantId,
                'created' => gmdate('Y-m-d H:i:s'),
            ]);
            $subjectId = (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $find->execute(['reference' => $reference]);
            $subjectId = $find->fetchColumn();
            if ($subjectId === false) {
                throw $exception;
            }
        }
    }
    $updateReference = $pdo->prepare(
        'UPDATE titulares SET referencia_privacidade = :reference WHERE id = :id AND referencia_privacidade IS NULL'
    );
    $updateReference->execute(['reference' => $reference, 'id' => $titularId]);
    if ($updateReference->rowCount() !== 1) {
        $storedReferenceQuery = $pdo->prepare('SELECT referencia_privacidade FROM titulares WHERE id = :id LIMIT 1');
        $storedReferenceQuery->execute(['id' => $titularId]);
        $storedReference = $storedReferenceQuery->fetchColumn();
        if (!is_string($storedReference) || preg_match('/^[a-f0-9]{64}$/D', $storedReference) !== 1) {
            throw new RuntimeException('PRIVACY_SUBJECT_REFERENCE_PERSIST_FAILED');
        }
        $reference = $storedReference;
        $find->execute(['reference' => $reference]);
        $subjectId = $find->fetchColumn();
        if ($subjectId === false) {
            throw new RuntimeException('PRIVACY_SUBJECT_REFERENCE_MISSING');
        }
    }
    privacySubjectLinkRecord($pdo, (int) $subjectId, 'titular', $titularId);
    return ['id' => (int) $subjectId, 'reference' => $reference, 'type' => 'pessoa'];
}

function privacySubjectRecordTypeAllowed(string $recordType): bool
{
    return in_array($recordType, [
        'titular', 'usuario', 'tenant_contato', 'whatsapp_contato', 'whatsapp_conversa',
        'mensagem_whatsapp', 'acesso_suporte', 'mcp_api_key', 'carteirinha_emitida', 'campo_privado',
    ], true);
}
