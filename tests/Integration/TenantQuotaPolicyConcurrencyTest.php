<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$connections = tenantTestConnections();
if (!isset($connections['MySQL/MariaDB'])) {
    fwrite(STDOUT, "SKIP: quota concurrency test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$pdo = $connections['MySQL/MariaDB'];
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$adminId = null;
$ownerId = null;
$planId = null;
$tenantId = null;
$childIds = [];
$processes = [];
$startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-quota-race-' . $suffix;

try {
    $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
    $plan->execute(['name' => 'Quota race plan ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $flag = $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)');
    $flag->execute(['plan' => $planId, 'key' => 'cota.distribuir']);
    $root = $pdo->prepare(
        "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES (:plan, 'cliente', :name, :slug, 'ativo')"
    );
    $root->execute(['plan' => $planId, 'name' => 'Quota race Sede', 'slug' => 'quota-race-' . $suffix]);
    $tenantId = (int) $pdo->lastInsertId();
    $owner = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    );
    $owner->execute([
        'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Quota race owner',
        'email' => 'quota-race-' . $suffix . '@example.test', 'password' => 'not-used',
    ]);
    $ownerId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'UPDATE tenants SET limite_subclientes = 2, limite_usuarios_equipe = 10, '
        . 'limite_titulares = 100, limite_carteirinhas = 100 WHERE id = :id'
    )->execute(['id' => $tenantId]);

    $insertChild = $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
    );
    for ($index = 1; $index <= 2; $index++) {
        $insertChild->execute([
            'parent' => $tenantId,
            'name' => 'Quota race unit ' . $index,
            'slug' => 'quota-race-' . $index . '-' . $suffix,
        ]);
        $childIds[] = (int) $pdo->lastInsertId();
    }

    $worker = __DIR__ . '/TenantQuotaPolicyRaceWorker.php';
    foreach ($childIds as $childId) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, $startFile, (string) $tenantId, (string) $ownerId, (string) $childId],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Quota concurrency worker could not start.');
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
            throw new RuntimeException('Quota concurrency worker failed: ' . $stderr . ' ' . $stdout);
        }
    }
    $allocated = $pdo->prepare("SELECT COALESCE(SUM(limite_titulares), 0) FROM tenants WHERE parent_id = :parent");
    $allocated->execute(['parent' => $tenantId]);
    if (count(array_filter($results, static fn (string $result): bool => $result === 'ALLOCATED')) !== 1
        || count(array_filter($results, static fn (string $result): bool => $result === 'TENANT_QUOTA_EXCEEDED')) !== 1
        || (int) $allocated->fetchColumn() !== 70) {
        throw new RuntimeException('Concurrent allocations must serialize and keep the fixed allocations within the root ceiling.');
    }

    fwrite(STDOUT, "PASS: MySQL/MariaDB concurrent quota allocations serialize under the root lock.\n");
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
    tenantQuotaTestCleanup($pdo, $tenantId, $childIds, $ownerId, $planId, $adminId);
}

return 'pass';
