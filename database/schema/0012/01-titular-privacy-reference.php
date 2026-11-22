<?php
declare(strict_types=1);

return [
    'titulares' => [
        'columns' => [
            'referencia_privacidade' => ['type' => 'varchar', 'length' => 64],
        ],
        'indexes' => [
            'uq_titulares_referencia_privacidade' => ['columns' => ['referencia_privacidade'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
    'titular_chaves_backup' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'destino_id' => ['type' => 'integer', 'nullable' => false],
            'referencia_hmac' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 20, 'nullable' => false, 'default' => 'pendente'],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_titular_chave_destino_ref' => ['columns' => ['destino_id', 'referencia_hmac'], 'unique' => true],
            'ix_titular_chave_ref_estado' => ['columns' => ['referencia_hmac', 'estado']],
        ],
        'foreign_keys' => [
            ['column' => 'destino_id', 'table' => 'destinos_backup', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
];
