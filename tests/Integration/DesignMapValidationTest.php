<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/design_map_contract.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$validMap = [
    'versao_schema' => '1.0',
    'dimensoes' => [
        'largura_px' => 1011,
        'altura_px' => 638,
        'dpi' => 300,
        'padrao' => 'CR80',
    ],
    'elementos' => [[
        'id' => 'el_nome',
        'lado' => 'frente',
        'tipo' => 'texto_dinamico',
        'chave_variavel' => 'titular.nome_completo',
        'x' => 340,
        'y' => 180,
        'largura_max' => 620,
        'fonte_arquivo' => 'Inter-Bold.ttf',
        'tamanho_pt' => 24,
        'cor_hex' => '#0F172A',
        'alinhamento' => 'left',
    ]],
];

$result = designMapValidate($validMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A valid CR80 layout map should pass validation without errors.');
}

$tooManyElementsMap = $validMap;
$tooManyElementsMap['elementos'] = [];
for ($elementIndex = 0; $elementIndex < 101; ++$elementIndex) {
    $element = $validMap['elementos'][0];
    $element['id'] = 'el_' . $elementIndex;
    $tooManyElementsMap['elementos'][] = $element;
}
$result = designMapValidate($tooManyElementsMap);
if ($result !== ['valid' => false, 'errors' => [['code' => 'too_many_elements', 'path' => '$.elementos']]]) {
    throw new RuntimeException('Layout maps must reject more than 100 elements.');
}

$result = designMapValidate(json_encode($validMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A valid JSON-encoded layout should pass the same contract validation.');
}
$validJson = json_encode($validMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$result = designMapValidate($validJson . str_repeat(' ', 262144 - strlen($validJson)));
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('The exact 256 KiB JSON size limit should be accepted.');
}
$mapBeforeValidation = $validMap;
designMapValidate($validMap);
if ($validMap !== $mapBeforeValidation) {
    throw new RuntimeException('Validation must not mutate the supplied map.');
}
$result = designMapValidate('{invalid json');
if ($result !== ['valid' => false, 'errors' => [['code' => 'invalid_json', 'path' => '$']]]) {
    throw new RuntimeException('Malformed JSON should return a structured validation error.');
}
$result = designMapValidate(str_repeat(' ', 262145));
if ($result !== ['valid' => false, 'errors' => [['code' => 'map_too_large', 'path' => '$']]]) {
    throw new RuntimeException('Oversized JSON must be rejected before decoding.');
}
$result = designMapValidate(17);
if ($result !== ['valid' => false, 'errors' => [['code' => 'invalid_map', 'path' => '$']]]) {
    throw new RuntimeException('A decoded value that is not an object should return a validation error.');
}

$emptyDraftMap = $validMap;
$emptyDraftMap['elementos'] = [];
$result = designMapValidate($emptyDraftMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A new layout draft may be empty before elements are added.');
}

$outOfBoundsMap = $validMap;
$outOfBoundsMap['elementos'][0]['x'] = 1010;
$result = designMapValidate($outOfBoundsMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'geometry_out_of_bounds'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].x') {
    throw new RuntimeException('Out-of-bounds geometry should return a stable code and field path.');
}

$negativeCoordinateMap = $validMap;
$negativeCoordinateMap['elementos'][0]['x'] = -0.01;
$result = designMapValidate($negativeCoordinateMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'geometry_out_of_bounds'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].x') {
    throw new RuntimeException('Negative coordinates must be rejected with their field path.');
}

$nonFiniteGeometryMap = $validMap;
$nonFiniteGeometryMap['elementos'][0]['x'] = INF;
$result = designMapValidate($nonFiniteGeometryMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_geometry'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].x') {
    throw new RuntimeException('Non-finite coordinates should be rejected without arithmetic warnings.');
}

$duplicateIdMap = $validMap;
$duplicateIdMap['elementos'][] = $validMap['elementos'][0];
$result = designMapValidate($duplicateIdMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'duplicate_element_id'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[1].id') {
    throw new RuntimeException('Duplicate element IDs should identify the duplicate field path.');
}

$unsafeElementIdMap = $validMap;
$unsafeElementIdMap['elementos'][0]['id'] = 'el_nome" onclick="x';
$result = designMapValidate($unsafeElementIdMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].id') {
    throw new RuntimeException('Element IDs must use the safe schema identifier format.');
}

$photoMap = $validMap;
$photoMap['elementos'][0] = [
    'id' => 'el_foto',
    'lado' => 'frente',
    'tipo' => 'foto_titular',
    'chave_variavel' => 'foto',
    'x' => 68,
    'y' => 140,
    'largura' => 240,
    'altura' => 320,
];
$result = designMapValidate($photoMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A valid titular photo element should pass validation.');
}

$photoOutsideCanvas = $photoMap;
$photoOutsideCanvas['elementos'][0]['y'] = 620;
$result = designMapValidate($photoOutsideCanvas);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'geometry_out_of_bounds'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].y') {
    throw new RuntimeException('A photo extending below the canvas should report its vertical position.');
}

$canvasEdgeMap = $photoMap;
$canvasEdgeMap['elementos'][0]['x'] = 0;
$canvasEdgeMap['elementos'][0]['y'] = 0;
$canvasEdgeMap['elementos'][0]['largura'] = 1011;
$canvasEdgeMap['elementos'][0]['altura'] = 638;
$result = designMapValidate($canvasEdgeMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('Geometry may touch the CR80 canvas edges exactly.');
}

$qrMap = $validMap;
$qrMap['elementos'][0] = [
    'id' => 'el_qr',
    'lado' => 'verso',
    'tipo' => 'qrcode_validacao',
    'chave_variavel' => '_sistema_url_validacao',
    'x' => 760,
    'y' => 380,
    'tamanho' => 200,
    'margem_quiet_zone' => 2,
];
$result = designMapValidate($qrMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A valid validation QR element should pass validation.');
}

$barcodeMap = $validMap;
$barcodeMap['elementos'][0] = [
    'id' => 'el_codigo',
    'lado' => 'verso',
    'tipo' => 'codigo_barras',
    'chave_variavel' => 'titular.matricula',
    'formato' => 'CODE128',
    'x' => 100,
    'y' => 300,
    'largura' => 400,
    'altura' => 80,
];
$result = designMapValidate($barcodeMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A valid barcode element should pass validation.');
}

$completeMap = $validMap;
$completeMap['elementos'][] = $photoMap['elementos'][0];
$completeMap['elementos'][] = $qrMap['elementos'][0];
$completeMap['elementos'][] = $barcodeMap['elementos'][0];
$result = designMapValidate($completeMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('A complete front/back map with every supported element should pass.');
}

$unsupportedBarcodeMap = $barcodeMap;
$unsupportedBarcodeMap['elementos'][0]['formato'] = 'UNSUPPORTED';
$result = designMapValidate($unsupportedBarcodeMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].formato') {
    throw new RuntimeException('Barcode symbology must be explicitly supported by the schema.');
}

$invalidSecondElementMap = $validMap;
$invalidSecondElementMap['elementos'][] = [
    'id' => 'el_foto',
    'lado' => 'frente',
    'tipo' => 'foto_titular',
    'chave_variavel' => 'foto',
    'x' => 10,
    'y' => 10,
    'largura' => 120,
];
$result = designMapValidate($invalidSecondElementMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[1].altura') {
    throw new RuntimeException('Every element must be validated and report its own map path.');
}

$reorderedDimensionsMap = $validMap;
$reorderedDimensionsMap['dimensoes'] = [
    'padrao' => 'CR80',
    'dpi' => 300,
    'altura_px' => 638,
    'largura_px' => 1011,
];
$result = designMapValidate($reorderedDimensionsMap);
if ($result !== ['valid' => true, 'errors' => []]) {
    throw new RuntimeException('JSON property order must not change the meaning of CR80 dimensions.');
}

$invalidColorMap = $validMap;
$invalidColorMap['elementos'][0]['cor_hex'] = '#GGGGGG';
$result = designMapValidate($invalidColorMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].cor_hex') {
    throw new RuntimeException('Dynamic text colors must be valid six-digit hexadecimal colors.');
}

$invalidAlignmentMap = $validMap;
$invalidAlignmentMap['elementos'][0]['alinhamento'] = 'justify';
$result = designMapValidate($invalidAlignmentMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].alinhamento') {
    throw new RuntimeException('Dynamic text alignment must use a supported value.');
}

$invalidFontSizeMap = $validMap;
$invalidFontSizeMap['elementos'][0]['tamanho_pt'] = 0;
$result = designMapValidate($invalidFontSizeMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].tamanho_pt') {
    throw new RuntimeException('Dynamic text font size must be a positive finite number.');
}

$unsafeFontPathMap = $validMap;
$unsafeFontPathMap['elementos'][0]['fonte_arquivo'] = '../fonts/Inter-Bold.ttf';
$result = designMapValidate($unsafeFontPathMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].fonte_arquivo') {
    throw new RuntimeException('Font references must not be able to escape the internal font collection.');
}

$invalidVariableMap = $validMap;
$invalidVariableMap['elementos'][0]['chave_variavel'] = '{{titular.nome_completo}}';
$result = designMapValidate($invalidVariableMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_variable_key'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].chave_variavel') {
    throw new RuntimeException('Variable keys must not include template delimiters or invalid characters.');
}

$extraRootPropertyMap = $validMap;
$extraRootPropertyMap['unexpected'] = true;
$result = designMapValidate($extraRootPropertyMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_map'
    || ($result['errors'][0]['path'] ?? null) !== '$') {
    throw new RuntimeException('The versioned layout root must reject unknown properties.');
}

$extraElementPropertyMap = $validMap;
$extraElementPropertyMap['elementos'][0]['onclick'] = 'alert(1)';
$result = designMapValidate($extraElementPropertyMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0]') {
    throw new RuntimeException('Elements must reject properties outside the versioned type contract.');
}

$unsafePlaceholderMap = $photoMap;
$unsafePlaceholderMap['elementos'][0]['placeholder_padrao'] = 'assets/img/../../private.jpg';
$result = designMapValidate($unsafePlaceholderMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0].placeholder_padrao') {
    throw new RuntimeException('Photo placeholders must stay inside the internal image collection.');
}

$unknownElementTypeMap = $validMap;
$unknownElementTypeMap['elementos'][0]['tipo'] = 'script';
$result = designMapValidate($unknownElementTypeMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'invalid_element'
    || ($result['errors'][0]['path'] ?? null) !== '$.elementos[0]') {
    throw new RuntimeException('Unregistered element types must be rejected.');
}

$unsupportedVersionMap = $validMap;
$unsupportedVersionMap['versao_schema'] = '2.0';
$result = designMapValidate($unsupportedVersionMap);
if ($result['valid'] !== false
    || ($result['errors'][0]['code'] ?? null) !== 'unsupported_schema_version'
    || ($result['errors'][0]['path'] ?? null) !== '$.versao_schema') {
    throw new RuntimeException('Unsupported schema versions should return a dedicated error.');
}

restore_error_handler();
