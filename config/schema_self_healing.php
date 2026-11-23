<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/schema/catalog.php';
require_once dirname(__DIR__) . '/database/schema/ddl.php';
require_once dirname(__DIR__) . '/database/schema/inspect.php';

use function IEPRCard\Database\Schema\catalogoCanonico;
use function IEPRCard\Database\Schema\catalogoVersao;
use function IEPRCard\Database\Schema\chaveEstrangeiraNormalizada;
use function IEPRCard\Database\Schema\chavesEstrangeirasExistentes;
use function IEPRCard\Database\Schema\citar;
use function IEPRCard\Database\Schema\definicaoColuna;
use function IEPRCard\Database\Schema\identificar;
use function IEPRCard\Database\Schema\indicesExistentes;
use function IEPRCard\Database\Schema\inspecionar;
use function IEPRCard\Database\Schema\sqlCriarIndice;
use function IEPRCard\Database\Schema\sqlCriarTabela;
use function IEPRCard\Database\Schema\tabelasExistentes;
use function IEPRCard\Database\Schema\colunasExistentes;

function schemaInspecionar(PDO $pdo, ?array $catalog = null): array
{
    return inspecionar($pdo, $catalog);
}

function schemaGarantirEstrutura(PDO $pdo, ?string $environment = null): array
{
    $environment = strtolower($environment ?? (getenv('APP_ENV') ?: 'production'));
    if (!in_array($environment, ['dev', 'test', 'production'], true)) {
        throw new InvalidArgumentException('APP_ENV precisa ser dev, test ou production.');
    }

    $report = inspecionar($pdo);
    if (!in_array($environment, ['dev', 'test'], true)) {
        return $report;
    }

    schemaAplicarCatalogoExpandOnly($pdo);
    return inspecionar($pdo);
}

function schemaAplicarCatalogoExpandOnly(PDO $pdo, ?array $catalog = null): void
{
    $driver = identificar($pdo);
    $catalog ??= catalogoCanonico();
    $tables = tabelasExistentes($pdo, $driver);
    $deferredForeignKeys = [];
    foreach ($catalog as $table => $definition) {
        if (!in_array($table, $tables, true)) {
            $tableDefinition = $definition;
            if ($driver === 'mysql') {
                $tableDefinition['foreign_keys'] = [];
                foreach ($definition['foreign_keys'] as $foreignKey) {
                    if ($foreignKey['table'] === $table || in_array($foreignKey['table'], $tables, true)) {
                        $tableDefinition['foreign_keys'][] = $foreignKey;
                    } else {
                        $deferredForeignKeys[] = ['table' => $table, 'definition' => $foreignKey];
                    }
                }
            }
            $pdo->exec(sqlCriarTabela($pdo, $table, $tableDefinition));
            $tables[] = $table;
        }
        $columns = colunasExistentes($pdo, $table, $driver);
        foreach ($definition['columns'] as $column => $columnDefinition) {
            if (in_array($column, $columns, true)) {
                continue;
            }
            if ($column === 'id') {
                throw new RuntimeException('Não é seguro adicionar chave primária ausente em tabela existente: ' . $table);
            }
            if (!($columnDefinition['nullable'] ?? true) && !array_key_exists('default', $columnDefinition)) {
                throw new RuntimeException('A coluna obrigatória ' . $table . '.' . $column . ' precisa de migração com backfill explícito.');
            }
            $pdo->exec('ALTER TABLE ' . citar($table, $driver) . ' ADD COLUMN ' . definicaoColuna($column, $columnDefinition, $driver));
            $columns[] = $column;
        }
        $indexes = indicesExistentes($pdo, $table, $driver);
        foreach ($definition['indexes'] as $index => $indexDefinition) {
            if (!in_array($index, $indexes, true)) {
                $pdo->exec(sqlCriarIndice($pdo, $table, $index, $indexDefinition));
                $indexes[] = $index;
            }
        }
    }

    foreach ($deferredForeignKeys as $deferred) {
        $table = $deferred['table'];
        $foreignKey = $deferred['definition'];
        if (!in_array($foreignKey['table'], $tables, true)) {
            throw new RuntimeException('A tabela referenciada não existe no catálogo: ' . $foreignKey['table']);
        }
        $expectedKey = chaveEstrangeiraNormalizada($foreignKey);
        if (in_array($expectedKey, chavesEstrangeirasExistentes($pdo, $table, $driver), true)) {
            continue;
        }
        $constraint = 'fk_' . $table . '_' . $foreignKey['column'];
        if (strlen($constraint) > 64) {
            $constraint = substr($constraint, 0, 47) . '_' . substr(hash('sha256', $constraint), 0, 16);
        }
        $pdo->exec(
            'ALTER TABLE ' . citar($table, $driver) . ' ADD CONSTRAINT ' . citar($constraint, $driver)
            . ' FOREIGN KEY (' . citar($foreignKey['column'], $driver) . ') REFERENCES '
            . citar($foreignKey['table'], $driver) . ' (' . citar($foreignKey['reference'], $driver) . ') ON DELETE '
            . strtoupper((string) $foreignKey['on_delete'])
        );
    }
}
