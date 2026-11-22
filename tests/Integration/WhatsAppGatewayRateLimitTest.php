<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/auth_session.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway_webhook.php';

$ipRatePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-gowa-ip-rate-' . bin2hex(random_bytes(6)) . '.json';
$testIp = '192.0.2.45';
if (!authSessionAllowAttempt($testIp, 1_800_000_000, $ipRatePath, 2)
    || !authSessionAllowAttempt($testIp, 1_800_000_000, $ipRatePath, 2)
    || authSessionAllowAttempt($testIp, 1_800_000_000, $ipRatePath, 2)
    || !authSessionAllowAttempt($testIp, 1_800_000_060, $ipRatePath, 2)) {
    throw new RuntimeException('Ephemeral GOWA IP rate limiting must enforce a configurable window limit.');
}
$storedIpRate = (string) file_get_contents($ipRatePath);
$rateEntries = json_decode($storedIpRate, true);
if (!is_array($rateEntries) || count($rateEntries) !== 1 || str_contains($storedIpRate, $testIp)
    || preg_match('/^[a-f0-9]{64}$/D', (string) array_key_first($rateEntries)) !== 1) {
    throw new RuntimeException('Ephemeral GOWA IP rate limiting must persist only a hash, not the address.');
}
unlink($ipRatePath);

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
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x73", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
try {
    foreach ($connections as $driver => $pdo) {
        schemaAplicarMigracoes($pdo, 'test');
        schemaGarantirEstrutura($pdo, 'test');
        $pdo->prepare(
            "INSERT INTO tenants (tipo, nome_organizacao, status_conta) "
            . "VALUES ('cliente', :name, 'ativo')"
        )->execute(['name' => 'GOWA Rate Limit ' . $driver]);
        $tenantId = (int) $pdo->lastInsertId();
        $token = str_repeat('a', 64);
        $instanceName = 'ieprcard-rate-target-' . bin2hex(random_bytes(5));
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO whatsapp_instancias "
            . "(tenant_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, estado, criado_em, atualizado_em) "
            . "VALUES (:tenant, :scope, :name, :device, :token_hash, 'ativa', :created, :updated)"
        )->execute([
            'tenant' => $tenantId,
            'scope' => 'tenant:' . $tenantId,
            'name' => $instanceName,
            'device' => 'rate-device-target-' . $driver,
            'token_hash' => hash('sha256', $token),
            'created' => $now,
            'updated' => $now,
        ]);
        $targetInstanceId = (int) $pdo->lastInsertId();
        $insertEvent = $pdo->prepare(
            "INSERT INTO webhook_gowa_eventos (instancia_id, evento_hash, estado, recebido_em, tentativas) "
            . "VALUES (:instance, :hash, 'processado', :received, 1)"
        );
        $seedEvents = static function (int $instanceId, int $count, string $receivedAt) use ($insertEvent): void {
            for ($index = 0; $index < $count; $index++) {
                $insertEvent->execute([
                    'instance' => $instanceId,
                    'hash' => hash('sha256', $instanceId . ':' . $index . ':' . bin2hex(random_bytes(4))),
                    'received' => $receivedAt,
                ]);
            }
        };
        $recent = gmdate('Y-m-d H:i:s', time() - 5);

        try {
            $seedEvents($targetInstanceId, 120, $recent);
            try {
                whatsappGatewayProcessWebhook(
                    $pdo,
                    $token,
                    ['usuario' => $instanceName, 'id_mensagem' => 'instance-rate-limit'],
                    static function (): void {}
                );
                throw new RuntimeException($driver . ': per-instance webhook rate limit was not enforced.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_RATE_LIMITED') throw $exception;
            }
            $pdo->exec('DELETE FROM webhook_gowa_eventos');

            for ($instanceNumber = 0; $instanceNumber < 10; $instanceNumber++) {
                $pdo->prepare(
                    "INSERT INTO tenants (parent_id, tipo, nome_organizacao, status_conta) "
                    . "VALUES (:parent, 'subcliente', :name, 'ativo')"
                )->execute([
                    'parent' => $tenantId,
                    'name' => 'GOWA Global Rate Unit ' . $instanceNumber . ' ' . $driver,
                ]);
                $unitId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    "INSERT INTO whatsapp_instancias "
                    . "(tenant_id, subcliente_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, estado, criado_em, atualizado_em) "
                    . "VALUES (:tenant, :unit, :scope, :name, :device, :token_hash, 'ativa', :created, :updated)"
                )->execute([
                    'tenant' => $tenantId,
                    'unit' => $unitId,
                    'scope' => 'subclient:' . $unitId,
                    'name' => 'ieprcard-rate-' . bin2hex(random_bytes(6)),
                    'device' => 'rate-device-' . $instanceNumber . '-' . $driver,
                    'token_hash' => hash('sha256', 'synthetic-rate-token-' . $instanceNumber . '-' . $driver),
                    'created' => $now,
                    'updated' => $now,
                ]);
                $seedEvents((int) $pdo->lastInsertId(), 120, $recent);
            }
            try {
                whatsappGatewayProcessWebhook(
                    $pdo,
                    $token,
                    ['usuario' => $instanceName, 'id_mensagem' => 'global-rate-limit'],
                    static function (): void {}
                );
                throw new RuntimeException($driver . ': global webhook rate limit was not enforced.');
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'WHATSAPP_WEBHOOK_RATE_LIMITED') throw $exception;
            }
            fwrite(STDOUT, 'PASS: ' . $driver . " GOWA webhook rate limits apply per instance and globally.\n");
        } finally {
            $pdo->prepare('DELETE FROM tenants WHERE parent_id = :id')->execute(['id' => $tenantId]);
            $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
        }
    }
} finally {
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
}

return 'pass';
