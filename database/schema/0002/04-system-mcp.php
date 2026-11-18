<?php
declare(strict_types=1);

return [
    'mcp_api_keys' => [
        'columns' => [
            'client_id' => ['type' => 'varchar', 'length' => 80],
        ],
        'indexes' => [
            'uq_mcp_client_id' => ['columns' => ['client_id'], 'unique' => true],
        ],
    ],
];
