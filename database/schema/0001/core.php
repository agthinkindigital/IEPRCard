<?php
declare(strict_types=1);

return [
    'planos' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'nome' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'descricao' => ['type' => 'text'],
            'preco' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'default' => 0],
            'permissoes_default_json' => ['type' => 'text'],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 1],
            'ordem' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_planos_nome' => ['columns' => ['nome'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
    'plano_feature_flags' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'plano_id' => ['type' => 'integer', 'nullable' => false],
            'feature_key' => ['type' => 'varchar', 'length' => 100, 'nullable' => false],
            'habilitado' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'limite_valor' => ['type' => 'integer'],
        ],
        'indexes' => [
            'uq_plano_feature' => ['columns' => ['plano_id', 'feature_key'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'plano_id', 'table' => 'planos', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
    'tenants' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'parent_id' => ['type' => 'integer'],
            'plano_id' => ['type' => 'integer'],
            'tipo' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'cliente'],
            'nome_organizacao' => ['type' => 'varchar', 'length' => 180, 'nullable' => false],
            'nome_responsavel' => ['type' => 'varchar', 'length' => 180],
            'cargo_responsavel' => ['type' => 'varchar', 'length' => 120],
            'slug_subdominio' => ['type' => 'varchar', 'length' => 120],
            'status_conta' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'pendente'],
            'regime_cobranca' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'cortesia_admin'],
            'isento_pagamento' => ['type' => 'boolean', 'nullable' => false, 'default' => 0],
            'trial_expira_em' => ['type' => 'datetime'],
            'limite_subclientes' => ['type' => 'integer'],
            'limite_usuarios_equipe' => ['type' => 'integer'],
            'limite_titulares' => ['type' => 'integer'],
            'limite_carteirinhas' => ['type' => 'integer'],
            'consumo_titulares' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'consumo_carteirinhas' => ['type' => 'integer', 'nullable' => false, 'default' => 0],
            'assinatura_responsavel_url' => ['type' => 'varchar', 'length' => 500],
            'permissoes_unidade_json' => ['type' => 'text'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'ix_tenants_parent' => ['columns' => ['parent_id']],
            'ix_tenants_plano' => ['columns' => ['plano_id']],
            'uq_tenants_slug' => ['columns' => ['slug_subdominio'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'parent_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'plano_id', 'table' => 'planos', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
    'dominios_tenant' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer', 'nullable' => false],
            'hostname' => ['type' => 'varchar', 'length' => 253, 'nullable' => false],
            'estado' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'pendente_verificacao'],
            'token_verificacao_hash' => ['type' => 'varchar', 'length' => 255],
            'verificado_em' => ['type' => 'datetime'],
            'tls_validado_em' => ['type' => 'datetime'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_dominios_hostname' => ['columns' => ['hostname'], 'unique' => true],
            'ix_dominios_tenant_estado' => ['columns' => ['tenant_id', 'estado']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'perfis_acesso' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer'],
            'nome_perfil' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'escopo_permitido' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'permissoes_acoes_json' => ['type' => 'text'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_perfis_tenant_nome' => ['columns' => ['tenant_id', 'nome_perfil'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
    'permissoes' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'chave' => ['type' => 'varchar', 'length' => 120, 'nullable' => false],
            'descricao' => ['type' => 'varchar', 'length' => 255],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_permissoes_chave' => ['columns' => ['chave'], 'unique' => true],
        ],
        'foreign_keys' => [],
    ],
    'usuarios' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'tenant_id' => ['type' => 'integer'],
            'subcliente_id' => ['type' => 'integer'],
            'perfil_acesso_id' => ['type' => 'integer'],
            'criado_por_usuario_id' => ['type' => 'integer'],
            'papel_sistema' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'nome' => ['type' => 'varchar', 'length' => 180, 'nullable' => false],
            'email' => ['type' => 'varchar', 'length' => 254, 'nullable' => false],
            'senha_hash' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
            'ativo' => ['type' => 'boolean', 'nullable' => false, 'default' => 1],
            'ultimo_login_em' => ['type' => 'datetime'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_usuarios_tenant_email' => ['columns' => ['tenant_id', 'email'], 'unique' => true],
            'ix_usuarios_tenant_subcliente' => ['columns' => ['tenant_id', 'subcliente_id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'perfil_acesso_id', 'table' => 'perfis_acesso', 'reference' => 'id', 'on_delete' => 'SET NULL'],
            ['column' => 'criado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'usuario_permissoes' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'usuario_id' => ['type' => 'integer', 'nullable' => false],
            'permissao_id' => ['type' => 'integer', 'nullable' => false],
            'subcliente_id' => ['type' => 'integer'],
            'permitido' => ['type' => 'boolean', 'nullable' => false, 'default' => 1],
            'criado_por_usuario_id' => ['type' => 'integer'],
            'criado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_usuario_permissao_escopo' => ['columns' => ['usuario_id', 'permissao_id', 'subcliente_id'], 'unique' => true],
        ],
        'foreign_keys' => [
            ['column' => 'usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'permissao_id', 'table' => 'permissoes', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'subcliente_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'CASCADE'],
            ['column' => 'criado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
];
