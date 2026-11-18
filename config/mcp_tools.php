<?php
declare(strict_types=1);

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
    ];
    $visible = array_values(array_filter($definitions, static fn(array $tool): bool => in_array($tool['scope'], $scopes, true)));
    foreach ($visible as &$tool) {
        unset($tool['scope']);
    }
    unset($tool);
    return $visible;
}

/** @param list<string> $scopes @return array{result?:array<string,mixed>,error?:array{code:int,message:string}} */
function mcpToolCall(string $name, mixed $arguments, array $scopes): array
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
    if (!is_array($arguments) || $arguments !== []) {
        return ['error' => ['code' => -32602, 'message' => 'Invalid tool arguments.']];
    }
    $structured = [
        'service' => 'IEPRCard',
        'status' => 'ready',
        'protocolVersion' => '2026-07-28',
    ];
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
