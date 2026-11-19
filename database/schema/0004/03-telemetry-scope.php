<?php
declare(strict_types=1);

return [
    'telemetria_diaria' => [
        'columns' => [
            // Nullable only while legacy rows are backfilled by migration 0005. New writes use 0 for Sede scope.
            'subcliente_scope_id' => ['type' => 'integer'],
        ],
        'indexes' => [
            'uq_telemetria_diaria_escopo_normalizado' => [
                'columns' => ['tenant_id', 'subcliente_scope_id', 'dia', 'categoria', 'evento_chave'],
                'unique' => true,
            ],
        ],
        'foreign_keys' => [],
    ],
];
