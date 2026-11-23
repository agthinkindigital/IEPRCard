<?php
declare(strict_types=1);

require_once __DIR__ . '/business_telemetry.php';
require_once __DIR__ . '/schema_self_healing.php';

/** @param list<string> $scopes @return list<array<string, mixed>> */
function mcpToolRegistry(array $scopes): array
{
    $definitions = [
        [
            'name' => 'system.read',
            'title' => 'Estado mínimo do serviço',
            'description' => 'Retorna somente a prontidão lógica e a versão MCP pública do serviço.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'protocolVersion' => ['type' => 'string'],
                ],
                'required' => ['service', 'status', 'protocolVersion'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            'scope' => 'system.read',
        ],
        [
            'name' => 'telemetry.read',
            'title' => 'Consultar telemetria diária da rede',
            'description' => 'Retorna agregados de negócio da Sede vinculada à credencial e de seus Subclientes em uma janela UTC limitada.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date'],
                    'to' => ['type' => 'string', 'format' => 'date'],
                ],
                'required' => ['from', 'to'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'rows' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'dia' => ['type' => 'string', 'format' => 'date'],
                                'categoria' => ['type' => 'string', 'enum' => array_keys(BUSINESS_TELEMETRY_EVENTS)],
                                'evento_chave' => ['type' => 'string'],
                                'quantidade' => ['type' => 'integer', 'minimum' => 0],
                            ],
                            'required' => ['dia', 'categoria', 'evento_chave', 'quantidade'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['rows'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            'scope' => 'telemetry.read',
        ],
        [
            'name' => 'db.inspect',
            'title' => 'Inspecionar estrutura do schema',
            'description' => 'Retorna a estrutura do catálogo canônico e divergências estruturais, sem ler linhas de aplicação.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['tabela' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'driver' => ['type' => 'string', 'enum' => ['mysql', 'sqlite']],
                    'tables' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'present' => ['type' => 'boolean'],
                                'columns' => ['type' => 'array', 'items' => ['type' => 'object']],
                                'indexes' => ['type' => 'array', 'items' => ['type' => 'object']],
                                'foreign_keys' => ['type' => 'array', 'items' => ['type' => 'object']],
                            ],
                            'required' => ['name', 'present', 'columns', 'indexes', 'foreign_keys'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'drift' => ['type' => 'object'],
                ],
                'required' => ['driver', 'tables', 'drift'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            'scope' => 'db.inspect',
        ],
    ];
    $visible = array_values(array_filter($definitions, static fn(array $tool): bool => in_array($tool['scope'], $scopes, true)));
    foreach ($visible as &$tool) {
        unset($tool['scope']);
    }
    unset($tool);
    return $visible;
}

/** @param list<string> $scopes @param array{id:int,tenant_id:int,client_id:string,scopes:list<string>} $principal @return array{result?:array<string,mixed>,error?:array{code:int,message:string}} */
function mcpToolCall(string $name, mixed $arguments, array $scopes, ?PDO $pdo = null, ?array $principal = null): array
{
    $tool = null;
    foreach (mcpToolRegistry($scopes) as $candidate) {
        if ($candidate['name'] === $name) {
            $tool = $candidate;
            break;
        }
    }
    if ($tool === null) {
        return ['error' => ['code' => -32602, 'message' => 'Unknown tool.']];
    }
    if ($name === 'system.read' && (!is_array($arguments) || $arguments !== [])) {
        return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
    }
    if ($name === 'telemetry.read') {
        if (!is_array($arguments) || count($arguments) !== 2 || array_diff(array_keys($arguments), ['from', 'to']) !== []
            || !is_string($arguments['from'] ?? null) || !is_string($arguments['to'] ?? null)
            || !$pdo instanceof PDO || !is_array($principal)) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        try {
            $structured = ['rows' => telemetryBusinessReadForMcp($pdo, ['tenant_id' => $principal['tenant_id'] ?? null], [
                'from' => $arguments['from'], 'to' => $arguments['to'],
            ])];
        } catch (InvalidArgumentException) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid telemetry date range.']];
        } catch (DomainException) {
            return ['error' => ['code' => -32603, 'message' => 'Tool execution failed.']];
        } catch (Throwable) {
            return ['error' => ['code' => -32603, 'message' => 'Tool execution failed.']];
        }
    } elseif ($name === 'db.inspect') {
        $hasTableArgument = is_array($arguments) && array_key_exists('tabela', $arguments);
        if (!is_array($arguments) || count($arguments) > 1 || array_diff(array_keys($arguments), ['tabela']) !== []
            || $hasTableArgument && (!is_string($arguments['tabela']) || $arguments['tabela'] === '')
            || !$pdo instanceof PDO) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
        $tableFilter = $hasTableArgument ? $arguments['tabela'] : null;
        if ($tableFilter !== null && !array_key_exists($tableFilter, $catalog)) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        try {
            $driver = \IEPRCard\Database\Schema\identificar($pdo);
            $existingTables = \IEPRCard\Database\Schema\tabelasExistentes($pdo, $driver);
            $inspectionCatalog = $tableFilter === null ? null : [$tableFilter => $catalog[$tableFilter]];
            $report = schemaInspecionar($pdo, $inspectionCatalog);
            $tables = [];
            foreach ($catalog as $table => $definition) {
                if ($tableFilter !== null && $table !== $tableFilter) {
                    continue;
                }
                $tableExists = in_array($table, $existingTables, true);
                $existingColumns = $tableExists ? \IEPRCard\Database\Schema\colunasExistentes($pdo, $table, $driver) : [];
                $columns = [];
                foreach ($definition['columns'] as $column => $metadata) {
                    $structuralMetadata = [
                        'name' => $column,
                        'type' => $metadata['type'],
                        'nullable' => $metadata['type'] === 'id' ? false : ($metadata['nullable'] ?? true),
                        'present' => in_array($column, $existingColumns, true),
                    ];
                    foreach (['length', 'precision', 'scale'] as $attribute) {
                        if (isset($metadata[$attribute])) {
                            $structuralMetadata[$attribute] = $metadata[$attribute];
                        }
                    }
                    $columns[] = $structuralMetadata;
                }
                $indexes = [];
                foreach ($definition['indexes'] as $index => $metadata) {
                    $indexes[] = ['name' => $index, 'columns' => $metadata['columns'], 'unique' => $metadata['unique'] ?? false];
                }
                $foreignKeys = array_map(static fn(array $key): array => [
                    'column' => $key['column'],
                    'table' => $key['table'],
                    'reference' => $key['reference'],
                    'on_delete' => strtoupper($key['on_delete']),
                ], $definition['foreign_keys']);
                $tables[] = ['name' => $table, 'present' => $tableExists, 'columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
            }
            $drift = [];
            foreach ($report as $kind => $items) {
                $drift[$kind] = $tableFilter === null
                    ? $items
                    : array_values(array_filter($items, static fn(string $item): bool => $item === $tableFilter || str_starts_with($item, $tableFilter . '.')));
            }
            $structured = ['driver' => $driver, 'tables' => $tables, 'drift' => $drift];
        } catch (Throwable) {
            return ['error' => ['code' => -32603, 'message' => 'Tool execution failed.']];
        }
    } else {
        $structured = [
            'service' => 'IEPRCard',
            'status' => 'ready',
            'protocolVersion' => '2026-07-28',
        ];
    }
    return ['result' => [
        'resultType' => 'complete',
        'content' => [[
            'type' => 'text',
            'text' => json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]],
        'structuredContent' => $structured,
        'isError' => false,
    ]];
}
