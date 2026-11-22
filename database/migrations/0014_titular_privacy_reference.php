<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0012'));
    $select = $pdo->query('SELECT id FROM titulares WHERE referencia_privacidade IS NULL ORDER BY id');
    $update = $pdo->prepare('UPDATE titulares SET referencia_privacidade = :reference WHERE id = :id AND referencia_privacidade IS NULL');
    while (($id = $select->fetchColumn()) !== false) {
        $update->execute(['reference' => bin2hex(random_bytes(32)), 'id' => (int) $id]);
    }
};
