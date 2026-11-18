<?php
declare(strict_types=1);

return [
    'mcp_api_keys' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'usuario_id' => ['type' => 'integer'],
            'nome' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'chave_hash' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            'escopos_json' => ['type' => 'text', 'nullable' => false],
            'limite_requisicoes_minuto' => ['type' => 'integer', 'nullable' => false, 'default' => 60],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 1],
            'ultimo_uso_em' => ['type' => 'datetime'],
            'expira_em' => ['type' => 'datetime'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_mcp_chave_hash' => ['columns' => ['chave_hash'], 'unique' => true],
            'ix_mcp_keys_tenant_ativo' => ['columns' => ['tenant_id', 'ativo']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'mcp_audit_logs' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer'],
            'api_key_id' => ['type' => 'integer'],
            'correlation_id' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'metodo' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'escopo' => ['type' => 'varchar', 'length' => 80],
            'resultado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'duracao_ms' => ['type' => 'integer'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_mcp_audit_tenant_data' => ['columns' => ['tenant_id', 'criado_em']],
            'ix_mcp_audit_correlation' => ['columns' => ['correlation_id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'api_key_id', 'table' => 'mcp_api_keys', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'configuracoes_operacionais' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'versao' => ['type' => 'integer', 'nullable' => false],
            'ambiente' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'configuracao_json' => ['type' => 'text', 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'pendente'],
            'alterado_por_usuario_id' => ['type' => 'integer'],
            'criado_em' => ['type' => 'datetime'],
            'ativado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_config_operacional_versao' => ['columns' => ['ambiente', 'versao'], 'unique' => true],
            'ix_config_operacional_estado' => ['columns' => ['estado']],
        ],
        'foreign_keys' => [
            ['column' => 'alterado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'metricas_lotes_diarias' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'dia' => ['type' => 'date', 'nullable' => false],
            'ambiente' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'pico_demanda_diario' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'registros_processados' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'duracao_media_ms' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'fila_maxima' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'falhas' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'versao_configuracao_id' => ['type' => 'integer'],
        ],
        'indexes' => [
            'uq_metricas_lote_dia_ambiente' => ['columns' => ['dia', 'ambiente'], 'unique' => true],
            'ix_metricas_lotes_configuracao' => ['columns' => ['versao_configuracao_id']],
        ],
        'foreign_keys' => [
            ['column' => 'versao_configuracao_id', 'table' => 'configuracoes_operacionais', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'solicitacoes_anonimizacao' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'solicitacao_id' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'referencia_titular_cifrada' => ['type' => 'text', 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'pendente'],
            'solicitado_em' => ['type' => 'datetime', 'nullable' => false],
            'prazo_em' => ['type' => 'datetime', 'nullable' => false],
            'concluido_em' => ['type' => 'datetime'],
            'tentativas' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'correlation_id' => ['type' => 'varchar', 'length' => 100],
        ],
        'indexes' => [
            'uq_solicitacao_anonimizacao_id' => ['columns' => ['solicitacao_id'], 'unique' => true],
            'ix_solicitacao_anonimizacao_estado_prazo' => ['columns' => ['estado', 'prazo_em']],
        ],
        'foreign_keys' => [],
    ],
];
