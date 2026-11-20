<?php
declare(strict_types=1);

return [
    'lotes_importacao' => [
        'columns' => [
            'titular_origem_id' => ['type' => 'integer'],
        ],
        'indexes' => [
            'idx_lotes_titular_origem' => ['columns' => ['titular_origem_id']],
        ],
        'foreign_keys' => [
            ['column' => 'titular_origem_id', 'table' => 'titulares', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
