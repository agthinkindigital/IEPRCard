<?php
declare(strict_types=1);

const MCP_SUPPORT_GRANT_DURATIONS = [
    '30m' => 1800,
    '1h' => 3600,
    '3h' => 10800,
    '6h' => 21600,
    '12h' => 43200,
    '24h' => 86400,
];

const MCP_SUPPORT_GRANT_CAPABILITIES = ['system.read', 'telemetry.read', 'db.inspect', 'repo.publish'];

require_once __DIR__ . '/privacy_snapshot_labels.php';

function mcpSupportGrantBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param array<string, mixed> $identity */
function mcpSupportGrantAssertSuperadmin(PDO $pdo, array $identity): int
{
    $actorId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($actorId) || $actorId < 1 || ($identity['papel_sistema'] ?? null) !== 'superadmin') {
        throw new DomainException('MCP_SUPPORT_GRANT_DENIED');
    }
    $actor = $pdo->prepare(
        "SELECT id FROM usuarios WHERE id = :id AND papel_sistema = 'superadmin' AND tenant_id IS NULL "
        . 'AND subcliente_id IS NULL AND ativo = 1 LIMIT 1'
    );
    $actor->execute(['id' => $actorId]);
    if ($actor->fetchColumn() === false) {
        throw new DomainException('MCP_SUPPORT_GRANT_DENIED');
    }
    return $actorId;
}

/** @param array<string, mixed> $identity @return list<array<string, mixed>> */
function mcpSupportGrantList(PDO $pdo, array $identity): array
{
    mcpSupportGrantAssertSuperadmin($pdo, $identity);
    $grants = $pdo->query(
        'SELECT g.id, g.rotulo, g.criado_por_usuario_id, u.nome AS criado_por, g.capacidades_json, '
        . 'g.criado_em, g.expira_em, g.revogado_em, g.ultimo_uso_em '
        . 'FROM mcp_support_grants g LEFT JOIN usuarios u ON u.id = g.criado_por_usuario_id ORDER BY g.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
    $targets = $pdo->prepare(
        'SELECT tenant_id, subcliente_id FROM mcp_support_grant_targets WHERE grant_id = :grant '
        . 'ORDER BY tenant_id, CASE WHEN subcliente_id IS NULL THEN 0 ELSE 1 END, subcliente_id'
    );
    $now = gmdate('Y-m-d H:i:s');
    $result = [];
    foreach ($grants as $grant) {
        $targets->execute(['grant' => (int) $grant['id']]);
        $capabilities = json_decode((string) $grant['capacidades_json'], true);
        $result[] = [
            'id' => (int) $grant['id'],
            'label' => (string) $grant['rotulo'],
            'created_by_user_id' => $grant['criado_por_usuario_id'] === null ? null : (int) $grant['criado_por_usuario_id'],
            'created_by' => is_string($grant['criado_por']) ? $grant['criado_por'] : null,
            'capabilities' => is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : [],
            'targets' => array_map(static fn(array $target): array => [
                'tenant_id' => (int) $target['tenant_id'],
                'subcliente_id' => $target['subcliente_id'] === null ? null : (int) $target['subcliente_id'],
            ], $targets->fetchAll(PDO::FETCH_ASSOC)),
            'created_at' => (string) $grant['criado_em'],
            'expires_at' => (string) $grant['expira_em'],
            'last_used_at' => $grant['ultimo_uso_em'] === null ? null : (string) $grant['ultimo_uso_em'],
            'state' => $grant['revogado_em'] !== null ? 'revoked' : ((string) $grant['expira_em'] <= $now ? 'expired' : 'active'),
        ];
    }
    return $result;
}

/** @param array<string, mixed> $identity */
function mcpSupportGrantRevoke(PDO $pdo, array $identity, mixed $grantId): bool
{
    $actorId = mcpSupportGrantAssertSuperadmin($pdo, $identity);
    $parsedId = filter_var($grantId, FILTER_VALIDATE_INT);
    if (!is_int($parsedId) || $parsedId < 1) {
        throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
    }
    $now = gmdate('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare(
            'UPDATE mcp_support_grants SET revogado_em = :revoked WHERE id = :id AND revogado_em IS NULL'
        );
        $update->execute(['revoked' => $now, 'id' => $parsedId]);
        if ($update->rowCount() === 0) {
            $pdo->commit();
            return false;
        }
        $audit = $pdo->prepare(
            'INSERT INTO mcp_support_audit_logs (actor_user_id, grant_id, tool, result, correlation_id, created_at) '
            . 'VALUES (:actor_id, :grant_id, :tool, :result, :correlation_id, :created_at)'
        );
        $audit->execute([
            'actor_id' => $actorId,
            'grant_id' => $parsedId,
            'tool' => 'grant.revoke',
            'result' => 'ok',
            'correlation_id' => bin2hex(random_bytes(16)),
            'created_at' => $now,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param array<string, mixed> $identity @param array<string, mixed> $input @return array{id:int,public_id:string,secret:string,expires_at:string} */
function mcpSupportGrantCreate(PDO $pdo, array $identity, array $input): array
{
    $actorId = mcpSupportGrantAssertSuperadmin($pdo, $identity);

    $label = $input['label'] ?? null;
    $duration = $input['duration'] ?? null;
    $targetsInput = $input['targets'] ?? null;
    $capabilitiesInput = $input['capabilities'] ?? null;
    $length = is_string($label) ? preg_match_all('/./us', trim($label)) : false;
    if (!is_string($label) || trim($label) === '' || $length === false || $length > 120
        || privacySnapshotLabelContainsStructuredPii(trim($label))
        || !is_string($duration) || !isset(MCP_SUPPORT_GRANT_DURATIONS[$duration])
        || !is_array($targetsInput) || $targetsInput === [] || count($targetsInput) > 50
        || !is_array($capabilitiesInput) || $capabilitiesInput === []) {
        throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
    }

    $capabilities = [];
    foreach ($capabilitiesInput as $capability) {
        if (!is_string($capability) || !in_array($capability, MCP_SUPPORT_GRANT_CAPABILITIES, true)) {
            throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
        }
        $capabilities[$capability] = true;
    }
    $capabilities = array_keys($capabilities);
    sort($capabilities, SORT_STRING);

    $frozenTargets = [];
    foreach ($targetsInput as $target) {
        if (!is_array($target) || !in_array($target['type'] ?? null, ['sede', 'unidade'], true)
            || filter_var($target['id'] ?? null, FILTER_VALIDATE_INT) === false || (int) $target['id'] < 1) {
            throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
        }
        $targetId = (int) $target['id'];
        if ($target['type'] === 'sede') {
            $root = $pdo->prepare(
                "SELECT id FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
            );
            $root->execute(['id' => $targetId]);
            if ($root->fetchColumn() === false) {
                throw new DomainException('MCP_SUPPORT_GRANT_TARGET_INVALID');
            }
            $frozenTargets[$targetId . ':root'] = ['tenant_id' => $targetId, 'subcliente_id' => null];
            $units = $pdo->prepare("SELECT id FROM tenants WHERE parent_id = :id AND tipo = 'subcliente' ORDER BY id");
            $units->execute(['id' => $targetId]);
            foreach ($units->fetchAll(PDO::FETCH_COLUMN) as $unitId) {
                $frozenTargets[$targetId . ':' . (int) $unitId] = ['tenant_id' => $targetId, 'subcliente_id' => (int) $unitId];
            }
            continue;
        }

        $unit = $pdo->prepare(
            "SELECT parent.id AS tenant_id, child.id AS subcliente_id FROM tenants child "
            . "INNER JOIN tenants parent ON parent.id = child.parent_id "
            . "WHERE child.id = :id AND child.tipo = 'subcliente' AND parent.tipo = 'cliente' "
            . "AND parent.parent_id IS NULL AND parent.status_conta = 'ativo' AND child.status_conta = 'ativo' LIMIT 1"
        );
        $unit->execute(['id' => $targetId]);
        $row = $unit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('MCP_SUPPORT_GRANT_TARGET_INVALID');
        }
        $tenantId = (int) $row['tenant_id'];
        $frozenTargets[$tenantId . ':' . $targetId] = ['tenant_id' => $tenantId, 'subcliente_id' => $targetId];
    }
    if ($frozenTargets === []) {
        throw new DomainException('MCP_SUPPORT_GRANT_INVALID');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $createdAt = $now->format('Y-m-d H:i:s');
    $expiresAt = $now->modify('+' . MCP_SUPPORT_GRANT_DURATIONS[$duration] . ' seconds')->format('Y-m-d H:i:s');
    $publicId = 'msp_' . mcpSupportGrantBase64Url(random_bytes(18));
    $secret = 'mcp_support_' . mcpSupportGrantBase64Url(random_bytes(32));
    $secretHash = password_hash($secret, PASSWORD_DEFAULT);
    if (!is_string($secretHash)) {
        throw new RuntimeException('MCP_SUPPORT_GRANT_UNAVAILABLE');
    }

    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO mcp_support_grants (public_id, segredo_hash, rotulo, criado_por_usuario_id, capacidades_json, criado_em, expira_em) '
            . 'VALUES (:public_id, :secret_hash, :label, :actor_id, :capabilities, :created_at, :expires_at)'
        );
        $insert->execute([
            'public_id' => $publicId,
            'secret_hash' => $secretHash,
            'label' => trim($label),
            'actor_id' => $actorId,
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);
        $grantId = (int) $pdo->lastInsertId();
        $insertTarget = $pdo->prepare(
            'INSERT INTO mcp_support_grant_targets (grant_id, tenant_id, subcliente_id, criado_em) '
            . 'VALUES (:grant_id, :tenant_id, :subcliente_id, :created_at)'
        );
        foreach ($frozenTargets as $frozenTarget) {
            $insertTarget->execute([
                'grant_id' => $grantId,
                'tenant_id' => $frozenTarget['tenant_id'],
                'subcliente_id' => $frozenTarget['subcliente_id'],
                'created_at' => $createdAt,
            ]);
        }
        $audit = $pdo->prepare(
            'INSERT INTO mcp_support_audit_logs (actor_user_id, grant_id, tool, result, correlation_id, created_at) '
            . 'VALUES (:actor_id, :grant_id, :tool, :result, :correlation_id, :created_at)'
        );
        $audit->execute([
            'actor_id' => $actorId,
            'grant_id' => $grantId,
            'tool' => 'grant.create',
            'result' => 'ok',
            'correlation_id' => bin2hex(random_bytes(16)),
            'created_at' => $createdAt,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['id' => $grantId, 'public_id' => $publicId, 'secret' => $secret, 'expires_at' => $expiresAt];
}
