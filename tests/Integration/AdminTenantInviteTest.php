<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_plans.php';
require_once dirname(__DIR__, 2) . '/config/admin_tenant_invites.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($pdo, 'test');
schemaAplicarMigracoes($pdo, 'test');
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES ('superadmin', 'Invite Admin', 'invite-admin@example.test', :hash, 1)"
)->execute(['hash' => password_hash('unused-password', PASSWORD_DEFAULT)]);
$identity = ['id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin'];
$context = new TenantContext('admin', null, null, []);
$planId = adminTenantPlanCreate($pdo, $context, $identity, [
    'nome' => 'Invite plan', 'descricao' => '', 'ativo' => true,
    'permissoes' => ['titular.ver', 'titular.criar', 'carteirinha.gerar', 'subcliente.criar', 'usuario.criar'],
    'cotas' => [
        'limite_subclientes' => 2, 'limite_usuarios_equipe' => 3,
        'limite_titulares' => 50, 'limite_carteirinhas' => 90,
    ],
]);

$invite = adminTenantInviteCreate($pdo, $context, $identity, $planId, 'new-owner@example.test', new DateTimeImmutable('2026-11-25 12:00:00 UTC'));
if (preg_match('/^[a-f0-9]{64}$/D', $invite['token']) !== 1
    || $invite['expires_at'] !== '2026-11-28 12:00:00') {
    throw new RuntimeException('Invite must return a high-entropy one-time token with a 72-hour expiry.');
}
$stored = $pdo->query('SELECT token_hash, email_hash FROM tenant_owner_invites')->fetch(PDO::FETCH_ASSOC);
if (!is_array($stored) || $stored['token_hash'] !== hash('sha256', $invite['token'])
    || password_verify('new-owner@example.test', (string) $stored['email_hash']) !== true) {
    throw new RuntimeException('Invite storage must bind the email and store only a token hash.');
}

$emailMismatchRejected = false;
try {
    adminTenantInviteComplete($pdo, $invite['token'], 'other-owner@example.test', [
        'nome_organizacao' => 'Wrong owner', 'slug_subdominio' => 'wrong-owner',
        'nome_titular' => 'Other', 'senha_titular' => 'safe-invite-password-456',
    ], [], new DateTimeImmutable('2026-11-26 12:00:00 UTC'));
} catch (DomainException $exception) {
    $emailMismatchRejected = $exception->getMessage() === 'ADMIN_TENANT_INVITE_UNAVAILABLE';
}
if (!$emailMismatchRejected || (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug_subdominio = 'wrong-owner'")->fetchColumn() !== 0) {
    throw new RuntimeException('A mismatched invite email must be rejected without consuming the valid invite.');
}

$result = adminTenantInviteComplete($pdo, $invite['token'], 'NEW-OWNER@example.test', [
    'nome_organizacao' => 'Igreja Convidada', 'slug_subdominio' => 'igreja-convidada',
    'nome_titular' => 'Responsável Convidado', 'senha_titular' => 'safe-invite-password-123',
], [], new DateTimeImmutable('2026-11-26 12:00:00 UTC'));
$tenant = $pdo->prepare('SELECT status_conta, plano_id, limite_subclientes, limite_usuarios_equipe, limite_titulares, limite_carteirinhas FROM tenants WHERE id = :id');
$tenant->execute(['id' => $result['tenant_id']]);
$tenantRow = $tenant->fetch(PDO::FETCH_ASSOC);
$owner = $pdo->prepare('SELECT email, ativo, senha_hash FROM usuarios WHERE id = :id');
$owner->execute(['id' => $result['owner_user_id']]);
$ownerRow = $owner->fetch(PDO::FETCH_ASSOC);
$inviteRow = $pdo->query('SELECT email_hash, consumido_em FROM tenant_owner_invites')->fetch(PDO::FETCH_ASSOC);
if (!is_array($tenantRow) || $tenantRow['status_conta'] !== 'pendente' || (int) $tenantRow['plano_id'] !== $planId
    || (int) $tenantRow['limite_subclientes'] !== 2 || (int) $tenantRow['limite_usuarios_equipe'] !== 3
    || (int) $tenantRow['limite_titulares'] !== 50 || (int) $tenantRow['limite_carteirinhas'] !== 90
    || !is_array($ownerRow) || $ownerRow['email'] !== 'new-owner@example.test' || (int) $ownerRow['ativo'] !== 0
    || !password_verify('safe-invite-password-123', (string) $ownerRow['senha_hash'])
    || !is_array($inviteRow) || $inviteRow['email_hash'] !== null || $inviteRow['consumido_em'] === null) {
    throw new RuntimeException('Invite completion must atomically create a pending Sede/owner and erase the email verifier.');
}
$subjectQuery = $pdo->prepare(
    "SELECT s.estado, s.inativado_em FROM privacy_subjects s JOIN privacy_subject_records r "
    . "ON r.privacy_subject_id = s.id WHERE r.tipo_registro = 'usuario' AND r.registro_id = :user LIMIT 1"
);
$subjectQuery->execute(['user' => $result['owner_user_id']]);
$subjectRow = $subjectQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($subjectRow) || $subjectRow['estado'] !== 'inativo' || $subjectRow['inativado_em'] === null) {
    throw new RuntimeException('A pending owner must start the existing privacy retention clock as inactive.');
}

$replayRejected = false;
try {
    adminTenantInviteComplete($pdo, $invite['token'], 'new-owner@example.test', [
        'nome_organizacao' => 'Duplicada', 'slug_subdominio' => 'duplicada',
        'nome_titular' => 'Responsável', 'senha_titular' => 'another-safe-password-123',
    ], [], new DateTimeImmutable('2026-11-26 12:00:00 UTC'));
} catch (DomainException $exception) {
    $replayRejected = $exception->getMessage() === 'ADMIN_TENANT_INVITE_UNAVAILABLE';
}
if (!$replayRejected || (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug_subdominio = 'duplicada'")->fetchColumn() !== 0) {
    throw new RuntimeException('A consumed invite must not be replayable.');
}

fwrite(STDOUT, "PASS: Sede invite binds a normalized email, expires in 72 hours, provisions atomically, and is single-use.\n");
