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
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x72", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY');
try {
    foreach ($connections as $driver => $pdo) {
        schemaAplicarMigracoes($pdo, 'test');
        schemaGarantirEstrutura($pdo, 'test');
        $email = 'gowa-webhook-' . bin2hex(random_bytes(6)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
            . "VALUES ('superadmin', 'GOWA Webhook Test', :email, :password, 1)"
        )->execute(['email' => $email, 'password' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $identity = ['id' => $userId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
        $rootIds = [];
        $instanceIds = [];

        try {
            whatsappGatewayConfigSave($pdo, $identity, 'https://gowa.example.test', 'synthetic-gowa-token');
            for ($tenantNumber = 1; $tenantNumber <= 2; $tenantNumber++) {
                $pdo->prepare(
                    "INSERT INTO tenants (tipo, nome_organizacao, status_conta) "
                    . "VALUES ('cliente', :name, 'ativo')"
                )->execute(['name' => 'GOWA Tenant ' . $tenantNumber . ' ' . $driver]);
                $tenantId = (int) $pdo->lastInsertId();
                $rootIds[] = $tenantId;
                if ($tenantNumber === 1) {
                    $pdo->prepare(
                        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, status_conta) "
                        . "VALUES (:parent, 'subcliente', :name, 'ativo')"
                    )->execute(['parent' => $tenantId, 'name' => 'GOWA Unit ' . $driver]);
                    $unitId = (int) $pdo->lastInsertId();
                    $unitInstance = whatsappGatewayInstanceProvision(
                        $pdo,
                        $identity,
                        $tenantId,
                        $unitId,
                        'https://cards.example.test',
                        static function (string $url, string $body): array {
                            $request = json_decode($body, true, 16);
                            if (!str_ends_with($url, '/api.php?acao=criar_instancia')
                                || ($request['nome_instancia_destino'] ?? '') === ''
                                || !str_starts_with((string) ($request['webhook_url'] ?? ''), 'https://cards.example.test/webhook-gowa.php?token=')
                                || ($request['cron_url'] ?? null) !== 'https://cards.example.test/webhook-gowa-validate.php') {
                                throw new RuntimeException('GOWA instance provision request was incomplete.');
                            }
                            return ['status' => 200, 'body' => json_encode(['ok' => true, 'device_id' => 'device-' . $request['nome_instancia_destino']])];
                        }
                    );
                    $instanceIds[] = $unitInstance['id'];
                    $unitToken = (string) parse_url($unitInstance['webhook_url'], PHP_URL_QUERY);
                    parse_str($unitToken, $unitQuery);
                    $unitToken = (string) ($unitQuery['token'] ?? '');
                    $unitRow = $pdo->query(
                        'SELECT tenant_id, subcliente_id, device_id, webhook_token_hash FROM whatsapp_instancias WHERE id = ' . $unitInstance['id']
                    )->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($unitRow) || (int) $unitRow['tenant_id'] !== $tenantId
                        || (int) $unitRow['subcliente_id'] !== $unitId || $unitRow['device_id'] !== 'device-' . $unitInstance['instance_name']
                        || preg_match('/^[a-f0-9]{64}$/D', $unitToken) !== 1
                        || $unitRow['webhook_token_hash'] !== hash('sha256', $unitToken)
                        || str_contains((string) json_encode($unitRow), $unitToken)) {
                        throw new RuntimeException($driver . ': provisioned instance did not keep only its scoped token hash.');
                    }
                    try {
                        whatsappGatewayResolveWebhookInstance($pdo, str_repeat('0', 64));
                        throw new RuntimeException($driver . ': an unregistered bearer token resolved an instance.');
                    } catch (DomainException $exception) {
                        if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_UNAUTHORIZED') throw $exception;
                    }
                } else {
                    $rootInstance = whatsappGatewayInstanceProvision(
                        $pdo,
                        $identity,
                        $tenantId,
                        null,
                        'https://cards.example.test',
                        static function (string $url, string $body) use ($tenantId): array {
                            $request = json_decode($body, true, 16);
                            if (($request['cron_url'] ?? null) !== 'https://cards.example.test/webhook-gowa-validate.php') {
                                throw new RuntimeException('GOWA webhook update did not retain its validation callback URL.');
                            }
                            return [
                                'status' => 200,
                                'body' => json_encode(['ok' => true, 'device_id' => 'device-root-' . $tenantId]),
                            ];
                        }
                    );
                    $instanceIds[] = $rootInstance['id'];
                    $rootTokenParts = [];
                    parse_str((string) parse_url($rootInstance['webhook_url'], PHP_URL_QUERY), $rootTokenParts);
                    $rootToken = (string) ($rootTokenParts['token'] ?? '');
                }
            }

            $unitId = (int) $pdo->query('SELECT subcliente_id FROM whatsapp_instancias WHERE id = ' . $instanceIds[0])->fetchColumn();
            $unitInstanceName = (string) $pdo->query('SELECT nome_instancia FROM whatsapp_instancias WHERE id = ' . $instanceIds[0])->fetchColumn();
            $firstPayload = [
                'id_mensagem' => 'external-message-reused',
                'usuario' => $unitInstanceName,
                'telefone' => '+5511999991111',
                'texto' => 'dados privados de teste',
                'media' => ['data' => 'conteudo de midia privado'],
                'tenant_id' => $rootIds[1],
                'subcliente_id' => null,
            ];
            $received = [];
            $handler = static function (array $instance, array $message) use (&$received): void {
                $received[] = [$instance, $message];
            };
            $first = whatsappGatewayProcessWebhook($pdo, $unitToken, $firstPayload, $handler);
            $replay = whatsappGatewayProcessWebhook(
                $pdo,
                $unitToken,
                array_merge($firstPayload, ['texto' => 'texto alterado no replay']),
                $handler
            );
            if (($first['processed'] ?? false) !== true || ($replay['duplicate'] ?? false) !== true
                || ($replay['processed'] ?? true) !== false || count($received) !== 1
                || $received[0][0]['tenant_id'] !== $rootIds[0] || $received[0][0]['subcliente_id'] !== $unitId
                || $received[0][1]['texto'] !== 'dados privados de teste') {
                throw new RuntimeException($driver . ': duplicate messages or untrusted payload scope were processed incorrectly.');
            }

            $otherInstancePayload = array_merge($firstPayload, ['usuario' => (string) $pdo->query(
                'SELECT nome_instancia FROM whatsapp_instancias WHERE id = ' . $instanceIds[1]
            )->fetchColumn()]);
            $otherInstance = whatsappGatewayProcessWebhook($pdo, $rootToken, $otherInstancePayload, $handler);
            if (($otherInstance['processed'] ?? false) !== true || count($received) !== 2
                || $received[1][0]['tenant_id'] !== $rootIds[1]) {
                throw new RuntimeException($driver . ': identical provider IDs in different instances must remain isolated.');
            }

            $failedPayload = array_merge($firstPayload, ['id_mensagem' => 'external-message-retry']);
            $attempts = 0;
            try {
                whatsappGatewayProcessWebhook($pdo, $unitToken, $failedPayload, static function () use (&$attempts): void {
                    $attempts++;
                    throw new RuntimeException('synthetic handler failure');
                });
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'synthetic handler failure') throw $exception;
            }
            $retried = whatsappGatewayProcessWebhook($pdo, $unitToken, $failedPayload, static function () use (&$attempts): void {
                $attempts++;
            });
            if (($retried['processed'] ?? false) !== true || $attempts !== 2) {
                throw new RuntimeException($driver . ': failed events must be retriable without duplicate concurrent claims.');
            }

            $rotated = whatsappGatewayRotateInstanceWebhook(
                $pdo,
                $identity,
                $instanceIds[0],
                'https://cards.example.test',
                static function (string $url, string $body): array {
                    $request = json_decode($body, true, 16);
                    if (!str_ends_with($url, '/api.php?acao=atualizar_instancia')
                        || ($request['device_id'] ?? '') === ''
                        || !str_contains((string) ($request['webhook_url'] ?? ''), '/webhook-gowa.php?token=')) {
                        throw new RuntimeException('GOWA webhook rotation request was incomplete.');
                    }
                    return ['status' => 200, 'body' => '{"ok":true}'];
                }
            );
            $rotatedTokenParts = [];
            parse_str((string) parse_url($rotated['webhook_url'], PHP_URL_QUERY), $rotatedTokenParts);
            $rotatedToken = (string) ($rotatedTokenParts['token'] ?? '');
            try {
                whatsappGatewayResolveWebhookInstance($pdo, $unitToken);
                throw new RuntimeException($driver . ': rotated webhook token remained valid.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_UNAUTHORIZED') throw $exception;
            }
            $rotatedReplay = whatsappGatewayProcessWebhook($pdo, $rotatedToken, $firstPayload, $handler);
            if (($rotatedReplay['duplicate'] ?? false) !== true || count($received) !== 2) {
                throw new RuntimeException($driver . ': replay deduplication must survive webhook-token rotation.');
            }

            try {
                whatsappGatewayProcessWebhook($pdo, $rotatedToken, ['usuario' => $unitInstanceName], $handler);
                throw new RuntimeException($driver . ': malformed GOWA payload was accepted.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_PAYLOAD_INVALID') throw $exception;
            }
            foreach ([
                ['telefone' => ['unexpected' => 'object']],
                ['texto' => ['unexpected' => 'object']],
                ['media' => 'not-an-object'],
                ['tipo' => str_repeat('x', 65)],
                ['is_from_me' => 'false'],
                ['grupo' => 2],
                ['id_mensagem' => "invalid\nmessage-id"],
            ] as $invalidFields) {
                try {
                    whatsappGatewayProcessWebhook(
                        $pdo,
                        $rotatedToken,
                        array_merge($firstPayload, ['id_mensagem' => 'schema-' . bin2hex(random_bytes(4))], $invalidFields),
                        $handler
                    );
                    throw new RuntimeException($driver . ': invalid optional GOWA field types were accepted.');
                } catch (DomainException $exception) {
                    if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_PAYLOAD_INVALID') throw $exception;
                }
            }
            try {
                whatsappGatewayProcessWebhook(
                    $pdo,
                    $rotatedToken,
                    array_merge($firstPayload, ['id_mensagem' => 'oversized', 'texto' => str_repeat('x', 270000)]),
                    $handler
                );
                throw new RuntimeException($driver . ': oversized GOWA payload was accepted.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_PAYLOAD_INVALID') throw $exception;
            }

            $storedEvents = $pdo->query('SELECT * FROM webhook_gowa_eventos')->fetchAll(PDO::FETCH_ASSOC);
            $serializedEvents = (string) json_encode($storedEvents);
            foreach (['+5511999991111', 'dados privados de teste', 'conteudo de midia privado'] as $piiFixture) {
                if (str_contains($serializedEvents, $piiFixture)) {
                    throw new RuntimeException($driver . ': message PII was persisted before explicit consent.');
                }
            }

            fwrite(STDOUT, 'PASS: ' . $driver . " GOWA webhooks are scoped, idempotent and payload-free at rest.\n");
        } finally {
            if ($instanceIds !== []) {
                $pdo->prepare('DELETE FROM tenants WHERE parent_id IN (' . implode(',', array_map('intval', $rootIds)) . ')')->execute();
                $pdo->prepare('DELETE FROM tenants WHERE id IN (' . implode(',', array_map('intval', $rootIds)) . ')')->execute();
            }
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
