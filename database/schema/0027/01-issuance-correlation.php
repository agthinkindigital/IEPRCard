<?php
declare(strict_types=1);

return [
    'lotes_importacao' => [
        'columns' => [
            'correlation_id' => ['type' => 'varchar', 'length' => 36],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ],
    'eventos_tecnicos' => [
        'columns' => [],
        'indexes' => [
            // Override the historical unique definition: one trace can contain multiple events.
            'uq_eventos_tecnicos_correlation' => ['columns' => ['correlation_id']],
        ],
        'foreign_keys' => [],
    ],
];
