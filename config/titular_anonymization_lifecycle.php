<?php
declare(strict_types=1);

/** @return array{inativado_em:string} */
function titularAnonymizationDeactivate(PDO $pdo, int $tenantId, int $titularId, ?int $now = null): array
{
    if ($tenantId < 1 || $titularId < 1) {
        throw new DomainException('ANONYMIZATION_TITULAR_NOT_FOUND');
    }
    $timestamp = gmdate('Y-m-d H:i:s', $now ?? time());
    $lock = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT ativo, inativado_em FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1' . $lock
        );
        $query->execute(['id' => $titularId, 'tenant' => $tenantId]);
        $holder = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($holder)) {
            throw new DomainException('ANONYMIZATION_TITULAR_NOT_FOUND');
        }
        $inactiveAt = is_string($holder['inativado_em']) && $holder['inativado_em'] !== ''
            ? $holder['inativado_em']
            : $timestamp;
        if ((int) $holder['ativo'] !== 0 || $holder['inativado_em'] === null) {
            $update = $pdo->prepare(
                'UPDATE titulares SET ativo = 0, acesso_app_liberado = 0, inativado_em = :inactive_at '
                . 'WHERE id = :id AND tenant_id = :tenant'
            );
            $update->execute(['inactive_at' => $inactiveAt, 'id' => $titularId, 'tenant' => $tenantId]);
        }
        $pdo->commit();
        return ['inativado_em' => $inactiveAt];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function titularAnonymizationTwelveMonthCutoff(int $now): string
{
    $date = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));
    $targetMonth = $date->modify('first day of this month')->modify('-12 months');
    $currentMonthLastDay = (int) $date->format('t');
    $targetMonthLastDay = (int) $targetMonth->format('t');
    $day = min((int) $date->format('d'), $targetMonthLastDay);
    if ((int) $date->format('d') === $currentMonthLastDay && $targetMonthLastDay > $currentMonthLastDay) {
        $day = $targetMonthLastDay;
    }

    return $targetMonth->setDate(
        (int) $targetMonth->format('Y'),
        (int) $targetMonth->format('m'),
        $day
    )->setTime((int) $date->format('H'), (int) $date->format('i'), (int) $date->format('s'))
        ->format('Y-m-d H:i:s');
}
