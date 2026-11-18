<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';

$startFile = $argv[1] ?? '';
$tenantId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ownerId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$subclientId = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($startFile === '' || $tenantId === false || $ownerId === false || $subclientId === false) {
    fwrite(STDERR, "Invalid race worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, "Race start signal timed out.\n");
    exit(2);
}

$pdo = dbConnect([
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '',
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
]);
$context = new TenantContext('cliente', (int) $tenantId, null, []);
$identity = [
    'id' => (int) $ownerId,
    'tenant_id' => (int) $tenantId,
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
];
try {
    tenantQuotaSetSubclientAllocation($pdo, $context, $identity, (int) $tenantId, (int) $subclientId, [
        'limite_usuarios_equipe' => null,
        'limite_titulares' => 70,
        'limite_carteirinhas' => null,
    ]);
    fwrite(STDOUT, "ALLOCATED\n");
} catch (DomainException $exception) {
    fwrite(STDOUT, $exception->getMessage() . "\n");
    exit($exception->getMessage() === 'TENANT_QUOTA_EXCEEDED' ? 0 : 1);
}
