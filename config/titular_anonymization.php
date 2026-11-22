<?php
declare(strict_types=1);

function titularAnonymizationRequest(
    PDO $pdo,
    int $tenantId,
    int $titularId,
    string $requestId,
    ?int $now = null
): array {
    if ($tenantId < 1 || $titularId < 1 || preg_match('/^[a-f0-9]{32,100}$/D', $requestId) !== 1) {
        throw new DomainException('ANONYMIZATION_REQUEST_INVALID');
    }
    $key = titularAnonymizationEncryptionKey();
    $now ??= time();
    $requestedAt = gmdate('Y-m-d H:i:s', $now);
    $dueAt = gmdate('Y-m-d H:i:s', $now + 72 * 60 * 60);
    $encryptedReference = titularAnonymizationEncryptReference($tenantId, $titularId, $key);

    $pdo->beginTransaction();
    try {
        $lock = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
        $existing = $pdo->prepare(
            'SELECT referencia_titular_cifrada, estado, solicitado_em, prazo_em '
            . 'FROM solicitacoes_anonimizacao WHERE solicitacao_id = :request_id LIMIT 1' . $lock
        );
        $existing->execute(['request_id' => $requestId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            $isCompletedReplay = $prior['estado'] === 'concluida'
                && (string) $prior['referencia_titular_cifrada'] === '';
            if (!$isCompletedReplay && !titularAnonymizationReferenceMatches(
                (string) $prior['referencia_titular_cifrada'],
                $tenantId,
                $titularId,
                $key
            )) {
                throw new DomainException('ANONYMIZATION_REQUEST_ID_CONFLICT');
            }
            $pdo->commit();
            return [
                'solicitacao_id' => $requestId,
                'estado' => (string) $prior['estado'],
                'solicitado_em' => (string) $prior['solicitado_em'],
                'prazo_em' => (string) $prior['prazo_em'],
            ];
        }

        $holder = $pdo->prepare('SELECT id FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1');
        $holder->execute(['id' => $titularId, 'tenant' => $tenantId]);
        if ($holder->fetchColumn() === false) {
            throw new DomainException('ANONYMIZATION_TITULAR_NOT_FOUND');
        }
        $insert = $pdo->prepare(
            "INSERT INTO solicitacoes_anonimizacao (solicitacao_id, referencia_titular_cifrada, estado, solicitado_em, prazo_em) "
            . "VALUES (:request_id, :reference, 'pendente', :requested_at, :due_at)"
        );
        $insert->execute([
            'request_id' => $requestId,
            'reference' => $encryptedReference,
            'requested_at' => $requestedAt,
            'due_at' => $dueAt,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'solicitacao_id' => $requestId,
        'estado' => 'pendente',
        'solicitado_em' => $requestedAt,
        'prazo_em' => $dueAt,
    ];
}

function titularAnonymizationEncryptionKey(): string
{
    $encoded = getenv('IEPR_ANONYMIZATION_KEY');
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!function_exists('sodium_crypto_secretbox') || !is_string($key)
        || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('ANONYMIZATION_KEY_UNAVAILABLE');
    }

    return $key;
}

function titularAnonymizationEncryptReference(int $tenantId, int $titularId, string $key): string
{
    $reference = json_encode(['tenant_id' => $tenantId, 'titular_id' => $titularId], JSON_THROW_ON_ERROR);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return base64_encode($nonce . sodium_crypto_secretbox($reference, $nonce, $key));
}

function titularAnonymizationReferenceMatches(string $ciphertext, int $tenantId, int $titularId, string $key): bool
{
    $decoded = base64_decode($ciphertext, true);
    if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return false;
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if (!is_string($plaintext)) {
        return false;
    }
    $reference = json_decode($plaintext, true);

    return is_array($reference)
        && ($reference['tenant_id'] ?? null) === $tenantId
        && ($reference['titular_id'] ?? null) === $titularId;
}

require_once __DIR__ . '/titular_anonymization_lifecycle.php';
require_once __DIR__ . '/titular_anonymization_worker.php';
require_once __DIR__ . '/titular_privacy_keys.php';
