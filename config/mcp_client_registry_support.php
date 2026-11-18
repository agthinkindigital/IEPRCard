<?php
declare(strict_types=1);

function mcpClientNormalizeName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = trim($value);
    $length = preg_match_all('/./us', $name);
    return $name !== '' && $length !== false && $length <= 120 ? $name : null;
}

function mcpClientNormalizeExpiry(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed->format('Y-m-d H:i:s') !== $value || $parsed <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        return null;
    }
    return $parsed->format('Y-m-d H:i:s');
}

function mcpClientWriteAudit(
    PDO $pdo,
    int $tenantId,
    int $actorId,
    string $action,
    int $entityId,
    ?string $before,
    string $after,
    string $createdAt
): void {
    $audit = $pdo->prepare(
        'INSERT INTO auditoria_processos (tenant_id, ator_usuario_id, ator_tipo, acao, entidade, entidade_id, '
        . 'antes_json, depois_json, criado_em) VALUES (:tenant_id, :actor_id, :actor_type, :action, :entity, '
        . ':entity_id, :before_json, :after_json, :created_at)'
    );
    $audit->execute([
        'tenant_id' => $tenantId,
        'actor_id' => $actorId,
        'actor_type' => 'superadmin',
        'action' => $action,
        'entity' => 'mcp_api_keys',
        'entity_id' => $entityId,
        'before_json' => $before,
        'after_json' => $after,
        'created_at' => $createdAt,
    ]);
}
