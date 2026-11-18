<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/tenant_quota_policy.php';
require_once dirname(__DIR__, 2) . '/config/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/config/tenant_subclient_provisioning.php';

$startFile = $argv[1] ?? '';
$tenantId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ownerId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$slug = $argv[4] ?? '';
if ($startFile === '' || $tenantId === false || $ownerId === false || $slug === '') {
    fwrite(STDERR, "Invalid Subcliente race worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (!is_file($startFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($startFile)) {
    fwrite(STDERR, "Subcliente race start signal timed out.\n");
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
    tenantSubclientCreate($pdo, $context, $identity, (int) $tenantId, [
        'nome_organizacao' => 'Concurrent unit ' . $slug,
        'slug_subdominio' => $slug,
    ]);
    fwrite(STDOUT, "CREATED\n");
} catch (DomainException $exception) {
    fwrite(STDOUT, $exception->getMessage() . "\n");
    exit($exception->getMessage() === 'TENANT_SUBCLIENT_QUOTA_EXCEEDED' ? 0 : 1);
}
