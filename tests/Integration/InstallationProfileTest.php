<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/installation_profile.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $adminInsert = $pdo->prepare(
        "INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES (NULL, NULL, NULL, NULL, 'superadmin', :name, :email, :hash, 1)"
    );
    $adminInsert->execute(['name' => 'Admin ' . $driver, 'email' => strtolower(str_replace('/', '-', $driver)) . '@example.test', 'hash' => password_hash('password-long-123', PASSWORD_DEFAULT)]);
    $adminId = (int) $pdo->lastInsertId();
    $unitUser = $pdo->prepare(
        "INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, papel_sistema, nome, email, senha_hash, ativo) "
        . "VALUES (:tenant, :unit, NULL, :admin, 'responsavel_sub', :name, :email, :hash, 1)"
    );
    $unitUser->execute([
        'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'admin' => $adminId,
        'name' => 'Unit ' . $driver, 'email' => 'unit-' . strtolower(str_replace('/', '-', $driver)) . '@example.test',
        'hash' => password_hash('password-long-123', PASSWORD_DEFAULT),
    ]);
    $unitUserId = (int) $pdo->lastInsertId();

    if (installationProfileValidate($pdo, ['modo' => 'local_host', 'tenant_local_id' => $fixture['tenant_id']])
        !== ['modo' => 'local_host', 'tenant_local_id' => $fixture['tenant_id'], 'hostname_plataforma' => null, 'dominio_base' => null]) {
        throw new RuntimeException($driver . ': Local Host profile should validate an active Sede.');
    }
    $hosted = installationProfileValidate($pdo, [
        'modo' => 'hospedado', 'hostname_plataforma' => 'ADMIN.Example.Test', 'dominio_base' => 'Example.Test',
    ]);
    if ($hosted['hostname_plataforma'] !== 'admin.example.test' || $hosted['dominio_base'] !== 'example.test') {
        throw new RuntimeException($driver . ': Hosted hostnames should be normalized before persistence.');
    }
    foreach ([
        ['modo' => 'remote'],
        ['modo' => 'local_host', 'tenant_local_id' => $fixture['subcliente_id']],
        ['modo' => 'hospedado', 'hostname_plataforma' => 'bad/path', 'dominio_base' => 'example.test'],
        ['modo' => 'hospedado', 'hostname_plataforma' => 'example.test', 'dominio_base' => 'example.test'],
        ['modo' => 'hospedado', 'hostname_plataforma' => '127.0.0.1', 'dominio_base' => 'example.test'],
        ['modo' => 'hospedado', 'hostname_plataforma' => 'admin.localhost', 'dominio_base' => 'localhost'],
    ] as $invalid) {
        try {
            installationProfileValidate($pdo, $invalid);
            throw new RuntimeException($driver . ': invalid installation profile was accepted.');
        } catch (InvalidArgumentException) {
        }
    }

    try {
        installationProfileSave($pdo, ['modo' => 'local_host', 'tenant_local_id' => $fixture['tenant_id']], $adminId + 1);
        throw new RuntimeException($driver . ': an unverified administrator must not change installation profile.');
    } catch (DomainException) {
    }
    $first = installationProfileSave($pdo, ['modo' => 'local_host', 'tenant_local_id' => $fixture['tenant_id']], $adminId);
    $second = installationProfileSave($pdo, [
        'modo' => 'hospedado', 'hostname_plataforma' => 'admin.example.test', 'dominio_base' => 'example.test',
    ], $adminId);
    $history = $pdo->query('SELECT id, modo, ator_usuario_id FROM configuracoes_instalacao ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($first >= $second || count($history) !== 2 || $history[0]['modo'] !== 'local_host'
        || (int) $history[0]['ator_usuario_id'] !== $adminId || installationProfileCurrent($pdo)['modo'] !== 'hospedado') {
        throw new RuntimeException($driver . ': profile revisions must preserve actor and order while exposing the newest state.');
    }

    $localProfile = ['modo' => 'local_host', 'tenant_local_id' => $fixture['tenant_id']];
    $originalServer = $_SERVER;
    $_SERVER['HTTP_HOST'] = 'localhost:9477';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $localContext = installationProfileTenantContext($pdo, $localProfile);
    if (!installationProfileIsLoopbackRequest() || $localContext?->tenantId !== $fixture['tenant_id']
        || $localContext?->tipo !== 'cliente' || $localContext?->subclienteId !== null) {
        throw new RuntimeException($driver . ': Local Host must resolve only the configured Sede on loopback.');
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    if (installationProfileIsLoopbackRequest()) {
        throw new RuntimeException($driver . ': a remote connection spoofing localhost must be rejected.');
    }
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    installationProfileSave($pdo, $localProfile, $adminId);
    if (tenantResolverResolve($pdo, 'localhost:9477')?->tenantId !== $fixture['tenant_id']
        || tenantResolverResolve($pdo, 'spoofed.example.test') !== null) {
        throw new RuntimeException($driver . ': loopback requests should resolve the configured Local Host Sede.');
    }
    $_SERVER['HTTP_HOST'] = '[::1]:9477';
    $_SERVER['REMOTE_ADDR'] = '::1';
    if (!installationProfileIsLoopbackRequest() || tenantResolverResolve($pdo, '[::1]:9477')?->tenantId !== $fixture['tenant_id']) {
        throw new RuntimeException($driver . ': IPv6 loopback should be accepted for Local Host.');
    }
    $_SERVER['HTTP_HOST'] = 'localhost:9477';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name('IEPRCARDTENANT');
    session_id(bin2hex(random_bytes(16)));
    if (!session_start()) throw new RuntimeException($driver . ': tenant session should start for local context test.');
    $_SESSION['tenant_user_id'] = $unitUserId;
    $unitContext = tenantResolverResolve($pdo, 'localhost:9477');
    if ($unitContext?->tipo !== 'subcliente' || $unitContext->tenantId !== $fixture['tenant_id']
        || $unitContext->subclienteId !== $fixture['subcliente_id']) {
        throw new RuntimeException($driver . ': Local Host unit context must come from the authenticated account record.');
    }
    session_write_close();
    $_SERVER = $originalServer;

    $pdo->prepare('DELETE FROM configuracoes_instalacao WHERE ator_usuario_id = :actor')->execute(['actor' => $adminId]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $unitUserId]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminId]);
    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' installation profile validation, audit history, and Local Host loopback guard.' . "\n");
}

return 'pass';
