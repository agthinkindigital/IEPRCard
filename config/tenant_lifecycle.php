<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';

/** Change the manually managed lifecycle state of a root tenant. Returns false for an idempotent request. */
function tenantLifecycleSetRootStatus(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    string $newStatus
): bool {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('TENANT_LIFECYCLE_FAILED');
    }
    motorPermissoesCacheLimpar($pdo);
    if ($context->tipo !== 'admin' || $context->tenantId !== null || $context->subclienteId !== null
        || !pode($pdo, $context, $identity, 'admin.clientes')) {
        throw new DomainException('TENANT_LIFECYCLE_DENIED');
    }
    if ($tenantId < 1 || !in_array($newStatus, ['ativo', 'suspenso', 'encerrado'], true)) {
        throw new DomainException('TENANT_LIFECYCLE_INVALID');
    }

    $pdo->beginTransaction();
    try {
        motorPermissoesCacheLimpar($pdo);
        if (!pode($pdo, $context, $identity, 'admin.clientes')) {
            throw new DomainException('TENANT_LIFECYCLE_DENIED');
        }

        $select = $pdo->prepare(
            "SELECT status_conta FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
        );
        $select->execute(['id' => $tenantId]);
        $currentStatus = $select->fetchColumn();
        if (!is_string($currentStatus)) {
            throw new DomainException('TENANT_LIFECYCLE_TARGET_INVALID');
        }
        if ($currentStatus === $newStatus) {
            $pdo->commit();
            return false;
        }

        $transitions = [
            'pendente' => ['ativo', 'encerrado'],
            'ativo' => ['suspenso', 'encerrado'],
            'suspenso' => ['ativo', 'encerrado'],
        ];
        if (!in_array($newStatus, $transitions[$currentStatus] ?? [], true)) {
            throw new DomainException('TENANT_LIFECYCLE_TRANSITION_INVALID');
        }

        $update = $pdo->prepare(
            "UPDATE tenants SET status_conta = :new_status WHERE id = :id AND parent_id IS NULL "
            . "AND tipo = 'cliente' AND status_conta = :current_status"
        );
        $update->execute(['new_status' => $newStatus, 'id' => $tenantId, 'current_status' => $currentStatus]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('TENANT_LIFECYCLE_CONFLICT');
        }

        $audit = $pdo->prepare(
            'INSERT INTO auditoria_processos (tenant_id, ator_usuario_id, ator_tipo, acao, entidade, entidade_id, '
            . 'antes_json, depois_json, criado_em) VALUES (:tenant_id, :actor_id, :actor_type, :action, :entity, '
            . ':entity_id, :before_json, :after_json, :created_at)'
        );
        $audit->execute([
            'tenant_id' => $tenantId,
            'actor_id' => (int) $identity['id'],
            'actor_type' => 'superadmin',
            'action' => 'tenant.status_changed',
            'entity' => 'tenants',
            'entity_id' => $tenantId,
            'before_json' => json_encode(['status_conta' => $currentStatus], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['status_conta' => $newStatus], JSON_THROW_ON_ERROR),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
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
        throw new RuntimeException('TENANT_LIFECYCLE_FAILED');
    }
}
