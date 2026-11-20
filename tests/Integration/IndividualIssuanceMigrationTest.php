<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/database/schema/inspect.php';

use function IEPRCard\Database\Schema\catalogoVersao;
use function IEPRCard\Database\Schema\chaveEstrangeiraNormalizada;
use function IEPRCard\Database\Schema\chavesEstrangeirasExistentes;
use function IEPRCard\Database\Schema\colunasExistentes;
use function IEPRCard\Database\Schema\mesclarCatalogo;

$individualMigrationPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
$legacyCatalog = [];
foreach (['0001', '0002', '0003', '0004', '0005', '0006'] as $version) {
    $legacyCatalog = mesclarCatalogo($legacyCatalog, catalogoVersao($version));
}
schemaAplicarCatalogoExpandOnly($individualMigrationPdo, $legacyCatalog);

$migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
sort($migrationFiles, SORT_STRING);
foreach ($migrationFiles as $migrationFile) {
    if (basename($migrationFile) === '0008_individual_card_issuance.php') {
        continue;
    }
    $migration = require $migrationFile;
    $migration($individualMigrationPdo, 'test');
}

$beforeColumns = colunasExistentes($individualMigrationPdo, 'lotes_importacao', 'sqlite');
if (in_array('titular_origem_id', $beforeColumns, true)) {
    throw new RuntimeException('The legacy issuance schema must not already contain the individual source field.');
}

$individualMigration = require dirname(__DIR__, 2) . '/database/migrations/0008_individual_card_issuance.php';
$individualMigration($individualMigrationPdo, 'test');
$individualMigration($individualMigrationPdo, 'test');
$afterColumns = colunasExistentes($individualMigrationPdo, 'lotes_importacao', 'sqlite');
$expectedForeignKey = chaveEstrangeiraNormalizada([
    'column' => 'titular_origem_id',
    'table' => 'titulares',
    'reference' => 'id',
    'on_delete' => 'SET NULL',
]);
$afterForeignKeys = chavesEstrangeirasExistentes($individualMigrationPdo, 'lotes_importacao', 'sqlite');
if (!in_array('titular_origem_id', $afterColumns, true) || !in_array($expectedForeignKey, $afterForeignKeys, true)) {
    throw new RuntimeException('The migration must add an optional titular FK to existing installations and remain repeatable.');
}

fwrite(STDOUT, "PASS: SQLite migration adds the individual issuance source to an existing schema.\n");

return 'pass';
