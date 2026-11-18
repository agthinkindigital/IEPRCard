<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/schema/catalog.php';
require_once dirname(__DIR__) . '/database/schema/ddl.php';
require_once dirname(__DIR__) . '/database/schema/inspect.php';

use function IEPRCard\Database\Schema\catalogoCanonico;
use function IEPRCard\Database\Schema\catalogoVersao;
use function IEPRCard\Database\Schema\citar;
use function IEPRCard\Database\Schema\definicaoColuna;
use function IEPRCard\Database\Schema\identificar;
use function IEPRCard\Database\Schema\indicesExistentes;
use function IEPRCard\Database\Schema\inspecionar;
use function IEPRCard\Database\Schema\sqlCriarIndice;
use function IEPRCard\Database\Schema\sqlCriarTabela;
use function IEPRCard\Database\Schema\tabelasExistentes;
use function IEPRCard\Database\Schema\colunasExistentes;

function schemaInspecionar(PDO $pdo): array
{
    return inspecionar($pdo);
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
    foreach ($catalog as $table => $definition) {
        if (!in_array($table, $tables, true)) {
            $pdo->exec(sqlCriarTabela($pdo, $table, $definition));
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
}
