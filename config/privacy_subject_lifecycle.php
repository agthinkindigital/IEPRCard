<?php
declare(strict_types=1);

require_once __DIR__ . '/titular_anonymization.php';
require_once __DIR__ . '/titular_anonymization_lifecycle.php';
require_once __DIR__ . '/titular_anonymization_worker.php';
require_once __DIR__ . '/titular_privacy_keys.php';
require_once __DIR__ . '/privacy_subjects.php';

/** @return array{processed:int,failed:int,errors:array<string,int>} */
function privacySubjectLifecycleProcessDue(
    PDO $pdo,
    ?int $now = null,
    int $limit = 100,
    ?callable $clientFactory = null
): array {
    if ($limit < 1 || $limit > 200) {
        throw new InvalidArgumentException('PRIVACY_SUBJECT_BATCH_LIMIT_INVALID');
    }
    $now ??= time();
    $nowText = gmdate('Y-m-d H:i:s', $now);
    privacySubjectLifecycleSyncInactiveUsers($pdo, $nowText, $limit);
    $cutoff = titularAnonymizationTwelveMonthCutoff($now);
    $query = $pdo->prepare(
        'SELECT s.id FROM privacy_subjects s WHERE s.anonimizado_em IS NULL '
        . "AND ((s.estado = 'inativo' AND s.inativado_em IS NOT NULL AND s.inativado_em <= :cutoff) "
        . "OR EXISTS (SELECT 1 FROM privacy_subject_records r JOIN whatsapp_conversas c ON c.id = r.registro_id "
        . "WHERE r.privacy_subject_id = s.id AND r.tipo_registro = 'whatsapp_conversa' "
        . 'AND c.titular_id IS NULL AND c.expira_em <= :now)) '
        . "AND NOT EXISTS (SELECT 1 FROM privacy_subject_records r WHERE r.privacy_subject_id = s.id AND r.tipo_registro = 'titular') "
        . 'ORDER BY COALESCE(s.inativado_em, s.criado_em), s.id LIMIT ' . $limit
    );
    $query->execute(['cutoff' => $cutoff, 'now' => $nowText]);
    $subjectIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    $processed = 0;
    $failed = 0;
    $errors = [];
    foreach ($subjectIds as $subjectId) {
        $errorCode = null;
        if (privacySubjectLifecycleProcessOne($pdo, $subjectId, $nowText, $errorCode, $clientFactory)) {
            ++$processed;
        } else {
            ++$failed;
            $errors[$errorCode ?? 'PRIVACY_SUBJECT_ANONYMIZATION_FAILED'] =
                ($errors[$errorCode ?? 'PRIVACY_SUBJECT_ANONYMIZATION_FAILED'] ?? 0) + 1;
        }
    }
    return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
}

function privacySubjectLifecycleSyncInactiveUsers(PDO $pdo, string $nowText, int $limit): void
{
    $query = $pdo->prepare(
        "SELECT DISTINCT s.id FROM privacy_subjects s "
        . "JOIN privacy_subject_records r ON r.privacy_subject_id = s.id AND r.tipo_registro = 'usuario' "
        . 'JOIN usuarios u ON u.id = r.registro_id '
        . "WHERE s.estado = 'ativo' AND s.anonimizado_em IS NULL AND u.ativo = 0 ORDER BY s.id LIMIT " . $limit
    );
    $query->execute();
    $update = $pdo->prepare(
        "UPDATE privacy_subjects SET estado = 'inativo', inativado_em = COALESCE(inativado_em, :at) "
        . "WHERE id = :id AND estado = 'ativo' AND anonimizado_em IS NULL"
    );
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $subjectId) {
        $update->execute(['at' => $nowText, 'id' => (int) $subjectId]);
    }
}

function privacySubjectLifecycleProcessOne(
    PDO $pdo,
    int $subjectId,
    string $nowText,
    ?string &$errorCode = null,
    ?callable $clientFactory = null
): bool {
    $lock = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $subjectQuery = $pdo->prepare(
            'SELECT id FROM privacy_subjects WHERE id = :id AND anonimizado_em IS NULL LIMIT 1' . $lock
        );
        $subjectQuery->execute(['id' => $subjectId]);
        if ($subjectQuery->fetchColumn() === false) {
            $pdo->commit();
            return true;
        }
        $records = $pdo->prepare(
            'SELECT tipo_registro, registro_id FROM privacy_subject_records WHERE privacy_subject_id = :id '
            . 'ORDER BY tipo_registro, registro_id'
        );
        $records->execute(['id' => $subjectId]);
        $linkedRecords = $records->fetchAll(PDO::FETCH_ASSOC);
        if ($linkedRecords === []) {
            throw new RuntimeException('PRIVACY_SUBJECT_RECORDS_MISSING');
        }
        $now = strtotime($nowText . ' UTC');
        if (!is_int($now) || !privacySubjectLifecycleIsDue($pdo, $subjectId, $now)) {
            $pdo->commit();
            return true;
        }
        $expiredConversation = $pdo->prepare(
            "SELECT c.expira_em FROM privacy_subject_records r JOIN whatsapp_conversas c ON c.id = r.registro_id "
            . "WHERE r.privacy_subject_id = :id AND r.tipo_registro = 'whatsapp_conversa' "
            . 'AND c.titular_id IS NULL AND c.expira_em <= :now ORDER BY c.expira_em LIMIT 1' . $lock
        );
        $expiredConversation->execute(['id' => $subjectId, 'now' => $nowText]);
        $expiration = $expiredConversation->fetchColumn();
        if (is_string($expiration)) {
            $pdo->prepare(
                "UPDATE privacy_subjects SET estado = 'inativo', inativado_em = COALESCE(inativado_em, :at) "
                . "WHERE id = :id AND estado <> 'anonimizado'"
            )->execute(['at' => $expiration, 'id' => $subjectId]);
        }
        foreach ($linkedRecords as $record) {
            privacySubjectLifecycleAnonymizeRecord(
                $pdo,
                (string) $record['tipo_registro'],
                (int) $record['registro_id'],
                $nowText
            );
        }
        privacySubjectEraseBackupKey($pdo, $subjectId, $clientFactory);
        $markCompleted = $pdo->prepare(
            "UPDATE privacy_subjects SET estado = 'anonimizado', anonimizado_em = :at WHERE id = :id"
        );
        $markCompleted->execute(['at' => $nowText, 'id' => $subjectId]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        $errorCode = preg_match('/^(?:ANONYMIZATION|PRIVACY_SUBJECT)_[A-Z0-9_]+$/D', $message) === 1
            ? $message
            : 'PRIVACY_SUBJECT_ANONYMIZATION_FAILED';
        return false;
    }
}

function privacySubjectLifecycleIsDue(PDO $pdo, int $subjectId, int $now): bool
{
    $query = $pdo->prepare('SELECT estado, inativado_em FROM privacy_subjects WHERE id = :id LIMIT 1');
    $query->execute(['id' => $subjectId]);
    $subject = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($subject)) {
        return false;
    }
    $cutoff = titularAnonymizationTwelveMonthCutoff($now);
    if ($subject['estado'] === 'inativo' && is_string($subject['inativado_em']) && $subject['inativado_em'] <= $cutoff) {
        return true;
    }
    $expiration = $pdo->prepare(
        "SELECT 1 FROM privacy_subject_records r JOIN whatsapp_conversas c ON c.id = r.registro_id "
        . "WHERE r.privacy_subject_id = :id AND r.tipo_registro = 'whatsapp_conversa' "
        . 'AND c.titular_id IS NULL AND c.expira_em <= :now LIMIT 1'
    );
    $expiration->execute(['id' => $subjectId, 'now' => gmdate('Y-m-d H:i:s', $now)]);
    return $expiration->fetchColumn() !== false;
}

function privacySubjectLifecycleAnonymizeRecord(PDO $pdo, string $recordType, int $recordId, string $nowText): void
{
    switch ($recordType) {
        case 'usuario':
            $update = $pdo->prepare(
                "UPDATE usuarios SET nome = 'Usuário anonimizado', email = :email, senha_hash = :password, "
                . 'ativo = 0, ultimo_login_em = NULL WHERE id = :id'
            );
            $update->execute([
                'email' => 'anonimizado-' . $recordId . '@invalid.example',
                'password' => '!disabled-privacy-user-' . $recordId,
                'id' => $recordId,
            ]);
            return;
        case 'tenant_contato':
            $query = $pdo->prepare('SELECT assinatura_responsavel_url FROM tenants WHERE id = :id LIMIT 1');
            $query->execute(['id' => $recordId]);
            $signature = $query->fetchColumn();
            if (is_string($signature) && $signature !== '') {
                titularAnonymizationDeletePrivateArtifact(
                    privacySubjectLifecycleTenantId($pdo, $recordId),
                    $signature
                );
            }
            $pdo->prepare(
                'UPDATE tenants SET nome_responsavel = NULL, cargo_responsavel = NULL, assinatura_responsavel_url = NULL WHERE id = :id'
            )->execute(['id' => $recordId]);
            return;
        case 'acesso_suporte':
            $pdo->prepare(
                'UPDATE acessos_suporte SET token_hash = :token, ip_usado = NULL WHERE id = :id'
            )->execute(['token' => hash('sha256', 'privacy-subject-support:' . $recordId), 'id' => $recordId]);
            return;
        case 'mcp_api_key':
            $pdo->prepare(
                "UPDATE mcp_api_keys SET nome = 'Chave revogada', chave_hash = :hash, ativo = 0, ultimo_uso_em = NULL WHERE id = :id"
            )->execute(['hash' => hash('sha256', 'privacy-subject-mcp:' . $recordId), 'id' => $recordId]);
            return;
        case 'whatsapp_conversa':
            $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'anonimizada', contato_hmac = NULL, consentimento_finalidade = NULL, "
                . 'consentimento_em = NULL, revogado_em = :at, titular_id = NULL, job_id = NULL, etapa_entrega = 0, '
                . 'tentativas_entrega = 0, ultimo_erro_entrega = NULL, dados_cifrados = NULL WHERE id = :id'
            )->execute(['at' => $nowText, 'id' => $recordId]);
            return;
        case 'mensagem_whatsapp':
            $query = $pdo->prepare('SELECT tenant_id, arquivo_url FROM mensagens_whatsapp WHERE id = :id LIMIT 1');
            $query->execute(['id' => $recordId]);
            $message = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($message)) {
                return;
            }
            if (is_string($message['arquivo_url']) && $message['arquivo_url'] !== '') {
                titularAnonymizationDeletePrivateArtifact((int) $message['tenant_id'], $message['arquivo_url']);
            }
            $pdo->prepare(
                'UPDATE mensagens_whatsapp SET telefone = NULL, conteudo = NULL, id_mensagem_api = NULL, arquivo_url = NULL WHERE id = :id'
            )->execute(['id' => $recordId]);
            return;
        case 'carteirinha_emitida':
            $query = $pdo->prepare(
                'SELECT tenant_id, webp_frente_url, webp_verso_url, pdf_individual_url FROM carteirinhas_emitidas WHERE id = :id LIMIT 1'
            );
            $query->execute(['id' => $recordId]);
            $card = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($card)) {
                return;
            }
            foreach (['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'] as $field) {
                if (is_string($card[$field]) && $card[$field] !== '') {
                    titularAnonymizationDeletePrivateArtifact((int) $card['tenant_id'], $card[$field]);
                }
            }
            $pdo->prepare(
                "UPDATE carteirinhas_emitidas SET status_carteirinha = 'revogada', codigo_validacao_qr = :qr, "
                . 'snapshot_dados_json = NULL, webp_frente_url = NULL, webp_verso_url = NULL, pdf_individual_url = NULL '
                . 'WHERE id = :id'
            )->execute(['qr' => 'snapshot-disabled-' . $recordId, 'id' => $recordId]);
            return;
        default:
            throw new RuntimeException('PRIVACY_SUBJECT_RECORD_TYPE_UNSUPPORTED');
    }
}

function privacySubjectLifecycleTenantId(PDO $pdo, int $tenantId): int
{
    $query = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
    $query->execute(['id' => $tenantId]);
    $found = $query->fetchColumn();
    if ($found === false) {
        throw new RuntimeException('PRIVACY_SUBJECT_RECORD_NOT_FOUND');
    }
    return (int) $found;
}
