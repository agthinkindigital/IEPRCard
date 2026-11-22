<?php
declare(strict_types=1);

return [
    'design_fundos_privacidade' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'design_id' => ['type' => 'integer', 'nullable' => false],
            'lado' => ['type' => 'varchar', 'length' => 8, 'nullable' => false],
            'classificacao' => ['type' => 'varchar', 'length' => 24, 'nullable' => false],
            'titular_id' => ['type' => 'integer'],
            'asset_url' => ['type' => 'varchar', 'length' => 500],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_design_fundo_lado' => ['columns' => ['design_id', 'lado'], 'unique' => true],
            'ix_design_fundo_titular' => ['columns' => ['tenant_id', 'titular_id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'design_id', 'table' => 'designs_carteirinha', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'titular_id', 'table' => 'titulares', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
