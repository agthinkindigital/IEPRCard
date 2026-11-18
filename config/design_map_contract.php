<?php
declare(strict_types=1);

/** @return array{valid: bool, errors: list<array{code: string, path: string}>} */
function designMapValidate(mixed $source): array
{
    if (is_string($source)) {
        if (strlen($source) > 262144) {
            return designMapValidationFailure('map_too_large', '$');
        }
        try {
            $map = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return designMapValidationFailure('invalid_json', '$');
        }
    } elseif (is_array($source)) {
        $map = $source;
    } else {
        return designMapValidationFailure('invalid_map', '$');
    }

    if (!is_array($map) || array_is_list($map)) {
        return designMapValidationFailure('invalid_map', '$');
    }
    foreach (array_keys($map) as $key) {
        if (!in_array($key, ['versao_schema', 'dimensoes', 'elementos'], true)) {
            return designMapValidationFailure('invalid_map', '$');
        }
    }
    if (!isset($map['versao_schema']) || !is_string($map['versao_schema'])) {
        return designMapValidationFailure('invalid_map', '$.versao_schema');
    }
    if ($map['versao_schema'] !== '1.0') {
        return designMapValidationFailure('unsupported_schema_version', '$.versao_schema');
    }

    $dimensions = $map['dimensoes'] ?? null;
    $hasCr80Dimensions = is_array($dimensions)
        && count($dimensions) === 4
        && ($dimensions['largura_px'] ?? null) === 1011
        && ($dimensions['altura_px'] ?? null) === 638
        && ($dimensions['dpi'] ?? null) === 300
        && ($dimensions['padrao'] ?? null) === 'CR80';

    if (isset($map['elementos']) && is_array($map['elementos']) && count($map['elementos']) > 100) {
        return designMapValidationFailure('too_many_elements', '$.elementos');
    }

    if (!$hasCr80Dimensions
        || !isset($map['elementos'])
        || !is_array($map['elementos'])
        || !array_is_list($map['elementos'])) {
        return designMapValidationFailure('invalid_map', '$');
    }

    $seenElementIds = [];
    foreach ($map['elementos'] as $index => $candidate) {
        $id = is_array($candidate) ? ($candidate['id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1) {
            return designMapValidationFailure('invalid_element', '$.elementos[' . $index . '].id');
        }
        if (isset($seenElementIds[$id])) {
            return designMapValidationFailure('duplicate_element_id', '$.elementos[' . $index . '].id');
        }
        $seenElementIds[$id] = true;
    }

    $elementContracts = [
        'texto_dinamico' => [
            'required' => [
                'id', 'lado', 'tipo', 'chave_variavel', 'x', 'y', 'largura_max',
                'fonte_arquivo', 'tamanho_pt', 'cor_hex', 'alinhamento',
            ],
            'optional' => ['rotulo_amigavel', 'obrigatorio', 'transformacao', 'auto_reduzir_fonte_se_longo'],
            'width' => 'largura_max',
            'height' => null,
            'variable' => null,
        ],
        'foto_titular' => [
            'required' => ['id', 'lado', 'tipo', 'chave_variavel', 'x', 'y', 'largura', 'altura'],
            'optional' => ['borda_raio', 'borda_espessura', 'borda_cor', 'placeholder_padrao'],
            'width' => 'largura',
            'height' => 'altura',
            'variable' => 'foto',
        ],
        'qrcode_validacao' => [
            'required' => ['id', 'lado', 'tipo', 'chave_variavel', 'x', 'y', 'tamanho'],
            'optional' => ['margem_quiet_zone'],
            'width' => 'tamanho',
            'height' => 'tamanho',
            'variable' => '_sistema_url_validacao',
        ],
        'codigo_barras' => [
            'required' => ['id', 'lado', 'tipo', 'chave_variavel', 'formato', 'x', 'y', 'largura', 'altura'],
            'optional' => [],
            'width' => 'largura',
            'height' => 'altura',
            'variable' => null,
        ],
    ];

    foreach ($map['elementos'] as $index => $element) {
        $elementPath = '$.elementos[' . $index . ']';
        if (!is_array($element) || !in_array($element['lado'] ?? null, ['frente', 'verso'], true)) {
            return designMapValidationFailure('invalid_element', $elementPath);
        }

        $type = $element['tipo'] ?? null;
        $contract = is_string($type) ? ($elementContracts[$type] ?? null) : null;
        if ($contract === null) {
            return designMapValidationFailure('invalid_element', $elementPath);
        }
        $missingProperties = array_values(array_diff($contract['required'], array_keys($element)));
        if ($missingProperties !== []) {
            return designMapValidationFailure('invalid_element', $elementPath . '.' . $missingProperties[0]);
        }
    foreach (array_keys($element) as $property) {
            if (!in_array($property, [...$contract['required'], ...$contract['optional']], true)) {
                return designMapValidationFailure('invalid_element', $elementPath);
            }
        }

        if ($contract['variable'] !== null && $element['chave_variavel'] !== $contract['variable']) {
            return designMapValidationFailure('invalid_element', $elementPath . '.chave_variavel');
        }
        if (!is_string($element['chave_variavel'])
            || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,119}$/D', $element['chave_variavel']) !== 1) {
            return designMapValidationFailure('invalid_variable_key', $elementPath . '.chave_variavel');
        }
        if ($type === 'codigo_barras' && !in_array($element['formato'], ['CODE128', 'EAN13'], true)) {
            return designMapValidationFailure('invalid_element', $elementPath . '.formato');
        }
        if ($type === 'texto_dinamico'
            && (!is_string($element['cor_hex']) || preg_match('/^#[A-Fa-f0-9]{6}$/D', $element['cor_hex']) !== 1)) {
            return designMapValidationFailure('invalid_element', $elementPath . '.cor_hex');
        }
        if ($type === 'texto_dinamico' && !in_array($element['alinhamento'], ['left', 'center', 'right'], true)) {
            return designMapValidationFailure('invalid_element', $elementPath . '.alinhamento');
        }
        if ($type === 'texto_dinamico') {
            $fontSize = $element['tamanho_pt'];
            if ((!is_int($fontSize) && !is_float($fontSize)) || !is_finite((float) $fontSize) || $fontSize <= 0) {
                return designMapValidationFailure('invalid_element', $elementPath . '.tamanho_pt');
            }
            if (!is_string($element['fonte_arquivo'])
                || preg_match('/^[A-Za-z0-9_-]+\.(?:ttf|otf)$/iD', $element['fonte_arquivo']) !== 1) {
                return designMapValidationFailure('invalid_element', $elementPath . '.fonte_arquivo');
            }
            if (array_key_exists('rotulo_amigavel', $element)
                && (!is_string($element['rotulo_amigavel']) || trim($element['rotulo_amigavel']) === '')) {
                return designMapValidationFailure('invalid_element', $elementPath . '.rotulo_amigavel');
            }
            if (array_key_exists('obrigatorio', $element) && !is_bool($element['obrigatorio'])) {
                return designMapValidationFailure('invalid_element', $elementPath . '.obrigatorio');
            }
            if (array_key_exists('transformacao', $element)
                && !in_array($element['transformacao'], ['uppercase', 'lowercase', 'capitalize', 'none'], true)) {
                return designMapValidationFailure('invalid_element', $elementPath . '.transformacao');
            }
            if (array_key_exists('auto_reduzir_fonte_se_longo', $element)
                && !is_bool($element['auto_reduzir_fonte_se_longo'])) {
                return designMapValidationFailure('invalid_element', $elementPath . '.auto_reduzir_fonte_se_longo');
            }
        }
        if ($type === 'foto_titular') {
            foreach (['borda_raio', 'borda_espessura'] as $property) {
                if (array_key_exists($property, $element)
                    && ((!is_int($element[$property]) && !is_float($element[$property]))
                        || !is_finite((float) $element[$property]) || $element[$property] < 0)) {
                    return designMapValidationFailure('invalid_element', $elementPath . '.' . $property);
                }
            }
            if (array_key_exists('borda_cor', $element)
                && (!is_string($element['borda_cor']) || preg_match('/^#[A-Fa-f0-9]{6}$/D', $element['borda_cor']) !== 1)) {
                return designMapValidationFailure('invalid_element', $elementPath . '.borda_cor');
            }
            if (array_key_exists('placeholder_padrao', $element)
                && (!is_string($element['placeholder_padrao'])
                    || preg_match('/^assets\/img\/[A-Za-z0-9_-]+\.(?:png|jpe?g|webp)$/iD', $element['placeholder_padrao']) !== 1)) {
                return designMapValidationFailure('invalid_element', $elementPath . '.placeholder_padrao');
            }
        }

        $widthField = $contract['width'];
        $heightField = $contract['height'];
        $geometry = ['x' => $element['x'], 'y' => $element['y'], $widthField => $element[$widthField]];
        if ($heightField !== null) {
            $geometry[$heightField] = $element[$heightField];
        }
        foreach ($geometry as $name => $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                return designMapValidationFailure('invalid_geometry', $elementPath . '.' . $name);
            }
        }

        $x = $element['x'];
        $y = $element['y'];
        $width = $element[$widthField];
        $height = $heightField === null ? null : $element[$heightField];
        if ($x < 0 || $x + $width > 1011) {
            return designMapValidationFailure('geometry_out_of_bounds', $elementPath . '.x');
        }
        if ($y < 0 || $y >= 638 || $y + ($height ?? 0) > 638) {
            return designMapValidationFailure('geometry_out_of_bounds', $elementPath . '.y');
        }
        if ($width <= 0) {
            return designMapValidationFailure('geometry_out_of_bounds', $elementPath . '.' . $widthField);
        }
        if ($height !== null && $height <= 0) {
            return designMapValidationFailure('geometry_out_of_bounds', $elementPath . '.' . $heightField);
        }

        if ($type === 'qrcode_validacao' && array_key_exists('margem_quiet_zone', $element)) {
            $quietZone = $element['margem_quiet_zone'];
            if ((!is_int($quietZone) && !is_float($quietZone)) || !is_finite((float) $quietZone) || $quietZone < 0) {
                return designMapValidationFailure('invalid_element', $elementPath . '.margem_quiet_zone');
            }
        }
    }

    return ['valid' => true, 'errors' => []];
}

/** @return array{valid: false, errors: list<array{code: string, path: string}>} */
function designMapValidationFailure(string $code, string $path): array
{
    return ['valid' => false, 'errors' => [['code' => $code, 'path' => $path]]];
}
