<?php
declare(strict_types=1);

return [
    'lotes_importacao' => [
        'columns' => [
            'lote_origem_id' => ['type' => 'integer'],
            'chave_idempotencia_hash' => ['type' => 'varchar', 'length' => 64],
            'worker_lease_until' => ['type' => 'datetime'],
            'worker_attempts' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'erro_codigo' => ['type' => 'varchar', 'length' => 80],
        ],
        'indexes' => [
            'uq_lotes_tenant_idempotencia' => [
                'columns' => ['tenant_id', 'chave_idempotencia_hash'],
                'unique' => true,
            ],
        ],
        'foreign_keys' => [
            ['column' => 'lote_origem_id', 'table' => 'lotes_importacao', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
