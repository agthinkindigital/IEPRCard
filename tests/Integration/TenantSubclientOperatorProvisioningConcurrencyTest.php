<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$connections = tenantTestConnections();
if (!isset($connections['MySQL/MariaDB'])) {
    fwrite(STDOUT, "SKIP: operator concurrency test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$pdo = $connections['MySQL/MariaDB'];
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$adminId = null;
$ownerId = null;
$planId = null;
$tenantId = null;
$childId = null;
$processes = [];
$startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-operator-race-' . $suffix;

try {
    $plan = $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)');
    $plan->execute(['name' => 'Operator race plan ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
    )->execute(['plan' => $planId, 'key' => 'usuario.criar']);

    $root = $pdo->prepare(
        "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe) "
        . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 1)"
    );
    $root->execute(['plan' => $planId, 'name' => 'Operator race Sede', 'slug' => 'operator-race-' . $suffix]);
    $tenantId = (int) $pdo->lastInsertId();
    $child = $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_usuarios_equipe) "
        . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', 1)"
    );
    $child->execute(['parent' => $tenantId, 'name' => 'Operator race unit', 'slug' => 'operator-unit-' . $suffix]);
    $childId = (int) $pdo->lastInsertId();

    $owner = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    );
    $owner->execute([
        'tenant' => $tenantId, 'role' => 'titular_cliente', 'name' => 'Operator race owner',
        'email' => 'operator-race-owner-' . $suffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
    ]);
    $ownerId = (int) $pdo->lastInsertId();

    $worker = __DIR__ . '/TenantSubclientOperatorProvisioningRaceWorker.php';
    for ($index = 1; $index <= 2; ++$index) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, $startFile, (string) $tenantId, (string) $childId, (string) $ownerId, $suffix . '-' . $index],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Operator concurrency worker could not start.');
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
            throw new RuntimeException('Operator concurrency worker failed: ' . $stderr . ' ' . $stdout);
        }
    }

    $userCount = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios WHERE tenant_id = :tenant AND subcliente_id = :child "
        . "AND papel_sistema = 'responsavel_sub' AND ativo = 1"
    );
    $userCount->execute(['tenant' => $tenantId, 'child' => $childId]);
    $auditCount = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'tenant.subclient_operator_created'"
    );
    $auditCount->execute(['tenant' => $tenantId]);
    if (count(array_filter($results, static fn(string $result): bool => $result === 'CREATED')) !== 1
        || count(array_filter($results, static fn(string $result): bool => $result === 'TENANT_OPERATOR_QUOTA_EXCEEDED')) !== 1
        || (int) $userCount->fetchColumn() !== 1 || (int) $auditCount->fetchColumn() !== 1) {
        throw new RuntimeException('Concurrent operator provisioning must persist exactly one account and matching audit under a one-seat ceiling.');
    }

    fwrite(STDOUT, "PASS: MySQL/MariaDB concurrent Subcliente operator creation honors root and unit quotas.\n");
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
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
        $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :id')->execute(['id' => $tenantId]);
        tenantQuotaTestCleanup($pdo, $tenantId, [$childId], $ownerId, $planId, $adminId);
    }
}

return 'pass';
