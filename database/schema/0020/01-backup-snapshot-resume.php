<?php
declare(strict_types=1);

return [
    'destinos_backup' => [
        'columns' => [
            'snapshot_pendente_id' => ['type' => 'varchar', 'length' => 32],
            'snapshot_pendente_em' => ['type' => 'datetime'],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ],
];
