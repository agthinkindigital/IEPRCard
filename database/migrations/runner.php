<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

function schemaAplicarMigracoes(PDO $pdo, string $environment): array
{
    if (!in_array(strtolower($environment), ['dev', 'test', 'production'], true)) {
        throw new InvalidArgumentException('Ambiente da migração precisa ser dev, test ou production.');
    }
    $environment = strtolower($environment);
    $files = glob(__DIR__ . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]_*.php') ?: [];
    sort($files, SORT_STRING);
    $applied = [];

    foreach ($files as $file) {
        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException('Migração precisa retornar uma função executável: ' . basename($file));
        }
        $migration($pdo, $environment);
        $applied[] = basename($file, '.php');
    }

    return $applied;
}
