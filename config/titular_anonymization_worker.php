<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging_support.php';

/** @return array{processed:int,failed:int,errors:array<string,int>} */
function titularAnonymizationProcessDue(PDO $pdo, ?int $now = null, int $limit = 100, ?callable $clientFactory = null): array
{
    if ($limit < 1 || $limit > 200) {
        throw new InvalidArgumentException('ANONYMIZATION_BATCH_LIMIT_INVALID');
    }
    $key = titularAnonymizationEncryptionKey();
    $nowText = gmdate('Y-m-d H:i:s', $now ?? time());
    titularAnonymizationQueueExpiredInactive($pdo, $key, $nowText, $limit);
    $candidates = $pdo->prepare(
        "SELECT id FROM solicitacoes_anonimizacao WHERE estado IN ('pendente', 'falha') "
        . 'AND solicitado_em <= :now ORDER BY solicitado_em, id LIMIT ' . $limit
    );
    $candidates->execute(['now' => $nowText]);
    $ids = array_map('intval', $candidates->fetchAll(PDO::FETCH_COLUMN));
    $processed = 0;
    $failed = 0;
    $errors = [];
    foreach ($ids as $id) {
        $errorCode = null;
        if (titularAnonymizationProcessOne($pdo, $id, $nowText, $key, $errorCode, $clientFactory)) {
            ++$processed;
        } else {
            ++$failed;
            $errorCode ??= 'ANONYMIZATION_FAILED';
            $errors[$errorCode] = ($errors[$errorCode] ?? 0) + 1;
        }
    }

    return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
}

function titularAnonymizationQueueExpiredInactive(PDO $pdo, string $key, string $nowText, int $limit): void
{
    $cutoff = titularAnonymizationTwelveMonthCutoff(strtotime($nowText . ' UTC'));
    $holders = $pdo->prepare(
        'SELECT tenant_id, id, inativado_em FROM titulares WHERE ativo = 0 AND anonimizado_em IS NULL '
        . 'AND inativado_em IS NOT NULL '
        . 'AND inativado_em <= :cutoff ORDER BY inativado_em, id LIMIT ' . $limit
    );
    $holders->execute(['cutoff' => $cutoff]);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $insertSql = $driver === 'mysql'
        ? "INSERT IGNORE INTO solicitacoes_anonimizacao (solicitacao_id, referencia_titular_cifrada, estado, solicitado_em, prazo_em) VALUES (:request_id, :reference, 'pendente', :requested, :deadline)"
        : "INSERT INTO solicitacoes_anonimizacao (solicitacao_id, referencia_titular_cifrada, estado, solicitado_em, prazo_em) VALUES (:request_id, :reference, 'pendente', :requested, :deadline) ON CONFLICT(solicitacao_id) DO NOTHING";
    $insert = $pdo->prepare($insertSql);
    foreach ($holders->fetchAll(PDO::FETCH_ASSOC) as $holder) {
        $tenantId = (int) $holder['tenant_id'];
        $titularId = (int) $holder['id'];
        $inactiveAt = (string) $holder['inativado_em'];
        $requestId = hash_hmac('sha256', 'retention:' . $tenantId . ':' . $titularId . ':' . $inactiveAt, $key);
        $insert->execute([
            'request_id' => $requestId,
            'reference' => titularAnonymizationEncryptReference($tenantId, $titularId, $key),
            'requested' => $nowText,
            'deadline' => $nowText,
        ]);
    }
}

function titularAnonymizationProcessOne(
    PDO $pdo,
    int $id,
    string $nowText,
    string $key,
    ?string &$errorCode = null,
    ?callable $clientFactory = null
): bool
{
    $lock = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT id, referencia_titular_cifrada FROM solicitacoes_anonimizacao "
            . "WHERE id = :id AND estado IN ('pendente', 'falha') AND solicitado_em <= :now LIMIT 1" . $lock
        );
        $query->execute(['id' => $id, 'now' => $nowText]);
        $request = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $pdo->commit();
            return true;
        }
        $reference = titularAnonymizationDecryptReference((string) $request['referencia_titular_cifrada'], $key);
        if ($reference === null) {
            $errorCode = 'ANONYMIZATION_REFERENCE_INVALID';
            titularAnonymizationMarkFailed($pdo, $id, $errorCode);
            $pdo->commit();
            return false;
        }

        $holderQuery = $pdo->prepare(
            'SELECT id, foto_url, referencia_privacidade FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1' . $lock
        );
        $holderQuery->execute(['id' => $reference['titular_id'], 'tenant' => $reference['tenant_id']]);
        $holder = $holderQuery->fetch(PDO::FETCH_ASSOC);
        if (is_array($holder)) {
            $privacyReference = titularPrivacyEnsureReference($pdo, $reference['tenant_id'], $reference['titular_id']);
            titularPrivacyEraseKeys($pdo, $privacyReference, $clientFactory);
            titularAnonymizationDeleteHolderArtifacts($pdo, $reference['tenant_id'], $reference['titular_id'], $holder);
            $cards = $pdo->prepare(
                "UPDATE carteirinhas_emitidas SET status_carteirinha = 'revogada', snapshot_dados_json = NULL, "
                . 'webp_frente_url = NULL, webp_verso_url = NULL, pdf_individual_url = NULL '
                . 'WHERE tenant_id = :tenant AND titular_id = :titular'
            );
            $cards->execute(['tenant' => $reference['tenant_id'], 'titular' => $reference['titular_id']]);
            $audit = $pdo->prepare(
                'UPDATE auditoria_processos SET entidade_id = NULL, antes_json = NULL, depois_json = NULL '
                . 'WHERE tenant_id = :tenant AND entidade = :entity AND entidade_id = :titular'
            );
            $audit->execute([
                'tenant' => $reference['tenant_id'],
                'entity' => 'titulares',
                'titular' => $reference['titular_id'],
            ]);
            $clearHolder = $pdo->prepare(
                "UPDATE titulares SET nome_completo = 'Titular anonimizado', cpf = NULL, rg = NULL, "
                . 'cargo_funcao = NULL, matricula = NULL, data_nascimento = NULL, data_batismo_ou_admissao = NULL, '
                . 'whatsapp = NULL, email_login = NULL, senha_portal_hash = NULL, token_unico_carteira = NULL, '
                . 'foto_url = NULL, dados_extras_json = NULL, acesso_app_liberado = 0, ativo = 0, ia_pausada = 1, '
                . 'ultima_interacao_em = NULL, inativado_em = COALESCE(inativado_em, :inactive_at), '
                . 'anonimizado_em = :anonymized_at '
                . 'WHERE id = :id AND tenant_id = :tenant'
            );
            $clearHolder->execute([
                'inactive_at' => $nowText,
                'anonymized_at' => $nowText,
                'id' => $reference['titular_id'],
                'tenant' => $reference['tenant_id'],
            ]);
        }
        $complete = $pdo->prepare(
            "UPDATE solicitacoes_anonimizacao SET estado = 'concluida', referencia_titular_cifrada = '', "
            . 'concluido_em = :completed, tentativas = tentativas + 1, erro_codigo = NULL '
            . "WHERE id = :id AND estado IN ('pendente', 'falha')"
        );
        $complete->execute(['completed' => $nowText, 'id' => $id]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->beginTransaction();
        try {
            $message = $exception->getMessage();
            $errorCode = preg_match('/^ANONYMIZATION_[A-Z0-9_]+$/D', $message) === 1 ? $message : 'ANONYMIZATION_INTERNAL';
            titularAnonymizationMarkFailed($pdo, $id, $errorCode);
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return false;
    }
}

/** @return array{tenant_id:int,titular_id:int}|null */
function titularAnonymizationDecryptReference(string $ciphertext, string $key): ?array
{
    $decoded = base64_decode($ciphertext, true);
    if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    $reference = is_string($plaintext) ? json_decode($plaintext, true) : null;
    if (!is_array($reference)
        || !is_int($reference['tenant_id'] ?? null) || $reference['tenant_id'] < 1
        || !is_int($reference['titular_id'] ?? null) || $reference['titular_id'] < 1) {
        return null;
    }

    return ['tenant_id' => $reference['tenant_id'], 'titular_id' => $reference['titular_id']];
}

function titularAnonymizationMarkFailed(PDO $pdo, int $id, string $errorCode): void
{
    $update = $pdo->prepare(
        "UPDATE solicitacoes_anonimizacao SET estado = 'falha', tentativas = tentativas + 1, "
        . 'erro_codigo = :error_code, correlation_id = COALESCE(correlation_id, :correlation) WHERE id = :id'
    );
    $update->execute([
        'error_code' => $errorCode,
        'correlation' => bin2hex(random_bytes(16)),
        'id' => $id,
    ]);
}

/** @param array<string, mixed> $holder */
function titularAnonymizationDeleteHolderArtifacts(PDO $pdo, int $tenantId, int $titularId, array $holder): void
{
    $keys = [];
    if (is_string($holder['foto_url'] ?? null) && $holder['foto_url'] !== '') {
        $keys[] = $holder['foto_url'];
    }
    $cards = $pdo->prepare(
        'SELECT webp_frente_url, webp_verso_url, pdf_individual_url FROM carteirinhas_emitidas '
        . 'WHERE tenant_id = :tenant AND titular_id = :titular'
    );
    $cards->execute(['tenant' => $tenantId, 'titular' => $titularId]);
    foreach ($cards->fetchAll(PDO::FETCH_ASSOC) as $card) {
        foreach (['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'] as $column) {
            if (is_string($card[$column] ?? null) && $card[$column] !== '') {
                $keys[] = $card[$column];
            }
        }
    }
    foreach (array_unique($keys) as $storedKey) {
        titularAnonymizationDeletePrivateArtifact($tenantId, $storedKey);
    }
}

function titularAnonymizationDeletePrivateArtifact(int $tenantId, string $storedKey): void
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $storedKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!file_exists($directory) && !is_link($directory)) {
        return;
    }
    if (is_link($directory)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    if (!file_exists($path)) {
        return;
    }
    $realPath = realpath($path);
    if (!is_string($realPath) || !is_file($realPath) || !batchImportStagingPathWithin($realPath, $root)
        || !unlink($realPath)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_DELETE_FAILED');
    }
}
