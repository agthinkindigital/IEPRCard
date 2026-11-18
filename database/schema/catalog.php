<?php
declare(strict_types=1);

namespace IEPRCard\Database\Schema;

function catalogoCanonico(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $versions = glob(__DIR__ . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [];
    sort($versions, SORT_STRING);
    $catalog = [];
    foreach ($versions as $version) {
        $catalog = mesclarCatalogo($catalog, catalogoVersao(basename($version)));
    }

    return $catalog;
}

function catalogoVersao(string $version): array
{
    if (!preg_match('/^\d{4}$/', $version)) {
        throw new \InvalidArgumentException('Versão de catálogo inválida.');
    }
    $directory = __DIR__ . DIRECTORY_SEPARATOR . $version;
    if (!is_dir($directory)) {
        throw new \RuntimeException('Versão de catálogo não encontrada: ' . $version);
    }

    $catalog = [];
    $modules = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($modules, SORT_STRING);
    foreach ($modules as $module) {
        $definitions = require $module;
        foreach ($definitions as $table => $definition) {
            if (isset($catalog[$table])) {
                throw new \LogicException('Tabela duplicada na versão ' . $version . ': ' . $table);
            }
            $catalog[$table] = $definition;
        }
    }
    return $catalog;
}

function mesclarCatalogo(array $base, array $version): array
{
    foreach ($version as $table => $definition) {
        if (!isset($base[$table])) {
            $base[$table] = $definition;
            continue;
        }
        foreach (['columns', 'indexes'] as $section) {
            $base[$table][$section] = array_merge($base[$table][$section] ?? [], $definition[$section] ?? []);
        }
        $foreignKeys = array_merge($base[$table]['foreign_keys'] ?? [], $definition['foreign_keys'] ?? []);
        $base[$table]['foreign_keys'] = array_values(array_unique($foreignKeys, SORT_REGULAR));
    }
    return $base;
}
