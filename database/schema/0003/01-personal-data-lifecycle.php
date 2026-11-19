<?php
declare(strict_types=1);

return [
    'titulares' => [
        'columns' => [
            'inativado_em' => ['type' => 'datetime'],
            'anonimizado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_titulares_retencao' => ['columns' => ['ativo', 'anonimizado_em', 'inativado_em', 'id']],
        ],
        'foreign_keys' => [],
    ],
    'solicitacoes_anonimizacao' => [
        'columns' => [
            'erro_codigo' => ['type' => 'varchar', 'length' => 100],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ],
];
