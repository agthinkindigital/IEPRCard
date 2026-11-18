<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    schemaAplicarMigracoes($pdo, 'test');
    if ((int) $pdo->query("SELECT COUNT(*) FROM permissoes WHERE chave = 'admin.clientes'")->fetchColumn() !== 1) {
        throw new RuntimeException($driver . ': the versioned permission catalog migration must be repeatable.');
    }
    $fixture = tenantTestSeed($pdo);
    $planName = 'Permission fixture ' . bin2hex(random_bytes(4));
    $planInsert = $pdo->prepare('INSERT INTO planos (nome) VALUES (:name)');
    $planInsert->execute(['name' => $planName]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE tenants SET plano_id = :plan WHERE id = :tenant')->execute([
        'plan' => $planId,
        'tenant' => $fixture['tenant_id'],
    ]);
    $permissionIds = [];
    $permissionLookup = $pdo->prepare('SELECT id FROM permissoes WHERE chave = :key');
    foreach (['titular.ver', 'titular.criar', 'design.editar'] as $key) {
        $permissionLookup->execute(['key' => $key]);
        $permissionIds[$key] = (int) $permissionLookup->fetchColumn();
    }
    $permissionLookup->execute(['key' => 'admin.clientes']);
    if ($permissionLookup->fetchColumn() === false) {
        throw new RuntimeException($driver . ': global administrative permission must exist in the catalog.');
    }
    $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :id')->execute([
        'permissions' => json_encode(['titular.ver', 'titular.criar'], JSON_THROW_ON_ERROR),
        'id' => $fixture['tenant_id'],
    ]);
    $planFlag = $pdo->prepare(
        'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, :enabled)'
    );
    foreach (['titular.ver' => 1, 'titular.criar' => 1, 'design.editar' => 1] as $key => $enabled) {
        $planFlag->execute(['plan' => $planId, 'feature' => $key, 'enabled' => $enabled]);
    }

    $addUser = static function (string $role, ?int $tenantId, ?int $subclientId, ?int $profileId = null, int $active = 1) use ($pdo): array {
        $email = $role . '-' . bin2hex(random_bytes(4)) . '@example.test';
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :subclient, :profile, :role, :name, :email, :password, :active)'
        );
        $statement->execute([
            'tenant' => $tenantId,
            'subclient' => $subclientId,
            'profile' => $profileId,
            'role' => $role,
            'name' => $role,
            'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'active' => $active,
        ]);
        $id = (int) $pdo->lastInsertId();
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'subcliente_id' => $subclientId,
            'papel_sistema' => $role,
        ];
    };

    $rootIdentity = $addUser('titular_cliente', $fixture['tenant_id'], null);
    $childIdentity = $addUser('responsavel_sub', $fixture['tenant_id'], $fixture['subcliente_id']);
    $profile = $pdo->prepare(
        "INSERT INTO perfis_acesso (tenant_id, nome_perfil, escopo_permitido, permissoes_acoes_json) "
        . "VALUES (:tenant, 'Rede', 'rede', :permissions)"
    );
    $profile->execute([
        'tenant' => $fixture['tenant_id'],
        'permissions' => json_encode(['titular.ver'], JSON_THROW_ON_ERROR),
    ]);
    $profileId = (int) $pdo->lastInsertId();
    $networkAccess = $addUser('subacesso', $fixture['tenant_id'], null, $profileId);
    $inactiveIdentity = $addUser('titular_cliente', $fixture['tenant_id'], null, null, 0);
    $globalIdentity = $addUser('superadmin', null, null);
    $base = ['TENANT_BASE_DOMAIN' => 'ieprcard.example'];
    $context = tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $base);
    $childContext = tenantResolverResolve(
        $pdo,
        'unit-' . substr($fixture['slug'], 7) . '.' . $fixture['slug'] . '.ieprcard.example',
        $base
    );
    $adminContext = tenantResolverResolve($pdo, 'admin.ieprcard.example', ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example']);
    $otherChild = $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES (:parent, 'subcliente', 'Outra unidade', :slug, 'ativo')"
    );
    $otherChild->execute([
        'parent' => $fixture['tenant_id'],
        'slug' => 'other-' . bin2hex(random_bytes(4)),
    ]);
    $otherChildId = (int) $pdo->lastInsertId();

    if (!pode($pdo, $context, $rootIdentity, 'titular.ver')) {
        throw new RuntimeException($driver . ': a plan-enabled root tenant permission must be granted.');
    }
    if (!pode($pdo, $context, $rootIdentity, 'titular.ver', $fixture['subcliente_id'])
        || pode($pdo, $context, $rootIdentity, 'titular.ver', $otherChildId + 1000000)) {
        throw new RuntimeException($driver . ': the Sede may filter only to real units inside its own tenant.');
    }
    if (!pode($pdo, $childContext, $childIdentity, 'titular.ver', $fixture['subcliente_id'])
        || pode($pdo, $childContext, $childIdentity, 'design.editar', $fixture['subcliente_id'])
        || pode($pdo, $childContext, $childIdentity, 'titular.ver', null)
        || pode($pdo, $childContext, $childIdentity, 'titular.ver', $otherChildId)) {
        throw new RuntimeException($driver . ': a responsible subclient must stay within Sede delegation and own scope.');
    }
    if (!pode($pdo, $context, $networkAccess, 'titular.ver', $fixture['subcliente_id'])
        || pode($pdo, $context, $networkAccess, 'titular.criar', $fixture['subcliente_id'])) {
        throw new RuntimeException($driver . ': profile permissions must be bounded by unit delegation.');
    }
    $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :key')->execute([
        'plan' => $planId, 'key' => 'design.editar',
    ]);
    motorPermissoesCacheLimpar($pdo);
    if (pode($pdo, $context, $rootIdentity, 'design.editar')) {
        throw new RuntimeException($driver . ': a missing plan feature flag must deny access.');
    }
    $override = $pdo->prepare(
        'INSERT INTO usuario_permissoes (usuario_id, permissao_id, subcliente_id, permitido) '
        . 'VALUES (:user, :permission, :subclient, :allowed)'
    );
    $override->execute([
        'user' => $networkAccess['id'], 'permission' => $permissionIds['titular.ver'],
        'subclient' => null, 'allowed' => 0,
    ]);
    $override->execute([
        'user' => $networkAccess['id'], 'permission' => $permissionIds['titular.ver'],
        'subclient' => $fixture['subcliente_id'], 'allowed' => 1,
    ]);
    $override->execute([
        'user' => $networkAccess['id'], 'permission' => $permissionIds['titular.criar'],
        'subclient' => $fixture['subcliente_id'], 'allowed' => 1,
    ]);
    motorPermissoesCacheLimpar($pdo);
    $unitViewAllowed = pode($pdo, $context, $networkAccess, 'titular.ver', $fixture['subcliente_id']);
    $unitCreateAllowed = pode($pdo, $context, $networkAccess, 'titular.criar', $fixture['subcliente_id']);
    $rootViewAllowed = pode($pdo, $context, $networkAccess, 'titular.ver');
    if (!$unitViewAllowed || !$unitCreateAllowed || $rootViewAllowed) {
        throw new RuntimeException($driver . ': unit override results ' . json_encode([$unitViewAllowed, $unitCreateAllowed, $rootViewAllowed]));
    }

    $disabled = $pdo->prepare('UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :key');
    $disabled->execute(['plan' => $planId, 'key' => 'titular.ver']);
    motorPermissoesCacheLimpar($pdo);
    if (pode($pdo, $context, $rootIdentity, 'titular.ver')
        || pode($pdo, $context, $rootIdentity, 'permissao.nao.cadastrada')
        || pode($pdo, $context, $inactiveIdentity, 'titular.ver')
        || pode($pdo, $context, array_merge($rootIdentity, ['tenant_id' => $fixture['subcliente_id']]), 'titular.ver')
        || pode($pdo, $context, array_merge($rootIdentity, ['papel_sistema' => 'superadmin']), 'admin.tenants.manage')
        || !pode($pdo, $adminContext, $globalIdentity, 'admin.clientes')
        || pode($pdo, $adminContext, $globalIdentity, 'titular.ver')
        || pode($pdo, $context, $rootIdentity, 'TITULAR.VER')) {
        throw new RuntimeException($driver . ': disabled features, invalid identities, and global roles must fail closed.');
    }

    $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
    motorPermissoesCacheLimpar($pdo);
    if (pode($pdo, $context, $rootIdentity, 'titular.criar')
        || pode($pdo, $childContext, $childIdentity, 'titular.criar', $fixture['subcliente_id'])) {
        throw new RuntimeException($driver . ': suspending the root must disable all tenant permissions.');
    }
    $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
    motorPermissoesCacheLimpar($pdo);

    $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $otherChildId]);
    $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :id OR id = :admin')->execute([
        'id' => $fixture['tenant_id'], 'admin' => $globalIdentity['id'],
    ]);
    $pdo->prepare('DELETE FROM perfis_acesso WHERE id = :id')->execute(['id' => $profileId]);
    $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :id')->execute(['id' => $planId]);
    $pdo->prepare('UPDATE tenants SET plano_id = NULL WHERE id = :id')->execute(['id' => $fixture['tenant_id']]);
    $pdo->prepare('DELETE FROM planos WHERE id = :id')->execute(['id' => $planId]);
    motorPermissoesCacheLimpar($pdo);
    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' role, plan, profile, override, and scope permission evaluation.' . "\n");
}

return 'pass';
