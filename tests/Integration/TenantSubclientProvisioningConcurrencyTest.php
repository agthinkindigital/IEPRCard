<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$connections = tenantTestConnections();
if (!isset($connections['MySQL/MariaDB'])) {
    fwrite(STDOUT, "SKIP: Subcliente concurrency test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$pdo = $connections['MySQL/MariaDB'];
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$adminId = null;
$ownerId = null;
$planId = null;
$tenantId = null;
$processes = [];
$startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-subclient-race-' . $suffix;

try {
    $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
    $plan->execute(['name' => 'Subclient race plan ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
    $flag->execute(['plan' => $planId, 'key' => 'subcliente.criar']);
    $root = $pdo->prepare(
        "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_subclientes) "
        . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 1)"
    );
    $root->execute(['plan' => $planId, 'name' => 'Subclient race Sede', 'slug' => 'sub-race-' . $suffix]);
    $tenantId = (int) $pdo->lastInsertId();
    $owner = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    );
    $owner->execute([
        'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Subclient race owner',
        'email' => 'sub-race-' . $suffix . '@example.test', 'password' => 'not-used',
    ]);
    $ownerId = (int) $pdo->lastInsertId();

    $worker = __DIR__ . '/TenantSubclientProvisioningRaceWorker.php';
    for ($index = 1; $index <= 2; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, $startFile, (string) $tenantId, (string) $ownerId, 'unit-' . $index . '-' . $suffix],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Subcliente concurrency worker could not start.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    file_put_contents($startFile, 'start');

    $results = [];
    foreach ($processes as $entry) {
        $stdout = trim(stream_get_contents($entry['stdout']));
        $stderr = trim(stream_get_contents($entry['stderr']));
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exit = proc_close($entry['process']);
        $results[] = $stdout;
        if ($exit !== 0 || $stderr !== '') {
            throw new RuntimeException('Subcliente concurrency worker failed: ' . $stderr . ' ' . $stdout);
        }
    }

    $childCount = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE parent_id = :parent AND tipo = 'subcliente'");
    $childCount->execute(['parent' => $tenantId]);
    $auditCount = $pdo->prepare("SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'subclient.created'");
    $auditCount->execute(['tenant' => $tenantId]);
    if (count(array_filter($results, static fn (string $result): bool => $result === 'CREATED')) !== 1
        || count(array_filter($results, static fn (string $result): bool => $result === 'TENANT_SUBCLIENT_QUOTA_EXCEEDED')) !== 1
        || (int) $childCount->fetchColumn() !== 1 || (int) $auditCount->fetchColumn() !== 1) {
        throw new RuntimeException('Concurrent Subcliente creation must persist exactly one unit and matching audit under a one-unit ceiling.');
    }

    fwrite(STDOUT, "PASS: MySQL/MariaDB concurrent Subcliente creation honors the root quota.\n");
} finally {
    foreach ($processes as $entry) {
        if (is_resource($entry['process'])) {
            proc_terminate($entry['process']);
            fclose($entry['stdout']);
            fclose($entry['stderr']);
            proc_close($entry['process']);
        }
    }
    if (is_file($startFile)) {
        unlink($startFile);
    }
    $children = $pdo->prepare("SELECT id FROM tenants WHERE parent_id = :parent AND tipo = 'subcliente'");
    if ($tenantId !== null) {
        $children->execute(['parent' => $tenantId]);
        $childIds = array_map('intval', $children->fetchAll(PDO::FETCH_COLUMN));
        tenantQuotaTestCleanup($pdo, $tenantId, $childIds, $ownerId, $planId, $adminId);
    }
}

return 'pass';
