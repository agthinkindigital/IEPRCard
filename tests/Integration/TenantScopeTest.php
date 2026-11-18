<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $configuration = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];
    $rootContext = tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $configuration);
    $childSlug = 'unit-' . substr($fixture['slug'], 7);
    $childContext = tenantResolverResolve($pdo, $childSlug . '.' . $fixture['slug'] . '.ieprcard.example', $configuration);
    $rootIdentity = ['id' => 101, 'papel_sistema' => 'titular_cliente', 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null];
    $childIdentity = ['id' => 202, 'papel_sistema' => 'responsavel_sub', 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => $fixture['subcliente_id']];
    $adminIdentity = ['id' => 1, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
    $holderIdentity = ['id' => 303, 'papel_sistema' => 'titular_final', 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null];

    if ($rootContext === null || $childContext === null
        || !tenantContextAuthorizesIdentity($rootContext, $rootIdentity)
        || !tenantContextAuthorizesIdentity($childContext, $childIdentity)
        || tenantContextAuthorizesIdentity($childContext, $rootIdentity)
        || tenantContextAuthorizesIdentity($rootContext, $adminIdentity)
        || tenantContextAuthorizesIdentity($rootContext, ['papel_sistema' => 'titular_cliente', 'tenant_id' => $fixture['tenant_id']])
        || tenantContextAuthorizesIdentity($rootContext, $holderIdentity)) {
        throw new RuntimeException($driver . ': host resolution must match an authenticated identity and exact unit scope.');
    }

    foreach ([$holderIdentity, ['papel_sistema' => 'titular_cliente', 'tenant_id' => $fixture['tenant_id']]] as $invalidIdentity) {
        try {
            tenantScopeBuildPredicate($invalidIdentity);
            throw new RuntimeException($driver . ': a card holder or identity without user ID must not receive operational scope.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'TENANT_SCOPE_INVALIDO') throw $exception;
        }
    }

    $pdo->exec('CREATE TEMPORARY TABLE tenant_scope_contracts (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, subcliente_id INTEGER NULL, label VARCHAR(40) NOT NULL)');
    $insert = $pdo->prepare('INSERT INTO tenant_scope_contracts (id, tenant_id, subcliente_id, label) VALUES (:id, :tenant, :subclient, :label)');
    foreach ([
        [1, $fixture['tenant_id'], null, 'root'],
        [2, $fixture['tenant_id'], $fixture['subcliente_id'], 'child'],
        [3, $fixture['tenant_id'] + 100, null, 'other-tenant'],
    ] as [$id, $tenantId, $subclientId, $label]) {
        $insert->execute(['id' => $id, 'tenant' => $tenantId, 'subclient' => $subclientId, 'label' => $label]);
    }

    $rootScope = tenantScopeBuildPredicate($rootIdentity);
    $rootRead = $pdo->prepare('SELECT id FROM tenant_scope_contracts WHERE ' . $rootScope['sql'] . ' ORDER BY id');
    $rootRead->execute($rootScope['params']);
    $childScope = tenantScopeBuildPredicate($childIdentity);
    $childRead = $pdo->prepare('SELECT id FROM tenant_scope_contracts WHERE ' . $childScope['sql']);
    $childRead->execute($childScope['params']);
    if (array_map('intval', $rootRead->fetchAll(PDO::FETCH_COLUMN)) !== [1, 2]
        || array_map('intval', $childRead->fetchAll(PDO::FETCH_COLUMN)) !== [2]) {
        throw new RuntimeException($driver . ': Sede and Subcliente queries must apply mandatory bound tenant predicates.');
    }
    $spoofedWrite = $pdo->prepare('UPDATE tenant_scope_contracts SET label = :label WHERE id = :requested_id AND ' . $childScope['sql']);
    $spoofedWrite->execute($childScope['params'] + ['label' => 'altered', 'requested_id' => 3]);
    if ($spoofedWrite->rowCount() !== 0) {
        throw new RuntimeException($driver . ': a Subcliente must not update another tenant row by ID.');
    }

    try {
        tenantScopeBuildPredicate($adminIdentity);
        throw new RuntimeException($driver . ': Admin operations must require an explicit tenant target.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'TENANT_SCOPE_INVALIDO') throw $exception;
    }
    $adminScope = tenantScopeBuildPredicate($adminIdentity, [
        'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => $fixture['subcliente_id'],
    ]);
    $adminRead = $pdo->prepare('SELECT id FROM tenant_scope_contracts WHERE ' . $adminScope['sql']);
    $adminRead->execute($adminScope['params']);
    if (array_map('intval', $adminRead->fetchAll(PDO::FETCH_COLUMN)) !== [2]) {
        throw new RuntimeException($driver . ': Admin query must honor its explicit tenant target.');
    }
    try {
        tenantScopeBuildPredicate($rootIdentity, null, 'scope; DROP TABLE tenants');
        throw new RuntimeException($driver . ': SQL aliases must reject injection syntax.');
    } catch (InvalidArgumentException) {
    }

    $pdo->exec('DROP TABLE tenant_scope_contracts');
    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' session binding, tenant scope, and IDOR denial. ' . "\n");
}

return 'pass';
