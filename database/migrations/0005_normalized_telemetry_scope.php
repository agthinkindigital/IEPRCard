<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';

use function IEPRCard\Database\Schema\catalogoVersao;

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, catalogoVersao('0004'));
    // The former nullable composite key allowed duplicate Sede totals on both MySQL and SQLite.
    // Fold those legacy rows before assigning the non-null normalized Sede key.
    $groups = $pdo->query(
        'SELECT tenant_id, dia, categoria, evento_chave, SUM(quantidade) AS total, MAX(atualizado_em) AS updated_at '
        . 'FROM telemetria_diaria WHERE subcliente_id IS NULL AND subcliente_scope_id IS NULL '
        . 'GROUP BY tenant_id, dia, categoria, evento_chave HAVING COUNT(*) > 1'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as $group) {
        $rows = $pdo->prepare(
            'SELECT id FROM telemetria_diaria WHERE tenant_id = :tenant AND subcliente_id IS NULL '
            . 'AND subcliente_scope_id IS NULL AND dia = :day AND categoria = :category AND evento_chave = :event_key ORDER BY id'
        );
        $rows->execute([
            'tenant' => $group['tenant_id'], 'day' => $group['dia'],
            'category' => $group['categoria'], 'event_key' => $group['evento_chave'],
        ]);
        $ids = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) < 2) {
            continue;
        }
        $update = $pdo->prepare('UPDATE telemetria_diaria SET quantidade = :quantity, atualizado_em = :updated WHERE id = :id');
        $update->execute(['quantity' => $group['total'], 'updated' => $group['updated_at'], 'id' => array_shift($ids)]);
        $delete = $pdo->prepare('DELETE FROM telemetria_diaria WHERE id = :id');
        foreach ($ids as $id) {
            $delete->execute(['id' => $id]);
        }
    }
    $pdo->exec('UPDATE telemetria_diaria SET subcliente_scope_id = COALESCE(subcliente_id, 0) WHERE subcliente_scope_id IS NULL');
};
