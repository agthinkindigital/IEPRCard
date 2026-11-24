<?php
declare(strict_types=1);

require_once __DIR__ . '/business_telemetry.php';
require_once __DIR__ . '/schema_self_healing.php';
require_once __DIR__ . '/mcp_support_github.php';

/** @param list<string> $scopes @return list<array<string, mixed>> */
function mcpToolRegistry(array $scopes, bool $support = false): array
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
    if ($support) {
        $definitions[] = [
            'name' => 'repo.publish',
            'title' => 'Publicar correção em DEV',
            'description' => 'Substitui arquivos de texto já rastreados no repositório IEPRCard e cria um commit não forçado somente em DEV. Retorna SHA e estado dos três checks obrigatórios. Não aceita arquivos sensíveis, arquivos novos ou alteração da configuração de CI.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 64],
                    'expected_head_sha' => ['type' => 'string', 'pattern' => '^[a-f0-9]{40}$'],
                    'commit_message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'files' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 20,
                        'items' => [
                            'type' => 'object',
                            'properties' => ['path' => ['type' => 'string', 'maxLength' => 300], 'content' => ['type' => 'string', 'maxLength' => 262144]],
                            'required' => ['path', 'content'], 'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['idempotency_key', 'expected_head_sha', 'commit_message', 'files'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'branch' => ['type' => 'string', 'enum' => ['DEV']],
                    'commit_sha' => ['type' => 'string'],
                    'ci_status' => ['type' => 'string', 'enum' => ['success', 'pending', 'failure']],
                    'checks' => ['type' => 'object'],
                    'idempotent_replay' => ['type' => 'boolean'],
                ],
                'required' => ['branch', 'commit_sha', 'ci_status', 'checks', 'idempotent_replay'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
            'scope' => 'repo.publish',
        ];
    }
    if ($support) {
        foreach ($definitions as &$definition) {
            if ($definition['name'] === 'telemetry.read') {
                $definition['inputSchema']['properties']['tenant_id'] = ['type' => 'integer', 'minimum' => 1];
                $definition['inputSchema']['properties']['subcliente_id'] = ['type' => 'integer', 'minimum' => 1];
                $definition['inputSchema']['required'][] = 'tenant_id';
            }
        }
        unset($definition);
    }
    $visible = array_values(array_filter($definitions, static fn(array $tool): bool => in_array($tool['scope'], $scopes, true)));
    foreach ($visible as &$tool) {
        unset($tool['scope']);
    }
    unset($tool);
    return $visible;
}

/** @param list<string> $scopes @param array<string,mixed> $principal @return array{result?:array<string,mixed>,error?:array{code:int,message:string}} */
function mcpToolCall(string $name, mixed $arguments, array $scopes, ?PDO $pdo = null, ?array $principal = null): array
{
    $tool = null;
    $support = ($principal['kind'] ?? null) === 'support';
    foreach (mcpToolRegistry($scopes, $support) as $candidate) {
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
        $allowedArguments = $support ? ['from', 'to', 'tenant_id', 'subcliente_id'] : ['from', 'to'];
        if (!is_array($arguments) || array_diff(array_keys($arguments), $allowedArguments) !== []
            || !is_string($arguments['from'] ?? null) || !is_string($arguments['to'] ?? null)
            || !$pdo instanceof PDO || !is_array($principal)) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        if ($support) {
            $tenantId = filter_var($arguments['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $subclientId = array_key_exists('subcliente_id', $arguments)
                ? filter_var($arguments['subcliente_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            if ($tenantId === false || $subclientId === false
                || !mcpSupportGrantAllowsTarget($principal, (int) $tenantId, $subclientId === null ? null : (int) $subclientId)) {
                return ['error' => ['code' => -32602, 'message' => 'Target is outside the support grant.']];
            }
        } elseif (count($arguments) !== 2) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        try {
            $window = ['from' => $arguments['from'], 'to' => $arguments['to']];
            $rows = $support
                ? telemetryBusinessReadForSupportMcp($pdo, (int) $tenantId, $subclientId === null ? null : (int) $subclientId, $window)
                : telemetryBusinessReadForMcp($pdo, ['tenant_id' => $principal['tenant_id'] ?? null], $window);
            $structured = ['rows' => $rows];
        } catch (InvalidArgumentException) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid telemetry date range.']];
        } catch (DomainException) {
            return ['error' => ['code' => -32603, 'message' => 'Tool execution failed.']];
        } catch (Throwable) {
            return ['error' => ['code' => -32603, 'message' => 'Tool execution failed.']];
        }
    } elseif ($name === 'repo.publish') {
        if (!$support || !is_array($arguments) || array_diff(array_keys($arguments), ['idempotency_key', 'expected_head_sha', 'commit_message', 'files']) !== []
            || count($arguments) !== 4 || !$pdo instanceof PDO || !is_array($principal)) {
            return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
        }
        try {
            $published = mcpSupportGithubPublish($pdo, $principal, $arguments);
            $structured = [
                'branch' => $published['branch'], 'commit_sha' => $published['commit_sha'],
                'ci_status' => $published['ci_status'], 'checks' => $published['checks'],
                'idempotent_replay' => $published['idempotent_replay'],
            ];
        } catch (DomainException $exception) {
            $errorCode = in_array($exception->getMessage(), ['MCP_SUPPORT_GITHUB_OPERATION_PENDING', 'MCP_SUPPORT_GITHUB_NOT_READY', 'MCP_SUPPORT_GITHUB_HEAD_CHANGED'], true)
                ? -32010 : -32603;
            return ['error' => ['code' => $errorCode, 'message' => 'Repository operation failed.']];
        } catch (Throwable) {
            return ['error' => ['code' => -32603, 'message' => 'Repository operation failed.']];
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

/** @param array<string,mixed> $principal */
function mcpSupportGrantAllowsTarget(array $principal, int $tenantId, ?int $subclientId): bool
{
    if (($principal['kind'] ?? null) !== 'support' || !is_array($principal['targets'] ?? null)) {
        return false;
    }
    foreach ($principal['targets'] as $target) {
        if (is_array($target) && ($target['tenant_id'] ?? null) === $tenantId
            && ($target['subcliente_id'] ?? null) === $subclientId) {
            return true;
        }
    }
    return false;
}
