<?php
declare(strict_types=1);

namespace IEPRCard\Database\Schema;

use PDO;

function inspecionar(PDO $pdo): array
{
    $driver = identificar($pdo);
    $catalog = catalogoCanonico();
    $existingTables = tabelasExistentes($pdo, $driver);
    $report = ['missing_tables' => [], 'missing_columns' => [], 'mismatched_columns' => [], 'missing_indexes' => [], 'missing_foreign_keys' => []];

    foreach ($catalog as $table => $definition) {
        if (!in_array($table, $existingTables, true)) {
            $report['missing_tables'][] = $table;
            continue;
        }
        $existingColumns = colunasMetadataExistente($pdo, $table, $driver);
        foreach ($definition['columns'] as $column => $_columnDefinition) {
            if (!isset($existingColumns[$column])) {
                $report['missing_columns'][] = $table . '.' . $column;
            } elseif (colunaDiverge($existingColumns[$column], $_columnDefinition, $driver)) {
                $report['mismatched_columns'][] = $table . '.' . $column;
            }
        }
        $existingIndexes = indicesExistentes($pdo, $table, $driver);
        foreach ($definition['indexes'] as $index => $_indexDefinition) {
            if (!in_array($index, $existingIndexes, true)) {
                $report['missing_indexes'][] = $table . '.' . $index;
            }
        }
        $existingForeignKeys = chavesEstrangeirasExistentes($pdo, $table, $driver);
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $key = chaveEstrangeiraNormalizada($foreignKey);
            if (!in_array($key, $existingForeignKeys, true)) {
                $report['missing_foreign_keys'][] = $table . '.' . $key;
            }
        }
    }

    return $report;
}

function colunasMetadataExistente(PDO $pdo, string $table, string $driver): array
{
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA table_info(' . citar($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['name']] = [
                'type' => strtoupper(str_replace(' ', '', (string) $row['type'])),
                'nullable' => (int) $row['notnull'] !== 1 && (int) $row['pk'] !== 1,
                'default' => $row['dflt_value'],
                'primary_key' => (int) $row['pk'] === 1,
            ];
        }
        return $columns;
    }
    $statement = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['COLUMN_NAME']] = [
            'type' => strtolower((string) $row['DATA_TYPE']),
            'column_type' => strtolower((string) $row['COLUMN_TYPE']),
            'nullable' => $row['IS_NULLABLE'] === 'YES',
            'default' => $row['COLUMN_DEFAULT'],
            'length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
            'precision' => $row['NUMERIC_PRECISION'] === null ? null : (int) $row['NUMERIC_PRECISION'],
            'scale' => $row['NUMERIC_SCALE'] === null ? null : (int) $row['NUMERIC_SCALE'],
            'primary_key' => $row['COLUMN_KEY'] === 'PRI',
        ];
    }
    return $columns;
}

function colunaDiverge(array $actual, array $expected, string $driver): bool
{
    $type = $expected['type'];
    $expectedType = match ($type) {
        'id', 'integer' => $driver === 'mysql' ? 'int' : 'INTEGER',
        'boolean' => $driver === 'mysql' ? 'tinyint' : 'INTEGER',
        'varchar' => 'varchar',
        'text' => $driver === 'mysql' ? 'text' : 'TEXT',
        'decimal' => 'decimal',
        'date' => $driver === 'mysql' ? 'date' : 'TEXT',
        'datetime' => $driver === 'mysql' ? 'datetime' : 'TEXT',
        default => throw new \InvalidArgumentException('Tipo de coluna desconhecido: ' . $type),
    };
    $actualType = $actual['type'];
    if ($driver === 'sqlite') {
        if (str_starts_with($actualType, 'VARCHAR(')) {
            $actualType = 'varchar';
        } elseif (str_starts_with($actualType, 'DECIMAL(')) {
            $actualType = 'decimal';
        }
    }
    if ($actualType !== $expectedType) {
        return true;
    }
    if ($driver === 'mysql') {
        if ($type === 'varchar' && $actual['length'] !== $expected['length']) {
            return true;
        }
        if ($type === 'decimal' && ($actual['precision'] !== $expected['precision'] || $actual['scale'] !== $expected['scale'])) {
            return true;
        }
    } elseif ($type === 'varchar' && !preg_match('/^VARCHAR\\(' . (int) $expected['length'] . '\\)$/', $actual['type'])) {
        return true;
    } elseif ($type === 'decimal' && $actual['type'] !== 'DECIMAL(' . (int) $expected['precision'] . ',' . (int) $expected['scale'] . ')') {
        return true;
    }
    if ($type === 'boolean' && $driver === 'mysql' && $actual['column_type'] !== 'tinyint(1)') {
        return true;
    }

    $expectedNullable = $type === 'id' ? false : ($expected['nullable'] ?? true);
    if ($actual['nullable'] !== $expectedNullable) {
        return true;
    }
    $expectedDefault = array_key_exists('default', $expected) ? normalizarPadrao($expected['default']) : null;
    return normalizarPadrao($actual['default']) !== $expectedDefault;
}

function normalizarPadrao(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return trim((string) $value, "'\"");
}

function tabelasExistentes(PDO $pdo, string $driver): array
{
    if ($driver === 'sqlite') {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    }
    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?');
    $statement->execute(['BASE TABLE']);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function colunasExistentes(PDO $pdo, string $table, string $driver): array
{
    if ($driver === 'sqlite') {
        return array_column($pdo->query('PRAGMA table_info(' . citar($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function indicesExistentes(PDO $pdo, string $table, string $driver): array
{
    if ($driver === 'sqlite') {
        return array_column($pdo->query('PRAGMA index_list(' . citar($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    $statement = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function chavesEstrangeirasExistentes(PDO $pdo, string $table, string $driver): array
{
    if ($driver === 'sqlite') {
        $rows = $pdo->query('PRAGMA foreign_key_list(' . citar($table, $driver) . ')')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $row): string => $row['from'] . '>' . $row['table'] . '.' . $row['to'] . ':' . strtoupper($row['on_delete']), $rows);
    }
    $statement = $pdo->prepare(
        'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE '
        . 'FROM information_schema.KEY_COLUMN_USAGE k '
        . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
        . 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
        . 'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $statement->execute([$table]);
    return array_map(static fn(array $row): string => $row['COLUMN_NAME'] . '>' . $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'] . ':' . strtoupper($row['DELETE_RULE']), $statement->fetchAll(PDO::FETCH_ASSOC));
}

function chaveEstrangeiraNormalizada(array $foreignKey): string
{
    return $foreignKey['column'] . '>' . $foreignKey['table'] . '.' . $foreignKey['reference'] . ':' . strtoupper($foreignKey['on_delete']);
}
