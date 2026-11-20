<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/operational_batch_metrics.php';

$oldEnvironment = getenv('APP_ENV');
putenv('APP_ENV=test');
$now = strtotime('2001-02-03 12:00:00 UTC');
$day = '2001-02-03';

try {
    foreach (tenantTestConnections() as $driver => $pdo) {
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
            ->execute(['day' => $day, 'environment' => 'test']);
        $nextVersion = (int) $pdo->query("SELECT COALESCE(MAX(versao), 0) + 1 FROM configuracoes_operacionais WHERE ambiente = 'test'")->fetchColumn();
        $configuration = $pdo->prepare(
            "INSERT INTO configuracoes_operacionais (versao, ambiente, configuracao_json, estado, criado_em) "
            . "VALUES (:version, 'test', '{}', 'ativa', :created)"
        );
        $configuration->execute(['version' => $nextVersion, 'created' => gmdate('Y-m-d H:i:s', $now)]);
        $configurationId = (int) $pdo->lastInsertId();

        operationalBatchMetricsRecord($pdo, [
            'demand' => 5,
            'processed' => 2,
            'duration_per_record_ms' => 100,
            'queue_depth' => 3,
            'failures' => 1,
        ], $now);
        operationalBatchMetricsRecord($pdo, [
            'demand' => 4,
            'processed' => 1,
            'duration_per_record_ms' => 300,
            'queue_depth' => 6,
            'failures' => 2,
        ], $now + 60);

        $query = $pdo->prepare(
            'SELECT pico_demanda_diario, registros_processados, duracao_media_ms, fila_maxima, falhas, versao_configuracao_id '
            . 'FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment'
        );
        $query->execute(['day' => $day, 'environment' => 'test']);
        $metrics = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($metrics)
            || (int) $metrics['pico_demanda_diario'] !== 5
            || (int) $metrics['registros_processados'] !== 3
            || (int) $metrics['duracao_media_ms'] !== 167
            || (int) $metrics['fila_maxima'] !== 6
            || (int) $metrics['falhas'] !== 3
            || (int) $metrics['versao_configuracao_id'] !== $configurationId) {
            throw new RuntimeException($driver . ': daily batch metrics must aggregate maxima, counts, weighted duration, and active configuration version: ' . json_encode([$metrics, $configurationId]));
        }

        $invalidRejected = false;
        try {
            operationalBatchMetricsRecord($pdo, ['demand' => -1], $now);
        } catch (InvalidArgumentException $exception) {
            $invalidRejected = $exception->getMessage() === 'OPERATIONAL_BATCH_METRIC_INVALID';
        }
        if (!$invalidRejected) {
            throw new RuntimeException($driver . ': malformed operational metric values must be rejected.');
        }

        $forecastNow = strtotime('2001-02-11 12:00:00 UTC');
        $forecastStart = '2001-01-11';
        $forecastEnd = '2001-02-11';
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia >= :start AND dia <= :end AND ambiente IN (\'test\', \'staging\')')
            ->execute(['start' => $forecastStart, 'end' => $forecastEnd]);
        $forecastInsert = $pdo->prepare(
            'INSERT INTO metricas_lotes_diarias '
            . '(dia, ambiente, pico_demanda_diario, registros_processados, duracao_media_ms, fila_maxima, falhas) '
            . 'VALUES (:day, :environment, :peak, :processed, :duration, :queue, :failures)'
        );
        for ($offset = 1; $offset <= 20; $offset++) {
            $forecastDay = gmdate('Y-m-d', $forecastNow - ($offset * 86400));
            $forecastInsert->execute([
                'day' => $forecastDay, 'environment' => 'test', 'peak' => $offset * 10,
                'processed' => $offset, 'duration' => $offset * 10, 'queue' => $offset * 2, 'failures' => $offset % 2,
            ]);
            $forecastInsert->execute([
                'day' => $forecastDay, 'environment' => 'staging', 'peak' => 5000,
                'processed' => 500, 'duration' => 900, 'queue' => 900, 'failures' => 999,
            ]);
        }
        $forecastInsert->execute([
            'day' => '2001-02-11', 'environment' => 'test', 'peak' => 9000,
            'processed' => 0, 'duration' => 0, 'queue' => 0, 'failures' => 0,
        ]);
        $forecastInsert->execute([
            'day' => '2001-01-11', 'environment' => 'test', 'peak' => 10000,
            'processed' => 0, 'duration' => 0, 'queue' => 0, 'failures' => 0,
        ]);
        $forecast = operationalBatchMetricsForecast($pdo, now: $forecastNow);
        if (($forecast['status'] ?? null) !== 'ready'
            || ($forecast['window_days'] ?? null) !== 30
            || ($forecast['observed_days'] ?? null) !== 20
            || ($forecast['forecast_peak'] ?? null) !== 190
            || ($forecast['recommended_lot_size'] ?? null) !== 190) {
            throw new RuntimeException($driver . ': default forecast must use the nearest-rank P95 of complete UTC days in the active environment.');
        }

        $summary = operationalBatchMetricsSummary($pdo, 30, $forecastNow);
        if (($summary['window_days'] ?? null) !== 30 || ($summary['observed_days'] ?? null) !== 20
            || ($summary['forecast_status'] ?? null) !== 'ready'
            || ($summary['forecast_peak'] ?? null) !== 190 || ($summary['recommended_lot_size'] ?? null) !== 190
            || ($summary['processed_total'] ?? null) !== 210 || ($summary['average_duration_per_record_ms'] ?? null) !== 137
            || ($summary['maximum_queue_depth'] ?? null) !== 40 || ($summary['failure_total'] ?? null) !== 10
            || ($summary['current_queue_depth'] ?? null) !== 0) {
            throw new RuntimeException($driver . ': operational dashboard summary must aggregate only the active environment, exclude today, and weight duration by processed records.');
        }

        $sevenDayForecast = operationalBatchMetricsForecast($pdo, 7, $forecastNow);
        if (($sevenDayForecast['status'] ?? null) !== 'ready'
            || ($sevenDayForecast['observed_days'] ?? null) !== 7
            || ($sevenDayForecast['forecast_peak'] ?? null) !== 70) {
            throw new RuntimeException($driver . ': explicit forecast windows must exclude older and incomplete days.');
        }

        $sixthMostRecentDay = gmdate('Y-m-d', $forecastNow - (6 * 86400));
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia < :day AND ambiente = :environment')
            ->execute(['day' => $sixthMostRecentDay, 'environment' => 'test']);
        $insufficientForecast = operationalBatchMetricsForecast($pdo, 7, $forecastNow);
        if (($insufficientForecast['status'] ?? null) !== 'insufficient_history'
            || ($insufficientForecast['observed_days'] ?? null) !== 6
            || !array_key_exists('forecast_peak', $insufficientForecast) || $insufficientForecast['forecast_peak'] !== null
            || !array_key_exists('recommended_lot_size', $insufficientForecast) || $insufficientForecast['recommended_lot_size'] !== null) {
            throw new RuntimeException($driver . ': fewer than seven observed days must not produce a numeric recommendation.');
        }

        $invalidWindowRejected = false;
        try {
            operationalBatchMetricsForecast($pdo, 6, $forecastNow);
        } catch (InvalidArgumentException $exception) {
            $invalidWindowRejected = $exception->getMessage() === 'OPERATIONAL_BATCH_FORECAST_WINDOW_INVALID';
        }
        if (!$invalidWindowRejected) {
            throw new RuntimeException($driver . ': forecast windows below seven days must be rejected.');
        }
        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia >= :start AND dia <= :end AND ambiente IN (\'test\', \'staging\')')
            ->execute(['start' => $forecastStart, 'end' => $forecastEnd]);

        if ($driver === 'MySQL/MariaDB') {
            $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
                ->execute(['day' => $day, 'environment' => 'test']);
            $startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-operational-metrics-race-' . bin2hex(random_bytes(8));
            $processes = [];
            try {
                foreach ([1, 2] as $_) {
                    $pipes = [];
                    $process = proc_open(
                        [PHP_BINARY, __DIR__ . '/OperationalBatchMetricsRaceWorker.php', $startFile, (string) $now],
                        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                        $pipes
                    );
                    if (!is_resource($process)) {
                        throw new RuntimeException('Operational metrics race worker could not start.');
                    }
                    fclose($pipes[0]);
                    $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
                }
                file_put_contents($startFile, 'start');
                $results = [];
                foreach ($processes as $entry) {
                    $stdout = trim(stream_get_contents($entry['stdout']));
                    $stderr = trim(stream_get_contents($entry['stderr']));
                    fclose($entry['stdout']);
                    fclose($entry['stderr']);
                    $exit = proc_close($entry['process']);
                    if ($exit !== 0 || $stderr !== '') {
                        throw new RuntimeException('Operational metrics race worker failed.');
                    }
                    $results[] = $stdout;
                }
                $query->execute(['day' => $day, 'environment' => 'test']);
                $concurrentMetrics = $query->fetch(PDO::FETCH_ASSOC);
                if ($results !== ['OK', 'OK'] || !is_array($concurrentMetrics)
                    || (int) $concurrentMetrics['pico_demanda_diario'] !== 5
                    || (int) $concurrentMetrics['registros_processados'] !== 4
                    || (int) $concurrentMetrics['duracao_media_ms'] !== 100
                    || (int) $concurrentMetrics['fila_maxima'] !== 3
                    || (int) $concurrentMetrics['falhas'] !== 2) {
                    throw new RuntimeException('Concurrent operational metric writes must preserve totals and maxima.');
                }
                fwrite(STDOUT, "PASS: MySQL/MariaDB concurrent operational metric writes preserve totals and maxima.\n");
            } finally {
                foreach ($processes as $entry) {
                    if (is_resource($entry['process'])) {
                        proc_terminate($entry['process']);
                        fclose($entry['stdout']);
                        fclose($entry['stderr']);
                        proc_close($entry['process']);
                    }
                }
                if (is_file($startFile)) {
                    unlink($startFile);
                }
            }
        }

        $pdo->prepare('DELETE FROM metricas_lotes_diarias WHERE dia = :day AND ambiente = :environment')
            ->execute(['day' => $day, 'environment' => 'test']);
        $pdo->prepare('DELETE FROM configuracoes_operacionais WHERE id = :id')->execute(['id' => $configurationId]);
        fwrite(STDOUT, 'PASS: ' . $driver . " operational batch metrics aggregate without personal identifiers.\n");
    }
} finally {
    putenv('APP_ENV=' . ($oldEnvironment === false ? '' : $oldEnvironment));
}
