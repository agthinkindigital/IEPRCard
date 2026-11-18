<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/design_mapping.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$design = [
    'versao_schema' => '1.0',
    'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'],
    'elementos' => [
        ['id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
            'x' => 10, 'y' => 10, 'largura_max' => 300, 'fonte_arquivo' => 'Inter.ttf', 'tamanho_pt' => 12,
            'cor_hex' => '#000000', 'alinhamento' => 'left', 'obrigatorio' => true],
        ['id' => 'el_curso', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'curso_semestre',
            'x' => 10, 'y' => 30, 'largura_max' => 300, 'fonte_arquivo' => 'Inter.ttf', 'tamanho_pt' => 12,
            'cor_hex' => '#000000', 'alinhamento' => 'left'],
        ['id' => 'el_validade', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'validade',
            'x' => 10, 'y' => 50, 'largura_max' => 300, 'fonte_arquivo' => 'Inter.ttf', 'tamanho_pt' => 12,
            'cor_hex' => '#000000', 'alinhamento' => 'left'],
        ['id' => 'el_qr', 'lado' => 'verso', 'tipo' => 'qrcode_validacao', 'chave_variavel' => '_sistema_url_validacao',
            'x' => 10, 'y' => 80, 'tamanho' => 100],
    ],
];

$mapping = [
    'versao_schema' => '1.0',
    'mapeamentos' => [
        'nome' => ['tipo' => 'campo', 'origem' => 'titular.nome_completo'],
        'curso_semestre' => ['tipo' => 'template', 'partes' => [
            ['campo' => 'Curso'], ' - ', ['campo' => 'Semestre'], 'º Sem',
        ]],
        'validade' => ['tipo' => 'valor', 'valor' => 'Validade: 12/2027'],
    ],
];

$source = ['titular' => ['nome_completo' => 'Ada Lovelace'], 'Curso' => 'Matemática', 'Semestre' => 2];
$result = designMappingResolve($design, $mapping, $source, ['_sistema_url_validacao' => 'https://example.test/c/abc']);
if ($result !== ['valid' => true, 'values' => [
    'nome' => 'Ada Lovelace',
    'curso_semestre' => 'Matemática - 2º Sem',
    'validade' => 'Validade: 12/2027',
    '_sistema_url_validacao' => 'https://example.test/c/abc',
], 'errors' => []]) {
    throw new RuntimeException('Field, template, literal, and explicit system values should resolve deterministically.');
}

$fixedMapping = [
    'versao_schema' => '1.0',
    'mapeamentos' => ['nome' => ['tipo' => 'campo', 'origem' => 'titular.nome_completo']],
];
$result = designMappingResolve($design, $fixedMapping, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['valid'] ?? null) !== true || ($result['values']['curso_semestre'] ?? null) !== ''
    || ($result['values']['validade'] ?? null) !== '') {
    throw new RuntimeException('A fixed mapping may omit optional design variables, which resolve to empty strings.');
}

$missingRequiredMapping = ['versao_schema' => '1.0', 'mapeamentos' => []];
$result = designMappingResolve($design, $missingRequiredMapping, $source, ['_sistema_url_validacao' => 'qr']);
if ($result !== ['valid' => false, 'values' => [], 'errors' => [
    ['code' => 'missing_required_mapping', 'path' => '$.mapeamentos.nome'],
]]) {
    throw new RuntimeException('A required design variable without mapping should fail with a stable path.');
}

$result = designMappingResolve($design, $fixedMapping, $source);
if ($result !== ['valid' => false, 'values' => [], 'errors' => [
    ['code' => 'missing_system_value', 'path' => '$.contexto_sistema._sistema_url_validacao'],
]]) {
    throw new RuntimeException('Reserved system values must come only from explicit system context.');
}

$badSourceReference = $fixedMapping;
$badSourceReference['mapeamentos']['nome']['origem'] = 'titular.inexistente';
$result = designMappingResolve($design, $badSourceReference, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'missing_required_value'
    || ($result['errors'][0]['path'] ?? null) !== '$.mapeamentos.nome') {
    throw new RuntimeException('A required variable with an absent source value should fail without exposing record data.');
}

$unknownTarget = $fixedMapping;
$unknownTarget['mapeamentos']['not_in_design'] = ['tipo' => 'valor', 'valor' => 'x'];
$result = designMappingResolve($design, $unknownTarget, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'unknown_target_variable') {
    throw new RuntimeException('Mappings for variables absent from the selected design should be rejected.');
}

$unsafePath = $fixedMapping;
$unsafePath['mapeamentos']['nome']['origem'] = 'titular.nome;system';
$result = designMappingResolve($design, $unsafePath, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'invalid_mapping') {
    throw new RuntimeException('Source paths must be data paths, never executable expressions.');
}

$systemFromSource = [
    'versao_schema' => '1.0',
    'mapeamentos' => ['_sistema_url_validacao' => ['tipo' => 'campo', 'origem' => 'qr']],
];
$result = designMappingResolve($design, $systemFromSource, ['qr' => 'attacker-value'], ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'reserved_system_variable') {
    throw new RuntimeException('Reserved system variables must not be supplied through record mappings.');
}

$badTemplate = $mapping;
$badTemplate['mapeamentos']['curso_semestre']['partes'][0] = ['expression' => 'phpinfo()'];
$result = designMappingResolve($design, $badTemplate, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'invalid_mapping') {
    throw new RuntimeException('Template parts must allow only literals and explicit source-field references.');
}

$jsonResult = designMappingResolve(
    json_encode($design, JSON_THROW_ON_ERROR),
    json_encode($mapping, JSON_THROW_ON_ERROR),
    $source,
    ['_sistema_url_validacao' => 'qr'],
);
if (($jsonResult['valid'] ?? null) !== true) {
    throw new RuntimeException('The serialized design and mapping contracts should resolve like decoded objects.');
}

$unsupportedVersion = $mapping;
$unsupportedVersion['versao_schema'] = '2.0';
$result = designMappingResolve($design, $unsupportedVersion, $source, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'invalid_mapping') {
    throw new RuntimeException('Unsupported mapping schema versions should be rejected.');
}

$nonScalarRecord = ['titular' => ['nome_completo' => ['Ada']]];
$result = designMappingResolve($design, $fixedMapping, $nonScalarRecord, ['_sistema_url_validacao' => 'qr']);
if (($result['errors'][0]['code'] ?? null) !== 'missing_required_value') {
    throw new RuntimeException('Arrays and objects from source records must not be coerced into output strings.');
}

$sourceBeforeResolution = $source;
$mappingBeforeResolution = $mapping;
designMappingResolve($design, $mapping, $source, ['_sistema_url_validacao' => 'qr']);
if ($source !== $sourceBeforeResolution || $mapping !== $mappingBeforeResolution) {
    throw new RuntimeException('Resolution must not mutate source records or mapping configuration.');
}

restore_error_handler();
