<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_resolver.php';

/** @var array<string, list<string>> */
const BUSINESS_TELEMETRY_EVENTS = [
    'issuance' => ['card.generated'],
    'messaging' => ['whatsapp.sent', 'whatsapp.failed'],
    'import' => ['rows.accepted', 'rows.rejected'],
    'validation' => ['qr.validated'],
];

/** @param array<string, mixed> $identity */
function telemetryBusinessAssertCurrentIdentity(PDO $pdo, array $identity): array
{
    $userId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $role = $identity['papel_sistema'] ?? null;
    if ($userId === false || !is_string($role)) {
        throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
    }

    $statement = $pdo->prepare(
        'SELECT id, papel_sistema, tenant_id, subcliente_id FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $stored = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    if (!is_array($stored) || $stored['papel_sistema'] !== $role
        || (isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null) !== ($stored['tenant_id'] === null ? null : (int) $stored['tenant_id'])
        || (isset($identity['subcliente_id']) ? (int) $identity['subcliente_id'] : null) !== ($stored['subcliente_id'] === null ? null : (int) $stored['subcliente_id'])) {
        throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
    }

    if ($role === 'superadmin') {
        if ($stored['tenant_id'] !== null || $stored['subcliente_id'] !== null) {
            throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
        }
        return ['id' => (int) $userId, 'papel_sistema' => $role, 'tenant_id' => null, 'subcliente_id' => null];
    }

    if (!in_array($role, ['titular_cliente', 'responsavel_sub', 'subacesso'], true)
        || $stored['tenant_id'] === null) {
        throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
    }
    $tenantId = (int) $stored['tenant_id'];
    $subclienteId = $stored['subcliente_id'] === null ? null : (int) $stored['subcliente_id'];
    if (($role === 'titular_cliente' && $subclienteId !== null)
        || ($role === 'responsavel_sub' && $subclienteId === null)) {
        throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
    }
    $scope = $pdo->prepare(
        "SELECT 1 FROM tenants root LEFT JOIN tenants child ON child.id = :child_for_join AND child.parent_id = root.id AND child.tipo = 'subcliente' AND child.status_conta = 'ativo' "
        . "WHERE root.id = :tenant AND root.parent_id IS NULL AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . 'AND (:child_required IS NULL OR child.id IS NOT NULL) LIMIT 1'
    );
    $scope->execute(['tenant' => $tenantId, 'child_for_join' => $subclienteId, 'child_required' => $subclienteId]);
    $scopeFound = $scope->fetchColumn() !== false;
    $scope->closeCursor();
    if (!$scopeFound) {
        throw new DomainException('TELEMETRIA_IDENTIDADE_INVALIDA');
    }

    return ['id' => (int) $userId, 'papel_sistema' => $role, 'tenant_id' => $tenantId, 'subcliente_id' => $subclienteId];
}

/**
 * Append one aggregate business event and atomically increment its UTC daily total.
 * No caller-supplied identifiers, values, or metadata are accepted.
 * @param array<string, mixed> $identity Authenticated identity resolved from the server-side session.
 */
function telemetryBusinessRecord(
    PDO $pdo,
    array $identity,
    string $category,
    string $eventKey,
    int $quantity = 1,
    ?int $timestamp = null
): void {
    $identity = telemetryBusinessAssertCurrentIdentity($pdo, $identity);
    if (!in_array($identity['papel_sistema'], ['titular_cliente', 'responsavel_sub', 'subacesso'], true)
        || !in_array($eventKey, BUSINESS_TELEMETRY_EVENTS[$category] ?? [], true)
        || $quantity < 1 || $quantity > 1000000) {
        throw new InvalidArgumentException('TELEMETRIA_EVENTO_INVALIDO');
    }

    $timestamp ??= time();
    if ($timestamp < 0 || $timestamp > time() + 300) {
        throw new InvalidArgumentException('TELEMETRIA_DATA_INVALIDA');
    }
    $createdAt = gmdate('Y-m-d H:i:s', $timestamp);
    $day = gmdate('Y-m-d', $timestamp);
    $tenantId = $identity['tenant_id'];
    $subclientId = $identity['subcliente_id'];
    $normalizedSubclientId = $subclientId ?? 0;
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sqliteLock = null;
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $databaseList = $pdo->query('PRAGMA database_list');
        $databaseRow = $databaseList->fetch(PDO::FETCH_ASSOC);
        $databaseList->closeCursor();
        $databasePath = is_array($databaseRow) ? (string) ($databaseRow['file'] ?? '') : '';
        if ($databasePath !== '') {
            $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-telemetry-' . hash('sha256', $databasePath) . '.lock';
            $sqliteLock = fopen($lockPath, 'c');
            if (!is_resource($sqliteLock) || !flock($sqliteLock, LOCK_EX)) {
                if (is_resource($sqliteLock)) fclose($sqliteLock);
                throw new RuntimeException('TELEMETRIA_LOCK_SQLITE_INDISPONIVEL');
            }
        }
    }

    try {
        for ($attempt = 0; ; $attempt++) {
            $transactionOpen = false;
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec('BEGIN IMMEDIATE');
                } else {
                    $pdo->beginTransaction();
                }
                $transactionOpen = true;
                $event = $pdo->prepare(
                    'INSERT INTO telemetria_eventos (tenant_id, subcliente_id, categoria, evento_chave, quantidade, criado_em) '
                    . 'VALUES (:tenant, :subclient, :category, :event_key, :quantity, :created)'
                );
                $event->execute([
                    'tenant' => $tenantId, 'subclient' => $subclientId, 'category' => $category,
                    'event_key' => $eventKey, 'quantity' => $quantity, 'created' => $createdAt,
                ]);
                if ($driver === 'sqlite') {
                    $upsert = 'INSERT INTO telemetria_diaria (tenant_id, subcliente_id, subcliente_scope_id, dia, categoria, evento_chave, quantidade, atualizado_em) '
                        . 'VALUES (:tenant, :subclient, :normalized, :day, :category, :event_key, :quantity, :updated) '
                        . 'ON CONFLICT (tenant_id, subcliente_scope_id, dia, categoria, evento_chave) DO UPDATE SET '
                        . 'quantidade = telemetria_diaria.quantidade + excluded.quantidade, atualizado_em = excluded.atualizado_em';
                } elseif ($driver === 'mysql') {
                    $upsert = 'INSERT INTO telemetria_diaria (tenant_id, subcliente_id, subcliente_scope_id, dia, categoria, evento_chave, quantidade, atualizado_em) '
                        . 'VALUES (:tenant, :subclient, :normalized, :day, :category, :event_key, :quantity, :updated) '
                        . 'ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = VALUES(atualizado_em)';
                } else {
                    throw new RuntimeException('TELEMETRIA_DRIVER_NAO_SUPORTADO');
                }
                $daily = $pdo->prepare($upsert);
                $daily->execute([
                    'tenant' => $tenantId, 'subclient' => $subclientId, 'normalized' => $normalizedSubclientId,
                    'day' => $day, 'category' => $category, 'event_key' => $eventKey,
                    'quantity' => $quantity, 'updated' => $createdAt,
                ]);
                if ($driver === 'sqlite') {
                    $pdo->exec('COMMIT');
                } else {
                    $pdo->commit();
                }
                $transactionOpen = false;
                return;
            } catch (Throwable $exception) {
                if ($transactionOpen) {
                    if ($driver === 'sqlite') {
                        $pdo->exec('ROLLBACK');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
                $sqliteBusy = $driver === 'sqlite' && $exception instanceof PDOException
                    && (((int) ($exception->errorInfo[1] ?? 0) === 5)
                        || str_contains(strtolower($exception->getMessage()), 'database is locked'));
                if (!$sqliteBusy || $attempt >= 7) {
                    throw $exception;
                }
                usleep(min(500000, (25000 * (2 ** $attempt)) + random_int(0, 15000)));
            }
        }
    } finally {
        if (is_resource($sqliteLock)) {
            flock($sqliteLock, LOCK_UN);
            fclose($sqliteLock);
        }
    }
}

/**
 * Read daily totals for an explicit Admin scope or the authenticated tenant scope.
 * @param array<string, mixed> $identity Authenticated identity resolved from the server-side session.
 * @param array{mode:string,tenant_id?:int,subcliente_id?:int|null} $scope
 * @param array{from:string,to:string} $window Inclusive UTC date range, at most 366 days.
 * @return list<array{dia:string,categoria:string,evento_chave:string,quantidade:int}>
 */
function telemetryBusinessRead(PDO $pdo, array $identity, array $scope, array $window): array
{
    $identity = telemetryBusinessAssertCurrentIdentity($pdo, $identity);
    $mode = $scope['mode'] ?? null;
    if (!is_string($mode) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $window['from'] ?? '')
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $window['to'] ?? '')) {
        throw new InvalidArgumentException('TELEMETRIA_CONSULTA_INVALIDA');
    }
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $window['from'], new DateTimeZone('UTC'));
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $window['to'], new DateTimeZone('UTC'));
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    if ($from === false || $to === false || $from->format('Y-m-d') !== $window['from']
        || $to->format('Y-m-d') !== $window['to'] || $to < $from || $to > $today
        || (int) $from->diff($to)->format('%a') > 365) {
        throw new InvalidArgumentException('TELEMETRIA_JANELA_INVALIDA');
    }

    $predicate = null;
    $params = [];
    if ($identity['papel_sistema'] === 'superadmin') {
        if ($mode === 'global' && count($scope) === 1) {
            $predicate = '1 = 1';
        } elseif ($mode === 'tenant' && count($scope) === 2) {
            $built = tenantScopeBuildPredicate($identity, ['tenant_id' => $scope['tenant_id'] ?? null]);
            $predicate = $built['sql'];
            $params = $built['params'];
        } elseif ($mode === 'unit' && count($scope) === 3) {
            $built = tenantScopeBuildPredicate($identity, [
                'tenant_id' => $scope['tenant_id'] ?? null,
                'subcliente_id' => $scope['subcliente_id'] ?? null,
            ]);
            $predicate = $built['sql'];
            $params = $built['params'];
            $verify = $pdo->prepare("SELECT 1 FROM tenants WHERE id = :child AND parent_id = :tenant AND tipo = 'subcliente' LIMIT 1");
            $verify->execute(['child' => $scope['subcliente_id'], 'tenant' => $scope['tenant_id']]);
            $unitExists = $verify->fetchColumn() !== false;
            $verify->closeCursor();
            if (!$unitExists) {
                throw new DomainException('TELEMETRIA_ESCOPO_INVALIDO');
            }
        } else {
            throw new DomainException('TELEMETRIA_ESCOPO_INVALIDO');
        }
    } elseif ($identity['papel_sistema'] === 'titular_cliente' && $mode === 'network' && count($scope) === 1) {
        $built = tenantScopeBuildPredicate($identity);
        $predicate = $built['sql'];
        $params = $built['params'];
    } elseif (in_array($identity['papel_sistema'], ['responsavel_sub', 'subacesso'], true)
        && $identity['subcliente_id'] !== null && $mode === 'unit' && count($scope) === 1) {
        $built = tenantScopeBuildPredicate($identity);
        $predicate = $built['sql'];
        $params = $built['params'];
    } else {
        throw new DomainException('TELEMETRIA_ESCOPO_INVALIDO');
    }

    $query = $pdo->prepare(
        'SELECT dia, categoria, evento_chave, SUM(quantidade) AS quantidade FROM telemetria_diaria WHERE '
        . $predicate . ' AND dia >= :from_day AND dia <= :to_day '
        . 'GROUP BY dia, categoria, evento_chave ORDER BY dia, categoria, evento_chave'
    );
    $query->execute($params + ['from_day' => $window['from'], 'to_day' => $window['to']]);
    return array_map(static fn (array $row): array => [
        'dia' => (string) $row['dia'], 'categoria' => (string) $row['categoria'],
        'evento_chave' => (string) $row['evento_chave'], 'quantidade' => (int) $row['quantidade'],
    ], $query->fetchAll(PDO::FETCH_ASSOC));
}
