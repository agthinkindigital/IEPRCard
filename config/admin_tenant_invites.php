<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_tenant_plans.php';
require_once __DIR__ . '/tenant_provisioning.php';

/** @param array<string, mixed> $identity @return array{token:string,expires_at:string} */
function adminTenantInviteCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $planId,
    string $email,
    ?DateTimeImmutable $now = null
): array {
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    $email = strtolower(trim($email));
    if ($planId < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        throw new DomainException('ADMIN_TENANT_INVITE_INVALID');
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = $now->setTimezone(new DateTimeZone('UTC'));
    $expiresAt = $now->modify('+72 hours');
    $token = bin2hex(random_bytes(32));
    $emailHash = password_hash($email, PASSWORD_DEFAULT);
    if (!is_string($emailHash)) throw new RuntimeException('ADMIN_TENANT_INVITE_FAILED');

    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        adminTenantPlanRequireAccess($pdo, $context, $identity);
        $purge = $pdo->prepare('DELETE FROM tenant_owner_invites WHERE expira_em <= :now');
        $purge->execute(['now' => $now->format('Y-m-d H:i:s')]);
        $plan = $pdo->prepare('SELECT id FROM planos WHERE id = :id AND ativo = 1 LIMIT 1');
        $plan->execute(['id' => $planId]);
        if ($plan->fetchColumn() === false) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        $createdAt = $now->format('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            'INSERT INTO tenant_owner_invites (token_hash, email_hash, plano_id, criado_por_usuario_id, criado_em, expira_em) '
            . 'VALUES (:token_hash, :email_hash, :plan, :creator, :created, :expires)'
        );
        $insert->execute([
            'token_hash' => hash('sha256', $token), 'email_hash' => $emailHash, 'plan' => $planId,
            'creator' => (int) $identity['id'], 'created' => $createdAt, 'expires' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('ADMIN_TENANT_INVITE_FAILED');
    }

    return ['token' => $token, 'expires_at' => $expiresAt->format('Y-m-d H:i:s')];
}

/** @param array<string, mixed> $input @param array<string, string>|null $configuration @return array{tenant_id:int,owner_user_id:int} */
function adminTenantInviteComplete(
    PDO $pdo,
    string $token,
    string $email,
    array $input,
    ?array $configuration = null,
    ?DateTimeImmutable $now = null
): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
    }
    $email = strtolower(trim($email));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = $now->setTimezone(new DateTimeZone('UTC'));
    $timestamp = $now->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $query = $pdo->prepare(
            'SELECT i.id, i.email_hash, i.plano_id FROM tenant_owner_invites i '
            . 'JOIN planos p ON p.id = i.plano_id '
            . 'WHERE i.token_hash = :token AND i.expira_em > :now AND i.consumido_em IS NULL '
            . 'AND i.revogado_em IS NULL AND i.email_hash IS NOT NULL AND p.ativo = 1 LIMIT 1'
            . ($driver === 'mysql' ? ' FOR UPDATE' : '')
        );
        $query->execute(['token' => hash('sha256', $token), 'now' => $timestamp]);
        $invite = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invite) || !password_verify($email, (string) $invite['email_hash'])) {
            throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
        }
        $planId = (int) $invite['plano_id'];
        $creatorId = adminTenantInviteCreatorId($pdo, (int) $invite['id']);
        $limits = tenantQuotaPlanCeilings($pdo, $planId);
        foreach ($limits as $limit) {
            if ($limit === null) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        }

        $consume = $pdo->prepare(
            'UPDATE tenant_owner_invites SET consumido_em = :now, email_hash = NULL '
            . 'WHERE id = :id AND consumido_em IS NULL AND revogado_em IS NULL AND expira_em > :now'
        );
        $consume->execute(['now' => $timestamp, 'id' => (int) $invite['id']]);
        if ($consume->rowCount() !== 1) throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');

        $context = new TenantContext('admin', null, null, []);
        $result = tenantProvisionCreate($pdo, $context, [
            'id' => $creatorId,
            'papel_sistema' => 'superadmin',
        ], [
            'nome_organizacao' => $input['nome_organizacao'] ?? null,
            'slug_subdominio' => $input['slug_subdominio'] ?? null,
            'nome_titular' => $input['nome_titular'] ?? null,
            'email_titular' => $email,
            'senha_titular' => $input['senha_titular'] ?? null,
            'plano_id' => $planId,
            'status_conta' => 'pendente',
        ], $configuration, $limits);
        $pdo->commit();

        return $result;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('ADMIN_TENANT_INVITE_FAILED');
    }
}

function adminTenantInviteCreatorId(PDO $pdo, int $inviteId): int
{
    $query = $pdo->prepare('SELECT criado_por_usuario_id FROM tenant_owner_invites WHERE id = :id LIMIT 1');
    $query->execute(['id' => $inviteId]);
    $creator = filter_var($query->fetchColumn(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($creator === false) throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
    return (int) $creator;
}

/** @param array<string, mixed> $identity */
function adminTenantInviteRevoke(PDO $pdo, TenantContext $context, array $identity, int $inviteId): void
{
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    if ($inviteId < 1) throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
    $now = gmdate('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'UPDATE tenant_owner_invites SET revogado_em = :now, email_hash = NULL '
        . 'WHERE id = :id AND consumido_em IS NULL AND revogado_em IS NULL AND expira_em > :now'
    );
    $statement->execute(['now' => $now, 'id' => $inviteId]);
    if ($statement->rowCount() !== 1) throw new DomainException('ADMIN_TENANT_INVITE_UNAVAILABLE');
}

/** @param array<string, mixed> $identity */
function adminTenantSedeActivate(PDO $pdo, TenantContext $context, array $identity, int $tenantId): void
{
    adminTenantPlanRequireAccess($pdo, $context, $identity);
    if ($tenantId < 1 || $pdo->inTransaction()) throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        adminTenantPlanRequireAccess($pdo, $context, $identity);
        $query = $pdo->prepare(
            "SELECT t.plano_id, t.status_conta FROM tenants t WHERE t.id = :id AND t.parent_id IS NULL AND t.tipo = 'cliente' LIMIT 1"
        );
        $query->execute(['id' => $tenantId]);
        $sede = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sede) || $sede['status_conta'] !== 'pendente') {
            throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
        }
        $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
        $plan->execute(['id' => (int) $sede['plano_id']]);
        if ((int) $plan->fetchColumn() !== 1) throw new DomainException('TENANT_PLAN_UNAVAILABLE');
        $ownerQuery = $pdo->prepare(
            "SELECT id FROM usuarios WHERE tenant_id = :tenant AND subcliente_id IS NULL AND papel_sistema = 'titular_cliente' AND ativo = 0 LIMIT 2"
        );
        $ownerQuery->execute(['tenant' => $tenantId]);
        $owners = array_map('intval', $ownerQuery->fetchAll(PDO::FETCH_COLUMN));
        if (count($owners) !== 1) throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
        $ownerId = $owners[0];
        $owner = $pdo->prepare('UPDATE usuarios SET ativo = 1 WHERE id = :id AND tenant_id = :tenant AND ativo = 0');
        $owner->execute(['id' => $ownerId, 'tenant' => $tenantId]);
        if ($owner->rowCount() !== 1) throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
        $pdo->prepare(
            "UPDATE privacy_subjects SET estado = 'ativo', inativado_em = NULL WHERE id IN "
            . "(SELECT privacy_subject_id FROM privacy_subject_records WHERE tipo_registro = 'usuario' AND registro_id = :owner)"
        )->execute(['owner' => $ownerId]);
        $update = $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id AND status_conta = 'pendente'");
        $update->execute(['id' => $tenantId]);
        if ($update->rowCount() !== 1) throw new DomainException('ADMIN_TENANT_SEDE_INVALID');
        tenantQuotaAudit($pdo, $tenantId, $tenantId, null, $identity, 'tenant.activated', ['status_conta' => 'pendente'], ['status_conta' => 'ativo']);
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('ADMIN_TENANT_SEDE_FAILED');
    }
    motorPermissoesCacheLimpar($pdo);
}
