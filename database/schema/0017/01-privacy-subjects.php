<?php
declare(strict_types=1);

return [
    'privacy_subjects' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'referencia' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
            'tipo' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'tenant_id' => ['type' => 'integer'],
            'estado' => ['type' => 'varchar', 'length' => 20, 'nullable' => false, 'default' => 'ativo'],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
            'inativado_em' => ['type' => 'datetime'],
            'anonimizado_em' => ['type' => 'datetime'],
        ],
        'indexes' => [
            'uq_privacy_subject_reference' => ['columns' => ['referencia'], 'unique' => true],
            'ix_privacy_subject_lifecycle' => ['columns' => ['estado', 'inativado_em', 'id']],
        ],
        'foreign_keys' => [
            ['column' => 'tenant_id', 'table' => 'tenants', 'reference' => 'id', 'on_delete' => 'SET NULL'],
        ],
    ],
    'privacy_subject_records' => [
        'columns' => [
            'id' => ['type' => 'id'],
            'privacy_subject_id' => ['type' => 'integer', 'nullable' => false],
            'tipo_registro' => ['type' => 'varchar', 'length' => 40, 'nullable' => false],
            'registro_id' => ['type' => 'integer', 'nullable' => false],
            'criado_em' => ['type' => 'datetime', 'nullable' => false],
        ],
        'indexes' => [
            'uq_privacy_subject_record' => ['columns' => ['tipo_registro', 'registro_id'], 'unique' => true],
            'ix_privacy_subject_records_subject' => ['columns' => ['privacy_subject_id', 'tipo_registro']],
        ],
        'foreign_keys' => [
            ['column' => 'privacy_subject_id', 'table' => 'privacy_subjects', 'reference' => 'id', 'on_delete' => 'CASCADE'],
        ],
    ],
];
