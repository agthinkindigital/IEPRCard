<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__) . '/schema/inspect.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

use function IEPRCard\Database\Schema\catalogoVersao;
use function IEPRCard\Database\Schema\chaveEstrangeiraNormalizada;
use function IEPRCard\Database\Schema\chavesEstrangeirasExistentes;
use function IEPRCard\Database\Schema\colunasExistentes;
use function IEPRCard\Database\Schema\identificar;

return static function (PDO $pdo): void {
    $driver = identificar($pdo);
    $foreignKey = [
        'column' => 'lote_origem_id',
        'table' => 'lotes_importacao',
        'reference' => 'id',
        'on_delete' => 'SET NULL',
    ];
    $expectedKey = chaveEstrangeiraNormalizada($foreignKey);
    $existingKeys = chavesEstrangeirasExistentes($pdo, 'lotes_importacao', $driver);
    if ($driver === 'sqlite' && !in_array($expectedKey, $existingKeys, true)) {
        if (in_array('lote_origem_id', colunasExistentes($pdo, 'lotes_importacao', $driver), true)) {
            throw new RuntimeException('A origem do job de emissão existe sem a chave estrangeira esperada.');
        }
        $pdo->exec(
            'ALTER TABLE "lotes_importacao" ADD COLUMN "lote_origem_id" INTEGER '
            . 'REFERENCES "lotes_importacao" ("id") ON DELETE SET NULL'
        );
    }

    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0006'));
    $columns = colunasExistentes($pdo, 'lotes_importacao', $driver);
    if (!in_array('worker_lease_until', $columns, true)) {
        $pdo->exec('ALTER TABLE ' . ($driver === 'mysql' ? '`lotes_importacao`' : '"lotes_importacao"')
            . ' ADD COLUMN ' . ($driver === 'mysql' ? '`worker_lease_until` DATETIME NULL' : '"worker_lease_until" TEXT NULL'));
    }
    if (!in_array('worker_attempts', $columns, true)) {
        $pdo->exec('ALTER TABLE ' . ($driver === 'mysql' ? '`lotes_importacao`' : '"lotes_importacao"')
            . ' ADD COLUMN ' . ($driver === 'mysql' ? '`worker_attempts` INT NOT NULL DEFAULT 0' : '"worker_attempts" INTEGER NOT NULL DEFAULT 0'));
    }
    $existingKeys = chavesEstrangeirasExistentes($pdo, 'lotes_importacao', $driver);
    if ($driver === 'mysql' && !in_array($expectedKey, $existingKeys, true)) {
        $pdo->exec(
            'ALTER TABLE `lotes_importacao` ADD CONSTRAINT `fk_lotes_lote_origem` '
            . 'FOREIGN KEY (`lote_origem_id`) REFERENCES `lotes_importacao` (`id`) ON DELETE SET NULL'
        );
    }
};
