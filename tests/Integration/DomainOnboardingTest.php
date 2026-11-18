<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/domain_onboarding.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $config = ['PLATFORM_HOSTNAME' => 'admin.ieprcard.example', 'TENANT_BASE_DOMAIN' => 'ieprcard.example'];
    $rootContext = tenantResolverResolve($pdo, $fixture['slug'] . '.ieprcard.example', $config);
    $rootIdentity = ['id' => 1, 'papel_sistema' => 'titular_cliente', 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => null];
    $childContext = tenantResolverResolve($pdo, 'unit-' . substr($fixture['slug'], 7) . '.' . $fixture['slug'] . '.ieprcard.example', $config);
    $childIdentity = ['id' => 2, 'papel_sistema' => 'responsavel_sub', 'tenant_id' => $fixture['tenant_id'], 'subcliente_id' => $fixture['subcliente_id']];
    $hostname = 'Org-' . bin2hex(random_bytes(4)) . '.Example.NET.';
    $created = domainOnboardingCreate($pdo, $rootContext, $rootIdentity, $hostname, $config, 1700000000);
    $stored = $pdo->query('SELECT * FROM dominios_tenant WHERE id = ' . (int) $created['id'])->fetch(PDO::FETCH_ASSOC);
    if ($created['hostname'] !== strtolower(rtrim($hostname, '.'))
        || $stored['estado'] !== 'pendente_verificacao'
        || !is_string($stored['token_verificacao_hash'])
        || hash_equals($created['token'], $stored['token_verificacao_hash'])) {
        throw new RuntimeException($driver . ': domain creation must normalize the host and store only a token hash.');
    }

    $lookup = static fn (string $name): array => $name === '_ieprcard-verification.' . $created['hostname']
        ? ['ieprcard-verification=' . $created['token']]
        : [];
    if (domainOnboardingVerify($pdo, (int) $created['id'], 'incorrect-token', $lookup, 1700000001)
        || domainOnboardingVerify($pdo, (int) $created['id'], $created['token'], static fn (): array => [], 1700000001)) {
        throw new RuntimeException($driver . ': invalid tokens or absent DNS records must not verify a domain.');
    }
    if (!domainOnboardingVerify($pdo, (int) $created['id'], $created['token'], $lookup, 1700000002)) {
        throw new RuntimeException($driver . ': a matching DNS TXT challenge must verify the domain.');
    }
    $verified = $pdo->query('SELECT estado, verificado_em, token_verificacao_hash, tls_validado_em FROM dominios_tenant WHERE id = ' . (int) $created['id'])->fetch(PDO::FETCH_ASSOC);
    if ($verified['estado'] !== 'tls_pendente' || $verified['verificado_em'] === null
        || $verified['token_verificacao_hash'] !== null || $verified['tls_validado_em'] !== null
        || domainOnboardingVerify($pdo, (int) $created['id'], $created['token'], $lookup, 1700000003)) {
        throw new RuntimeException($driver . ': successful verification must be one-time and leave TLS pending.');
    }

    $raced = domainOnboardingCreate($pdo, $rootContext, $rootIdentity, 'race-' . bin2hex(random_bytes(4)) . '.example.net', $config);
    $concurrentTransition = static function (string $name) use ($pdo, $raced): array {
        $pdo->prepare("UPDATE dominios_tenant SET estado = 'tls_pendente' WHERE id = :id")
            ->execute(['id' => $raced['id']]);
        return ['ieprcard-verification=' . $raced['token']];
    };
    if (domainOnboardingVerify($pdo, (int) $raced['id'], $raced['token'], $concurrentTransition)) {
        throw new RuntimeException($driver . ': a concurrent state transition must not be overwritten.');
    }

    $reject = static function (callable $operation, string $message): void {
        try {
            $operation();
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException($message);
    };
    foreach (['bad host', '127.0.0.1', 'admin.ieprcard.example', 'tenant.ieprcard.example'] as $invalid) {
        $reject(
            static fn () => domainOnboardingCreate($pdo, $rootContext, $rootIdentity, $invalid, $config),
            $driver . ': invalid, IP, platform, and application hosts must be rejected.'
        );
    }
    $reject(
        static fn () => domainOnboardingCreate($pdo, $childContext, $childIdentity, 'unit.example.net', $config),
        $driver . ': subclients cannot own custom domains.'
    );
    $reject(
        static fn () => domainOnboardingCreate($pdo, $rootContext, array_merge($rootIdentity, ['tenant_id' => $fixture['subcliente_id']]), 'mismatch.example.net', $config),
        $driver . ': a mismatched session tenant cannot register a domain.'
    );
    $inactive = $pdo->prepare("UPDATE tenants SET status_conta = 'suspenso' WHERE id = :id");
    $inactive->execute(['id' => $fixture['tenant_id']]);
    $reject(
        static fn () => domainOnboardingCreate($pdo, $rootContext, $rootIdentity, 'inactive.example.net', $config),
        $driver . ': inactive tenants cannot add custom domains.'
    );
    $inactive->execute(['id' => $fixture['tenant_id']]);
    $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :id")->execute(['id' => $fixture['tenant_id']]);
    $reject(
        static fn () => domainOnboardingCreate($pdo, $rootContext, $rootIdentity, $hostname, $config),
        $driver . ': an already claimed hostname must be unavailable.'
    );

    tenantTestCleanup($pdo, $fixture);
    fwrite(STDOUT, 'PASS: ' . $driver . ' custom domain DNS onboarding and one-time verification.' . "\n");
}

return 'pass';
