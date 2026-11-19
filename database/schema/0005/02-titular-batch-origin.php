<?php
declare(strict_types=1);

return [
    'titulares' => [
        'columns' => [
            'lote_origem_id' => ['type' => 'integer'],
        ],
        'indexes' => [
            'ix_titulares_lote_origem_scope' => [
                'columns' => ['lote_origem_id', 'tenant_id', 'subcliente_id'],
            ],
        ],
        'foreign_keys' => [
            ['column' => 'lote_origem_id', 'table' => 'lotes_importacao', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
