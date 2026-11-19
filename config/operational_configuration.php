<?php
declare(strict_types=1);

require_once __DIR__ . '/operational_batch_metrics.php';

/** @return array{lote_maximo_registros:int,pico_previsto_diario:int,janela_previsao_dias:int} */
function operationalConfigValidateSettings(array $settings): array
{
    $expected = ['janela_previsao_dias', 'lote_maximo_registros', 'pico_previsto_diario'];
    $keys = array_keys($settings);
    sort($keys, SORT_STRING);
    if ($keys !== $expected
        || !is_int($settings['lote_maximo_registros'] ?? null)
        || $settings['lote_maximo_registros'] < 1 || $settings['lote_maximo_registros'] > 500
        || !is_int($settings['pico_previsto_diario'] ?? null)
        || $settings['pico_previsto_diario'] < 0 || $settings['pico_previsto_diario'] > 1_000_000_000
        || !is_int($settings['janela_previsao_dias'] ?? null)
        || $settings['janela_previsao_dias'] < 7 || $settings['janela_previsao_dias'] > 365) {
        throw new InvalidArgumentException('OPERATIONAL_CONFIG_INVALID');
    }

    return [
        'lote_maximo_registros' => $settings['lote_maximo_registros'],
        'pico_previsto_diario' => $settings['pico_previsto_diario'],
        'janela_previsao_dias' => $settings['janela_previsao_dias'],
    ];
}

/**
 * @param array<string, mixed> $settings
 * @return array{id:int,versao:int,ambiente:string,estado:'pendente',configuracao:array{lote_maximo_registros:int,pico_previsto_diario:int,janela_previsao_dias:int}}
 */
function operationalConfigSavePending(PDO $pdo, array $settings, ?int $actorId = null, ?int $now = null): array
{
    $settings = operationalConfigValidateSettings($settings);
    if ($actorId !== null && $actorId < 1) {
        throw new InvalidArgumentException('OPERATIONAL_CONFIG_ACTOR_INVALID');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('OPERATIONAL_CONFIG_TRANSACTION_OPEN');
    }

    $environment = operationalBatchMetricsEnvironment();
    $now ??= time();
    $createdAt = gmdate('Y-m-d H:i:s', $now);
    $configurationJson = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            operationalConfigBeginTransaction($pdo, $driver);
            operationalConfigRequireTransaction($pdo, 'save_started');
            $versionQuery = 'SELECT versao FROM configuracoes_operacionais WHERE ambiente = :environment '
                . 'ORDER BY versao DESC LIMIT 1' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $currentVersion = $pdo->prepare($versionQuery);
            $currentVersion->execute(['environment' => $environment]);
            $version = (int) ($currentVersion->fetchColumn() ?: 0) + 1;
            operationalConfigRequireTransaction($pdo, 'save_version_read');

            $supersedePending = $pdo->prepare(
                "UPDATE configuracoes_operacionais SET estado = 'substituida' "
                . "WHERE ambiente = :environment AND estado = 'pendente'"
            );
            $supersedePending->execute(['environment' => $environment]);
            operationalConfigRequireTransaction($pdo, 'save_pending_superseded');

            $insert = $pdo->prepare(
                'INSERT INTO configuracoes_operacionais '
                . '(versao, ambiente, configuracao_json, estado, alterado_por_usuario_id, criado_em) '
                . "VALUES (:version, :environment, :settings, 'pendente', :actor, :created)"
            );
            $insert->execute([
                'version' => $version,
                'environment' => $environment,
                'settings' => $configurationJson,
                'actor' => $actorId,
                'created' => $createdAt,
            ]);
            operationalConfigRequireTransaction($pdo, 'save_inserted');
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();

            return [
                'id' => $id,
                'versao' => $version,
                'ambiente' => $environment,
                'estado' => 'pendente',
                'configuracao' => $settings,
            ];
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attempt < 2 && operationalConfigIsRetryable($exception)) {
                usleep(10_000 * ($attempt + 1));
                continue;
            }
            throw new RuntimeException('OPERATIONAL_CONFIG_SAVE_FAILED', 0, $exception);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    throw new RuntimeException('OPERATIONAL_CONFIG_SAVE_FAILED');
}

/**
 * Applies the newest pending snapshot at the beginning of one worker invocation.
 *
 * @return array{id:int,versao:int,ambiente:string,estado:'ativa',configuracao:array{lote_maximo_registros:int,pico_previsto_diario:int,janela_previsao_dias:int},ativou_pendente:bool}|null
 */
function operationalConfigWorkerStart(PDO $pdo, ?int $now = null): ?array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('OPERATIONAL_CONFIG_TRANSACTION_OPEN');
    }
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $environment = operationalBatchMetricsEnvironment();
    $now ??= time();

    // There is nothing to promote on a fresh installation. Keep this as a
    // read-only fast path; a setting saved concurrently will be picked up by
    // the next worker invocation.
    $existing = $pdo->prepare(
        "SELECT id FROM configuracoes_operacionais WHERE ambiente = :environment "
        . "AND estado IN ('pendente', 'ativa') ORDER BY versao DESC, id DESC LIMIT 1"
    );
    $existing->execute(['environment' => $environment]);
    if ($existing->fetchColumn() === false) {
        return null;
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            operationalConfigBeginTransaction($pdo, $driver);
            $pendingQuery = 'SELECT id, versao, configuracao_json FROM configuracoes_operacionais '
                . "WHERE ambiente = :environment AND estado = 'pendente' ORDER BY versao DESC, id DESC LIMIT 1"
                . ($driver === 'mysql' ? ' FOR UPDATE' : '');
            $pendingStatement = $pdo->prepare($pendingQuery);
            $pendingStatement->execute(['environment' => $environment]);
            $pending = $pendingStatement->fetch(PDO::FETCH_ASSOC);
            $activatedPending = is_array($pending);

            if ($activatedPending) {
                $supersedeActive = $pdo->prepare(
                    "UPDATE configuracoes_operacionais SET estado = 'substituida' "
                    . "WHERE ambiente = :environment AND estado = 'ativa'"
                );
                $supersedeActive->execute(['environment' => $environment]);
                $activate = $pdo->prepare(
                    "UPDATE configuracoes_operacionais SET estado = 'ativa', ativado_em = :activated "
                    . "WHERE id = :id AND ambiente = :environment AND estado = 'pendente'"
                );
                $activate->execute([
                    'activated' => gmdate('Y-m-d H:i:s', $now),
                    'id' => (int) $pending['id'],
                    'environment' => $environment,
                ]);
                if ($activate->rowCount() !== 1) {
                    throw new RuntimeException('OPERATIONAL_CONFIG_ACTIVATION_CONFLICT');
                }
                $row = $pending;
            } else {
                $activeQuery = 'SELECT id, versao, configuracao_json FROM configuracoes_operacionais '
                    . "WHERE ambiente = :environment AND estado = 'ativa' ORDER BY versao DESC, id DESC LIMIT 1"
                    . ($driver === 'mysql' ? ' FOR UPDATE' : '');
                $activeStatement = $pdo->prepare($activeQuery);
                $activeStatement->execute(['environment' => $environment]);
                $row = $activeStatement->fetch(PDO::FETCH_ASSOC);
            }

            if (!is_array($row)) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                return null;
            }

            $settings = json_decode((string) $row['configuracao_json'], true, 512, JSON_THROW_ON_ERROR);
            $settings = operationalConfigValidateSettings(is_array($settings) ? $settings : []);
            $pdo->commit();

            return [
                'id' => (int) $row['id'],
                'versao' => (int) $row['versao'],
                'ambiente' => $environment,
                'estado' => 'ativa',
                'configuracao' => $settings,
                'ativou_pendente' => $activatedPending,
            ];
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attempt < 2 && operationalConfigIsRetryable($exception)) {
                usleep(10_000 * ($attempt + 1));
                continue;
            }
            throw new RuntimeException('OPERATIONAL_CONFIG_ACTIVATION_FAILED', 0, $exception);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    throw new RuntimeException('OPERATIONAL_CONFIG_ACTIVATION_FAILED');
}

function operationalConfigBeginTransaction(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        return;
    }
    if ($driver !== 'mysql') {
        throw new InvalidArgumentException('OPERATIONAL_CONFIG_DRIVER_INVALID');
    }
    $pdo->beginTransaction();
}

function operationalConfigRequireTransaction(PDO $pdo, string $stage): void
{
    if (!$pdo->inTransaction()) {
        $details = ['driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)];
        if ($details['driver'] === 'mysql') {
            $details['autocommit'] = $pdo->query('SELECT @@autocommit')->fetchColumn();
            $engine = $pdo->prepare(
                'SELECT ENGINE FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $engine->execute(['table_name' => 'configuracoes_operacionais']);
            $details['table_engine'] = $engine->fetchColumn();
        }
        throw new RuntimeException('OPERATIONAL_CONFIG_TRANSACTION_LOST:' . $stage . ':' . json_encode($details));
    }
}

function operationalConfigIsRetryable(PDOException $exception): bool
{
    $sqlState = (string) $exception->getCode();
    $driverCode = isset($exception->errorInfo[1]) ? (string) $exception->errorInfo[1] : '';

    return in_array($sqlState, ['23000', '40001'], true)
        || in_array($driverCode, ['5', '6', '19', '1062', '1205', '1213'], true);
}
