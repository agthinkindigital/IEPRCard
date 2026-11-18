<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/tenant_resolver.php';

/** @return array{id:int,nome:string,email:string,papel_sistema:string,tenant_id:int,subcliente_id:?int}|null */
function tenantAuthAuthenticate(PDO $pdo, TenantContext $context, string $email, string $password): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $validEmail = filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) !== false
        && strlen($normalizedEmail) <= 254;

    $statement = $pdo->prepare(
        'SELECT id, nome, email, senha_hash, papel_sistema, tenant_id, subcliente_id, ativo '
        . 'FROM usuarios WHERE tenant_id = :tenant_id AND email = :email ORDER BY id LIMIT 2'
    );
    $tenantId = $context->tenantId ?? 0;
    $statement->execute(['tenant_id' => $tenantId, 'email' => $validEmail ? $normalizedEmail : '']);
    $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
    $user = count($matches) === 1 ? $matches[0] : null;

    static $dummyHash = null;
    $dummyHash ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $hash = $user === null ? $dummyHash : (string) $user['senha_hash'];
    $passwordMatches = password_verify($password, $hash);

    if (!$validEmail || $user === null || !$passwordMatches || (int) $user['ativo'] !== 1
        || !tenantAuthContextIsActive($pdo, $context)) {
        return null;
    }

    $identity = tenantAuthIdentityFromRow($user);
    if ($identity === null || !tenantContextAuthorizesIdentity($context, $identity)) {
        return null;
    }

    return $identity;
}

/** @param array<string,mixed> $session */
function tenantAuthCsrfToken(array &$session): string
{
    return authSessionCsrfToken($session);
}

/** @param array<string,mixed> $session */
function tenantAuthVerifyCsrf(array $session, mixed $submittedToken): bool
{
    return authSessionVerifyCsrf($session, $submittedToken);
}

function tenantAuthStartSession(?bool $https = null): bool
{
    return authSessionStart('IEPRCARDTENANT', $https);
}

function tenantAuthLogin(
    PDO $pdo,
    TenantContext $context,
    string $email,
    string $password,
    string $clientIp,
    ?int $timestamp = null,
    ?string $rateLimitPath = null
): ?array
{
    if (!tenantAuthStartSession() || !tenantAuthAllowAttempt($clientIp, $timestamp, $rateLimitPath)) {
        return null;
    }

    $user = tenantAuthAuthenticate($pdo, $context, $email, $password);
    if ($user === null) {
        return null;
    }

    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        tenantAuthLogout();
        return null;
    }

    $_SESSION['tenant_user_id'] = $user['id'];
    $_SESSION['tenant_id'] = $user['tenant_id'];
    $_SESSION['subcliente_id'] = $user['subcliente_id'];
    $_SESSION['papel_sistema'] = $user['papel_sistema'];
    $_SESSION['tenant_authenticated_at'] = time();
    $_SESSION['last_activity_at'] = time();
    tenantAuthCsrfToken($_SESSION);

    return $user;
}

/** @return array{id:int,nome:string,email:string,papel_sistema:string,tenant_id:int,subcliente_id:?int}|null */
function tenantAuthCurrentUser(PDO $pdo, TenantContext $context): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== 'IEPRCARDTENANT'
        || !array_key_exists('tenant_user_id', $_SESSION)) {
        return null;
    }

    $userId = filter_var($_SESSION['tenant_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lastActivity = filter_var($_SESSION['last_activity_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false || $lastActivity === false || time() - $lastActivity > 1800) {
        tenantAuthResetExpiredSession();
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, nome, email, papel_sistema, tenant_id, subcliente_id, ativo '
        . 'FROM usuarios WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $user = is_array($row) ? tenantAuthIdentityFromRow($row) : null;

    if ($user === null || (int) $row['ativo'] !== 1
        || $user['papel_sistema'] !== ($_SESSION['papel_sistema'] ?? null)
        || $user['tenant_id'] !== tenantAuthSessionId($_SESSION['tenant_id'] ?? null)
        || !tenantAuthSessionNullableIdMatches($_SESSION['subcliente_id'] ?? null, $user['subcliente_id'])
        || !tenantAuthContextIsActive($pdo, $context)
        || !tenantContextAuthorizesIdentity($context, $user)) {
        tenantAuthResetExpiredSession();
        return null;
    }

    $_SESSION['last_activity_at'] = time();

    return $user;
}

function tenantAuthLogout(): void
{
    authSessionLogout('IEPRCARDTENANT');
}

function tenantAuthResetExpiredSession(): void
{
    authSessionResetExpired('IEPRCARDTENANT');
}

function tenantAuthAllowAttempt(string $clientIp, ?int $timestamp = null, ?string $storagePath = null): bool
{
    $storagePath ??= (string) (getenv('IEPRCARD_TENANT_RATE_LIMIT_PATH')
        ?: dirname(__DIR__) . '/var/tenant-login-rate-limit.json');
    return authSessionAllowAttempt($clientIp, $timestamp, $storagePath);
}

/** @param array<string,mixed> $row @return array{id:int,nome:string,email:string,papel_sistema:string,tenant_id:int,subcliente_id:?int}|null */
function tenantAuthIdentityFromRow(array $row): ?array
{
    $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($row['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rawSubclienteId = $row['subcliente_id'] ?? null;
    $subclienteId = $rawSubclienteId === null ? null : tenantAuthSessionId($rawSubclienteId);
    $role = $row['papel_sistema'] ?? null;
    if ($id === false || $tenantId === false || ($rawSubclienteId !== null && $subclienteId === null)
        || !in_array($role, ['titular_cliente', 'responsavel_sub', 'subacesso'], true)
        || ($role === 'titular_cliente' && $subclienteId !== null)
        || ($role === 'responsavel_sub' && $subclienteId === null)) {
        return null;
    }

    return [
        'id' => (int) $id,
        'nome' => (string) $row['nome'],
        'email' => (string) $row['email'],
        'papel_sistema' => $role,
        'tenant_id' => (int) $tenantId,
        'subcliente_id' => $subclienteId,
    ];
}

function tenantAuthContextIsActive(PDO $pdo, TenantContext $context): bool
{
    if ($context->tipo === 'cliente' && $context->tenantId !== null && $context->subclienteId === null) {
        $statement = $pdo->prepare(
            "SELECT 1 FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
        );
        $statement->execute(['id' => $context->tenantId]);
        return $statement->fetchColumn() !== false;
    }

    if ($context->tipo !== 'subcliente' || $context->tenantId === null || $context->subclienteId === null) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT 1 FROM tenants child JOIN tenants root ON root.id = child.parent_id "
        . "WHERE child.id = :child AND child.parent_id = :root AND child.tipo = 'subcliente' "
        . "AND child.status_conta = 'ativo' AND root.tipo = 'cliente' AND root.parent_id IS NULL "
        . "AND root.status_conta = 'ativo' LIMIT 1"
    );
    $statement->execute(['child' => $context->subclienteId, 'root' => $context->tenantId]);
    return $statement->fetchColumn() !== false;
}

function tenantAuthSessionId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : (int) $id;
}

function tenantAuthSessionNullableIdMatches(mixed $sessionValue, ?int $databaseValue): bool
{
    if ($databaseValue === null) {
        return $sessionValue === null;
    }
    return tenantAuthSessionId($sessionValue) === $databaseValue;
}
