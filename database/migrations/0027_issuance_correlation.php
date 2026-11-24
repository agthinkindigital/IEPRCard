<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

return static function (PDO $pdo): void {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $uniqueCorrelationIndex = false;
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA index_list("eventos_tecnicos")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['name'] === 'uq_eventos_tecnicos_correlation') {
                $uniqueCorrelationIndex = (int) $index['unique'] === 1;
                break;
            }
        }
    } elseif ($driver === 'mysql') {
        $index = $pdo->prepare(
            'SELECT MAX(NON_UNIQUE = 0) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $index->execute(['eventos_tecnicos', 'uq_eventos_tecnicos_correlation']);
        $uniqueCorrelationIndex = (int) $index->fetchColumn() === 1;
    }
    if ($uniqueCorrelationIndex) {
        if ($driver === 'sqlite') {
            $pdo->exec('DROP INDEX "uq_eventos_tecnicos_correlation"');
        } elseif ($driver === 'mysql') {
            $pdo->exec('ALTER TABLE `eventos_tecnicos` DROP INDEX `uq_eventos_tecnicos_correlation`');
        }
    }

    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0027'));
};
