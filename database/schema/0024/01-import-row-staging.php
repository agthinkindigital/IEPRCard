<?php
declare(strict_types=1);

return [
    'importacao_linhas_staging' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'lote_id' => ['type' => 'integer', 'nullable' => false],
            'indice_linha' => ['type' => 'integer', 'nullable' => false],
            'privacy_subject_id' => ['type' => 'integer', 'nullable' => false],
            'dados_cifrados' => ['type' => 'longtext', 'nullable' => false],
            'foto_cifrada' => ['type' => 'longtext'],
            'foto_extensao' => ['type' => 'varchar', 'length' => 8],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_importacao_linha' => ['columns' => ['lote_id', 'indice_linha'], 'unique' => true],
            'ix_importacao_linha_subject' => ['columns' => ['privacy_subject_id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'lote_id', 'table' => 'lotes_importacao', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'privacy_subject_id', 'table' => 'privacy_subjects', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
];
