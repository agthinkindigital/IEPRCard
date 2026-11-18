<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/TenantQuotaPolicyTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$connections = tenantTestConnections();
if (!isset($connections['MySQL/MariaDB'])) {
    fwrite(STDOUT, "SKIP: titular batch concurrency test needs TEST_MYSQL_* and pdo_mysql.\n");
    return 'skip';
}

$pdo = $connections['MySQL/MariaDB'];
schemaAplicarMigracoes($pdo, 'test');
$suffix = bin2hex(random_bytes(5));
$planId = null;
$tenantId = null;
$childId = null;
$ownerId = null;
$processes = [];
$startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-titular-batch-race-' . $suffix;

try {
    $pdo->prepare('INSERT INTO planos (nome, ativo) VALUES (:name, 1)')
        ->execute(['name' => 'Titular batch race plan ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)')
        ->execute(['plan' => $planId, 'key' => 'titular.criar']);
    $pdo->prepare(
        "INSERT INTO tenants (plano_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_titulares) "
        . "VALUES (:plan, 'cliente', :name, :slug, 'ativo', 3)"
    )->execute(['plan' => $planId, 'name' => 'Titular batch race Sede', 'slug' => 'titular-batch-race-' . $suffix]);
    $tenantId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta, limite_titulares) "
        . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo', 3)"
    )->execute([
        'parent' => $tenantId,
        'name' => 'Titular batch race unit',
        'slug' => 'titular-batch-race-unit-' . $suffix,
    ]);
    $childId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, NULL, :role, :name, :email, :password, 1)'
    )->execute([
        'tenant' => $tenantId,
        'role' => 'titular_cliente',
        'name' => 'Titular batch race owner',
        'email' => 'titular-batch-race-owner-' . $suffix . '@example.test',
        'password' => password_hash('unused', PASSWORD_DEFAULT),
    ]);
    $ownerId = (int) $pdo->lastInsertId();

    $worker = __DIR__ . '/TitularBatchProvisioningRaceWorker.php';
    for ($index = 1; $index <= 2; ++$index) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, $startFile, (string) $tenantId, (string) $childId, (string) $ownerId, $suffix . '-' . $index],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Titular batch concurrency worker could not start.');
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
            throw new RuntimeException('Titular batch concurrency worker failed: ' . $stderr . ' ' . $stdout);
        }
    }

    $holderCount = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND subcliente_id = :child');
    $holderCount->execute(['tenant' => $tenantId, 'child' => $childId]);
    $auditCount = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'titular.created'"
    );
    $auditCount->execute(['tenant' => $tenantId]);
    if (count(array_filter($results, static fn(string $result): bool => str_starts_with($result, 'CREATED:'))) !== 1
        || count(array_filter($results, static fn(string $result): bool => $result === 'TITULAR_CREATE_QUOTA_EXCEEDED')) !== 1
        || (int) $holderCount->fetchColumn() !== 2 || (int) $auditCount->fetchColumn() !== 2) {
        throw new RuntimeException('Concurrent two-holder chunks must persist one complete chunk under a three-holder quota.');
    }

    fwrite(STDOUT, "PASS: MySQL/MariaDB concurrent titular chunks honor the root quota without partial writes.\n");
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
        $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
        $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :tenant')->execute(['tenant' => $tenantId]);
    }
    tenantQuotaTestCleanup($pdo, $tenantId, $childId === null ? [] : [$childId], $ownerId, $planId, null);
}

return 'pass';
