<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_quota_policy.php';

/** @param array<string, mixed> $identity */
function tenantPdfPortalSetEnabled(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    bool $enabled
): void {
    if ($tenantId < 1 || !tenantPdfPortalCanManage($pdo, $context, $identity, $tenantId)) {
        throw new DomainException('TENANT_PDF_ACCESS_DENIED');
    }

    tenantQuotaRunLocked($pdo, $tenantId, function () use ($pdo, $context, $identity, $tenantId, $enabled): void {
        motorPermissoesCacheLimpar($pdo);
        if (!tenantPdfPortalCanManage($pdo, $context, $identity, $tenantId)) {
            throw new DomainException('TENANT_PDF_ACCESS_DENIED');
        }
        $query = $pdo->prepare(
            "SELECT pdf_portal_habilitado FROM tenants WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' AND status_conta = 'ativo' LIMIT 1"
        );
        $query->execute(['id' => $tenantId]);
        $current = $query->fetchColumn();
        if ($current === false) {
            throw new DomainException('TENANT_PDF_ACCESS_TARGET_INVALID');
        }
        $before = (int) $current === 1;
        if ($before === $enabled) {
            return;
        }

        $update = $pdo->prepare('UPDATE tenants SET pdf_portal_habilitado = :enabled WHERE id = :id');
        $update->execute(['enabled' => $enabled ? 1 : 0, 'id' => $tenantId]);
        tenantQuotaAudit(
            $pdo,
            $tenantId,
            $tenantId,
            null,
            $identity,
            'tenant.portal_pdf_access_changed',
            ['pdf_portal_habilitado' => $before],
            ['pdf_portal_habilitado' => $enabled]
        );
    });
}

/** @param array<string, mixed> $identity */
function tenantPdfPortalCanManage(PDO $pdo, TenantContext $context, array $identity, int $tenantId): bool
{
    return $context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null
        && ($identity['papel_sistema'] ?? null) === 'titular_cliente'
        && ($identity['tenant_id'] ?? null) === $tenantId && ($identity['subcliente_id'] ?? null) === null
        && pode($pdo, $context, $identity, 'carteirinha.pdf');
}
