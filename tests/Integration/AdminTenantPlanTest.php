<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_plans.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
    . "VALUES ('superadmin', 'Plan Admin', 'plan-admin@example.test', :password, 1)"
)->execute(['password' => password_hash('unused-password', PASSWORD_DEFAULT)]);
$identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
$context = new TenantContext('admin', null, null, []);

$planId = adminTenantPlanCreate($pdo, $context, $identity, [
    'nome' => 'Igreja piloto',
    'descricao' => 'Plano inicial para teste de emissão',
    'ativo' => true,
    'permissoes' => ['titular.ver', 'titular.criar', 'carteirinha.gerar', 'subcliente.criar', 'usuario.criar'],
    'cotas' => [
        'limite_subclientes' => 3,
        'limite_usuarios_equipe' => 5,
        'limite_titulares' => 500,
        'limite_carteirinhas' => 1000,
    ],
]);

$planQuery = $pdo->prepare('SELECT nome, descricao, ativo FROM planos WHERE id = :id');
$planQuery->execute(['id' => $planId]);
$plan = $planQuery->fetch(PDO::FETCH_ASSOC);
$flagsQuery = $pdo->prepare('SELECT feature_key, habilitado, limite_valor FROM plano_feature_flags WHERE plano_id = :id');
$flagsQuery->execute(['id' => $planId]);
$flags = [];
foreach ($flagsQuery->fetchAll(PDO::FETCH_ASSOC) as $flag) {
    $flags[(string) $flag['feature_key']] = $flag;
}

$expectedLimits = [
    'subcliente.criar' => 3,
    'usuario.criar' => 5,
    'titular.criar' => 500,
    'carteirinha.gerar' => 1000,
];
foreach ($expectedLimits as $feature => $limit) {
    if (!isset($flags[$feature]) || (int) $flags[$feature]['habilitado'] !== 1
        || (int) $flags[$feature]['limite_valor'] !== $limit) {
        throw new RuntimeException('Plan permission and quota ceiling must be stored together.');
    }
}
if (!is_array($plan) || $plan['nome'] !== 'Igreja piloto' || (int) $plan['ativo'] !== 1
    || isset($flags['admin.clientes']) || isset($flags['admin.planos']) || isset($flags['financeiro.ver'])) {
    throw new RuntimeException('Tenant plans must persist safely without global-admin or future finance privileges.');
}

adminTenantPlanSetActive($pdo, $context, $identity, $planId, false);
if ((int) $pdo->query('SELECT ativo FROM planos WHERE id = ' . $planId)->fetchColumn() !== 0) {
    throw new RuntimeException('An unassigned plan must be deactivatable.');
}
adminTenantPlanSetActive($pdo, $context, $identity, $planId, true);
if ((int) $pdo->query('SELECT ativo FROM planos WHERE id = ' . $planId)->fetchColumn() !== 1) {
    throw new RuntimeException('A customer plan must be reactivatable for new Sedes.');
}

fwrite(STDOUT, "PASS: global superadmin can create and manage a customer plan with enabled features and enforced quota ceilings.\n");
