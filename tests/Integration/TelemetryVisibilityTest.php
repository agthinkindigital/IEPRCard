<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/business_telemetry.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $first = tenantTestSeed($pdo);
    $second = tenantTestSeed($pdo);
    $secondUnit = $pdo->prepare(
        "INSERT INTO tenants (parent_id, tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES (:parent, 'subcliente', :name, :slug, 'ativo')"
    );
    $secondUnit->execute([
        'parent' => $first['tenant_id'], 'name' => 'Second unit', 'slug' => 'unit-extra-' . bin2hex(random_bytes(5)),
    ]);
    $tenantIds = [$first['tenant_id'], $second['tenant_id']];
    $subclientIds = [$first['subcliente_id'], (int) $pdo->lastInsertId(), $second['subcliente_id']];
    $testUserIds = [];

    $createIdentity = static function (string $role, ?int $tenantId, ?int $subclientId = null) use ($pdo, &$testUserIds): array {
        $suffix = bin2hex(random_bytes(6));
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :subclient, :role, :name, :email, :password, 1)'
        );
        $statement->execute([
            'tenant' => $tenantId, 'subclient' => $subclientId, 'role' => $role,
            'name' => 'Telemetry fixture', 'email' => $suffix . '@example.test',
            'password' => password_hash('fixture-only', PASSWORD_DEFAULT),
        ]);
        $testUserIds[] = (int) $pdo->lastInsertId();
        return [
            'id' => (int) $pdo->lastInsertId(), 'papel_sistema' => $role,
            'tenant_id' => $tenantId, 'subcliente_id' => $subclientId,
        ];
    };

    $sedeA = $createIdentity('titular_cliente', $tenantIds[0]);
    $subA1 = $createIdentity('responsavel_sub', $tenantIds[0], $subclientIds[0]);
    $subA2 = $createIdentity('responsavel_sub', $tenantIds[0], $subclientIds[1]);
    $sedeB = $createIdentity('titular_cliente', $tenantIds[1]);
    $subB1 = $createIdentity('responsavel_sub', $tenantIds[1], $subclientIds[2]);

    foreach ([[$sedeA, 3, 0], [$subA1, 3, 2], [$subA2, 1, 1], [$sedeB, 1, 0], [$subB1, 2, 2]] as [$identity, $issuanceCount, $messageCount]) {
        for ($index = 0; $index < $issuanceCount; $index++) {
            telemetryBusinessRecord($pdo, $identity, 'issuance', 'card.generated', 1, strtotime('2026-11-17 12:00:00 UTC'));
        }
        for ($index = 0; $index < $messageCount; $index++) {
            telemetryBusinessRecord($pdo, $identity, 'messaging', 'whatsapp.sent', 1, strtotime('2026-11-17 12:00:00 UTC'));
        }
    }

    $admin = $createIdentity('superadmin', null);
    $window = ['from' => '2026-11-17', 'to' => '2026-11-17'];
    $global = telemetryBusinessRead($pdo, $admin, ['mode' => 'global'], $window);
    $network = telemetryBusinessRead($pdo, $sedeA, ['mode' => 'network'], $window);
    $unit = telemetryBusinessRead($pdo, $subA1, ['mode' => 'unit'], $window);

    $sum = static function (array $rows, string $category): int {
        return array_sum(array_map(static fn (array $row): int => $row['categoria'] === $category ? (int) $row['quantidade'] : 0, $rows));
    };
    if ($sum($global, 'issuance') !== 10 || $sum($global, 'messaging') !== 5
        || $sum($network, 'issuance') !== 7 || $sum($network, 'messaging') !== 3
        || $sum($unit, 'issuance') !== 3 || $sum($unit, 'messaging') !== 2) {
        throw new RuntimeException($driver . ': Admin, Sede network, and Subcliente reads must match the 10 issuance / 5 messaging fixture without cross-scope leakage.');
    }

    $eventRows = $pdo->query('SELECT usuario_id, valor_associado, metadados_json FROM telemetria_eventos')->fetchAll(PDO::FETCH_ASSOC);
    if (count($eventRows) !== 15) {
        throw new RuntimeException($driver . ': telemetry must append one aggregate event row per accepted occurrence.');
    }
    foreach ($eventRows as $eventRow) {
        if ($eventRow['usuario_id'] !== null || $eventRow['valor_associado'] !== null || $eventRow['metadados_json'] !== null) {
            throw new RuntimeException($driver . ': business telemetry must not persist user identifiers, associated personal data, or arbitrary metadata.');
        }
    }
    $dailyRows = $pdo->prepare('SELECT COUNT(*) FROM telemetria_diaria WHERE tenant_id = :tenant');
    $dailyRows->execute(['tenant' => $tenantIds[0]]);
    $rootDaily = $pdo->prepare('SELECT quantidade FROM telemetria_diaria WHERE tenant_id = :tenant AND subcliente_id IS NULL AND subcliente_scope_id = 0 AND categoria = \'issuance\'');
    $rootDaily->execute(['tenant' => $tenantIds[0]]);
    if ((int) $dailyRows->fetchColumn() !== 5 || (int) $rootDaily->fetchColumn() !== 3) {
        throw new RuntimeException($driver . ': normalized Sede scope must hold one unique daily total independent of SQL NULL uniqueness semantics.');
    }

    foreach ([
        static fn () => telemetryBusinessRecord($pdo, $sedeA, 'issuance', 'card.generated', 0),
        static fn () => telemetryBusinessRecord($pdo, $admin, 'issuance', 'card.generated', 1),
        static fn () => telemetryBusinessRecord($pdo, ['id' => 1301, 'papel_sistema' => 'responsavel_sub', 'tenant_id' => $tenantIds[0], 'subcliente_id' => $subclientIds[2]], 'issuance', 'card.generated', 1),
        static fn () => telemetryBusinessRead($pdo, $sedeA, ['mode' => 'tenant', 'tenant_id' => $tenantIds[1]], $window),
        static fn () => telemetryBusinessRead($pdo, $admin, [], $window),
        static fn () => telemetryBusinessRead($pdo, $unit['id'] ?? [], ['mode' => 'unit'], $window),
    ] as $rejectedCall) {
        try {
            $rejectedCall();
            throw new RuntimeException($driver . ': invalid telemetry scope or payload must be rejected.');
        } catch (InvalidArgumentException|DomainException) {
        }
    }

    $inactive = $createIdentity('titular_cliente', $tenantIds[0]);
    $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = :id')->execute(['id' => $inactive['id']]);
    try {
        telemetryBusinessRead($pdo, $inactive, ['mode' => 'network'], $window);
        throw new RuntimeException($driver . ': inactive identities must not read business telemetry.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'TELEMETRIA_IDENTIDADE_INVALIDA') throw $exception;
    }

    $racePath = null;
    $racePdo = $pdo;
    $raceIdentities = [$sedeA, $subA1];
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $racePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-telemetry-race-' . bin2hex(random_bytes(8)) . '.sqlite';
        $racePdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $racePath]);
        schemaAplicarMigracoes($racePdo, 'test');
        $raceFixture = tenantTestSeed($racePdo);
        $raceUser = $racePdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :subclient, :role, :name, :email, :password, 1)'
        );
        $raceIdentities = [];
        foreach ([['titular_cliente', null], ['responsavel_sub', $raceFixture['subcliente_id']]] as [$role, $subclientId]) {
            $raceUser->execute([
                'tenant' => $raceFixture['tenant_id'], 'subclient' => $subclientId,
                'role' => $role, 'name' => 'Race fixture', 'email' => bin2hex(random_bytes(6)) . '@example.test',
                'password' => password_hash('fixture-only', PASSWORD_DEFAULT),
            ]);
            $raceIdentities[] = [
                'id' => (int) $racePdo->lastInsertId(), 'papel_sistema' => $role,
                'tenant_id' => $raceFixture['tenant_id'], 'subcliente_id' => $subclientId,
            ];
        }
        $raceUser->closeCursor();
    }
    $raceDriver = (string) $racePdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $raceDate = strtotime('2026-11-17 13:00:00 UTC');
    foreach ($raceIdentities as $raceIdentity) {
        $before = $racePdo->prepare(
            'SELECT COALESCE(SUM(quantidade), 0) FROM telemetria_diaria WHERE tenant_id = :tenant AND subcliente_scope_id = :scope '
            . 'AND dia = :day AND categoria = \'issuance\' AND evento_chave = \'card.generated\''
        );
        $scopeId = $raceIdentity['subcliente_id'] ?? 0;
        $before->execute(['tenant' => $raceIdentity['tenant_id'], 'scope' => $scopeId, 'day' => '2026-11-17']);
        $beforeTotal = (int) $before->fetchColumn();
        $before->closeCursor();
        $eventCount = $racePdo->prepare(
            'SELECT COUNT(*) FROM telemetria_eventos WHERE tenant_id = :tenant AND '
            . ($raceIdentity['subcliente_id'] === null ? 'subcliente_id IS NULL' : 'subcliente_id = :subclient')
            . ' AND categoria = \'issuance\' AND evento_chave = \'card.generated\' AND criado_em = \'2026-11-17 13:00:00\''
        );
        $eventParams = ['tenant' => $raceIdentity['tenant_id']];
        if ($raceIdentity['subcliente_id'] !== null) $eventParams['subclient'] = $raceIdentity['subcliente_id'];
        $eventCount->execute($eventParams);
        $beforeEvents = (int) $eventCount->fetchColumn();
        $eventCount->closeCursor();
        if ($racePath !== null) {
            $racePdo = null;
        }

        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-telemetry-race-start-' . bin2hex(random_bytes(8));
        $processes = [];
        try {
            foreach ([1, 2, 3] as $_) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/TelemetryRaceWorker.php', $raceDriver, $racePath ?? '', $barrier,
                        (string) $raceIdentity['id'], '10', (string) $raceDate],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes
                );
                if (!is_resource($process)) throw new RuntimeException('Telemetry race worker could not start.');
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }
            file_put_contents($barrier, 'start');
            foreach ($processes as $entry) {
                $stdout = trim(stream_get_contents($entry['stdout']));
                $stderr = trim(stream_get_contents($entry['stderr']));
                fclose($entry['stdout']);
                fclose($entry['stderr']);
                $exit = proc_close($entry['process']);
                if ($exit !== 0 || $stderr !== '' || $stdout !== 'OK') {
                    throw new RuntimeException($driver . ': concurrent telemetry worker failed: ' . $stderr);
                }
            }
            if ($racePath !== null) {
                $racePdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $racePath]);
            }
            $after = $racePdo->prepare(
                'SELECT quantidade FROM telemetria_diaria WHERE tenant_id = :tenant AND subcliente_scope_id = :scope '
                . 'AND dia = :day AND categoria = \'issuance\' AND evento_chave = \'card.generated\''
            );
            $after->execute(['tenant' => $raceIdentity['tenant_id'], 'scope' => $scopeId, 'day' => '2026-11-17']);
            $afterTotal = (int) $after->fetchColumn();
            $after->closeCursor();
            $eventCount = $racePdo->prepare(
                'SELECT COUNT(*) FROM telemetria_eventos WHERE tenant_id = :tenant AND '
                . ($raceIdentity['subcliente_id'] === null ? 'subcliente_id IS NULL' : 'subcliente_id = :subclient')
                . ' AND categoria = \'issuance\' AND evento_chave = \'card.generated\' AND criado_em = \'2026-11-17 13:00:00\''
            );
            $eventCount->execute($eventParams);
            $afterEvents = (int) $eventCount->fetchColumn();
            $eventCount->closeCursor();
            if ($afterTotal !== $beforeTotal + 30 || $afterEvents !== $beforeEvents + 30) {
                throw new RuntimeException($driver . ': concurrent telemetry expected aggregate/events '
                    . ($beforeTotal + 30) . '/' . ($beforeEvents + 30) . ', found ' . $afterTotal . '/' . $afterEvents . '.');
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
            if (is_file($barrier)) unlink($barrier);
        }
    }
    if ($racePath !== null) {
        $racePdo = null;
        if (is_file($racePath)) unlink($racePath);
    }

    $pdo->prepare('DELETE FROM telemetria_eventos WHERE tenant_id IN (:first, :second)')->execute([
        'first' => $tenantIds[0], 'second' => $tenantIds[1],
    ]);
    $pdo->prepare('DELETE FROM telemetria_diaria WHERE tenant_id IN (:first, :second)')->execute([
        'first' => $tenantIds[0], 'second' => $tenantIds[1],
    ]);
    $deleteUsers = $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . implode(', ', array_fill(0, count($testUserIds), '?')) . ')');
    $deleteUsers->execute($testUserIds);
    $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $subclientIds[1]]);
    tenantTestCleanup($pdo, $first);
    tenantTestCleanup($pdo, $second);
    fwrite(STDOUT, 'PASS: ' . $driver . " business telemetry privacy and visibility scopes.\n");
}

return 'pass';
