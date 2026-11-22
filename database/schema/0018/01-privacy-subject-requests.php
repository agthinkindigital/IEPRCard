<?php
declare(strict_types=1);

return [
    'solicitacoes_anonimizacao' => [
        'columns' => [
            'privacy_subject_id' => ['type' => 'integer'],
        ],
        'indexes' => [
            'ix_solicitacao_anonimizacao_subject_state' => ['columns' => ['privacy_subject_id', 'estado', 'prazo_em']],
        ],
        'foreign_keys' => [],
    ],
];
