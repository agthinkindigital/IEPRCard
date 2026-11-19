<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/operational_configuration.php';

function operationalConfigurationTestWorkerStart(PDO $pdo, int $now, string $phase): ?array
{
    try {
        return operationalConfigWorkerStart($pdo, $now);
    } catch (Throwable $exception) {
        $cause = $exception->getPrevious();
        $details = $cause instanceof PDOException
            ? ['class' => get_class($cause), 'message' => $cause->getMessage(), 'sqlstate' => $cause->getCode(), 'driver_code' => $cause->errorInfo[1] ?? null, 'driver_message' => $cause->errorInfo[2] ?? null, 'trace' => $cause->getTraceAsString()]
            : null;
        throw new RuntimeException($phase . ': ' . $exception->getMessage() . ' ' . json_encode($details));
    }
}

$previousEnvironment = getenv('APP_ENV');
putenv('APP_ENV=staging');
$now = strtotime('2026-01-02 12:00:00 UTC');
$settings = [
    'lote_maximo_registros' => 25,
    'pico_previsto_diario' => 100,
    'janela_previsao_dias' => 30,
];

try {
    foreach (tenantTestConnections() as $driver => $pdo) {
        schemaAplicarMigracoes($pdo, 'test');
        $pdo->prepare("DELETE FROM configuracoes_operacionais WHERE ambiente = 'staging'")->execute();
        foreach ([
            ['lote_maximo_registros' => 0, 'pico_previsto_diario' => 100, 'janela_previsao_dias' => 30],
            ['lote_maximo_registros' => 25, 'pico_previsto_diario' => -1, 'janela_previsao_dias' => 30],
            ['lote_maximo_registros' => 25, 'pico_previsto_diario' => 100, 'janela_previsao_dias' => 366],
            ['lote_maximo_registros' => 25, 'pico_previsto_diario' => 100, 'janela_previsao_dias' => 30, 'unexpected' => true],
        ] as $invalidSettings) {
            $invalidRejected = false;
            try {
                operationalConfigSavePending($pdo, $invalidSettings, null, $now);
            } catch (InvalidArgumentException $exception) {
                $invalidRejected = $exception->getMessage() === 'OPERATIONAL_CONFIG_INVALID';
            }
            if (!$invalidRejected) {
                throw new RuntimeException($driver . ': malformed or out-of-range operational settings must be rejected.');
            }
        }
        if (operationalConfigurationTestWorkerStart($pdo, $now, 'initial worker start') !== null) {
            throw new RuntimeException($driver . ': a fresh environment must have no active operational snapshot.');
        }

        $pending = operationalConfigSavePending($pdo, $settings, null, $now);
        $pendingState = $pdo->prepare('SELECT estado, ativado_em FROM configuracoes_operacionais WHERE id = :id');
        $pendingState->execute(['id' => $pending['id']]);
        $pendingRow = $pendingState->fetch(PDO::FETCH_ASSOC);
        $activeBeforeWorker = $pdo->query("SELECT COUNT(*) FROM configuracoes_operacionais WHERE ambiente = 'staging' AND estado = 'ativa'")->fetchColumn();
        if (($pending['versao'] ?? null) !== 1 || ($pending['estado'] ?? null) !== 'pendente'
            || !is_array($pendingRow) || $pendingRow['estado'] !== 'pendente' || $pendingRow['ativado_em'] !== null
            || (int) $activeBeforeWorker !== 0) {
            throw new RuntimeException($driver . ': saving manual settings must create a pending version without changing active settings.');
        }

        $snapshot = operationalConfigurationTestWorkerStart($pdo, $now + 5, 'first activation');
        $activeAgain = operationalConfigurationTestWorkerStart($pdo, $now + 10, 'idempotent activation');
        $states = $pdo->query("SELECT estado, COUNT(*) AS total FROM configuracoes_operacionais WHERE ambiente = 'staging' GROUP BY estado")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($snapshot['id'] ?? null) !== $pending['id'] || ($snapshot['versao'] ?? null) !== 1
            || ($snapshot['estado'] ?? null) !== 'ativa' || ($snapshot['configuracao'] ?? null) !== $settings
            || ($snapshot['ativou_pendente'] ?? null) !== true
            || ($activeAgain['id'] ?? null) !== $pending['id'] || ($activeAgain['ativou_pendente'] ?? null) !== false
            || ($states['ativa'] ?? null) !== 1 || isset($states['pendente'])) {
            throw new RuntimeException($driver . ': worker startup must atomically activate pending settings once and return a stable active snapshot.');
        }

        $updatedSettings = $settings;
        $updatedSettings['lote_maximo_registros'] = 50;
        $updated = operationalConfigSavePending($pdo, $updatedSettings, null, $now + 15);
        $stillActive = $pdo->query("SELECT id FROM configuracoes_operacionais WHERE ambiente = 'staging' AND estado = 'ativa'")->fetchColumn();
        $nextSnapshot = operationalConfigurationTestWorkerStart($pdo, $now + 20, 'replacement activation');
        $replacementStates = $pdo->query("SELECT estado, COUNT(*) AS total FROM configuracoes_operacionais WHERE ambiente = 'staging' GROUP BY estado")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($updated['versao'] ?? null) !== 2 || ($updated['estado'] ?? null) !== 'pendente'
            || (int) $stillActive !== $pending['id'] || ($nextSnapshot['id'] ?? null) !== $updated['id']
            || ($nextSnapshot['versao'] ?? null) !== 2 || ($nextSnapshot['configuracao'] ?? null) !== $updatedSettings
            || ($nextSnapshot['ativou_pendente'] ?? null) !== true
            || ($replacementStates['ativa'] ?? null) !== 1 || ($replacementStates['substituida'] ?? null) !== 1) {
            throw new RuntimeException($driver . ': a new manual version must replace active settings only at the next worker startup.');
        }

        if (in_array($driver, ['SQLite', 'MySQL/MariaDB'], true)) {
            $racePdo = $pdo;
            $raceDriver = $driver === 'SQLite' ? 'sqlite' : 'mysql';
            $sqliteRacePath = null;
            if ($raceDriver === 'sqlite') {
                $sqliteRacePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-operational-config-' . bin2hex(random_bytes(8)) . '.sqlite';
                $racePdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sqliteRacePath]);
                schemaGarantirEstrutura($racePdo, 'test');
                schemaAplicarMigracoes($racePdo, 'test');
            }
            $racePdo->prepare("DELETE FROM configuracoes_operacionais WHERE ambiente = 'staging'")->execute();
            foreach ([['save', [20, 40]], ['activate', [0, 0]]] as [$mode, $values]) {
                $startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-operational-config-race-' . bin2hex(random_bytes(8));
                $processes = [];
                try {
                    foreach ($values as $value) {
                        $pipes = [];
                        $command = [PHP_BINARY];
                        if ($raceDriver === 'sqlite' && PHP_OS_FAMILY === 'Windows') {
                            array_push($command, '-d', 'extension=php_pdo_sqlite.dll');
                        }
                        array_push(
                            $command,
                            __DIR__ . '/OperationalBatchConfigurationRaceWorker.php',
                            $startFile,
                            $mode,
                            (string) $value,
                            $raceDriver,
                            $sqliteRacePath ?? ''
                        );
                        $process = proc_open(
                            $command,
                            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                            $pipes
                        );
                        if (!is_resource($process)) {
                            throw new RuntimeException('Operational configuration race worker could not start.');
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
                            throw new RuntimeException('Operational configuration race worker failed.');
                        }
                        $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                    }

                    if ($mode === 'save') {
                        $versions = array_column($results, 'versao');
                        sort($versions, SORT_NUMERIC);
                        $states = $racePdo->query("SELECT estado, COUNT(*) AS total FROM configuracoes_operacionais WHERE ambiente = 'staging' GROUP BY estado")
                            ->fetchAll(PDO::FETCH_KEY_PAIR);
                        if ($versions !== [1, 2] || ($states['pendente'] ?? null) !== 1
                            || ($states['substituida'] ?? null) !== 1) {
                            throw new RuntimeException('Concurrent operational configuration saves must keep versions monotonic and one pending snapshot.');
                        }
                    } else {
                        $ids = array_column($results, 'id');
                        $activated = array_column($results, 'ativou_pendente');
                        sort($activated);
                        $activeCount = (int) $racePdo->query("SELECT COUNT(*) FROM configuracoes_operacionais WHERE ambiente = 'staging' AND estado = 'ativa'")->fetchColumn();
                        $pendingCount = (int) $racePdo->query("SELECT COUNT(*) FROM configuracoes_operacionais WHERE ambiente = 'staging' AND estado = 'pendente'")->fetchColumn();
                        if (count(array_unique($ids)) !== 1 || $activated !== [false, true]
                            || $activeCount !== 1 || $pendingCount !== 0) {
                            throw new RuntimeException('Concurrent worker starts must activate one pending version exactly once.');
                        }
                    }
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
            $racePdo->prepare("DELETE FROM configuracoes_operacionais WHERE ambiente = 'staging'")->execute();
            if ($sqliteRacePath !== null) {
                unset($racePdo);
                foreach ([$sqliteRacePath, $sqliteRacePath . '-wal', $sqliteRacePath . '-shm'] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }

        $pdo->prepare("DELETE FROM configuracoes_operacionais WHERE ambiente = 'staging'")->execute();
        fwrite(STDOUT, 'PASS: ' . $driver . " operational settings remain pending until worker startup and activate idempotently.\n");
    }
} finally {
    putenv('APP_ENV=' . ($previousEnvironment === false ? '' : $previousEnvironment));
}

return 'pass';
