<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway_webhook.php';

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

$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x71", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY');
try {
    foreach ($connections as $driver => $pdo) {
        schemaAplicarMigracoes($pdo, 'test');
        schemaGarantirEstrutura($pdo, 'test');
        $email = 'gowa-config-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
            . "VALUES ('superadmin', 'GOWA Config Test', :email, :password, 1)"
        )->execute(['email' => $email, 'password' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $superadmin = ['id' => $userId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
        $token = 'synthetic-gowa-token-' . $driver;

        try {
            try {
                whatsappGatewayConfigSave(
                    $pdo,
                    ['id' => $userId, 'papel_sistema' => 'tenant_admin'],
                    'https://gowa.example.test',
                    $token
                );
                throw new RuntimeException($driver . ': non-superadmin identity managed global GOWA credentials.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'AI_CONFIG_FORBIDDEN') {
                    throw $exception;
                }
            }

            $saved = whatsappGatewayConfigSave($pdo, $superadmin, 'https://gowa.example.test/', $token);
            whatsappGatewayValidateCronPing($pdo, ['token' => $token, 'usuarios' => []]);
            try {
                whatsappGatewayValidateCronPing($pdo, ['token' => 'invalid-gowa-token', 'usuarios' => []]);
                throw new RuntimeException($driver . ': invalid GOWA validation ping token was accepted.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_GOWA_PING_UNAUTHORIZED') throw $exception;
            }
            try {
                whatsappGatewayValidateCronPing($pdo, ['token' => $token, 'usuarios' => 'not-a-list']);
                throw new RuntimeException($driver . ': malformed GOWA validation ping was accepted.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_GOWA_PING_INVALID') throw $exception;
            }
            $stored = $pdo->query(
                "SELECT api_base_url, api_token_cifrado, ultimo_teste_status FROM whatsapp_config_global WHERE chave = 'global'"
            )->fetch(PDO::FETCH_ASSOC);
            if (($saved['configured'] ?? false) !== true || ($saved['token_configured'] ?? false) !== true
                || str_contains((string) json_encode($saved), $token)
                || !is_array($stored) || $stored['api_base_url'] !== 'https://gowa.example.test'
                || $stored['api_token_cifrado'] === $token || $stored['ultimo_teste_status'] !== null) {
                throw new RuntimeException($driver . ': GOWA credentials must be encrypted and omitted from safe reads.');
            }

            foreach ([
                'http://gowa.example.test',
                'https://user:password@gowa.example.test',
                'https://gowa.example.test/?token=leak',
            ] as $unsafeUrl) {
                try {
                    whatsappGatewayConfigSave($pdo, $superadmin, $unsafeUrl, 'replacement-token');
                    throw new RuntimeException($driver . ': unsafe GOWA API URL was accepted.');
                } catch (InvalidArgumentException) {
                }
            }

            $tested = whatsappGatewayConfigTest(
                $pdo,
                $superadmin,
                static function (string $url, string $body, array $headers) use ($token): array {
                    $request = json_decode($body, true, 16);
                    if (!str_ends_with($url, '/api.php?acao=StatusInstancias')
                        || ($request['token'] ?? null) !== $token
                        || ($headers['Content-Type'] ?? null) !== 'application/json'
                        || count($request ?? []) !== 1) {
                        throw new RuntimeException('GOWA connectivity probe sent unexpected data.');
                    }
                    return ['status' => 200, 'body' => '{"ok":true,"instancias":[]}'];
                }
            );
            if (($tested['status'] ?? null) !== 'success') {
                throw new RuntimeException($driver . ': synthetic GOWA connection probe failed.');
            }

            $ciphertextBeforeFailure = (string) $pdo->query(
                "SELECT api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global'"
            )->fetchColumn();
            $failed = whatsappGatewayConfigTest(
                $pdo,
                $superadmin,
                static fn(): array => ['status' => 503, 'body' => 'synthetic failure']
            );
            $afterFailure = $pdo->query(
                "SELECT api_base_url, api_token_cifrado, ultimo_teste_status FROM whatsapp_config_global WHERE chave = 'global'"
            )->fetch(PDO::FETCH_ASSOC);
            if (($failed['status'] ?? null) !== 'failed' || !is_array($afterFailure)
                || $afterFailure['api_base_url'] !== 'https://gowa.example.test'
                || $afterFailure['api_token_cifrado'] !== $ciphertextBeforeFailure
                || $afterFailure['ultimo_teste_status'] !== 'failed') {
                throw new RuntimeException($driver . ': failed connectivity checks must preserve saved credentials.');
            }
            $candidateFailure = whatsappGatewayConfigSaveTested(
                $pdo,
                $superadmin,
                'https://new-gowa.example.test',
                'replacement-token',
                static fn(): array => ['status' => 401, 'body' => '{"error":"synthetic"}']
            );
            $preserved = whatsappGatewayConfigRead($pdo, $superadmin);
            if ($candidateFailure['status'] !== 'failed' || $preserved['api_base_url'] !== 'https://gowa.example.test'
                || aiGlobalConfigOpen((string) $pdo->query("SELECT api_token_cifrado FROM whatsapp_config_global WHERE chave='global'")->fetchColumn()) !== $token) {
                throw new RuntimeException($driver . ': a failed candidate probe must preserve the last known-good GOWA connection.');
            }
            $candidateSuccess = whatsappGatewayConfigSaveTested(
                $pdo,
                $superadmin,
                'https://new-gowa.example.test',
                'replacement-token',
                static fn(): array => ['status' => 200, 'body' => '{"ok":true}']
            );
            $updated = whatsappGatewayConfigRead($pdo, $superadmin);
            if ($candidateSuccess['status'] !== 'success' || $updated['api_base_url'] !== 'https://new-gowa.example.test'
                || $updated['last_test_status'] !== 'success') {
                throw new RuntimeException($driver . ': a verified candidate should replace the connection and retain a safe test status.');
            }

            fwrite(STDOUT, 'PASS: ' . $driver . " GOWA global settings stay encrypted and probes are read-only.\n");
        } finally {
            $pdo->prepare('DELETE FROM whatsapp_config_global WHERE chave = \'global\'')->execute();
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $userId]);
        }
    }
} finally {
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
    if ($oldAnonymizationKey === false) putenv('IEPR_ANONYMIZATION_KEY');
    else putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
}

return 'pass';
