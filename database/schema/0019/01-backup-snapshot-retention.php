<?php
declare(strict_types=1);

return [
    'destinos_backup' => [
        'columns' => [
            'retencao_dias' => ['type' => 'integer', 'nullable' => false, 'default' => 30],
            'retencao_ultima_execucao_em' => ['type' => 'datetime'],
            'retencao_erro_codigo' => ['type' => 'varchar', 'length' => 80],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ],
];
