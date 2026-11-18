<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $configuration = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];
    $custom = tenantResolverResolve($pdo, strtoupper($fixture['hostname']) . ':443', $configuration);
    if ($custom === null || $custom->tipo !== 'cliente' || $custom->tenantId !== $fixture['tenant_id']
        || $custom->subclienteId !== null || !isset($custom->branding['nome_organizacao'])) {
        throw new RuntimeException($driver . ': active verified custom domains with TLS must resolve the Sede.');
    }

    $root = tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $configuration);
    $child = tenantResolverResolve($pdo, 'unit-' . substr($fixture['slug'], 7) . '.' . $fixture['slug'] . '.ieprcard.example', $configuration);
    $admin = tenantResolverResolve($pdo, 'ADMIN.ieprcard.example.:443', $configuration);
    if ($root?->tipo !== 'cliente' || $root->tenantId !== $fixture['tenant_id']
        || $child?->tipo !== 'subcliente' || $child->tenantId !== $fixture['tenant_id']
        || $child->subclienteId !== $fixture['subcliente_id'] || $admin?->tipo !== 'admin') {
        throw new RuntimeException($driver . ': configured hosts must resolve admin, Sede, and Subcliente contexts.');
    }

    $pending = $pdo->prepare(
        'INSERT INTO dominios_tenant (tenant_id, hostname, estado, verificado_em, tls_validado_em) '
        . 'VALUES (:tenant, :hostname, :state, :verified, :tls)'
    );
    $pending->execute([
        'tenant' => $fixture['tenant_id'], 'hostname' => 'pending-' . $fixture['hostname'],
        'state' => 'pendente_verificacao', 'verified' => null, 'tls' => null,
    ]);
    $pendingHost = 'pending-' . $fixture['hostname'];
    $tlsMissing = $pdo->prepare(
        "INSERT INTO dominios_tenant (tenant_id, hostname, estado, verificado_em, tls_validado_em) "
        . "VALUES (:tenant, :hostname, 'ativo', :verified, NULL)"
    );
    $tlsMissing->execute(['tenant' => $fixture['tenant_id'], 'hostname' => 'no-tls-' . $fixture['hostname'], 'verified' => gmdate('Y-m-d H:i:s')]);
    $unverified = $pdo->prepare(
        "INSERT INTO dominios_tenant (tenant_id, hostname, estado, verificado_em, tls_validado_em) "
        . "VALUES (:tenant, :hostname, 'ativo', NULL, :tls)"
    );
    $unverified->execute(['tenant' => $fixture['tenant_id'], 'hostname' => 'unverified-' . $fixture['hostname'], 'tls' => gmdate('Y-m-d H:i:s')]);
    $inactiveTenant = $pdo->prepare(
        "INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'Conta suspensa', 'suspenso')"
    );
    $inactiveTenant->execute();
    $inactiveTenantId = (int) $pdo->lastInsertId();
    $tlsValid = gmdate('Y-m-d H:i:s');
    $inactiveDomain = $pdo->prepare(
        "INSERT INTO dominios_tenant (tenant_id, hostname, estado, verificado_em, tls_validado_em) "
        . "VALUES (:tenant, :hostname, 'ativo', :verified, :tls)"
    );
    $inactiveDomain->execute(['tenant' => $inactiveTenantId, 'hostname' => 'inactive-' . $fixture['hostname'], 'verified' => $tlsValid, 'tls' => $tlsValid]);

    foreach ([$pendingHost, 'no-tls-' . $fixture['hostname'], 'unverified-' . $fixture['hostname'], 'inactive-' . $fixture['hostname'], 'unknown.example.test'] as $rejectedHost) {
        if (tenantResolverResolve($pdo, $rejectedHost, $configuration) !== null) {
            throw new RuntimeException($driver . ': pending, TLS-missing, inactive, and unknown hosts must be rejected.');
        }
    }
    foreach (['cards.example.test@evil.test', 'cards.example.test/path', 'cards.example.test..', 'cards.example.test:65536'] as $invalidHost) {
        if (tenantResolverResolve($pdo, $invalidHost, $configuration) !== null) {
            throw new RuntimeException($driver . ': malformed Host values must be rejected.');
        }
    }
    if (!tenantResolverIsPlatformHost('ADMIN.ieprcard.example.:443', $configuration)
        || tenantResolverIsPlatformHost($fixture['hostname'], $configuration)) {
        throw new RuntimeException($driver . ': only the explicitly configured platform host may expose global administration.');
    }

    try {
        $pending->execute([
            'tenant' => $fixture['tenant_id'], 'hostname' => $fixture['hostname'],
            'state' => 'ativo', 'verified' => $tlsValid, 'tls' => $tlsValid,
        ]);
        throw new RuntimeException($driver . ': canonical domain hostname uniqueness must reject duplicates.');
    } catch (PDOException) {
    }

    $pdo->prepare('DELETE FROM dominios_tenant WHERE tenant_id = :tenant')->execute(['tenant' => $inactiveTenantId]);
    $pdo->prepare('DELETE FROM tenants WHERE id = :tenant')->execute(['tenant' => $inactiveTenantId]);
    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' host normalization, domain lifecycle, and tenant hierarchy. ' . "\n");
}

return 'pass';
