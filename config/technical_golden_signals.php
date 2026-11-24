<?php
declare(strict_types=1);

/**
 * Read minimized HTTP golden signals from persisted best-effort events.
 * @return array{window_from:?string,window_to:?string,observed_count:int,observed_per_hour:?float,p95_ms:?int,sample_size:int,sampled:bool,http_5xx_count:int,http_5xx_rate:?float}
 */
function technicalEventsGoldenSignals(
    PDO $pdo,
    string $from,
    string $to,
    int $retentionDays,
    ?int $now = null,
    int $sampleLimit = 5000,
): array {
    if ($retentionDays < 1 || $retentionDays > 3650 || $sampleLimit < 1 || $sampleLimit > 5000) {
        throw new InvalidArgumentException('TECHNICAL_GOLDEN_SIGNALS_INVALID');
    }

    $now ??= time();
    $utc = new DateTimeZone('UTC');
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
    $fromErrors = DateTimeImmutable::getLastErrors();
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);
    $toErrors = DateTimeImmutable::getLastErrors();
    if (!$fromDate || ($fromErrors !== false && ($fromErrors['warning_count'] > 0 || $fromErrors['error_count'] > 0))
        || $fromDate->format('Y-m-d') !== $from
        || !$toDate || ($toErrors !== false && ($toErrors['warning_count'] > 0 || $toErrors['error_count'] > 0))
        || $toDate->format('Y-m-d') !== $to || $fromDate > $toDate
        || (int) $fromDate->diff($toDate)->format('%a') >= 366
        || $toDate->getTimestamp() > $now) {
        throw new InvalidArgumentException('TECHNICAL_GOLDEN_SIGNALS_INVALID');
    }

    $requestedStart = $fromDate->getTimestamp();
    $requestedEnd = $toDate->setTime(23, 59, 59)->getTimestamp();
    $retentionStart = $now - ($retentionDays * 86400);
    $windowStart = max($requestedStart, $retentionStart);
    $windowEnd = min($requestedEnd, $now);
    if ($windowStart > $windowEnd) {
        return [
            'window_from' => null,
            'window_to' => null,
            'observed_count' => 0,
            'observed_per_hour' => null,
            'p95_ms' => null,
            'sample_size' => 0,
            'sampled' => false,
            'http_5xx_count' => 0,
            'http_5xx_rate' => null,
        ];
    }

    $windowFrom = gmdate('Y-m-d H:i:s', $windowStart);
    $windowTo = gmdate('Y-m-d H:i:s', $windowEnd);
    $totals = $pdo->prepare(
        'SELECT COUNT(*) AS observed_count, '
        . 'SUM(CASE WHEN status_http BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS http_5xx_count '
        . 'FROM eventos_tecnicos WHERE evento_chave = :event_key AND criado_em >= :window_from AND criado_em <= :window_to'
    );
    $totals->execute(['event_key' => 'http.response', 'window_from' => $windowFrom, 'window_to' => $windowTo]);
    $totalRow = $totals->fetch(PDO::FETCH_ASSOC);
    if (!is_array($totalRow)) throw new RuntimeException('TECHNICAL_GOLDEN_SIGNALS_UNAVAILABLE');
    $observedCount = (int) $totalRow['observed_count'];
    $http5xxCount = (int) ($totalRow['http_5xx_count'] ?? 0);

    $sample = $pdo->prepare(
        'SELECT duracao_ms FROM eventos_tecnicos '
        . 'WHERE evento_chave = :event_key AND criado_em >= :window_from AND criado_em <= :window_to '
        . 'ORDER BY criado_em DESC, id DESC LIMIT ' . $sampleLimit
    );
    $sample->execute(['event_key' => 'http.response', 'window_from' => $windowFrom, 'window_to' => $windowTo]);
    $durations = array_map(static fn (array $row): int => (int) $row['duracao_ms'], $sample->fetchAll(PDO::FETCH_ASSOC));
    sort($durations, SORT_NUMERIC);
    $sampleSize = count($durations);
    $p95 = $sampleSize > 0 ? $durations[(int) ceil(0.95 * $sampleSize) - 1] : null;
    $elapsedSeconds = $windowEnd - $windowStart;

    return [
        'window_from' => $windowFrom,
        'window_to' => $windowTo,
        'observed_count' => $observedCount,
        'observed_per_hour' => $observedCount > 0 && $elapsedSeconds > 0
            ? $observedCount / ($elapsedSeconds / 3600) : null,
        'p95_ms' => $p95,
        'sample_size' => $sampleSize,
        'sampled' => $observedCount >= $sampleLimit,
        'http_5xx_count' => $http5xxCount,
        'http_5xx_rate' => $observedCount > 0 ? $http5xxCount / $observedCount : null,
    ];
}
