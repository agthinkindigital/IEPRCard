<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $column = $driver === 'mysql' ? 'retencao_itens_removidos' : 'retencao_itens_removidos';
    $exists = $driver === 'mysql'
        ? (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'destinos_backup' AND column_name = 'retencao_itens_removidos'")->fetchColumn() > 0
        : (bool) $pdo->query("PRAGMA table_info('destinos_backup')")->fetchAll(PDO::FETCH_ASSOC);
    if ($driver !== 'mysql') {
        $columns = $pdo->query("PRAGMA table_info('destinos_backup')")->fetchAll(PDO::FETCH_ASSOC);
        $exists = (bool) array_filter($columns, static fn(array $row): bool => ($row['name'] ?? null) === $column);
    }
    if (!$exists) {
        $pdo->exec('ALTER TABLE destinos_backup ADD COLUMN ' . $column . ' INTEGER NOT NULL DEFAULT 0');
    }
};
