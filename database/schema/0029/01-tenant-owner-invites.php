<?php
declare(strict_types=1);

return [
    'tenant_owner_invites' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'token_hash' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'email_hash' => ['type' => 'varchar', 'length' => 255],
            'plano_id' => ['type' => 'integer', 'nullable' => false],
            'criado_por_usuario_id' => ['type' => 'integer', 'nullable' => false],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'expira_em' => ['type' => 'datetime', 'nullable' => false],
            'consumido_em' => ['type' => 'datetime'],
            'revogado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_tenant_owner_invite_token' => ['columns' => ['token_hash'], 'unique' => true],
            'ix_tenant_owner_invite_expiry' => ['columns' => ['expira_em', 'consumido_em', 'revogado_em']],
        ],
        'foreign_keys' => [
            ['column' => 'plano_id', 'table' => 'planos', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
            ['column' => 'criado_por_usuario_id', 'table' => 'usuarios', 'reference' => 'id', 'on_delete' => 'RESTRICT'],
        ],
    ],
];
