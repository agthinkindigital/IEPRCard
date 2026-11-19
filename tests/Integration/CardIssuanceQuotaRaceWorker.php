<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance.php';

if ($argc !== 9) {
    fwrite(STDERR, "Invalid issuance quota race worker arguments.\n");
    exit(2);
}
[, $databasePath, $tenantValue, $ownerValue, $sourceValue, $designValue, $key, $barrier, $resultPath] = $argv;
foreach ([$tenantValue, $ownerValue, $sourceValue, $designValue] as $value) {
    if (!ctype_digit($value) || (int) $value < 1) {
        fwrite(STDERR, "Invalid issuance quota race worker identifiers.\n");
        exit(2);
    }
}
$deadline = microtime(true) + 15;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($barrier)) {
    fwrite(STDERR, "Issuance quota race barrier timed out.\n");
    exit(2);
}

try {
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $tenantId = (int) $tenantValue;
    $ownerId = (int) $ownerValue;
    $context = new TenantContext('cliente', $tenantId, null, []);
    $identity = ['id' => $ownerId, 'tenant_id' => $tenantId, 'subcliente_id' => null, 'papel_sistema' => 'titular_cliente'];
    $result = cardIssuanceCreateJob(
        $pdo, $context, $identity, $tenantId, null, (int) $sourceValue, (int) $designValue, 'webp', $key
    );
    $output = ['result' => 'created', 'job_id' => $result['job_id']];
} catch (DomainException $exception) {
    $output = ['result' => $exception->getMessage()];
} catch (Throwable $exception) {
    $output = ['result' => 'unexpected', 'error' => get_class($exception) . ':' . $exception->getMessage()];
}
if (file_put_contents($resultPath, json_encode($output, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    fwrite(STDERR, "Could not publish issuance quota race result.\n");
    exit(1);
}
exit(0);
