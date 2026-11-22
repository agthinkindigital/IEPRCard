<?php
declare(strict_types=1);

/** Seed the already-configured installation expected by admin tool HTTP tests. */
function adminHttpSeedHostedProfile(PDO $pdo, int $adminId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO configuracoes_instalacao '
        . '(modo, tenant_local_id, hostname_plataforma, dominio_base, ator_usuario_id, criado_em) '
        . "VALUES ('hospedado', NULL, '127.0.0.1', 'tenant.example.test', :actor, :created)"
    );
    $statement->execute(['actor' => $adminId, 'created' => gmdate('Y-m-d H:i:s')]);
}
