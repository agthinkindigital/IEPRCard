<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';

/** @return array<string, PDO> */
function tenantTestConnections(): array
{
    $connections = ['SQLite' => dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:'])];
    $host = getenv('TEST_MYSQL_HOST');
    $database = getenv('TEST_MYSQL_DATABASE');
    $username = getenv('TEST_MYSQL_USERNAME');
    $password = getenv('TEST_MYSQL_PASSWORD');

    if (in_array('mysql', PDO::getAvailableDrivers(), true)
        && $host !== false && $database !== false && $username !== false && $password !== false) {
        $connections['MySQL/MariaDB'] = dbConnect([
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
        ]);
    }

    foreach ($connections as $pdo) {
        schemaGarantirEstrutura($pdo, 'test');
    }

    return $connections;
}

/** @return array{tenant_id:int,subcliente_id:int,slug:string,hostname:string} */
function tenantTestSeed(PDO $pdo): array
{
    $suffix = bin2hex(random_bytes(4));
    $slug = 'tenant-' . $suffix;
    $hostname = 'cards-' . $suffix . '.example.test';
    $root = $pdo->prepare(
        "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) VALUES ('cliente', :name, :slug, 'ativo')"
    );
    $root->execute(['name' => 'Tenant ' . $suffix, 'slug' => $slug]);
    $tenantId = (int) $pdo->lastInsertId();
    $child = $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
    );
    $child->execute(['parent' => $tenantId, 'name' => 'Unidade ' . $suffix, 'slug' => 'unit-' . $suffix]);
    $subclienteId = (int) $pdo->lastInsertId();
    $domain = $pdo->prepare(
        "INSERT INTO dominios_tenant (tenant_id, hostname, estado, verificado_em, tls_validado_em) "
        . "VALUES (:tenant, :hostname, 'ativo', :verified, :tls)"
    );
    $domain->execute([
        'tenant' => $tenantId,
        'hostname' => $hostname,
        'verified' => gmdate('Y-m-d H:i:s'),
        'tls' => gmdate('Y-m-d H:i:s'),
    ]);

    return ['tenant_id' => $tenantId, 'subcliente_id' => $subclienteId, 'slug' => $slug, 'hostname' => $hostname];
}

/** @param array{tenant_id:int,subcliente_id:int,slug:string,hostname:string} $fixture */
function tenantTestCleanup(PDO $pdo, array $fixture): void
{
    $pdo->prepare('DELETE FROM dominios_tenant WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
    $pdo->prepare('DELETE FROM tenants WHERE id = :tenant')->execute(['tenant' => $fixture['subcliente_id']]);
    $pdo->prepare('DELETE FROM tenants WHERE id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
}
