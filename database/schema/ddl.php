<?php
declare(strict_types=1);

namespace IEPRCard\Database\Schema;

use PDO;

function identificar(PDO $pdo): string
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new \InvalidArgumentException('Schema só é compatível com SQLite e MySQL/MariaDB.');
    }
    return $driver;
}

function citar(string $identifier, string $driver): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $identifier)) {
        throw new \InvalidArgumentException('Identificador de schema inválido.');
    }
    return $driver === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
}

function definicaoColuna(string $name, array $definition, string $driver): string
{
    $column = citar($name, $driver);
    $type = $definition['type'] ?? '';
    $sqlType = match ($type) {
        'id' => $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'integer' => $driver === 'mysql' ? 'INT' : 'INTEGER',
        'boolean' => $driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER',
        'varchar' => 'VARCHAR(' . validarLimite($definition['length'] ?? null) . ')',
        'text' => 'TEXT',
        'decimal' => 'DECIMAL(' . validarPrecisao($definition['precision'] ?? null) . ',' . validarEscala($definition['scale'] ?? null) . ')',
        'date' => $driver === 'mysql' ? 'DATE' : 'TEXT',
        'datetime' => $driver === 'mysql' ? 'DATETIME' : 'TEXT',
        default => throw new \InvalidArgumentException('Tipo de coluna desconhecido: ' . $type),
    };
    if ($type === 'id') {
        return $column . ' ' . $sqlType;
    }

    $sql = $column . ' ' . $sqlType;
    $sql .= ($definition['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
    if (array_key_exists('default', $definition)) {
        $sql .= ' DEFAULT ' . valorPadrao($definition['default']);
    }
    return $sql;
}

function sqlCriarTabela(PDO $pdo, string $table, array $definition): string
{
    $driver = identificar($pdo);
    $parts = [];
    foreach ($definition['columns'] as $name => $column) {
        $parts[] = definicaoColuna($name, $column, $driver);
    }
    foreach ($definition['foreign_keys'] as $foreignKey) {
        $parts[] = 'FOREIGN KEY (' . citar($foreignKey['column'], $driver) . ') REFERENCES '
            . citar($foreignKey['table'], $driver) . ' (' . citar($foreignKey['reference'], $driver) . ') ON DELETE '
            . acaoExclusao($foreignKey['on_delete']);
    }
    $sql = 'CREATE TABLE ' . citar($table, $driver) . ' (' . implode(', ', $parts) . ')';
    if ($driver === 'mysql') {
        $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    return $sql;
}

function sqlCriarIndice(PDO $pdo, string $table, string $name, array $definition): string
{
    $driver = identificar($pdo);
    $columns = array_map(static fn(string $column): string => citar($column, $driver), $definition['columns']);
    return 'CREATE ' . (($definition['unique'] ?? false) ? 'UNIQUE ' : '') . 'INDEX '
        . citar($name, $driver) . ' ON ' . citar($table, $driver) . ' (' . implode(', ', $columns) . ')';
}

function validarLimite(mixed $length): int
{
    if (!is_int($length) || $length < 1 || $length > 65535) {
        throw new \InvalidArgumentException('Comprimento VARCHAR inválido.');
    }
    return $length;
}

function validarPrecisao(mixed $precision): int
{
    if (!is_int($precision) || $precision < 1 || $precision > 65) {
        throw new \InvalidArgumentException('Precisão DECIMAL inválida.');
    }
    return $precision;
}

function validarEscala(mixed $scale): int
{
    if (!is_int($scale) || $scale < 0 || $scale > 30) {
        throw new \InvalidArgumentException('Escala DECIMAL inválida.');
    }
    return $scale;
}

function valorPadrao(string|int|float|bool $value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace("'", "''", $value) . "'";
}

function acaoExclusao(string $action): string
{
    if (!in_array($action, ['RESTRICT', 'CASCADE', 'SET NULL'], true)) {
        throw new \InvalidArgumentException('Ação ON DELETE inválida.');
    }
    return $action;
}
