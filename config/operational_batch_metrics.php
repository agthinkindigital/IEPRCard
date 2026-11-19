<?php
declare(strict_types=1);

/**
 * @param array{demand:int,processed:int,duration_per_record_ms:int,queue_depth:int,failures:int} $measurement
 */
function operationalBatchMetricsRecord(PDO $pdo, array $measurement, ?int $now = null): void
{
    foreach (['demand', 'processed', 'duration_per_record_ms', 'queue_depth', 'failures'] as $field) {
        if (!is_int($measurement[$field] ?? null) || $measurement[$field] < 0 || $measurement[$field] > 1_000_000_000) {
            throw new InvalidArgumentException('OPERATIONAL_BATCH_METRIC_INVALID');
        }
    }

    $environment = operationalBatchMetricsEnvironment();
    $now ??= time();
    $day = gmdate('Y-m-d', $now);
    $activeConfiguration = $pdo->prepare(
        "SELECT id FROM configuracoes_operacionais WHERE ambiente = :environment AND estado = 'ativa' "
        . 'ORDER BY versao DESC, id DESC LIMIT 1'
    );
    $activeConfiguration->execute(['environment' => $environment]);
    $configurationId = $activeConfiguration->fetchColumn();
    $configurationId = $configurationId === false ? null : (int) $configurationId;

    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO metricas_lotes_diarias '
            . '(dia, ambiente, pico_demanda_diario, registros_processados, duracao_media_ms, fila_maxima, falhas, versao_configuracao_id) '
            . 'VALUES (:day, :environment, :demand, :processed, :duration, :queue_depth, :failures, :configuration_id) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'duracao_media_ms = CASE WHEN VALUES(registros_processados) = 0 THEN duracao_media_ms '
            . 'WHEN registros_processados = 0 THEN VALUES(duracao_media_ms) '
            . 'ELSE ROUND((duracao_media_ms * registros_processados + VALUES(duracao_media_ms) * VALUES(registros_processados)) '
            . '/ (registros_processados + VALUES(registros_processados))) END, '
            . 'pico_demanda_diario = GREATEST(pico_demanda_diario, VALUES(pico_demanda_diario)), '
            . 'fila_maxima = GREATEST(fila_maxima, VALUES(fila_maxima)), '
            . 'registros_processados = registros_processados + VALUES(registros_processados), '
            . 'falhas = falhas + VALUES(falhas), '
            . 'versao_configuracao_id = COALESCE(VALUES(versao_configuracao_id), versao_configuracao_id)';
    } else {
        $sql = 'INSERT INTO metricas_lotes_diarias '
            . '(dia, ambiente, pico_demanda_diario, registros_processados, duracao_media_ms, fila_maxima, falhas, versao_configuracao_id) '
            . 'VALUES (:day, :environment, :demand, :processed, :duration, :queue_depth, :failures, :configuration_id) '
            . 'ON CONFLICT(dia, ambiente) DO UPDATE SET '
            . 'duracao_media_ms = CASE WHEN excluded.registros_processados = 0 THEN metricas_lotes_diarias.duracao_media_ms '
            . 'WHEN metricas_lotes_diarias.registros_processados = 0 THEN excluded.duracao_media_ms '
            . 'ELSE CAST(ROUND(1.0 * (metricas_lotes_diarias.duracao_media_ms * metricas_lotes_diarias.registros_processados '
            . '+ excluded.duracao_media_ms * excluded.registros_processados) '
            . '/ (metricas_lotes_diarias.registros_processados + excluded.registros_processados)) AS INTEGER) END, '
            . 'pico_demanda_diario = MAX(metricas_lotes_diarias.pico_demanda_diario, excluded.pico_demanda_diario), '
            . 'fila_maxima = MAX(metricas_lotes_diarias.fila_maxima, excluded.fila_maxima), '
            . 'registros_processados = metricas_lotes_diarias.registros_processados + excluded.registros_processados, '
            . 'falhas = metricas_lotes_diarias.falhas + excluded.falhas, '
            . 'versao_configuracao_id = COALESCE(excluded.versao_configuracao_id, metricas_lotes_diarias.versao_configuracao_id)';
    }

    $insert = $pdo->prepare($sql);
    $insert->execute([
        'day' => $day,
        'environment' => $environment,
        'demand' => $measurement['demand'],
        'processed' => $measurement['processed'],
        'duration' => $measurement['duration_per_record_ms'],
        'queue_depth' => $measurement['queue_depth'],
        'failures' => $measurement['failures'],
        'configuration_id' => $configurationId,
    ]);
}

/**
 * @return array{status:'ready'|'insufficient_history',window_days:int,observed_days:int,forecast_peak:?int,recommended_lot_size:?int}
 */
function operationalBatchMetricsForecast(PDO $pdo, int $windowDays = 30, ?int $now = null): array
{
    if ($windowDays < 7 || $windowDays > 365) {
        throw new InvalidArgumentException('OPERATIONAL_BATCH_FORECAST_WINDOW_INVALID');
    }

    $environment = operationalBatchMetricsEnvironment();
    $today = (new DateTimeImmutable('@' . ($now ?? time())))
        ->setTimezone(new DateTimeZone('UTC'))
        ->setTime(0, 0);
    $end = $today->modify('-1 day')->format('Y-m-d');
    $start = $today->modify('-' . $windowDays . ' days')->format('Y-m-d');
    $query = $pdo->prepare(
        'SELECT pico_demanda_diario FROM metricas_lotes_diarias '
        . 'WHERE ambiente = :environment AND dia >= :start AND dia <= :end ORDER BY dia ASC'
    );
    $query->execute(['environment' => $environment, 'start' => $start, 'end' => $end]);
    $peaks = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    $observedDays = count($peaks);
    if ($observedDays < 7) {
        return [
            'status' => 'insufficient_history',
            'window_days' => $windowDays,
            'observed_days' => $observedDays,
            'forecast_peak' => null,
            'recommended_lot_size' => null,
        ];
    }

    sort($peaks, SORT_NUMERIC);
    $nearestRank = (int) ceil(0.95 * $observedDays);
    $forecastPeak = $peaks[$nearestRank - 1];

    return [
        'status' => 'ready',
        'window_days' => $windowDays,
        'observed_days' => $observedDays,
        'forecast_peak' => $forecastPeak,
        'recommended_lot_size' => $forecastPeak,
    ];
}

function operationalBatchMetricsEnvironment(): string
{
    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
    if (!in_array($environment, ['production', 'staging', 'dev', 'test'], true)) {
        throw new InvalidArgumentException('OPERATIONAL_BATCH_METRIC_ENVIRONMENT_INVALID');
    }

    return $environment;
}

function operationalBatchMetricsQueueDepth(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM lotes_importacao WHERE status_lote IN ('aguardando_mapa', 'na_fila')"
    )->fetchColumn();
}

function operationalBatchMetricsDurationPerRecord(string $createdAt, int $processed, int $finishedAt): int
{
    if ($processed < 1) {
        return 0;
    }
    $created = strtotime($createdAt . ' UTC');
    if ($created === false) {
        return 0;
    }

    return (int) floor(max(0, ($finishedAt - $created) * 1000) / $processed);
}
