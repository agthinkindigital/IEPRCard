<?php
declare(strict_types=1);

function ieprMigrarSqliteParaMysql(PDO $source, PDO $target): array
{
    if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
        || $target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new InvalidArgumentException('A origem precisa ser SQLite e o destino MySQL/MariaDB.');
    }
    if ($source->inTransaction() || $target->inTransaction()) {
        throw new RuntimeException('A migração exige conexões sem transações abertas.');
    }

    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    $source->beginTransaction();
    try {
        ieprMigracaoValidarSchema($source, $catalog, 'origem');
        ieprMigracaoValidarSchema($target, $catalog, 'destino');
        if ($source->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC) !== []) {
            throw new RuntimeException('A origem contém referências inválidas; transferência cancelada antes da gravação.');
        }
        if ((int) $target->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() !== 1) {
            throw new RuntimeException('O destino precisa iniciar com verificação de chaves estrangeiras habilitada.');
        }

        $targetTables = \IEPRCard\Database\Schema\tabelasExistentes($target, 'mysql');
        $expectedTables = array_keys($catalog);
        sort($targetTables, SORT_STRING);
        sort($expectedTables, SORT_STRING);
        if ($targetTables !== $expectedTables) {
            throw new RuntimeException('O destino precisa conter exatamente o schema canônico de 27 tabelas.');
        }
        foreach ($catalog as $table => $_definition) {
            $count = (int) $target->query('SELECT COUNT(*) FROM ' . \IEPRCard\Database\Schema\citar($table, 'mysql'))->fetchColumn();
            if ($count !== 0) {
                throw new RuntimeException('O destino contém dados; a execução pode já ter ocorrido. Nenhum dado foi alterado.');
            }
        }
    } catch (Throwable $exception) {
        $source->rollBack();
        throw $exception;
    }

    $startedAt = hrtime(true);
    $foreignKeysDisabled = false;
    try {
        $target->beginTransaction();
        $target->exec('SET FOREIGN_KEY_CHECKS = 0');
        $foreignKeysDisabled = true;
        $tableReports = [];
        $totalRecords = 0;

        foreach ($catalog as $table => $definition) {
            $columns = array_keys($definition['columns']);
            $quotedColumns = array_map(static fn(string $column): string => \IEPRCard\Database\Schema\citar($column, 'sqlite'), $columns);
            $insertColumns = array_map(static fn(string $column): string => \IEPRCard\Database\Schema\citar($column, 'mysql'), $columns);
            $sourceQuery = 'SELECT ' . implode(', ', $quotedColumns) . ' FROM '
                . \IEPRCard\Database\Schema\citar($table, 'sqlite') . ' ORDER BY ' . \IEPRCard\Database\Schema\citar('id', 'sqlite');
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insertQuery = 'INSERT INTO ' . \IEPRCard\Database\Schema\citar($table, 'mysql')
                . ' (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
            $sourceStatement = $source->query($sourceQuery);
            $insertStatement = $target->prepare($insertQuery);
            $sourceHash = hash_init('sha256');
            $rowCount = 0;
            $tableStartedAt = hrtime(true);

            while (($row = $sourceStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $normalizedRow = ieprMigracaoNormalizarLinha($row, $definition['columns']);
                hash_update($sourceHash, json_encode($normalizedRow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $insertStatement->execute(array_values($row));
                $rowCount++;
            }
            $sourceChecksum = hash_final($sourceHash);
            $targetChecksum = ieprMigracaoChecksumTabela($target, $table, $definition);
            $targetCount = (int) $target->query('SELECT COUNT(*) FROM ' . \IEPRCard\Database\Schema\citar($table, 'mysql'))->fetchColumn();
            if ($targetCount !== $rowCount || !hash_equals($sourceChecksum, $targetChecksum)) {
                throw new RuntimeException('Contagem ou checksum divergente durante a transferência.');
            }
            $tableReports[$table] = [
                'row_count' => $rowCount,
                'sha256' => $sourceChecksum,
                'duration_ms' => (int) ((hrtime(true) - $tableStartedAt) / 1_000_000),
            ];
            $totalRecords += $rowCount;
        }

        ieprMigracaoValidarForeignKeys($target, $catalog);
        $target->exec('SET FOREIGN_KEY_CHECKS = 1');
        $foreignKeysDisabled = false;
        $source->commit();
        $target->commit();
    } catch (Throwable $exception) {
        if ($source->inTransaction()) {
            $source->rollBack();
        }
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        if ($foreignKeysDisabled) {
            try {
                $target->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
                // Keep the transfer failure message free of driver values and credentials.
            }
        }
        throw new RuntimeException('A transferência falhou e foi revertida no destino.', 0);
    }

    return [
        'total_records' => $totalRecords,
        'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        'tables' => $tableReports,
    ];
}

function ieprMigracaoValidarSchema(PDO $pdo, array $catalog, string $role): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $expectedTables = array_keys($catalog);
    $existingTables = \IEPRCard\Database\Schema\tabelasExistentes($pdo, $driver);
    sort($expectedTables, SORT_STRING);
    sort($existingTables, SORT_STRING);
    if ($existingTables !== $expectedTables || array_filter(schemaInspecionar($pdo)) !== []) {
        throw new RuntimeException('O schema da ' . $role . ' não corresponde ao catálogo canônico; transferência cancelada.');
    }
}

function ieprMigracaoChecksumTabela(PDO $pdo, string $table, array $definition): string
{
    $columns = array_keys($definition['columns']);
    $quotedColumns = array_map(static fn(string $column): string => \IEPRCard\Database\Schema\citar($column, 'mysql'), $columns);
    $statement = $pdo->query('SELECT ' . implode(', ', $quotedColumns) . ' FROM '
        . \IEPRCard\Database\Schema\citar($table, 'mysql') . ' ORDER BY ' . \IEPRCard\Database\Schema\citar('id', 'mysql'));
    $hash = hash_init('sha256');
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $normalizedRow = ieprMigracaoNormalizarLinha($row, $definition['columns']);
        hash_update($hash, json_encode($normalizedRow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    return hash_final($hash);
}

function ieprMigracaoNormalizarLinha(array $row, array $columns): array
{
    $normalized = [];
    foreach ($columns as $column => $definition) {
        $value = $row[$column];
        if ($value === null) {
            $normalized[$column] = null;
        } elseif (in_array($definition['type'], ['id', 'integer', 'boolean'], true)) {
            $normalized[$column] = (string) (int) $value;
        } elseif ($definition['type'] === 'decimal') {
            $normalized[$column] = number_format((float) $value, $definition['scale'], '.', '');
        } else {
            $normalized[$column] = (string) $value;
        }
    }
    return $normalized;
}

function ieprMigracaoValidarForeignKeys(PDO $target, array $catalog): void
{
    foreach ($catalog as $table => $definition) {
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $childTable = \IEPRCard\Database\Schema\citar($table, 'mysql');
            $childColumn = \IEPRCard\Database\Schema\citar($foreignKey['column'], 'mysql');
            $parentTable = \IEPRCard\Database\Schema\citar($foreignKey['table'], 'mysql');
            $parentColumn = \IEPRCard\Database\Schema\citar($foreignKey['reference'], 'mysql');
            $sql = 'SELECT COUNT(*) FROM ' . $childTable . ' c LEFT JOIN ' . $parentTable . ' p ON c.' . $childColumn . ' = p.' . $parentColumn
                . ' WHERE c.' . $childColumn . ' IS NOT NULL AND p.' . $parentColumn . ' IS NULL';
            if ((int) $target->query($sql)->fetchColumn() !== 0) {
                throw new RuntimeException('A transferência produziu referências inválidas; rollback solicitado.');
            }
        }
    }
}
