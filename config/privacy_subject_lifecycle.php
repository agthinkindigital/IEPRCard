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
    $requests = $pdo->prepare(
        "SELECT id, privacy_subject_id FROM solicitacoes_anonimizacao WHERE privacy_subject_id IS NOT NULL "
        . "AND estado IN ('pendente', 'falha') AND prazo_em <= :now ORDER BY solicitado_em, id LIMIT " . $limit
    );
    $requests->execute(['now' => $nowText]);
    $requestRows = $requests->fetchAll(PDO::FETCH_ASSOC);
    $requestIds = array_map('intval', array_column($requestRows, 'id'));
    $processed = 0;
    $failed = 0;
    $errors = [];
    foreach ($requestRows as $request) {
        $requestId = (int) $request['id'];
        $subjectId = (int) $request['privacy_subject_id'];
        $errorCode = null;
        if (privacySubjectLifecycleProcessOne($pdo, $subjectId, $nowText, $errorCode, $clientFactory, true)) {
            $complete = $pdo->prepare(
                "UPDATE solicitacoes_anonimizacao SET estado = 'concluida', privacy_subject_id = NULL, "
                . "referencia_titular_cifrada = '', concluido_em = :completed, tentativas = tentativas + 1, erro_codigo = NULL "
                . "WHERE id = :id AND privacy_subject_id = :subject AND estado IN ('pendente', 'falha')"
            );
            $complete->execute(['completed' => $nowText, 'id' => $requestId, 'subject' => $subjectId]);
            if ($complete->rowCount() === 1) {
                ++$processed;
            }
            continue;
        }
        $errorCode ??= 'PRIVACY_SUBJECT_ANONYMIZATION_FAILED';
        $fail = $pdo->prepare(
            "UPDATE solicitacoes_anonimizacao SET estado = 'falha', tentativas = tentativas + 1, erro_codigo = :error, "
            . 'correlation_id = COALESCE(correlation_id, :correlation) WHERE id = :id AND privacy_subject_id = :subject '
            . "AND estado IN ('pendente', 'falha')"
        );
        $fail->execute([
            'error' => $errorCode, 'correlation' => bin2hex(random_bytes(16)), 'id' => $requestId, 'subject' => $subjectId,
        ]);
        if ($fail->rowCount() === 1) {
            ++$failed;
            $errors[$errorCode] = ($errors[$errorCode] ?? 0) + 1;
        }
    }
    $remainingLimit = max(0, $limit - count($requestIds));
    if ($remainingLimit === 0) {
        return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
    }
    privacySubjectLifecycleSyncInactiveUsers($pdo, $nowText, $remainingLimit);
    $cutoff = titularAnonymizationTwelveMonthCutoff($now);
    $query = $pdo->prepare(
        'SELECT s.id FROM privacy_subjects s WHERE s.anonimizado_em IS NULL '
        . "AND ((s.estado = 'inativo' AND s.inativado_em IS NOT NULL AND s.inativado_em <= :cutoff) "
        . "OR EXISTS (SELECT 1 FROM privacy_subject_records r JOIN whatsapp_conversas c ON c.id = r.registro_id "
        . "WHERE r.privacy_subject_id = s.id AND r.tipo_registro = 'whatsapp_conversa' "
        . 'AND c.titular_id IS NULL AND c.expira_em <= :now)) '
        . "AND NOT EXISTS (SELECT 1 FROM privacy_subject_records r WHERE r.privacy_subject_id = s.id AND r.tipo_registro = 'titular') "
        . 'ORDER BY COALESCE(s.inativado_em, s.criado_em), s.id LIMIT ' . $remainingLimit
    );
    $query->execute(['cutoff' => $cutoff, 'now' => $nowText]);
    $subjectIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
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
    ?callable $clientFactory = null,
    bool $force = false
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
        if (!is_int($now) || (!$force && !privacySubjectLifecycleIsDue($pdo, $subjectId, $now))) {
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

/** @return array{solicitacao_id:string,estado:string,solicitado_em:string,prazo_em:string} */
function privacySubjectAnonymizationRequest(
    PDO $pdo,
    int $subjectId,
    string $requestId,
    ?int $now = null
): array {
    if ($subjectId < 1 || preg_match('/^[a-f0-9]{32,100}$/D', $requestId) !== 1) {
        throw new DomainException('PRIVACY_SUBJECT_REQUEST_INVALID');
    }
    $now ??= time();
    $requestedAt = gmdate('Y-m-d H:i:s', $now);
    $dueAt = gmdate('Y-m-d H:i:s', $now + 72 * 60 * 60);
    $pdo->beginTransaction();
    try {
        $lock = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
        $existing = $pdo->prepare(
            'SELECT privacy_subject_id, estado, solicitado_em, prazo_em FROM solicitacoes_anonimizacao '
            . 'WHERE solicitacao_id = :request_id LIMIT 1' . $lock
        );
        $existing->execute(['request_id' => $requestId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            if ($prior['estado'] !== 'concluida' && (int) $prior['privacy_subject_id'] !== $subjectId) {
                throw new DomainException('PRIVACY_SUBJECT_REQUEST_ID_CONFLICT');
            }
            $pdo->commit();
            return [
                'solicitacao_id' => $requestId,
                'estado' => (string) $prior['estado'],
                'solicitado_em' => (string) $prior['solicitado_em'],
                'prazo_em' => (string) $prior['prazo_em'],
            ];
        }
        $subject = $pdo->prepare('SELECT id, estado FROM privacy_subjects WHERE id = :id LIMIT 1' . $lock);
        $subject->execute(['id' => $subjectId]);
        $subjectRow = $subject->fetch(PDO::FETCH_ASSOC);
        if (!is_array($subjectRow) || $subjectRow['estado'] === 'anonimizado') {
            throw new DomainException('PRIVACY_SUBJECT_NOT_FOUND');
        }
        $records = $pdo->prepare(
            'SELECT tipo_registro FROM privacy_subject_records WHERE privacy_subject_id = :id LIMIT 1'
        );
        $records->execute(['id' => $subjectId]);
        if ($records->fetchColumn() === false) {
            throw new DomainException('PRIVACY_SUBJECT_RECORDS_MISSING');
        }
        $holder = $pdo->prepare(
            "SELECT 1 FROM privacy_subject_records WHERE privacy_subject_id = :id AND tipo_registro = 'titular' LIMIT 1"
        );
        $holder->execute(['id' => $subjectId]);
        if ($holder->fetchColumn() !== false) {
            throw new DomainException('PRIVACY_SUBJECT_REQUEST_USE_TITULAR_FLOW');
        }
        $insert = $pdo->prepare(
            "INSERT INTO solicitacoes_anonimizacao (solicitacao_id, referencia_titular_cifrada, privacy_subject_id, estado, solicitado_em, prazo_em) "
            . "VALUES (:request_id, '', :subject, 'pendente', :requested, :deadline)"
        );
        $insert->execute([
            'request_id' => $requestId, 'subject' => $subjectId, 'requested' => $requestedAt, 'deadline' => $dueAt,
        ]);
        $pdo->prepare(
            "UPDATE privacy_subjects SET estado = 'inativo', inativado_em = COALESCE(inativado_em, :at) "
            . "WHERE id = :id AND estado <> 'anonimizado'"
        )->execute(['at' => $requestedAt, 'id' => $subjectId]);
        $pdo->commit();
        return ['solicitacao_id' => $requestId, 'estado' => 'pendente', 'solicitado_em' => $requestedAt, 'prazo_em' => $dueAt];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
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
        case 'design_fundo':
            $query = $pdo->prepare('SELECT tenant_id, design_id, lado, asset_url FROM design_fundos_privacidade WHERE id = :id LIMIT 1');
            $query->execute(['id' => $recordId]);
            $background = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($background)) return;
            if (is_string($background['asset_url']) && $background['asset_url'] !== '') {
                titularAnonymizationDeletePrivateArtifact((int) $background['tenant_id'], $background['asset_url']);
            }
            $field = ($background['lado'] ?? null) === 'frente' ? 'bg_frente_url' : 'bg_verso_url';
            $pdo->prepare('UPDATE designs_carteirinha SET ' . $field . ' = NULL WHERE id = :id AND tenant_id = :tenant')
                ->execute(['id' => $background['design_id'], 'tenant' => $background['tenant_id']]);
            $pdo->prepare("UPDATE design_fundos_privacidade SET classificacao = 'anonimizado', titular_id = NULL, asset_url = NULL, atualizado_em = :at WHERE id = :id")
                ->execute(['at' => $nowText, 'id' => $recordId]);
            return;
        case 'import_row':
            $query = $pdo->prepare('SELECT lote_id FROM importacao_linhas_staging WHERE id = :id LIMIT 1');
            $query->execute(['id' => $recordId]);
            $lotId = $query->fetchColumn();
            $pdo->prepare('DELETE FROM importacao_linhas_staging WHERE id = :id')->execute(['id' => $recordId]);
            $pdo->prepare("DELETE FROM privacy_subject_records WHERE tipo_registro = 'import_row' AND registro_id = :id")
                ->execute(['id' => $recordId]);
            if ($lotId !== false) {
                $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'expirado', arquivo_original_url = NULL, finalizado_em = :at WHERE id = :id AND status_lote IN ('aguardando_mapa', 'na_fila')")
                    ->execute(['at' => $nowText, 'id' => $lotId]);
            }
            return;
        case 'carteirinha_emitida':
            $query = $pdo->prepare(
                'SELECT tenant_id, lote_id, webp_frente_url, webp_verso_url, pdf_individual_url FROM carteirinhas_emitidas WHERE id = :id LIMIT 1'
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
            if (filter_var($card['lote_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                titularAnonymizationInvalidateConsolidatedPdf($pdo, (int) $card['tenant_id'], (int) $card['lote_id']);
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
