<?php
declare(strict_types=1);

return [
    'telemetria_eventos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'usuario_id' => ['type' => 'integer'],
            'categoria' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'evento_chave' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'quantidade' => ['type' => 'integer', 'nullable' => false, 'default' => 1],
            'valor_associado' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'metadados_json' => ['type' => 'text'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_telemetria_scope_categoria_data' => ['columns' => ['tenant_id', 'subcliente_id', 'categoria', 'criado_em']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'telemetria_diaria' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'dia' => ['type' => 'date', 'nullable' => false],
            'categoria' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'evento_chave' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'quantidade' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'valor_associado' => ['type' => 'decimal', 'precision' => 12, 'scale' => 2],
            'atualizado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_telemetria_diaria_escopo' => ['columns' => ['tenant_id', 'subcliente_id', 'dia', 'categoria', 'evento_chave'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'auditoria_processos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'ator_usuario_id' => ['type' => 'integer'],
            'ator_tipo' => ['type' => 'varchar', 'length' => 30],
            'acao' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'entidade' => ['type' => 'varchar', 'length' => 80],
            'entidade_id' => ['type' => 'integer'],
            'antes_json' => ['type' => 'text'],
            'depois_json' => ['type' => 'text'],
            'correlation_id' => ['type' => 'varchar', 'length' => 100],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_auditoria_scope_data' => ['columns' => ['tenant_id', 'subcliente_id', 'criado_em']],
            'ix_auditoria_entidade' => ['columns' => ['entidade', 'entidade_id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'ator_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'acessos_suporte' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'usuario_id' => ['type' => 'integer'],
            'token_hash' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            'tipo' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'criado_em' => ['type' => 'datetime'],
            'expira_em' => ['type' => 'datetime', 'nullable' => false],
            'usado_em' => ['type' => 'datetime'],
            'ip_usado' => ['type' => 'varchar', 'length' => 45],
        ],
        'indexes' => [
            'uq_acessos_suporte_token' => ['columns' => ['token_hash'], 'unique' => true],
            'ix_acessos_suporte_tenant_expira' => ['columns' => ['tenant_id', 'expira_em']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'mensagens_whatsapp' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'telefone' => ['type' => 'varchar', 'length' => 30],
            'direcao' => ['type' => 'varchar', 'length' => 20, 'nullable' => false],
            'tipo_mensagem' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'conteudo' => ['type' => 'text'],
            'id_mensagem_api' => ['type' => 'varchar', 'length' => 160],
            'arquivo_url' => ['type' => 'varchar', 'length' => 500],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_mensagens_api_id' => ['columns' => ['id_mensagem_api'], 'unique' => true],
            'ix_mensagens_scope_data' => ['columns' => ['tenant_id', 'subcliente_id', 'criado_em']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'ia_config' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'provedor' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'chave_cifrada' => ['type' => 'text'],
            'modelo' => ['type' => 'varchar', 'length' => 120],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'criado_em' => ['type' => 'datetime'],
            'atualizado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_ia_config_tenant_provedor' => ['columns' => ['tenant_id', 'provedor'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'webhooks_recebidos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'evento_id' => ['type' => 'varchar', 'length' => 180, 'nullable' => false],
            'origem' => ['type' => 'varchar', 'length' => 60, 'nullable' => false],
            'payload_hash' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'recebido'],
            'recebido_em' => ['type' => 'datetime'],
            'processado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_webhooks_evento_origem' => ['columns' => ['evento_id', 'origem'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
    'outbox_eventos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'evento_chave' => ['type' => 'varchar', 'length' => 180, 'nullable' => false],
            'tipo_evento' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'payload_json' => ['type' => 'text', 'nullable' => false],
            'status' => ['type' => 'varchar', 'length' => 20, 'nullable' => false, 'default' => 'pendente'],
            'tentativas' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'proxima_tentativa_em' => ['type' => 'datetime'],
            'criado_em' => ['type' => 'datetime'],
            'enviado_em' => ['type' => 'datetime'],
            'correlation_id' => ['type' => 'varchar', 'length' => 100],
        ],
        'indexes' => [
            'uq_outbox_evento_chave' => ['columns' => ['evento_chave'], 'unique' => true],
            'ix_outbox_drenagem' => ['columns' => ['status', 'proxima_tentativa_em']],
        ],
        'foreign_keys' => [],
    ],
    'reservas_cota' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'lote_id' => ['type' => 'integer'],
            'quantidade' => ['type' => 'integer', 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 24, 'nullable' => false, 'default' => 'reservada'],
            'expira_em' => ['type' => 'datetime', 'nullable' => false],
            'criado_em' => ['type' => 'datetime'],
            'concluido_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_reservas_estado_expira' => ['columns' => ['estado', 'expira_em']],
            'uq_reservas_lote' => ['columns' => ['lote_id'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'lote_id', 'table' => 'lotes_importacao', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
];
