<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__) . '/schema/ddl.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

use function IEPRCard\Database\Schema\catalogoVersao;

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0002'));
};
