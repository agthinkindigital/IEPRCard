<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__) . '/schema/ddl.php';
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
    $existingKeys = chavesEstrangeirasExistentes($pdo, 'titulares', identificar($pdo));
    if (in_array($expectedKey, $existingKeys, true)) {
        schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0005'));
        return;
    }

    if ($driver === 'sqlite') {
        if (in_array('lote_origem_id', colunasExistentes($pdo, 'titulares', $driver), true)) {
            throw new RuntimeException('A coluna de origem do lote existe sem a chave estrangeira esperada.');
        }
        $pdo->exec(
            'ALTER TABLE "titulares" ADD COLUMN "lote_origem_id" INTEGER '
            . 'REFERENCES "lotes_importacao" ("id") ON DELETE SET NULL'
        );
        schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0005'));
        return;
    }

    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0005'));
    if ($driver === 'mysql') {
        $pdo->exec(
            'ALTER TABLE `titulares` ADD CONSTRAINT `fk_titulares_lote_origem` '
            . 'FOREIGN KEY (`lote_origem_id`) REFERENCES `lotes_importacao` (`id`) ON DELETE SET NULL'
        );
    }
};
