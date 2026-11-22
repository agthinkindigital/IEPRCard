<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_resolver.php';

/** @return array<string,mixed>|null */
function installationProfileCurrent(PDO $pdo): ?array
{
    try {
        $statement = $pdo->query(
            'SELECT id, modo, tenant_local_id, hostname_plataforma, dominio_base, ator_usuario_id, criado_em '
            . 'FROM configuracoes_instalacao ORDER BY id DESC LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (PDOException $error) {
        if (stripos($error->getMessage(), 'configuracoes_instalacao') !== false
            && (stripos($error->getMessage(), 'no such table') !== false
                || stripos($error->getMessage(), "doesn't exist") !== false
                || stripos($error->getMessage(), '42S02') !== false)) {
            return null;
        }
        throw $error;
    }
}

/** @return array{modo:string,tenant_local_id:?int,hostname_plataforma:?string,dominio_base:?string} */
function installationProfileValidate(PDO $pdo, array $input): array
{
    $mode = $input['modo'] ?? null;
    if (!in_array($mode, ['hospedado', 'local_host'], true)) {
        throw new InvalidArgumentException('Perfil de instalação inválido.');
    }
    if ($mode === 'local_host') {
        $tenantId = filter_var($input['tenant_local_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false) {
            throw new InvalidArgumentException('Selecione uma Sede ativa para Local Host.');
        }
        $query = $pdo->prepare("SELECT 1 FROM tenants WHERE id = :id AND tipo = 'cliente' AND parent_id IS NULL AND status_conta = 'ativo' LIMIT 1");
        $query->execute(['id' => $tenantId]);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('A Sede selecionada precisa estar ativa.');
        }
        return ['modo' => 'local_host', 'tenant_local_id' => (int) $tenantId, 'hostname_plataforma' => null, 'dominio_base' => null];
    }

    $platformHost = is_string($input['hostname_plataforma'] ?? null)
        ? tenantResolverNormalizeHost(trim($input['hostname_plataforma'])) : null;
    $baseDomain = is_string($input['dominio_base'] ?? null)
        ? tenantResolverNormalizeHost(trim($input['dominio_base'])) : null;
    $isDnsName = static fn (?string $host): bool => $host !== null
        && filter_var($host, FILTER_VALIDATE_IP) === false
        && str_contains($host, '.')
        && $host !== 'localhost'
        && !str_ends_with($host, '.localhost');
    if (!$isDnsName($platformHost) || !$isDnsName($baseDomain) || $platformHost === $baseDomain) {
        throw new InvalidArgumentException('Informe host da plataforma e domínio-base válidos e distintos.');
    }
    return ['modo' => 'hospedado', 'tenant_local_id' => null, 'hostname_plataforma' => $platformHost, 'dominio_base' => $baseDomain];
}

function installationProfileSave(PDO $pdo, array $input, int $actorId): int
{
    if ($actorId < 1) {
        throw new InvalidArgumentException('Ator inválido.');
    }
    $actor = $pdo->prepare("SELECT 1 FROM usuarios WHERE id = :id AND tenant_id IS NULL AND subcliente_id IS NULL AND papel_sistema = 'superadmin' AND ativo = 1 LIMIT 1");
    $actor->execute(['id' => $actorId]);
    if ($actor->fetchColumn() === false) {
        throw new DomainException('Somente superadmin ativo pode configurar o perfil de instalação.');
    }
    $profile = installationProfileValidate($pdo, $input);
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO configuracoes_instalacao '
            . '(modo, tenant_local_id, hostname_plataforma, dominio_base, ator_usuario_id, criado_em) '
            . 'VALUES (:modo, :tenant, :platform, :base, :actor, :created)'
        );
        $insert->execute([
            'modo' => $profile['modo'], 'tenant' => $profile['tenant_local_id'],
            'platform' => $profile['hostname_plataforma'], 'base' => $profile['dominio_base'],
            'actor' => $actorId, 'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function installationProfileIsLoopbackRequest(?array $server = null): bool
{
    $server ??= $_SERVER;
    $host = installationProfileNormalizeHostHeader((string) ($server['HTTP_HOST'] ?? ''));
    $remote = filter_var($server['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP);
    $allowedHosts = ['localhost', '127.0.0.1', '::1'];
    return $host !== null && in_array($host, $allowedHosts, true)
        && is_string($remote) && in_array($remote, ['127.0.0.1', '::1'], true);
}

function installationProfileNormalizeHostHeader(string $value): ?string
{
    $host = tenantResolverNormalizeHost($value);
    if ($host !== null) return $host;
    if ($value === '::1') return '::1';
    if (preg_match('/^\[::1\](?::([0-9]{1,5}))?$/Di', $value, $matches) !== 1) return null;
    if (isset($matches[1]) && ((int) $matches[1] < 1 || (int) $matches[1] > 65535)) return null;
    return '::1';
}

function installationProfileTenantContext(PDO $pdo, array $profile): ?TenantContext
{
    $tenantId = filter_var($profile['tenant_local_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($tenantId === false) return null;

    $contextType = 'cliente';
    $unitId = null;
    $unitName = null;
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'IEPRCARDTENANT'
        && isset($_SESSION['tenant_user_id'])) {
        $query = $pdo->prepare(
            'SELECT u.tenant_id, u.subcliente_id, t.nome_organizacao, unit.nome_organizacao AS unit_name '
            . 'FROM usuarios u JOIN tenants t ON t.id = u.tenant_id '
            . "LEFT JOIN tenants unit ON unit.id = u.subcliente_id AND unit.parent_id = t.id AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
            . "WHERE u.id = :id AND u.ativo = 1 AND t.id = :tenant AND t.parent_id IS NULL AND t.tipo = 'cliente' AND t.status_conta = 'ativo'"
        );
        $query->execute(['id' => $_SESSION['tenant_user_id'], 'tenant' => $tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        if ($row['subcliente_id'] !== null) {
            if ($row['unit_name'] === null) return null;
            $contextType = 'subcliente';
            $unitId = (int) $row['subcliente_id'];
            $unitName = (string) $row['unit_name'];
        }
        $rootName = (string) $row['nome_organizacao'];
    } elseif (session_status() === PHP_SESSION_ACTIVE && session_name() === 'IEPRCARDPORTAL'
        && isset($_SESSION['portal_holder_id'])) {
        $query = $pdo->prepare(
            'SELECT h.tenant_id, h.subcliente_id, t.nome_organizacao, unit.nome_organizacao AS unit_name '
            . 'FROM titulares h JOIN tenants t ON t.id = h.tenant_id '
            . "LEFT JOIN tenants unit ON unit.id = h.subcliente_id AND unit.parent_id = t.id AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
        . "WHERE h.id = :id AND h.ativo = 1 AND h.acesso_app_liberado = 1 AND t.id = :tenant AND t.parent_id IS NULL AND t.tipo = 'cliente' AND t.status_conta = 'ativo'"
        );
        $query->execute(['id' => $_SESSION['portal_holder_id'], 'tenant' => $tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        if ($row['subcliente_id'] !== null) {
            if ($row['unit_name'] === null) return null;
            $contextType = 'subcliente';
            $unitId = (int) $row['subcliente_id'];
            $unitName = (string) $row['unit_name'];
        }
        $rootName = (string) $row['nome_organizacao'];
    } else {
        if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'IEPRCARDADMIN'
            && isset($_SESSION['admin_user_id'])) {
            $adminQuery = $pdo->prepare(
                "SELECT 1 FROM usuarios WHERE id = :id AND tenant_id IS NULL AND subcliente_id IS NULL AND papel_sistema = 'superadmin' AND ativo = 1 LIMIT 1"
            );
            $adminQuery->execute(['id' => $_SESSION['admin_user_id']]);
            if ($adminQuery->fetchColumn() !== false) return new TenantContext('admin', null, null, []);
        }
        $query = $pdo->prepare("SELECT nome_organizacao FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo'");
        $query->execute(['id' => $tenantId]);
        $rootName = $query->fetchColumn();
        if (!is_string($rootName)) return null;
    }
    $branding = ['nome_organizacao' => $rootName];
    if ($unitName !== null) $branding['nome_unidade'] = $unitName;
    return new TenantContext($contextType, (int) $tenantId, $unitId, $branding);
}
