<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';

$connections = ['SQLite' => dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:'])];
$mysqlSettings = [
    'DB_HOST' => getenv('TEST_MYSQL_HOST'),
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE'),
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME'),
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD'),
];
if (in_array('mysql', PDO::getAvailableDrivers(), true)
    && count(array_filter($mysqlSettings, static fn(mixed $value): bool => $value !== false)) === count($mysqlSettings)) {
    $connections['MySQL/MariaDB'] = dbConnect(['DB_DRIVER' => 'mysql'] + $mysqlSettings);
}

foreach ($connections as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    schemaGarantirEstrutura($pdo, 'test');
    if ((int) $pdo->query('SELECT COUNT(*) FROM ia_config_global')->fetchColumn() !== 0) {
        throw new RuntimeException($driver . ': AI global configuration tests require an empty test table.');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM ia_config')->fetchColumn() !== 0) {
        throw new RuntimeException($driver . ': legacy AI configuration must be empty for the migration preservation check.');
    }
    $legacyTenant = $pdo->prepare(
        "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'AI Migration Fixture', 'ativo')"
    );
    $legacyTenant->execute();
    $legacyTenantId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO ia_config (tenant_id, provedor, chave_cifrada, modelo, ativo) "
        . "VALUES (:tenant, 'openai', 'legacy-ciphertext', 'legacy-model', 0)"
    )->execute(['tenant' => $legacyTenantId]);
    $migration = require dirname(__DIR__, 2) . '/database/migrations/0009_ai_global_config.php';
    $migration($pdo);
    $migration($pdo);
    $legacyRow = $pdo->query('SELECT tenant_id, provedor, modelo FROM ia_config WHERE tenant_id = ' . $legacyTenantId)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($legacyRow) || (int) $legacyRow['tenant_id'] !== $legacyTenantId
        || $legacyRow['provedor'] !== 'openai' || $legacyRow['modelo'] !== 'legacy-model') {
        throw new RuntimeException($driver . ': the additive global-config migration must preserve legacy configuration rows.');
    }
    $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $legacyTenantId]);

    $userEmail = 'ai-config-' . bin2hex(random_bytes(6)) . '@example.test';
    $pdo->prepare(
        "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES ('superadmin', 'AI Config Test', :email, :hash, 1)"
    )->execute(['email' => $userEmail, 'hash' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $oldKey = getenv('IEPR_INTEGRATION_KEY');
    $oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
    putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x42", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $superadmin = ['id' => $userId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];

    try {
        $saved = aiGlobalConfigSave($pdo, $superadmin, 'openai', 'synthetic-openai-secret', 'gpt-test-model');
        $status = aiGlobalConfigRead($pdo, $superadmin);
        if (($saved['configured'] ?? false) !== true || isset($saved['api_key'])
            || ($status['providers']['openai']['configured'] ?? false) !== true
            || ($status['providers']['openai']['model'] ?? null) !== 'gpt-test-model'
            || ($status['active_provider'] ?? null) !== null) {
            throw new RuntimeException('Saving a global provider must persist safe metadata without activating it or returning its secret.');
        }
        aiGlobalConfigSave($pdo, $superadmin, 'openai', 'synthetic-openai-secret', 'gpt-test-model');
        if ((int) $pdo->query("SELECT COUNT(*) FROM ia_config_global WHERE provedor = 'openai'")->fetchColumn() !== 1) {
            throw new RuntimeException('Saving the same provider repeatedly must update one global row instead of duplicating it.');
        }

        $row = $pdo->query("SELECT chave_cifrada, ativo FROM ia_config_global WHERE provedor = 'openai'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['chave_cifrada'] === 'synthetic-openai-secret'
            || (int) $row['ativo'] !== 0) {
            throw new RuntimeException('Global credentials must be encrypted, unscoped to tenants, and inactive until selected.');
        }

        if (!aiGlobalConfigSetActive($pdo, $superadmin, 'openai')
            || aiGlobalConfigGetActiveCredential($pdo) !== 'synthetic-openai-secret') {
            throw new RuntimeException('The configured active global provider must be usable by the backend.');
        }

        aiGlobalConfigSave($pdo, $superadmin, 'gemini', 'synthetic-gemini-secret', 'gemini-test-model');
        aiGlobalConfigSetActive($pdo, $superadmin, 'gemini');
        $switched = aiGlobalConfigRead($pdo, $superadmin);
        $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM ia_config_global WHERE ativo = 1')->fetchColumn();
        if (($switched['active_provider'] ?? null) !== 'gemini' || $activeCount !== 1
            || aiGlobalConfigGetActiveCredential($pdo) !== 'synthetic-gemini-secret') {
            throw new RuntimeException('Switching the global provider must leave exactly one active provider.');
        }

        if ($driver === 'MySQL/MariaDB') {
            $workers = [];
            foreach (['openai', 'gemini'] as $provider) {
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/AiGlobalConfigRaceWorker.php', $provider, (string) $userId, '15'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Could not start an AI provider selection race worker.');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }
            foreach ($workers as [$process, $stdout, $stderr]) {
                $workerOutput = stream_get_contents($stdout) . stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                if (proc_close($process) !== 0 || str_contains($workerOutput, 'synthetic-')) {
                    throw new RuntimeException('Concurrent AI provider selection must complete without exposing credentials.');
                }
            }
            $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM ia_config_global WHERE ativo = 1')->fetchColumn();
            if ($activeCount !== 1) {
                throw new RuntimeException('Concurrent global provider selection must leave exactly one active provider.');
            }
        }

        try {
            aiGlobalConfigRead($pdo, ['id' => $userId, 'papel_sistema' => 'titular_cliente', 'tenant_id' => 1]);
            throw new RuntimeException('Tenant users must not read global AI configuration.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_FORBIDDEN') {
                throw $exception;
            }
        }

        $selectedBeforeMissingKey = aiGlobalConfigRead($pdo, $superadmin)['active_provider'];
        putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x43", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        try {
            aiGlobalConfigGetActiveCredential($pdo);
            throw new RuntimeException('A credential encrypted under another master key must fail closed.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_SECRET_UNAVAILABLE') {
                throw $exception;
            }
        }
        putenv('IEPR_INTEGRATION_KEY');
        $statusWithoutKey = aiGlobalConfigRead($pdo, $superadmin);
        if (($statusWithoutKey['providers']['gemini']['configured'] ?? false) !== true) {
            throw new RuntimeException('Non-secret configuration status must remain available without the master key.');
        }
        try {
            aiGlobalConfigSetActive($pdo, $superadmin, 'openai');
            throw new RuntimeException('Activating credentials must fail closed without the master key.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_KEY_UNAVAILABLE') {
                throw $exception;
            }
        }
        if (aiGlobalConfigRead($pdo, $superadmin)['active_provider'] !== $selectedBeforeMissingKey) {
            throw new RuntimeException('A failed activation must leave the previous provider selection intact.');
        }
        try {
            aiGlobalConfigGetActiveCredential($pdo);
            throw new RuntimeException('Reading the active encrypted credential must fail closed when the master key is missing.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_KEY_UNAVAILABLE') {
                throw $exception;
            }
        }
        try {
            aiGlobalConfigSave($pdo, $superadmin, 'openai', 'replacement-secret', 'gpt-test-model');
            throw new RuntimeException('Saving credentials must fail closed without the master key.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_KEY_UNAVAILABLE') {
                throw $exception;
            }
        }
        $sameKey = base64_encode(str_repeat("\x44", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        putenv('IEPR_INTEGRATION_KEY=' . $sameKey);
        putenv('IEPR_ANONYMIZATION_KEY=' . $sameKey);
        try {
            aiGlobalConfigSave($pdo, $superadmin, 'openai', 'replacement-secret', 'gpt-test-model');
            throw new RuntimeException('The integration key must not be reused as the anonymization key.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'AI_CONFIG_KEY_INVALID') {
                throw $exception;
            }
        }
        fwrite(STDOUT, 'PASS: ' . $driver . " global AI credentials are encrypted, superadmin-scoped, and safely selectable.\n");
    } finally {
        if ($oldKey === false) putenv('IEPR_INTEGRATION_KEY');
        else putenv('IEPR_INTEGRATION_KEY=' . $oldKey);
        if ($oldAnonymizationKey === false) putenv('IEPR_ANONYMIZATION_KEY');
        else putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
        $pdo->prepare('DELETE FROM ia_config_global WHERE provedor IN (:openai, :gemini)')
            ->execute(['openai' => 'openai', 'gemini' => 'gemini']);
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $userId]);
    }
}

return 'pass';
