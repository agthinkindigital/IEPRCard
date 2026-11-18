<?php
declare(strict_types=1);

function tenantQuotaTestCleanup(
    PDO $pdo,
    ?int $tenantId,
    array $childIds,
    ?int $ownerId,
    ?int $planId,
    ?int $adminId
): void {
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :id')->execute(['id' => $tenantId]);
    }
    foreach ($childIds as $childId) {
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $childId]);
    }
    if ($ownerId !== null) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]);
    }
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
    }
    if ($planId !== null) {
        $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :id')->execute(['id' => $planId]);
        $pdo->prepare('DELETE FROM planos WHERE id = :id')->execute(['id' => $planId]);
    }
    if ($adminId !== null) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $adminId]);
    }
}
