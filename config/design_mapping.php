<?php
declare(strict_types=1);

require_once __DIR__ . '/design_map_contract.php';

/** @return array{valid: bool, values: array<string, string>, errors: list<array{code: string, path: string}>} */
function designMappingResolve(mixed $design, mixed $mapping, mixed $record, mixed $systemContext = []): array
{
    $designValidation = designMapValidate($design);
    if (!$designValidation['valid']) {
        return designMappingFailure('invalid_design', '$.design');
    }
    if (is_string($design)) {
        try {
            $design = json_decode($design, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return designMappingFailure('invalid_design', '$.design');
        }
    }
    $mappingValue = designMappingDecode($mapping);
    if (!is_array($mappingValue)
        || array_is_list($mappingValue)
        || count(array_diff(array_keys($mappingValue), ['versao_schema', 'mapeamentos'])) > 0
        || ($mappingValue['versao_schema'] ?? null) !== '1.0'
        || !isset($mappingValue['mapeamentos'])
        || !is_array($mappingValue['mapeamentos'])
        || (array_is_list($mappingValue['mapeamentos']) && $mappingValue['mapeamentos'] !== [])) {
        return designMappingFailure('invalid_mapping', '$');
    }
    if (!is_array($record) || (array_is_list($record) && $record !== [])) {
        return designMappingFailure('invalid_record', '$.registro');
    }
    if (!is_array($systemContext) || (array_is_list($systemContext) && $systemContext !== [])) {
        return designMappingFailure('invalid_system_context', '$.contexto_sistema');
    }

    $variables = [];
    foreach ($design['elementos'] as $element) {
        $key = $element['chave_variavel'];
        if (!isset($variables[$key])) {
            $variables[$key] = ['required' => false, 'system' => str_starts_with($key, '_sistema_')];
        }
        if (($element['tipo'] ?? null) === 'texto_dinamico' && ($element['obrigatorio'] ?? false) === true) {
            $variables[$key]['required'] = true;
        }
    }

    $errors = [];
    foreach ($mappingValue['mapeamentos'] as $target => $definition) {
        if (!is_string($target) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,119}$/D', $target) !== 1) {
            $errors[] = ['code' => 'invalid_mapping', 'path' => '$.mapeamentos'];
            continue;
        }
        if (str_starts_with($target, '_sistema_')) {
            $errors[] = ['code' => 'reserved_system_variable', 'path' => '$.mapeamentos.' . $target];
        } elseif (!array_key_exists($target, $variables)) {
            $errors[] = ['code' => 'unknown_target_variable', 'path' => '$.mapeamentos.' . $target];
        } elseif (!designMappingDefinitionIsValid($definition)) {
            $errors[] = ['code' => 'invalid_mapping', 'path' => '$.mapeamentos.' . $target];
        }
    }
    foreach ($systemContext as $key => $value) {
        if ($key !== '_sistema_url_validacao' || !isset($variables[$key]) || !designMappingScalarToString($value, $converted)) {
            $errors[] = ['code' => 'invalid_system_context', 'path' => '$.contexto_sistema'];
        }
    }
    if ($errors !== []) {
        return ['valid' => false, 'values' => [], 'errors' => $errors];
    }

    $values = [];
    foreach ($variables as $key => $metadata) {
        if ($metadata['system']) {
            if (!array_key_exists($key, $systemContext) || !designMappingScalarToString($systemContext[$key], $value)) {
                $errors[] = ['code' => 'missing_system_value', 'path' => '$.contexto_sistema.' . $key];
            } elseif (strlen($value) > 8192) {
                $errors[] = ['code' => 'value_too_large', 'path' => '$.contexto_sistema.' . $key];
            } else {
                $values[$key] = $value;
            }
            continue;
        }

        if (!array_key_exists($key, $mappingValue['mapeamentos'])) {
            if ($metadata['required']) {
                $errors[] = ['code' => 'missing_required_mapping', 'path' => '$.mapeamentos.' . $key];
            } else {
                $values[$key] = '';
            }
            continue;
        }

        $value = designMappingEvaluate($mappingValue['mapeamentos'][$key], $record, $found);
        if (!$found || ($metadata['required'] && $value === '')) {
            if ($metadata['required']) {
                $errors[] = ['code' => 'missing_required_value', 'path' => '$.mapeamentos.' . $key];
            } else {
                $values[$key] = '';
            }
        } elseif (strlen($value) > 8192) {
            $errors[] = ['code' => 'value_too_large', 'path' => '$.mapeamentos.' . $key];
        } else {
            $values[$key] = $value;
        }
    }

    if ($errors !== []) {
        return ['valid' => false, 'values' => [], 'errors' => $errors];
    }
    return ['valid' => true, 'values' => $values, 'errors' => []];
}

function designMappingDecode(mixed $mapping): mixed
{
    if (is_string($mapping)) {
        if (strlen($mapping) > 262144) {
            return null;
        }
        try {
            return json_decode($mapping, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
    return $mapping;
}

function designMappingDefinitionIsValid(mixed $definition): bool
{
    if (!is_array($definition) || array_is_list($definition)) {
        return false;
    }
    if (($definition['tipo'] ?? null) === 'campo') {
        return count($definition) === 2
            && is_string($definition['origem'] ?? null)
            && designMappingPathIsValid($definition['origem']);
    }
    if (($definition['tipo'] ?? null) === 'valor') {
        return count($definition) === 2 && is_string($definition['valor'] ?? null)
            && strlen($definition['valor']) <= 8192;
    }
    if (($definition['tipo'] ?? null) !== 'template' || count($definition) !== 2
        || !is_array($definition['partes'] ?? null) || !array_is_list($definition['partes'])
        || count($definition['partes']) < 1 || count($definition['partes']) > 100) {
        return false;
    }
    foreach ($definition['partes'] as $part) {
        if (is_string($part) && strlen($part) <= 8192) {
            continue;
        }
        if (is_array($part) && !array_is_list($part) && count($part) === 1
            && is_string($part['campo'] ?? null) && designMappingPathIsValid($part['campo'])) {
            continue;
        }
        return false;
    }
    return true;
}

function designMappingPathIsValid(string $path): bool
{
    $segments = explode('.', $path);
    if (count($segments) > 16) {
        return false;
    }
    foreach ($segments as $segment) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,119}$/D', $segment) !== 1) {
            return false;
        }
    }
    return true;
}

function designMappingEvaluate(array $definition, array $record, ?bool &$found): string
{
    $found = true;
    if ($definition['tipo'] === 'valor') {
        return $definition['valor'];
    }
    if ($definition['tipo'] === 'campo') {
        $value = designMappingReadPath($record, $definition['origem'], $found);
        return $found && designMappingScalarToString($value, $converted) ? $converted : '';
    }

    $parts = [];
    foreach ($definition['partes'] as $part) {
        if (is_string($part)) {
            $parts[] = $part;
            continue;
        }
        $value = designMappingReadPath($record, $part['campo'], $partFound);
        if (!$partFound || !designMappingScalarToString($value, $converted)) {
            $found = false;
            return '';
        }
        $parts[] = $converted;
    }
    return implode('', $parts);
}

function designMappingReadPath(array $record, string $path, ?bool &$found): mixed
{
    $value = $record;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            $found = false;
            return null;
        }
        $value = $value[$segment];
    }
    $found = true;
    return $value;
}

function designMappingScalarToString(mixed $value, ?string &$converted): bool
{
    if (is_string($value) || is_int($value)) {
        $converted = (string) $value;
        return true;
    }
    if (is_float($value) && is_finite($value)) {
        $converted = (string) $value;
        return true;
    }
    if (is_bool($value)) {
        $converted = $value ? 'true' : 'false';
        return true;
    }
    $converted = null;
    return false;
}

/** @return array{valid: false, values: array{}, errors: list<array{code: string, path: string}>} */
function designMappingFailure(string $code, string $path): array
{
    return ['valid' => false, 'values' => [], 'errors' => [['code' => $code, 'path' => $path]]];
}
