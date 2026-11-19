<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/design_map_contract.php';
require_once dirname(__DIR__, 2) . '/config/design_mapping.php';
require_once dirname(__DIR__, 2) . '/config/card_webp_renderer.php';

function cardWebpAssertIndependentDecode(string $webp, string $expected): void
{
    if (!function_exists('proc_open')) {
        if (getenv('CI') === 'true') {
            throw new RuntimeException('CI must allow launching the independent barcode decoder.');
        }
        return;
    }
    $scanner = null;
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $pathDirectory) {
        foreach (['zbarimg', 'zbarimg.exe'] as $scannerName) {
            $candidate = rtrim($pathDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scannerName;
            if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                $scanner = $candidate;
                break 2;
            }
        }
    }
    if ($scanner === null) {
        if (getenv('CI') === 'true') {
            throw new RuntimeException('CI must provide zbarimg for independent barcode decoding.');
        }
        return;
    }

    $image = imagecreatefromstring($webp);
    $path = tempnam(sys_get_temp_dir(), 'ieprcard-code-');
    if (!$image instanceof GdImage || $path === false) {
        if ($image instanceof GdImage) {
            unset($image);
        }
        throw new RuntimeException('Could not create a temporary barcode fixture.');
    }
    imagepng($image, $path);
    unset($image);
    $pipes = [];
    $process = proc_open([$scanner, '--quiet', '--raw', $path], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        unlink($path);
        throw new RuntimeException('Could not start the independent barcode decoder.');
    }
    fclose($pipes[0]);
    $decoded = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($path);
    if ($status !== 0 || trim((string) $decoded) !== $expected) {
        throw new RuntimeException('Independent barcode decoding returned unexpected content: ' . trim((string) $error));
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$design = [
    'versao_schema' => '1.0',
    'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'],
    'elementos' => [],
];
$background = imagecreatetruecolor(1011, 638);
$white = imagecolorallocate($background, 255, 255, 255);
imagefill($background, 0, 0, $white);
ob_start();
imagepng($background);
$backgroundPng = ob_get_clean();
unset($background);

$result = cardRenderWebpSides($design, [], ['frente' => $backgroundPng, 'verso' => $backgroundPng], []);
if (($result['valid'] ?? null) !== true || ($result['errors'] ?? null) !== []
    || array_keys($result['images'] ?? []) !== ['frente', 'verso']) {
    throw new RuntimeException('A valid CR80 design should render a WebP for both sides.');
}
foreach ($result['images'] as $side => $webp) {
    $imageInfo = getimagesizefromstring($webp);
    if (!is_array($imageInfo) || $imageInfo[0] !== 1011 || $imageInfo[1] !== 638
        || ($imageInfo['mime'] ?? null) !== 'image/webp') {
        throw new RuntimeException('Rendered ' . $side . ' output must be WebP at exactly 1011×638 pixels.');
    }
}

$textDesign = $design;
$textDesign['elementos'] = [[
    'id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
    'x' => 25, 'y' => 20, 'largura_max' => 800, 'fonte_arquivo' => 'Inter-Bold.otf',
    'tamanho_pt' => 18, 'cor_hex' => '#000000', 'alinhamento' => 'left', 'obrigatorio' => true,
]];
$result = cardRenderWebpSides($textDesign, ['nome' => 'Ação & <script>'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($result['valid'] ?? null) !== true) {
    throw new RuntimeException('A valid text element should render without treating its value as markup.');
}
$renderedFront = imagecreatefromstring($result['images']['frente']);
$darkPixels = 0;
for ($pixelY = 20; $pixelY < 140; ++$pixelY) {
    for ($pixelX = 20; $pixelX < 400; ++$pixelX) {
        $color = imagecolorsforindex($renderedFront, imagecolorat($renderedFront, $pixelX, $pixelY));
        if ($color['red'] < 80 && $color['green'] < 80 && $color['blue'] < 80) {
            ++$darkPixels;
        }
    }
}
unset($renderedFront);
if ($darkPixels < 10) {
    throw new RuntimeException('Text should be drawn visibly at its design coordinates.');
}

$overflowDesign = $textDesign;
$overflowDesign['elementos'][0]['largura_max'] = 100;
$overflow = cardRenderWebpSides($overflowDesign, ['nome' => 'Nome muito longo para o espaço disponível'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if ($overflow !== ['valid' => false, 'images' => [], 'errors' => [
    ['code' => 'text_overflow', 'path' => '$.values.nome'],
]]) {
    throw new RuntimeException('Text wider than its field should fail without returning a partial image.');
}
$verticalOverflowDesign = $textDesign;
$verticalOverflowDesign['elementos'][0]['y'] = 630;
$verticalOverflow = cardRenderWebpSides($verticalOverflowDesign, ['nome' => 'Texto'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if ($verticalOverflow !== ['valid' => false, 'images' => [], 'errors' => [
    ['code' => 'text_out_of_bounds', 'path' => '$.design.elementos.el_nome'],
]]) {
    throw new RuntimeException('Text whose glyphs exceed the card canvas must fail without partial output.');
}
$shrinkDesign = $overflowDesign;
$shrinkDesign['elementos'][0]['largura_max'] = 300;
$shrinkDesign['elementos'][0]['auto_reduzir_fonte_se_longo'] = true;
$shrunken = cardRenderWebpSides($shrinkDesign, ['nome' => 'Nome muito longo para o espaço disponível'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($shrunken['valid'] ?? null) !== true) {
    throw new RuntimeException('A configured text field should reduce its font until the value fits.');
}

$photo = imagecreatetruecolor(100, 60);
$red = imagecolorallocate($photo, 210, 20, 20);
imagefill($photo, 0, 0, $red);
ob_start();
imagepng($photo);
$photoPng = ob_get_clean();
unset($photo);
$photoDesign = $design;
$photoDesign['elementos'] = [[
    'id' => 'el_foto', 'lado' => 'frente', 'tipo' => 'foto_titular', 'chave_variavel' => 'foto',
    'x' => 100, 'y' => 100, 'largura' => 200, 'altura' => 200,
]];
$photoResult = cardRenderWebpSides($photoDesign, ['foto' => 'tenant-private-photo-key'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng], ['foto' => $photoPng]);
if (($photoResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A private photo byte buffer should render in its mapped design area.');
}
$renderedPhotoCard = imagecreatefromstring($photoResult['images']['frente']);
$photoPixel = imagecolorsforindex($renderedPhotoCard, imagecolorat($renderedPhotoCard, 180, 180));
$outsidePixel = imagecolorsforindex($renderedPhotoCard, imagecolorat($renderedPhotoCard, 50, 50));
unset($renderedPhotoCard);
if ($photoPixel['red'] < 180 || $photoPixel['green'] > 50 || $outsidePixel['red'] < 230) {
    throw new RuntimeException('The photo should be cropped into its box without changing the surrounding background.');
}
$borderDesign = $photoDesign;
$borderDesign['elementos'][0]['borda_raio'] = 20;
$borderDesign['elementos'][0]['borda_espessura'] = 5;
$borderDesign['elementos'][0]['borda_cor'] = '#0000FF';
$borderResult = cardRenderWebpSides($borderDesign, ['foto' => 'tenant-private-photo-key'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng], ['foto' => $photoPng]);
if (($borderResult['valid'] ?? null) !== true) {
    throw new RuntimeException('Photo border options should be accepted by the renderer.');
}
$borderCard = imagecreatefromstring($borderResult['images']['frente']);
$borderPixel = imagecolorsforindex($borderCard, imagecolorat($borderCard, 102, 180));
$roundedCorner = imagecolorsforindex($borderCard, imagecolorat($borderCard, 100, 100));
unset($borderCard);
if ($borderPixel['blue'] < 180 || $borderPixel['red'] > 60 || $roundedCorner['red'] < 230) {
    throw new RuntimeException('Photo border color and rounded transparent corners should be preserved.');
}

$barcodeDesign = $design;
$barcodeDesign['elementos'] = [[
    'id' => 'el_barras', 'lado' => 'verso', 'tipo' => 'codigo_barras', 'chave_variavel' => 'codigo',
    'formato' => 'CODE128', 'x' => 100, 'y' => 100, 'largura' => 500, 'altura' => 150,
]];
$barcodeResult = cardRenderWebpSides($barcodeDesign, ['codigo' => 'ABC123'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($barcodeResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A valid CODE128 element should render on its configured side.');
}
cardWebpAssertIndependentDecode($barcodeResult['images']['verso'], 'ABC123');
$renderedBarcode = imagecreatefromstring($barcodeResult['images']['verso']);
$barcodeDarkPixels = 0;
for ($pixelY = 100; $pixelY < 250; ++$pixelY) {
    for ($pixelX = 100; $pixelX < 600; ++$pixelX) {
        $color = imagecolorsforindex($renderedBarcode, imagecolorat($renderedBarcode, $pixelX, $pixelY));
        if ($color['red'] < 60 && $color['green'] < 60 && $color['blue'] < 60) {
            ++$barcodeDarkPixels;
        }
    }
}
unset($renderedBarcode);
if ($barcodeDarkPixels < 1000) {
    throw new RuntimeException('CODE128 bars should be visible in the configured rectangle.');
}

$eanDesign = $barcodeDesign;
$eanDesign['elementos'][0]['formato'] = 'EAN13';
$eanResult = cardRenderWebpSides($eanDesign, ['codigo' => '4006381333931'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($eanResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A valid EAN13 value with its check digit should render.');
}
cardWebpAssertIndependentDecode($eanResult['images']['verso'], '4006381333931');
$invalidEan = cardRenderWebpSides($eanDesign, ['codigo' => '4006381333932'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if ($invalidEan !== ['valid' => false, 'images' => [], 'errors' => [
    ['code' => 'invalid_barcode_value', 'path' => '$.values.codigo'],
]]) {
    throw new RuntimeException('EAN13 with an invalid check digit must fail without partial images.');
}

$qrValue = 'https://ieprcard.example/validar/teste-qr-2026';
$qrDesign = $design;
$qrDesign['elementos'] = [[
    'id' => 'el_qr', 'lado' => 'frente', 'tipo' => 'qrcode_validacao',
    'chave_variavel' => '_sistema_url_validacao', 'x' => 100, 'y' => 100, 'tamanho' => 300,
    'margem_quiet_zone' => 4,
]];
$qrResult = cardRenderWebpSides($qrDesign, ['_sistema_url_validacao' => $qrValue],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($qrResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A validation QR should render from the system-provided URL.');
}
cardWebpAssertIndependentDecode($qrResult['images']['frente'], $qrValue);

$invalidBackground = cardRenderWebpSides($design, [], ['frente' => 'not-an-image', 'verso' => $backgroundPng]);
if ($invalidBackground !== ['valid' => false, 'images' => [], 'errors' => [
    ['code' => 'invalid_image', 'path' => '$.fundos.frente'],
]]) {
    throw new RuntimeException('Malformed image bytes must fail with a stable path and no partial output.');
}
$missingFontDesign = $textDesign;
$missingFontDesign['elementos'][0]['fonte_arquivo'] = 'Missing-Test-Font.otf';
$missingFont = cardRenderWebpSides($missingFontDesign, ['nome' => 'Nome'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if ($missingFont !== ['valid' => false, 'images' => [], 'errors' => [
    ['code' => 'font_not_found', 'path' => '$.design.elementos.el_nome.fonte_arquivo'],
]]) {
    throw new RuntimeException('An unavailable internal font must fail with no partial output.');
}

$benchmarkDesign = $design;
$benchmarkDesign['elementos'] = [
    $textDesign['elementos'][0],
    $photoDesign['elementos'][0],
    $qrDesign['elementos'][0],
    [
        'id' => 'el_barras', 'lado' => 'verso', 'tipo' => 'codigo_barras', 'chave_variavel' => 'codigo',
        'formato' => 'CODE128', 'x' => 100, 'y' => 100, 'largura' => 500, 'altura' => 100,
    ],
];
$benchmarkStart = hrtime(true);
$benchmarkResult = cardRenderWebpSides($benchmarkDesign, [
    'nome' => 'Ação & <script>', 'foto' => 'tenant-private-photo-key',
    '_sistema_url_validacao' => $qrValue, 'codigo' => 'ABC123',
], ['frente' => $backgroundPng, 'verso' => $backgroundPng], ['foto' => $photoPng]);
$benchmarkMs = (hrtime(true) - $benchmarkStart) / 1000000;
if (($benchmarkResult['valid'] ?? null) !== true) {
    throw new RuntimeException('The representative card benchmark fixture should render both sides.');
}
fwrite(STDOUT, sprintf("BENCHMARK: WebP frente/verso com texto, foto, QR e CODE128: %.2f ms total (alvo 80 ms por face; média %.2f ms/face).\n", $benchmarkMs, $benchmarkMs / 2));
$baselineStart = hrtime(true);
$baselineResult = cardRenderWebpSides($design, [], ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
$baselineMs = (hrtime(true) - $baselineStart) / 1000000;
if (($baselineResult['valid'] ?? null) !== true) {
    throw new RuntimeException('The blank two-sided card benchmark fixture should render.');
}
fwrite(STDOUT, sprintf("BENCHMARK: baseline sem elementos nas duas faces: %.2f ms.\n", $baselineMs));
foreach ([
    'texto' => [$textDesign['elementos'][0], ['nome' => 'Ação & <script>'], []],
    'foto' => [$photoDesign['elementos'][0], ['foto' => 'tenant-private-photo-key'], ['foto' => $photoPng]],
    'QR' => [$qrDesign['elementos'][0], ['_sistema_url_validacao' => $qrValue], []],
    'CODE128' => [$benchmarkDesign['elementos'][3], ['codigo' => 'ABC123'], []],
] as $label => [$component, $componentValues, $componentAssets]) {
    $componentDesign = $design;
    $componentDesign['elementos'] = [$component];
    $componentStart = hrtime(true);
    $componentResult = cardRenderWebpSides($componentDesign, $componentValues,
        ['frente' => $backgroundPng, 'verso' => $backgroundPng], $componentAssets);
    $componentMs = (hrtime(true) - $componentStart) / 1000000;
    if (($componentResult['valid'] ?? null) !== true) {
        throw new RuntimeException('The ' . $label . ' component benchmark fixture should render.');
    }
    fwrite(STDOUT, sprintf("BENCHMARK: somente %s nas duas faces: %.2f ms.\n", $label, $componentMs));
}

restore_error_handler();
