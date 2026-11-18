<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_quota_policy.php';
require_once __DIR__ . '/cpf.php';
require_once __DIR__ . '/titular_provisioning_support.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $input */
function titularCreate(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    array $input
): int {
    $ids = titularCreateBatch($pdo, $context, $identity, $tenantId, $subclienteId, [$input]);

    return $ids[0];
}

/** @param array<string, mixed> $identity @param list<array<string, mixed>> $inputs @return list<int> */
function titularCreateBatch(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId,
    array $inputs
): array {
    $normalized = titularCreateBatchNormalize($inputs);
    if ($tenantId < 1 || ($subclienteId !== null && $subclienteId < 1)) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
        throw new DomainException('TITULAR_CREATE_DENIED');
    }

    $createdIds = [];
    try {
        tenantQuotaRunLocked($pdo, $tenantId, function () use (
            $pdo,
            $context,
            $identity,
            $tenantId,
            $subclienteId,
            $normalized,
            &$createdIds
        ): void {
            motorPermissoesCacheLimpar($pdo);
            if (!titularCreateCanManage($pdo, $context, $identity, $tenantId, $subclienteId)) {
                throw new DomainException('TITULAR_CREATE_DENIED');
            }
            titularCreateBatchValidateScope($pdo, $tenantId, $subclienteId, count($normalized));
            titularCreateBatchValidateTargets($pdo, $tenantId, $normalized);

            foreach ($normalized as $record) {
                $createdIds[] = titularCreateInsert($pdo, $identity, $tenantId, $subclienteId, $record);
            }
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'TENANT_QUOTA_FAILED') {
            throw new RuntimeException('TITULAR_CREATE_FAILED');
        }
        throw $exception;
    }

    motorPermissoesCacheLimpar($pdo);

    return $createdIds;
}
