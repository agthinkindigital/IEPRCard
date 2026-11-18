<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/admin_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';

$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-tenant-auth-sessions-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Could not create temporary tenant-auth session storage.');
}
session_save_path($sessionDirectory);

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $configuration = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];
    $rootContext = tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $configuration);
    $childSlug = 'unit-' . substr($fixture['slug'], 7);
    $childContext = tenantResolverResolve($pdo, $childSlug . '.' . $fixture['slug'] . '.ieprcard.example', $configuration);
    if ($rootContext === null || $childContext === null) {
        throw new RuntimeException($driver . ': tenant auth fixtures must resolve root and child contexts.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
        . 'VALUES (:tenant, :subclient, :role, :name, :email, :password, :active)'
    );
    $addUser = static function (int $tenantId, ?int $subclienteId, string $role, string $email, int $active = 1) use ($insert, $pdo): int {
        $insert->execute([
            'tenant' => $tenantId,
            'subclient' => $subclienteId,
            'role' => $role,
            'name' => $role,
            'email' => $email,
            'password' => password_hash('tenant-password-123', PASSWORD_DEFAULT),
            'active' => $active,
        ]);
        return (int) $pdo->lastInsertId();
    };

    $rootEmail = 'owner-' . bin2hex(random_bytes(4)) . '@example.test';
    $childEmail = 'leader-' . bin2hex(random_bytes(4)) . '@example.test';
    $networkEmail = 'network-' . bin2hex(random_bytes(4)) . '@example.test';
    $unitEmail = 'unit-access-' . bin2hex(random_bytes(4)) . '@example.test';
    $inactiveEmail = 'inactive-' . bin2hex(random_bytes(4)) . '@example.test';
    $collisionEmail = 'shared-' . bin2hex(random_bytes(4)) . '@example.test';
    $rootUserId = $addUser($fixture['tenant_id'], null, 'titular_cliente', $rootEmail);
    $childUserId = $addUser($fixture['tenant_id'], $fixture['subcliente_id'], 'responsavel_sub', $childEmail);
    $networkUserId = $addUser($fixture['tenant_id'], null, 'subacesso', $networkEmail);
    $unitAccessUserId = $addUser($fixture['tenant_id'], $fixture['subcliente_id'], 'subacesso', $unitEmail);
    $inactiveUserId = $addUser($fixture['tenant_id'], null, 'titular_cliente', $inactiveEmail, 0);
    $collisionOwnerUserId = $addUser($fixture['tenant_id'], null, 'titular_cliente', $collisionEmail);
    $otherTenant = $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', 'Outro tenant', :slug, 'ativo')");
    $otherTenant->execute(['slug' => 'tenant-' . bin2hex(random_bytes(5))]);
    $otherTenantId = (int) $pdo->lastInsertId();
    $otherTenantUserId = $addUser($otherTenantId, null, 'titular_cliente', $collisionEmail);
    $admin = $pdo->prepare('INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) VALUES (:role, :name, :email, :password, 1)');
    $admin->execute([
        'role' => 'superadmin', 'name' => 'Admin global',
        'email' => 'admin-' . bin2hex(random_bytes(4)) . '@example.test',
        'password' => password_hash('tenant-password-123', PASSWORD_DEFAULT),
    ]);
    $adminUserId = (int) $pdo->lastInsertId();
    $holder = $pdo->prepare('INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo) VALUES (:tenant, :role, :name, :email, :password, 1)');
    $holder->execute([
        'tenant' => $fixture['tenant_id'], 'role' => 'titular_final', 'name' => 'Titular final',
        'email' => 'holder-' . bin2hex(random_bytes(4)) . '@example.test',
        'password' => password_hash('tenant-password-123', PASSWORD_DEFAULT),
    ]);
    $holderUserId = (int) $pdo->lastInsertId();
    $login = static fn(TenantContext $context, string $email, string $password): ?array => tenantAuthLogin(
        $pdo,
        $context,
        $email,
        $password,
        '127.0.0.1',
        null,
        $sessionDirectory . DIRECTORY_SEPARATOR . 'tenant-login-' . strtolower(str_replace('/', '-', $driver)) . '.json'
    );

    $validRoot = tenantAuthAuthenticate($pdo, $rootContext, $rootEmail, 'tenant-password-123');
    $validChild = tenantAuthAuthenticate($pdo, $childContext, $childEmail, 'tenant-password-123');
    if ($validRoot === null || $validRoot['id'] !== $rootUserId || $validRoot['tenant_id'] !== $fixture['tenant_id']
        || $validChild === null || $validChild['id'] !== $childUserId || $validChild['subcliente_id'] !== $fixture['subcliente_id']
        || tenantAuthAuthenticate($pdo, $rootContext, $networkEmail, 'tenant-password-123') === null
        || tenantAuthAuthenticate($pdo, $childContext, $unitEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': active tenant operators must authenticate only in their matching host scope.');
    }
    foreach ([
        [$rootContext, $childEmail], [$childContext, $rootEmail], [$childContext, $networkEmail],
        [$rootContext, $unitEmail], [$rootContext, $inactiveEmail],
    ] as [$context, $email]) {
        if (tenantAuthAuthenticate($pdo, $context, $email, 'tenant-password-123') !== null) {
            throw new RuntimeException($driver . ': invalid, inactive, ambiguous, or scope-mismatched users must be denied.');
        }
    }
    foreach ([$adminUserId, $holderUserId] as $deniedUserId) {
        $deniedUser = $pdo->query('SELECT email FROM usuarios WHERE id = ' . (int) $deniedUserId)->fetchColumn();
        if (tenantAuthAuthenticate($pdo, $rootContext, (string) $deniedUser, 'tenant-password-123') !== null) {
            throw new RuntimeException($driver . ': global administrators and card holders must not enter tenant sessions.');
        }
    }
    if (tenantAuthAuthenticate($pdo, $rootContext, $rootEmail, 'wrong-password') !== null
        || tenantAuthAuthenticate($pdo, $rootContext, strtoupper($rootEmail), 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': wrong passwords must deny while email normalization remains case-insensitive.');
    }
    $rateLimitPath = $sessionDirectory . DIRECTORY_SEPARATOR . 'tenant-rate-limit-' . strtolower(str_replace('/', '-', $driver)) . '.json';
    if (tenantAuthAllowAttempt('not-an-ip', 6000, $rateLimitPath)) {
        throw new RuntimeException($driver . ': tenant login rate limiting must reject an invalid client IP.');
    }
    for ($attempt = 0; $attempt < 60; $attempt++) {
        if (!tenantAuthAllowAttempt('198.51.100.77', 6000, $rateLimitPath)) {
            throw new RuntimeException($driver . ': the tenant rate limit must allow attempts within the configured window.');
        }
    }
    if (tenantAuthAllowAttempt('198.51.100.77', 6000, $rateLimitPath)
        || str_contains((string) file_get_contents($rateLimitPath), '198.51.100.77')) {
        throw new RuntimeException($driver . ': the tenant rate limit must cap attempts and persist no raw IP.');
    }
    $_SERVER['HTTPS'] = 'on';
    if (tenantAuthLogin($pdo, $rootContext, $rootEmail, 'tenant-password-123', '198.51.100.77', 6000, $rateLimitPath) !== null) {
        throw new RuntimeException($driver . ': tenant login must enforce the rate limiter even for valid credentials.');
    }
    $otherTenantSlug = (string) $pdo->query('SELECT slug_subdominio FROM tenants WHERE id = ' . $otherTenantId)->fetchColumn();
    $otherTenantContext = tenantResolverResolve($pdo, $otherTenantSlug . '.ieprcard.example', $configuration);
    $collisionOwner = tenantAuthAuthenticate($pdo, $rootContext, $collisionEmail, 'tenant-password-123');
    $collisionOther = $otherTenantContext === null ? null : tenantAuthAuthenticate($pdo, $otherTenantContext, $collisionEmail, 'tenant-password-123');
    if ($collisionOwner === null || $collisionOwner['id'] !== $collisionOwnerUserId
        || $collisionOther === null || $collisionOther['id'] !== $otherTenantUserId) {
        throw new RuntimeException($driver . ': identical emails must authenticate only the account belonging to the resolved tenant.');
    }

    $previousName = session_name();
    $previousId = session_id();
    if (!tenantAuthStartSession()) {
        throw new RuntimeException($driver . ': tenant session must start.');
    }
    $_SESSION['pre_authentication_value'] = 'discard-me';
    $preAuthenticationId = session_id();
    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null
        || session_id() === $preAuthenticationId || isset($_SESSION['pre_authentication_value'])
        || ($_SESSION['tenant_user_id'] ?? null) !== $rootUserId
        || session_name() === 'IEPRCARDADMIN') {
        throw new RuntimeException($driver . ': tenant login must rotate and isolate its session.');
    }
    $cookie = session_get_cookie_params();
    $current = tenantAuthCurrentUser($pdo, $rootContext);
    if ($current === null || $current['id'] !== $rootUserId || $cookie['httponly'] !== true
        || strtolower((string) $cookie['samesite']) !== 'lax' || $cookie['secure'] !== true) {
        throw new RuntimeException($driver . ': tenant sessions must revalidate users and use secure cookie policy.');
    }
    $_SESSION['last_activity_at'] = time() - 1801;
    if (tenantAuthCurrentUser($pdo, $rootContext) !== null || isset($_SESSION['tenant_user_id'])) {
        throw new RuntimeException($driver . ': tenant session must expire after 30 minutes of inactivity.');
    }

    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': a valid tenant operator must be able to start a new session.');
    }
    $pdo->prepare('UPDATE usuarios SET papel_sistema = :role WHERE id = :id')->execute(['role' => 'responsavel_sub', 'id' => $rootUserId]);
    if (tenantAuthCurrentUser($pdo, $rootContext) !== null || isset($_SESSION['tenant_user_id'])) {
        throw new RuntimeException($driver . ': role changes during a session must revoke access.');
    }

    $pdo->prepare('UPDATE usuarios SET papel_sistema = :role WHERE id = :id')->execute(['role' => 'titular_cliente', 'id' => $rootUserId]);
    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': restored valid credentials must authenticate.');
    }
    $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = :id')->execute(['id' => $rootUserId]);
    if (tenantAuthCurrentUser($pdo, $rootContext) !== null || isset($_SESSION['tenant_user_id'])) {
        throw new RuntimeException($driver . ': deactivating a tenant operator must revoke its active session.');
    }
    $pdo->prepare('UPDATE usuarios SET ativo = 1 WHERE id = :id')->execute(['id' => $rootUserId]);
    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': reactivating an operator must allow authentication again.');
    }
    $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
    if (tenantAuthCurrentUser($pdo, $rootContext) !== null || isset($_SESSION['tenant_user_id'])) {
        throw new RuntimeException($driver . ': suspending a tenant must revoke its active operator session.');
    }
    $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': reactivating a tenant must allow its active operator to authenticate again.');
    }
    $csrf = tenantAuthCsrfToken($_SESSION);
    if (!tenantAuthVerifyCsrf($_SESSION, $csrf) || tenantAuthVerifyCsrf($_SESSION, substr($csrf, 1))) {
        throw new RuntimeException($driver . ': tenant CSRF tokens must be session-bound and compared safely.');
    }
    if ($login($childContext, $childEmail, 'tenant-password-123') === null
        || tenantAuthCurrentUser($pdo, $rootContext) !== null || isset($_SESSION['tenant_user_id'])) {
        throw new RuntimeException($driver . ': a tenant session presented on the wrong resolved host must be revoked.');
    }
    if ($login($rootContext, $rootEmail, 'tenant-password-123') === null) {
        throw new RuntimeException($driver . ': valid root credentials must create a fresh session after host mismatch.');
    }
    $tenantSessionId = session_id();
    tenantAuthLogout();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        throw new RuntimeException($driver . ': tenant logout must destroy the tenant session.');
    }

    if (!adminAuthStartSession(false)) {
        throw new RuntimeException($driver . ': admin session must remain usable after tenant logout.');
    }
    $_SESSION['admin_user_id'] = $adminUserId;
    adminAuthCsrfToken($_SESSION);
    $adminSessionId = session_id();
    if (tenantAuthStartSession(false)) {
        throw new RuntimeException($driver . ': an active admin session must not be reused as a tenant session.');
    }
    tenantAuthLogout();
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== 'IEPRCARDADMIN'
        || (int) ($_SESSION['admin_user_id'] ?? 0) !== $adminUserId) {
        throw new RuntimeException($driver . ': tenant logout must be a no-op when the active session is administrative.');
    }
    session_write_close();
    $_COOKIE['IEPRCARDTENANT'] = $tenantSessionId;
    if (!tenantAuthStartSession(false) || tenantAuthCurrentUser($pdo, $rootContext) !== null) {
        throw new RuntimeException($driver . ': tenant session must not inherit global admin identity.');
    }
    if (session_id() === $adminSessionId) {
        throw new RuntimeException($driver . ': switching session namespaces must not reuse the previous session ID.');
    }
    tenantAuthLogout();
    $_COOKIE['IEPRCARDADMIN'] = $adminSessionId;
    session_name('IEPRCARDADMIN');
    session_id($adminSessionId);
    $adminRestarted = adminAuthStartSession(false);
    if (!$adminRestarted || (int) ($_SESSION['admin_user_id'] ?? 0) !== $adminUserId) {
        throw new RuntimeException($driver . ': tenant logout must not destroy a separately stored admin session. '
            . json_encode(['started' => $adminRestarted, 'name' => session_name(), 'session_id' => session_id(),
                'expected_session_id' => $adminSessionId, 'admin_user_id' => $_SESSION['admin_user_id'] ?? null,
                'expected_admin_user_id' => $adminUserId], JSON_THROW_ON_ERROR));
    }
    adminAuthLogout();
    unset($_COOKIE['IEPRCARDTENANT'], $_COOKIE['IEPRCARDADMIN'], $_SERVER['HTTPS']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if ($previousName !== '' && $previousName !== session_name()) {
        session_name($previousName);
    }
    if ($previousId !== '') {
        session_id($previousId);
    }

    $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $otherTenantUserId]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminUserId]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $holderUserId]);
    $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $otherTenantId]);
    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' tenant authentication, session isolation, scope binding, and revocation.' . "\n");
}

foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}
@rmdir($sessionDirectory);

return 'pass';
