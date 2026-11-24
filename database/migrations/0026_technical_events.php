<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0026'));
};
