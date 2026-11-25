<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_plans.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_invites.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_access.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$pdo->prepare("INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES ('superadmin', 'Access Admin', 'access-admin@example.test', :hash, 1)")
    ->execute(['hash' => password_hash('unused-password', PASSWORD_DEFAULT)]);
$identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
$context = new TenantContext('admin', null, null, []);
$planId = adminTenantPlanCreate($pdo, $context, $identity, [
    'nome' => 'Access plan', 'descricao' => '', 'ativo' => true,
    'permissoes' => ['usuario.criar', 'titular.ver', 'titular.criar', 'carteirinha.gerar', 'subcliente.criar'],
    'cotas' => ['limite_subclientes' => 1, 'limite_usuarios_equipe' => 1, 'limite_titulares' => 10, 'limite_carteirinhas' => 20],
]);
$created = tenantProvisionCreate($pdo, $context, $identity, [
    'nome_organizacao' => 'Access Sede', 'slug_subdominio' => 'access-sede', 'nome_titular' => 'Sede Owner',
    'email_titular' => 'sede-owner@example.test', 'senha_titular' => 'owner-password-secure-123',
    'plano_id' => $planId, 'status_conta' => 'pendente',
], [], ['limite_subclientes' => 1, 'limite_usuarios_equipe' => 1, 'limite_titulares' => 10, 'limite_carteirinhas' => 20]);
adminTenantSedeActivate($pdo, $context, $identity, $created['tenant_id']);
$sedeAfterActivation = $pdo->prepare('SELECT status_conta FROM tenants WHERE id = :id');
$sedeAfterActivation->execute(['id' => $created['tenant_id']]);
if ($sedeAfterActivation->fetchColumn() !== 'ativo') throw new RuntimeException('Manual Sede activation must update the tenant.');
$ownerSubject = $pdo->prepare(
    "SELECT s.estado FROM privacy_subjects s JOIN privacy_subject_records r ON r.privacy_subject_id = s.id "
    . "WHERE r.tipo_registro = 'usuario' AND r.registro_id = :owner LIMIT 1"
);
$ownerSubject->execute(['owner' => $created['owner_user_id']]);
if ($ownerSubject->fetchColumn() !== 'ativo') throw new RuntimeException('Sede activation must reactivate the owner privacy subject.');
$access = adminTenantSedeAccessCreate($pdo, $context, $identity, $created['tenant_id'], [
    'nome' => 'Sede Operator', 'email' => 'sede-operator@example.test', 'senha' => 'operator-password-secure-123',
    'perfil' => 'Consulta de titulares', 'permissoes' => ['titular.ver'],
]);
$quotaRejected = false;
try {
    adminTenantSedeAccessCreate($pdo, $context, $identity, $created['tenant_id'], [
        'nome' => 'Extra Operator', 'email' => 'extra-operator@example.test', 'senha' => 'operator-password-secure-456',
        'perfil' => 'Extra', 'permissoes' => ['titular.ver'],
    ]);
} catch (DomainException $exception) {
    $quotaRejected = $exception->getMessage() === 'TENANT_QUOTA_EXCEEDED';
}
if (!$quotaRejected) throw new RuntimeException('Superadmin-created Sede access must respect the plan team quota.');
$deactivationRejected = false;
try {
    adminTenantPlanSetActive($pdo, $context, $identity, $planId, false);
} catch (DomainException $exception) {
    $deactivationRejected = $exception->getMessage() === 'ADMIN_TENANT_PLAN_IN_USE';
}
if (!$deactivationRejected) throw new RuntimeException('An active Sede plan must not be deactivated.');
$user = $pdo->prepare('SELECT tenant_id, subcliente_id, perfil_acesso_id, papel_sistema, ativo FROM usuarios WHERE id = :id');
$user->execute(['id' => $access['user_id']]);
$row = $user->fetch(PDO::FETCH_ASSOC);
$profile = $pdo->prepare('SELECT tenant_id, escopo_permitido, permissoes_acoes_json FROM perfis_acesso WHERE id = :id');
$profile->execute(['id' => $access['profile_id']]);
$profileRow = $profile->fetch(PDO::FETCH_ASSOC);
if (!is_array($row) || (int) $row['tenant_id'] !== $created['tenant_id'] || $row['subcliente_id'] !== null
    || (int) $row['perfil_acesso_id'] !== $access['profile_id'] || $row['papel_sistema'] !== 'subacesso' || (int) $row['ativo'] !== 1
    || !is_array($profileRow) || $profileRow['escopo_permitido'] !== 'sede'
    || json_decode((string) $profileRow['permissoes_acoes_json'], true) !== ['titular.ver']) {
    throw new RuntimeException('Sede subaccess must be scoped to the tenant and explicitly selected plan permissions.');
}

$rejected = false;
try {
    adminTenantSedeAccessCreate($pdo, $context, $identity, $created['tenant_id'], [
        'nome' => 'Extra Operator', 'email' => 'extra-operator@example.test', 'senha' => 'operator-password-secure-456',
        'perfil' => 'Extra', 'permissoes' => ['admin.clientes'],
    ]);
} catch (DomainException $exception) {
    $rejected = in_array($exception->getMessage(), ['TENANT_DELEGATION_PERMISSION_FORBIDDEN', 'TENANT_DELEGATION_PERMISSION_UNAVAILABLE'], true);
}
if (!$rejected) throw new RuntimeException('Sede access must not grant global or plan-disabled permissions.');

fwrite(STDOUT, "PASS: global Sede access is created within plan quota and with explicit tenant-scoped permissions.\n");
