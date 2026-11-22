<?php
declare(strict_types=1);

return [
    'destinos_backup' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'nome' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'provedor' => ['type' => 'varchar', 'length' => 20, 'nullable' => false, 'default' => 's3'],
            'bucket' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            'regiao' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'endpoint' => ['type' => 'text'],
            'path_style' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'credentials_cifradas' => ['type' => 'text', 'nullable' => false],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'ultimo_teste_status' => ['type' => 'varchar', 'length' => 20],
            'ultimo_teste_em' => ['type' => 'datetime'],
            'ultimo_sucesso_em' => ['type' => 'datetime'],
            'ultimo_erro_codigo' => ['type' => 'varchar', 'length' => 80],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_destinos_backup_nome' => ['columns' => ['nome'], 'unique' => true],
            'ix_destinos_backup_ativo' => ['columns' => ['ativo', 'id']],
        ],
        'foreign_keys' => [],
    ],
];
