<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/mcp_client_registry_support.php';

/** @return array{id:int,tenant_id:int,client_id:string,client_secret:string,expira_em:?string} */
function mcpClientCreate(PDO $pdo, TenantContext $context, array $identity, int $tenantId, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('MCP_CLIENT_CREATE_FAILED');
    }
    motorPermissoesCacheLimpar($pdo);
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || !pode($pdo, $context, $identity, 'admin.mcp.tokens')) {
        throw new DomainException('MCP_CLIENT_DENIED');
    }

    $name = mcpClientNormalizeName($input['nome'] ?? null);
    $scopes = $input['escopos'] ?? null;
    $rateLimit = filter_var($input['limite_requisicoes_minuto'] ?? 60, FILTER_VALIDATE_INT);
    $expiresAt = mcpClientNormalizeExpiry($input['expira_em'] ?? null);
    $allowedScopes = ['batch.dry_run', 'db.inspect', 'design.read', 'system.read', 'telemetry.read'];
    if ($tenantId < 1 || $name === null || !is_array($scopes) || $scopes === []
        || $rateLimit === false || $rateLimit < 1 || $rateLimit > 600
        || ($input['expira_em'] ?? null) !== null && $expiresAt === null) {
        throw new DomainException('MCP_CLIENT_INVALID');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, $allowedScopes, true)) {
            throw new DomainException('MCP_CLIENT_INVALID');
        }
    }
    $scopes = array_values(array_unique($scopes));
    sort($scopes, SORT_STRING);

    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $context, $identity, 'admin.mcp.tokens')) {
            throw new DomainException('MCP_CLIENT_DENIED');
        }
        $tenant = $pdo->prepare(
            "SELECT id FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
        );
        $tenant->execute(['id' => $tenantId]);
        if ($tenant->fetchColumn() === false) {
            throw new DomainException('MCP_CLIENT_TARGET_INVALID');
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(20));
        $clientSecret = 'mcpsec_' . bin2hex(random_bytes(32));
        $createdAt = gmdate('Y-m-d H:i:s');
        $insert = $pdo->prepare(
            'INSERT INTO mcp_api_keys (tenant_id, usuario_id, nome, client_id, chave_hash, escopos_json, '
            . 'limite_requisicoes_minuto, ativo, expira_em, criado_em) '
            . 'VALUES (:tenant_id, :user_id, :name, :client_id, :secret_hash, :scopes, :rate_limit, 1, :expires_at, :created_at)'
        );
        $insert->execute([
            'tenant_id' => $tenantId,
            'user_id' => (int) $identity['id'],
            'name' => $name,
            'client_id' => $clientId,
            'secret_hash' => password_hash($clientSecret, PASSWORD_DEFAULT),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rate_limit' => (int) $rateLimit,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);
        $id = (int) $pdo->lastInsertId();
        $after = json_encode([
            'client_id' => $clientId,
            'nome' => $name,
            'escopos' => $scopes,
            'limite_requisicoes_minuto' => (int) $rateLimit,
            'expira_em' => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        mcpClientWriteAudit($pdo, $tenantId, (int) $identity['id'], 'mcp.client_created', $id, null, $after, $createdAt);
        $pdo->commit();

        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'expira_em' => $expiresAt,
        ];
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('MCP_CLIENT_CREATE_FAILED');
    }
}

/** @return list<array{id:int,tenant_id:int,nome:string,client_id:string,escopos:list<string>,limite_requisicoes_minuto:int,ativo:bool,expira_em:?string,criado_em:?string,ultimo_uso_em:?string}> */
function mcpClientList(PDO $pdo, TenantContext $context, array $identity, int $tenantId): array
{
    motorPermissoesCacheLimpar($pdo);
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || !pode($pdo, $context, $identity, 'admin.mcp.tokens')) {
        throw new DomainException('MCP_CLIENT_DENIED');
    }
    if ($tenantId < 1) {
        throw new DomainException('MCP_CLIENT_INVALID');
    }
    $statement = $pdo->prepare(
        'SELECT id, tenant_id, nome, client_id, escopos_json, limite_requisicoes_minuto, ativo, expira_em, criado_em, ultimo_uso_em '
        . 'FROM mcp_api_keys WHERE tenant_id = :tenant_id AND client_id IS NOT NULL ORDER BY id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $clients = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scopes = json_decode((string) $row['escopos_json'], true);
        $clients[] = [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'nome' => (string) $row['nome'],
            'client_id' => (string) $row['client_id'],
            'escopos' => is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            'limite_requisicoes_minuto' => (int) $row['limite_requisicoes_minuto'],
            'ativo' => (int) $row['ativo'] === 1,
            'expira_em' => $row['expira_em'] === null ? null : (string) $row['expira_em'],
            'criado_em' => $row['criado_em'] === null ? null : (string) $row['criado_em'],
            'ultimo_uso_em' => $row['ultimo_uso_em'] === null ? null : (string) $row['ultimo_uso_em'],
        ];
    }
    return $clients;
}

function mcpClientRevoke(PDO $pdo, TenantContext $context, array $identity, int $clientRecordId): bool
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('MCP_CLIENT_REVOKE_FAILED');
    }
    motorPermissoesCacheLimpar($pdo);
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || !pode($pdo, $context, $identity, 'admin.mcp.tokens')) {
        throw new DomainException('MCP_CLIENT_DENIED');
    }
    if ($clientRecordId < 1) {
        throw new DomainException('MCP_CLIENT_INVALID');
    }

    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $context, $identity, 'admin.mcp.tokens')) {
            throw new DomainException('MCP_CLIENT_DENIED');
        }
        $find = $pdo->prepare(
            'SELECT id, tenant_id, ativo FROM mcp_api_keys WHERE id = :id AND client_id IS NOT NULL LIMIT 1'
        );
        $find->execute(['id' => $clientRecordId]);
        $client = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($client)) {
            throw new DomainException('MCP_CLIENT_TARGET_INVALID');
        }
        if ((int) $client['ativo'] === 0) {
            $pdo->commit();
            return false;
        }
        $update = $pdo->prepare('UPDATE mcp_api_keys SET ativo = 0 WHERE id = :id AND ativo = 1');
        $update->execute(['id' => $clientRecordId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('MCP_CLIENT_REVOKE_CONFLICT');
        }
        $now = gmdate('Y-m-d H:i:s');
        mcpClientWriteAudit(
            $pdo,
            (int) $client['tenant_id'],
            (int) $identity['id'],
            'mcp.client_revoked',
            $clientRecordId,
            json_encode(['ativo' => true], JSON_THROW_ON_ERROR),
            json_encode(['ativo' => false], JSON_THROW_ON_ERROR),
            $now
        );
        $pdo->commit();
        return true;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('MCP_CLIENT_REVOKE_FAILED');
    }
}

