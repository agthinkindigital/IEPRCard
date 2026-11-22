<?php
declare(strict_types=1);

const AI_GLOBAL_CONFIG_PROVIDERS = ['openai', 'gemini'];

/** @param array<string, mixed> $identity */
function aiGlobalConfigSave(PDO $pdo, array $identity, string $provider, string $apiKey, string $model, ?array $configuration = null): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    aiGlobalConfigAssertProvider($provider);
    $apiKey = trim($apiKey);
    if ($apiKey === '' || strlen($apiKey) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $apiKey) === 1) {
        throw new InvalidArgumentException('Credencial inválida.');
    }
    if ($model === '' || strlen($model) > 120 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $model) !== 1) {
        throw new InvalidArgumentException('Modelo inválido.');
    }
    $encryptedKey = aiGlobalConfigSeal($apiKey, $configuration);
    $now = gmdate('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            'UPDATE ia_config_global SET chave_cifrada = :secret, modelo = :model, atualizado_em = :updated '
            . 'WHERE provedor = :provider'
        );
        $update->execute(['secret' => $encryptedKey, 'model' => $model, 'updated' => $now, 'provider' => $provider]);
        if ($update->rowCount() === 0) {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO ia_config_global (provedor, chave_cifrada, modelo, ativo, criado_em, atualizado_em) '
                    . 'VALUES (:provider, :secret, :model, 0, :created, :updated)'
                );
                $insert->execute([
                    'provider' => $provider, 'secret' => $encryptedKey, 'model' => $model,
                    'created' => $now, 'updated' => $now,
                ]);
            } catch (PDOException $exception) {
                if (!aiGlobalConfigIsUniqueViolation($exception)) {
                    throw $exception;
                }
                $update->execute([
                    'secret' => $encryptedKey, 'model' => $model, 'updated' => $now, 'provider' => $provider,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['provider' => $provider, 'configured' => true, 'model' => $model];
}

/** @param array<string, mixed> $identity
 *  @return array{active_provider: string|null, providers: array<string, array{configured: bool, model: string|null, active: bool}>}
 */
function aiGlobalConfigRead(PDO $pdo, array $identity): array
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    $providers = [];
    foreach (AI_GLOBAL_CONFIG_PROVIDERS as $provider) {
        $providers[$provider] = ['configured' => false, 'model' => null, 'active' => false];
    }
    $statement = $pdo->query('SELECT provedor, modelo, ativo FROM ia_config_global');
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $provider = (string) $row['provedor'];
        if (!isset($providers[$provider])) {
            continue;
        }
        $providers[$provider] = [
            'configured' => true,
            'model' => (string) $row['modelo'],
            'active' => (int) $row['ativo'] === 1,
        ];
    }
    $activeProviders = array_keys(array_filter($providers, static fn(array $provider): bool => $provider['active']));

    return [
        'active_provider' => count($activeProviders) === 1 ? $activeProviders[0] : null,
        'providers' => $providers,
    ];
}

/** @param array<string, mixed> $identity */
function aiGlobalConfigSetActive(PDO $pdo, array $identity, string $provider, ?array $configuration = null): bool
{
    aiGlobalConfigAssertSuperadmin($pdo, $identity);
    aiGlobalConfigAssertProvider($provider);
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException('Driver de banco não suportado para configuração global de IA.');
    }

    try {
        $pdo->beginTransaction();
        if ($driver === 'mysql') {
            $pdo->query('SELECT id FROM ia_config_global ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        }
        $selected = $pdo->prepare('SELECT chave_cifrada FROM ia_config_global WHERE provedor = :provider');
        $selected->execute(['provider' => $provider]);
        $encryptedKey = $selected->fetchColumn();
        if (!is_string($encryptedKey) || $encryptedKey === '') {
            throw new DomainException('AI_PROVIDER_NOT_CONFIGURED');
        }
        aiGlobalConfigOpen($encryptedKey, $configuration);

        $pdo->exec('UPDATE ia_config_global SET ativo = 0');
        $activate = $pdo->prepare('UPDATE ia_config_global SET ativo = 1 WHERE provedor = :provider');
        $activate->execute(['provider' => $provider]);
        if ($activate->rowCount() !== 1) {
            throw new DomainException('AI_PROVIDER_NOT_CONFIGURED');
        }
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** Return the active credential for a provider adapter; never expose it to an HTTP response. */
function aiGlobalConfigGetActiveCredential(PDO $pdo, ?array $configuration = null): ?string
{
    $statement = $pdo->query('SELECT provedor, chave_cifrada FROM ia_config_global WHERE ativo = 1 LIMIT 2');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return null;
    }
    if (count($rows) !== 1) {
        throw new RuntimeException('AI_CONFIG_ACTIVE_STATE_INVALID');
    }
    aiGlobalConfigAssertProvider((string) $rows[0]['provedor']);
    return aiGlobalConfigOpen((string) $rows[0]['chave_cifrada'], $configuration);
}

/** @param array<string, mixed> $identity */
function aiGlobalConfigAssertSuperadmin(PDO $pdo, array $identity): void
{
    $userId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false || ($identity['papel_sistema'] ?? null) !== 'superadmin'
        || ($identity['tenant_id'] ?? null) !== null || ($identity['subcliente_id'] ?? null) !== null) {
        throw new DomainException('AI_CONFIG_FORBIDDEN');
    }
    $statement = $pdo->prepare(
        "SELECT 1 FROM usuarios WHERE id = :id AND tenant_id IS NULL AND subcliente_id IS NULL "
        . "AND papel_sistema = 'superadmin' AND ativo = 1 LIMIT 1"
    );
    $statement->execute(['id' => $userId]);
    if ($statement->fetchColumn() === false) {
        throw new DomainException('AI_CONFIG_FORBIDDEN');
    }
}

function aiGlobalConfigAssertProvider(string $provider): void
{
    if (!in_array($provider, AI_GLOBAL_CONFIG_PROVIDERS, true)) {
        throw new DomainException('AI_PROVIDER_UNSUPPORTED');
    }
}

function aiGlobalConfigSeal(string $plaintext, ?array $configuration = null): string
{
    $key = aiGlobalConfigEncryptionKey($configuration);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
}

function aiGlobalConfigOpen(string $sealed, ?array $configuration = null): string
{
    $decoded = base64_decode($sealed, true);
    if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('AI_CONFIG_SECRET_UNAVAILABLE');
    }
    $key = aiGlobalConfigEncryptionKey($configuration);
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if (!is_string($plaintext)) {
        throw new RuntimeException('AI_CONFIG_SECRET_UNAVAILABLE');
    }
    return $plaintext;
}

/** @param array<string, mixed>|null $configuration */
function aiGlobalConfigEncryptionKey(?array $configuration = null): string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('AI_CONFIG_KEY_UNAVAILABLE');
    }
    $encoded = is_array($configuration)
        ? ($configuration['IEPR_INTEGRATION_KEY'] ?? null)
        : (getenv('IEPR_INTEGRATION_KEY') ?: null);
    $key = is_string($encoded) ? base64_decode($encoded, true) : false;
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('AI_CONFIG_KEY_UNAVAILABLE');
    }
    $anonymizationEncoded = is_array($configuration)
        ? ($configuration['IEPR_ANONYMIZATION_KEY'] ?? null)
        : (getenv('IEPR_ANONYMIZATION_KEY') ?: null);
    $anonymizationKey = is_string($anonymizationEncoded) ? base64_decode($anonymizationEncoded, true) : false;
    if (is_string($anonymizationKey) && strlen($anonymizationKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        && hash_equals($key, $anonymizationKey)) {
        throw new RuntimeException('AI_CONFIG_KEY_INVALID');
    }
    return $key;
}

function aiGlobalConfigIsUniqueViolation(PDOException $exception): bool
{
    return in_array((string) $exception->getCode(), ['23000', '23505'], true);
}
