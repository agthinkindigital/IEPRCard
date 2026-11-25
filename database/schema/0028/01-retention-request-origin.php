<?php
declare(strict_types=1);

return [
    'solicitacoes_anonimizacao' => [
        'columns' => [
            'origem' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'solicitacao'],
        ],
        'indexes' => [
            'ix_solicitacao_anonimizacao_origin_state' => ['columns' => ['origem', 'estado', 'prazo_em']],
        ],
        'foreign_keys' => [],
    ],
];
