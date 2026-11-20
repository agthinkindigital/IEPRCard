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
        'column' => 'titular_origem_id',
        'table' => 'titulares',
        'reference' => 'id',
        'on_delete' => 'SET NULL',
    ];
    $expectedKey = chaveEstrangeiraNormalizada($foreignKey);
    $columns = colunasExistentes($pdo, 'lotes_importacao', $driver);
    $foreignKeys = chavesEstrangeirasExistentes($pdo, 'lotes_importacao', $driver);

    if ($driver === 'sqlite' && !in_array($expectedKey, $foreignKeys, true)) {
        if (in_array('titular_origem_id', $columns, true)) {
            throw new RuntimeException('A origem individual do job existe sem a chave estrangeira esperada.');
        }
        $pdo->exec(
            'ALTER TABLE "lotes_importacao" ADD COLUMN "titular_origem_id" INTEGER '
            . 'REFERENCES "titulares" ("id") ON DELETE SET NULL'
        );
    }

    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0007'));
    $foreignKeys = chavesEstrangeirasExistentes($pdo, 'lotes_importacao', $driver);
    if ($driver === 'mysql' && !in_array($expectedKey, $foreignKeys, true)) {
        $pdo->exec(
            'ALTER TABLE `lotes_importacao` ADD CONSTRAINT `fk_lotes_titular_origem` '
            . 'FOREIGN KEY (`titular_origem_id`) REFERENCES `titulares` (`id`) ON DELETE SET NULL'
        );
    }
};
