<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $find = $pdo->prepare('SELECT id FROM permissoes WHERE chave = :key LIMIT 1');
    $find->execute(['key' => 'carteirinha.pdf']);
    if ($find->fetchColumn() !== false) {
        return;
    }
    $insert = $pdo->prepare('INSERT INTO permissoes (chave, descricao) VALUES (:key, :description)');
    $insert->execute(['key' => 'carteirinha.pdf', 'description' => 'Disponibilizar download PDF de carteirinha']);
};
