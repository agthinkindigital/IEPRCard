<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0001'));
};
