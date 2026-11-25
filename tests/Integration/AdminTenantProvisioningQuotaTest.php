<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_plans.php';
require_once dirname(__DIR__, 2) . '/config/tenant_provisioning.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
    . "VALUES ('superadmin', 'Provision Admin', 'provision-admin@example.test', :password, 1)"
)->execute(['password' => password_hash('unused-password', PASSWORD_DEFAULT)]);
$identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
$context = new TenantContext('admin', null, null, []);
$planId = adminTenantPlanCreate($pdo, $context, $identity, [
    'nome' => 'Quota plan', 'descricao' => '', 'ativo' => true,
    'permissoes' => ['titular.ver', 'titular.criar', 'carteirinha.gerar', 'subcliente.criar', 'usuario.criar'],
    'cotas' => [
        'limite_subclientes' => 3, 'limite_usuarios_equipe' => 5,
        'limite_titulares' => 500, 'limite_carteirinhas' => 1000,
    ],
]);
$input = [
    'nome_organizacao' => 'Igreja Piloto', 'slug_subdominio' => 'igreja-piloto',
    'nome_titular' => 'Responsável Piloto', 'email_titular' => 'pilot-owner@example.test',
    'senha_titular' => 'pilot-owner-password-123', 'plano_id' => $planId, 'status_conta' => 'ativo',
];
$limits = [
    'limite_subclientes' => 3, 'limite_usuarios_equipe' => 5,
    'limite_titulares' => 500, 'limite_carteirinhas' => 1000,
];
$created = tenantProvisionCreate($pdo, $context, $identity, $input, [], $limits);
$query = $pdo->prepare(
    'SELECT limite_subclientes, limite_usuarios_equipe, limite_titulares, limite_carteirinhas '
    . 'FROM tenants WHERE id = :id AND parent_id IS NULL'
);
$query->execute(['id' => $created['tenant_id']]);
$stored = $query->fetch(PDO::FETCH_ASSOC);
if (!is_array($stored) || (int) $stored['limite_subclientes'] !== 3
    || (int) $stored['limite_usuarios_equipe'] !== 5 || (int) $stored['limite_titulares'] !== 500
    || (int) $stored['limite_carteirinhas'] !== 1000) {
    throw new RuntimeException('Initial Sede quotas must be applied atomically from the selected plan.');
}

$rejected = false;
try {
    tenantProvisionCreate($pdo, $context, $identity, array_replace($input, [
        'nome_organizacao' => 'Over quota church', 'slug_subdominio' => 'over-quota-church',
        'email_titular' => 'over-quota@example.test',
    ]), [], array_replace($limits, ['limite_subclientes' => 4]));
} catch (DomainException $exception) {
    $rejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
}
if (!$rejected || (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug_subdominio = 'over-quota-church'")->fetchColumn() !== 0) {
    throw new RuntimeException('Superadmin provisioning must reject quotas above the plan without partial records.');
}

fwrite(STDOUT, "PASS: global Sede provisioning applies plan quota ceilings atomically and rejects over-quota values.\n");
