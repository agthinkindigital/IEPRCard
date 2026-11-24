<?php
declare(strict_types=1);

return [
    'mcp_support_grants' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'public_id' => ['type' => 'varchar', 'length' => 80, 'nullable' => false],
            'segredo_hash' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            'rotulo' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'criado_por_usuario_id' => ['type' => 'integer'],
            'capacidades_json' => ['type' => 'text', 'nullable' => false],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'expira_em' => ['type' => 'datetime', 'nullable' => false],
            'revogado_em' => ['type' => 'datetime'],
            'ultimo_uso_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_mcp_support_grants_public_id' => ['columns' => ['public_id'], 'unique' => true],
            'ix_mcp_support_grants_expiry' => ['columns' => ['revogado_em', 'expira_em']],
        ],
        'foreign_keys' => [
            ['column' => 'criado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'mcp_support_grant_targets' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'grant_id' => ['type' => 'integer', 'nullable' => false],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_mcp_support_grant_target' => ['columns' => ['grant_id', 'tenant_id', 'subcliente_id'], 'unique' => true],
            'ix_mcp_support_target_tenant' => ['columns' => ['tenant_id', 'subcliente_id']],
        ],
        'foreign_keys' => [
            ['column' => 'grant_id', 'table' => 'mcp_support_grants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
    'mcp_support_audit_logs' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'actor_user_id' => ['type' => 'integer'],
            'grant_id' => ['type' => 'integer'],
            'capability' => ['type' => 'varchar', 'length' => 80],
            'tool' => ['type' => 'varchar', 'length' => 120],
            'target_tenant_id' => ['type' => 'integer'],
            'target_subcliente_id' => ['type' => 'integer'],
            'operation_key' => ['type' => 'varchar', 'length' => 64],
            'commit_sha' => ['type' => 'varchar', 'length' => 40],
            'result' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
            'correlation_id' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'duration_ms' => ['type' => 'integer'],
            'created_at' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'ix_mcp_support_audit_grant_date' => ['columns' => ['grant_id', 'created_at']],
            'ix_mcp_support_audit_actor_date' => ['columns' => ['actor_user_id', 'created_at']],
            'uq_mcp_support_audit_correlation' => ['columns' => ['correlation_id'], 'unique' => true],
            'uq_mcp_support_audit_operation' => ['columns' => ['operation_key'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'actor_user_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'grant_id', 'table' => 'mcp_support_grants', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'target_tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'target_subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'mcp_support_github_config' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'token_cifrado' => ['type' => 'longtext', 'nullable' => false],
            'configurado_por_usuario_id' => ['type' => 'integer'],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'atualizado_em' => ['type' => 'datetime', 'nullable' => false],
            'ultimo_teste_status' => ['type' => 'varchar', 'length' => 20],
            'ultimo_teste_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_mcp_support_github_updated' => ['columns' => ['atualizado_em']],
        ],
        'foreign_keys' => [
            ['column' => 'configurado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
