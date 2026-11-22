<?php
declare(strict_types=1);

return [
    'whatsapp_config_global' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'chave' => ['type' => 'varchar', 'length' => 20, 'nullable' => false],
            'api_base_url' => ['type' => 'varchar', 'length' => 500, 'nullable' => false],
            'api_token_cifrado' => ['type' => 'text', 'nullable' => false],
            'ultimo_teste_status' => ['type' => 'varchar', 'length' => 20],
            'ultimo_teste_em' => ['type' => 'datetime'],
            'ultimo_erro_codigo' => ['type' => 'varchar', 'length' => 80],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_whatsapp_config_global_chave' => ['columns' => ['chave'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
    'whatsapp_instancias' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'escopo_chave' => ['type' => 'varchar', 'length' => 80, 'nullable' => false],
            'nome_instancia' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'device_id' => ['type' => 'varchar', 'length' => 180],
            'webhook_token_hash' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'pendente'],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_whatsapp_instancia_scope' => ['columns' => ['escopo_chave'], 'unique' => true],
            'uq_whatsapp_instancia_nome' => ['columns' => ['nome_instancia'], 'unique' => true],
            'uq_whatsapp_instancia_device' => ['columns' => ['device_id'], 'unique' => true],
            'uq_whatsapp_instancia_webhook' => ['columns' => ['webhook_token_hash'], 'unique' => true],
            'ix_whatsapp_instancias_tenant_estado' => ['columns' => ['tenant_id', 'estado']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'webhook_gowa_eventos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'instancia_id' => ['type' => 'integer', 'nullable' => false],
            'evento_hash' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'recebido'],
            'recebido_em' => ['type' => 'datetime', 'nullable' => false],
            'processando_em' => ['type' => 'datetime'],
            'processado_em' => ['type' => 'datetime'],
            'tentativas' => ['type' => 'integer', 'nullable' => false, 'default' => 1],
        ],
        'indexes' => [
            'uq_webhook_gowa_evento' => ['columns' => ['instancia_id', 'evento_hash'], 'unique' => true],
            'ix_webhook_gowa_estado_data' => ['columns' => ['estado', 'recebido_em']],
            'ix_webhook_gowa_instancia_data' => ['columns' => ['instancia_id', 'recebido_em']],
            'ix_webhook_gowa_recebido' => ['columns' => ['recebido_em']],
        ],
        'foreign_keys' => [
            ['column' => 'instancia_id', 'table' => 'whatsapp_instancias', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
];
