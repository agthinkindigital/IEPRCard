<?php
declare(strict_types=1);

return [
    'whatsapp_instancias' => [
        'columns' => [
            'fluxo_cadastro_ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
        ],
        'indexes' => [],
        'foreign_keys' => [],
    ],
    'whatsapp_conversas' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'instancia_id' => ['type' => 'integer', 'nullable' => false],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'contato_hmac' => ['type' => 'varchar', 'length' => 64],
            'estado' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'aviso_versao' => ['type' => 'varchar', 'length' => 40],
            'consentimento_finalidade' => ['type' => 'varchar', 'length' => 80],
            'consentimento_em' => ['type' => 'datetime'],
            'revogado_em' => ['type' => 'datetime'],
            'titular_id' => ['type' => 'integer'],
            'job_id' => ['type' => 'integer'],
            'etapa_entrega' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'tentativas_entrega' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'ultimo_erro_entrega' => ['type' => 'varchar', 'length' => 80],
            'dados_cifrados' => ['type' => 'text'],
            'expira_em' => ['type' => 'datetime', 'nullable' => false],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_whatsapp_conversa_ativa' => ['columns' => ['instancia_id', 'contato_hmac'], 'unique' => true],
            'ix_whatsapp_conversa_expira' => ['columns' => ['estado', 'expira_em']],
            'ix_whatsapp_conversa_titular' => ['columns' => ['titular_id', 'consentimento_em']],
            'ix_whatsapp_conversa_job' => ['columns' => ['job_id', 'estado']],
        ],
        'foreign_keys' => [
            ['column' => 'instancia_id', 'table' => 'whatsapp_instancias', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'titular_id', 'table' => 'titulares', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'job_id', 'table' => 'lotes_importacao', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
