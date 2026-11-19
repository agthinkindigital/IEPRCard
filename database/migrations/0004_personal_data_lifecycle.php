<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__) . '/schema/ddl.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

use function IEPRCard\Database\Schema\catalogoVersao;

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0003'));
    $baseline = gmdate('Y-m-d H:i:s');
    $backfill = $pdo->prepare(
        'UPDATE titulares SET inativado_em = :baseline WHERE ativo = 0 AND inativado_em IS NULL'
    );
    $backfill->execute(['baseline' => $baseline]);
};
