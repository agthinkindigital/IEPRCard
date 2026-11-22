<?php
declare(strict_types=1);

return [
    'configuracoes_instalacao' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'modo' => ['type' => 'varchar', 'length' => 20, 'nullable' => false],
            'tenant_local_id' => ['type' => 'integer'],
            'hostname_plataforma' => ['type' => 'varchar', 'length' => 253],
            'dominio_base' => ['type' => 'varchar', 'length' => 253],
            'ator_usuario_id' => ['type' => 'integer', 'nullable' => false],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'ix_config_instalacao_ator' => ['columns' => ['ator_usuario_id', 'id']],
            'ix_config_instalacao_modo' => ['columns' => ['modo', 'id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_local_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'ator_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
];
