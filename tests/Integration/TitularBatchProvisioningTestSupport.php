<?php
declare(strict_types=1);

function titularBatchAssertFailure(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    int $subclientId,
    array $records,
    string $expectedCode
): void {
    $before = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
    $before->execute(['tenant' => $tenantId]);
    $count = (int) $before->fetchColumn();
    try {
        titularCreateBatch($pdo, $context, $identity, $tenantId, $subclientId, $records);
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== $expectedCode) {
            throw $exception;
        }
        $after = $pdo->prepare('SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant');
        $after->execute(['tenant' => $tenantId]);
        if ((int) $after->fetchColumn() !== $count) {
            throw new RuntimeException('A rejected titular batch must not partially insert records.');
        }
        return;
    }

    throw new RuntimeException('The titular batch was expected to fail with ' . $expectedCode . '.');
}

function titularBatchTestCpf(int $seed): string
{
    $base = str_pad((string) (200000000 + $seed), 9, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($index = 0; $index < 9; ++$index) {
        $sum += (int) $base[$index] * (10 - $index);
    }
    $remainder = $sum % 11;
    $first = $remainder < 2 ? 0 : 11 - $remainder;
    $sum = 0;
    for ($index = 0; $index < 9; ++$index) {
        $sum += (int) $base[$index] * (11 - $index);
    }
    $sum += $first * 2;
    $remainder = $sum % 11;
    $second = $remainder < 2 ? 0 : 11 - $remainder;

    return $base . $first . $second;
}
