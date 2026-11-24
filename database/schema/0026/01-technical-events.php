<?php
declare(strict_types=1);

return [
    'eventos_tecnicos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'correlation_id' => ['type' => 'varchar', 'length' => 36, 'nullable' => false],
            'categoria' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'evento_chave' => ['type' => 'varchar', 'length' => 80, 'nullable' => false],
            'rota' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'status_http' => ['type' => 'integer', 'nullable' => false],
            'duracao_ms' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_eventos_tecnicos_correlation' => ['columns' => ['correlation_id'], 'unique' => true],
            'ix_eventos_tecnicos_data' => ['columns' => ['criado_em']],
            'ix_eventos_tecnicos_categoria_data' => ['columns' => ['categoria', 'criado_em']],
            'ix_eventos_tecnicos_rota_data' => ['columns' => ['rota', 'criado_em']],
        ],
        'foreign_keys' => [],
    ],
];
