<?php
declare(strict_types=1);

return [
    'ia_config_global' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'provedor' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'chave_cifrada' => ['type' => 'text', 'nullable' => false],
            'modelo' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'criado_em' => ['type' => 'datetime'],
            'atualizado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_ia_config_global_provedor' => ['columns' => ['provedor'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
];
