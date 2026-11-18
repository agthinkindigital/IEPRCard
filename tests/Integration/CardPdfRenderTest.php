<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/design_map_contract.php';
require_once dirname(__DIR__, 2) . '/includes/card_pdf_renderer.php';

function cardPdfFindTool(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        foreach ([$name, $name . '.exe'] as $fileName) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                return $candidate;
            }
        }
    }
    return null;
}

function cardPdfExtractText(string $pdfBytes): ?string
{
    $tool = cardPdfFindTool('pdftotext');
    if ($tool === null) {
        if (getenv('CI') === 'true') {
            throw new RuntimeException('CI must provide pdftotext for independent PDF text verification.');
        }
        return null;
    }
    $path = tempnam(sys_get_temp_dir(), 'ieprcard-pdf-');
    if ($path === false || file_put_contents($path, $pdfBytes) !== strlen($pdfBytes)) {
        throw new RuntimeException('Could not create the temporary PDF text fixture.');
    }
    $pipes = [];
    $process = proc_open([$tool, $path, '-'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        unlink($path);
        throw new RuntimeException('Could not start the independent PDF text parser.');
    }
    fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($path);
    if ($status !== 0) {
        throw new RuntimeException('The independent PDF parser failed: ' . trim((string) $error));
    }
    return is_string($text) ? $text : '';
}

function cardPdfRenderPage(string $pdfBytes, int $page): ?string
{
    $tool = cardPdfFindTool('pdftoppm');
    if ($tool === null) {
        if (getenv('CI') === 'true') {
            throw new RuntimeException('CI must provide pdftoppm for independent PDF page rendering.');
        }
        return null;
    }
    $pdfPath = tempnam(sys_get_temp_dir(), 'ieprcard-pdf-');
    $basePath = tempnam(sys_get_temp_dir(), 'ieprcard-page-');
    if ($pdfPath === false || $basePath === false || file_put_contents($pdfPath, $pdfBytes) !== strlen($pdfBytes)) {
        throw new RuntimeException('Could not create temporary PDF rendering fixtures.');
    }
    unlink($basePath);
    $pngPath = $basePath . '.png';
    $pipes = [];
    $process = proc_open([$tool, '-f', (string) $page, '-l', (string) $page, '-singlefile', '-r', '300', '-png', $pdfPath, $basePath], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        unlink($pdfPath);
        throw new RuntimeException('Could not start the independent PDF renderer.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($pdfPath);
    if ($status !== 0 || !is_file($pngPath)) {
        if (is_file($pngPath)) {
            unlink($pngPath);
        }
        throw new RuntimeException('The independent PDF renderer failed: ' . trim((string) $error));
    }
    $png = file_get_contents($pngPath);
    unlink($pngPath);
    return is_string($png) ? $png : '';
}

function cardPdfAssertScans(string $pdfBytes, int $page, string $expected): void
{
    $scanner = cardPdfFindTool('zbarimg');
    if ($scanner === null) {
        if (getenv('CI') === 'true') {
            throw new RuntimeException('CI must provide zbarimg for independent PDF barcode decoding.');
        }
        return;
    }
    $png = cardPdfRenderPage($pdfBytes, $page);
    if ($png === null) {
        return;
    }
    $path = tempnam(sys_get_temp_dir(), 'ieprcard-pdf-code-');
    if ($path === false || file_put_contents($path, $png) !== strlen($png)) {
        throw new RuntimeException('Could not create the temporary PDF barcode fixture.');
    }
    $pipes = [];
    $process = proc_open([$scanner, '--quiet', '--raw', $path], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        unlink($path);
        throw new RuntimeException('Could not start the independent PDF barcode decoder.');
    }
    fclose($pipes[0]);
    $decoded = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    unlink($path);
    if ($status !== 0 || trim((string) $decoded) !== $expected) {
        throw new RuntimeException('Independent PDF barcode decoding failed: ' . trim((string) $error));
    }
}

function cardPdfHasVectorRectangles(string $pdfBytes, int $minimum): bool
{
    preg_match_all('/\/Filter\s*\/FlateDecode[^\r\n]*\s*stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $streams);
    $rectangles = 0;
    foreach ($streams[1] ?? [] as $stream) {
        $decoded = @gzuncompress($stream);
        if (is_string($decoded)) {
            $rectangles += substr_count($decoded, ' re f');
        }
    }
    return $rectangles >= $minimum;
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
imagedestroy($background);

$result = cardRenderPdfSides($design, [], ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($result['valid'] ?? null) !== true || !is_string($result['pdf'] ?? null)
    || !str_starts_with($result['pdf'], '%PDF-1.4')) {
    throw new RuntimeException('A valid CR80 card must produce a complete PDF 1.4 document.');
}
$pageCount = preg_match_all('/\/Type\s*\/Page\b/', $result['pdf']);
if ($pageCount !== 2
    || !str_contains($result['pdf'], '/MediaBox [0 0 242.65 153.01]')) {
    throw new RuntimeException('The CR80 PDF must contain front/back pages sized 85.6×53.98 mm in landscape.');
}

$textDesign = $design;
$textDesign['elementos'] = [[
    'id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
    'x' => 25, 'y' => 20, 'largura_max' => 800, 'fonte_arquivo' => 'Inter-Bold.otf',
    'tamanho_pt' => 18, 'cor_hex' => '#0F172A', 'alinhamento' => 'left', 'obrigatorio' => true,
]];
$textResult = cardRenderPdfSides($textDesign, ['nome' => 'Ação São Paulo'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($textResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A valid Portuguese dynamic text field should render to the CR80 PDF.');
}
$extracted = cardPdfExtractText($textResult['pdf']);
if ($extracted !== null && !str_contains($extracted, 'Ação São Paulo')) {
    throw new RuntimeException('The vector PDF text must remain extractable with Portuguese accents.');
}
$unsupportedText = cardRenderPdfSides($textDesign, ['nome' => '東京'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($unsupportedText['valid'] ?? null) !== false || ($unsupportedText['pdf'] ?? null) !== ''
    || ($unsupportedText['errors'][0]['code'] ?? null) !== 'unsupported_text_encoding') {
    throw new RuntimeException('Text outside the configured PDF font encoding must fail without partial output.');
}

$qrValue = 'https://ieprcard.example/validar/teste-qr-2026';
$qrDesign = $design;
$qrDesign['elementos'] = [[
    'id' => 'el_qr', 'lado' => 'frente', 'tipo' => 'qrcode_validacao',
    'chave_variavel' => '_sistema_url_validacao', 'x' => 100, 'y' => 100, 'tamanho' => 300,
    'margem_quiet_zone' => 4,
]];
$qrResult = cardRenderPdfSides($qrDesign, ['_sistema_url_validacao' => $qrValue],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($qrResult['valid'] ?? null) !== true || !cardPdfHasVectorRectangles($qrResult['pdf'], 10)) {
    throw new RuntimeException('A validation QR should be encoded as vector paths in the PDF.');
}
cardPdfAssertScans($qrResult['pdf'], 1, $qrValue);

$barcodeDesign = $design;
$barcodeDesign['elementos'] = [[
    'id' => 'el_barras', 'lado' => 'verso', 'tipo' => 'codigo_barras', 'chave_variavel' => 'codigo',
    'formato' => 'CODE128', 'x' => 100, 'y' => 100, 'largura' => 500, 'altura' => 150,
]];
$barcodeResult = cardRenderPdfSides($barcodeDesign, ['codigo' => 'ABC123'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($barcodeResult['valid'] ?? null) !== true || !cardPdfHasVectorRectangles($barcodeResult['pdf'], 10)) {
    throw new RuntimeException('A CODE128 barcode should be encoded as vector paths in the PDF.');
}
cardPdfAssertScans($barcodeResult['pdf'], 2, 'ABC123');

$eanDesign = $barcodeDesign;
$eanDesign['elementos'][0]['formato'] = 'EAN13';
$eanResult = cardRenderPdfSides($eanDesign, ['codigo' => '4006381333931'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng]);
if (($eanResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A valid EAN13 value should render as PDF vectors.');
}
cardPdfAssertScans($eanResult['pdf'], 2, '4006381333931');

$photo = imagecreatetruecolor(100, 60);
$red = imagecolorallocate($photo, 210, 20, 20);
imagefill($photo, 0, 0, $red);
ob_start();
imagepng($photo);
$photoPng = ob_get_clean();
imagedestroy($photo);
$photoDesign = $design;
$photoDesign['elementos'] = [[
    'id' => 'el_foto', 'lado' => 'frente', 'tipo' => 'foto_titular', 'chave_variavel' => 'foto',
    'x' => 100, 'y' => 100, 'largura' => 200, 'altura' => 200,
]];
$photoResult = cardRenderPdfSides($photoDesign, ['foto' => 'tenant-private-photo-key'],
    ['frente' => $backgroundPng, 'verso' => $backgroundPng], ['foto' => $photoPng]);
if (($photoResult['valid'] ?? null) !== true) {
    throw new RuntimeException('A private photo byte buffer should render in its configured CR80 area.');
}
$frontPng = cardPdfRenderPage($photoResult['pdf'], 1);
if ($frontPng !== null) {
    $front = imagecreatefromstring($frontPng);
    if (!$front instanceof GdImage || abs(imagesx($front) - 1011) > 1 || abs(imagesy($front) - 638) > 1) {
        $actual = $front instanceof GdImage ? imagesx($front) . '×' . imagesy($front) : 'invalid image';
        if ($front instanceof GdImage) {
            imagedestroy($front);
        }
        throw new RuntimeException('A 300 DPI CR80 PDF face must render to 1011×638 pixels; got ' . $actual . '.');
    }
    $photoPixel = imagecolorsforindex($front, imagecolorat($front, 150, 150));
    $backgroundPixel = imagecolorsforindex($front, imagecolorat($front, 50, 50));
    imagedestroy($front);
    if ($photoPixel['red'] < 180 || $photoPixel['green'] > 50 || $backgroundPixel['red'] < 230) {
        throw new RuntimeException('The photo must occupy its mapped area without changing the background.');
    }
}

$benchmarkDesign = $design;
$benchmarkDesign['elementos'] = [
    [
        'id' => 'el_foto', 'lado' => 'frente', 'tipo' => 'foto_titular', 'chave_variavel' => 'foto',
        'x' => 100, 'y' => 100, 'largura' => 200, 'altura' => 200,
    ],
    [
        'id' => 'el_nome', 'lado' => 'frente', 'tipo' => 'texto_dinamico', 'chave_variavel' => 'nome',
        'x' => 350, 'y' => 100, 'largura_max' => 600, 'fonte_arquivo' => 'Inter-Bold.otf',
        'tamanho_pt' => 18, 'cor_hex' => '#0F172A', 'alinhamento' => 'left', 'obrigatorio' => true,
    ],
    [
        'id' => 'el_qr', 'lado' => 'verso', 'tipo' => 'qrcode_validacao',
        'chave_variavel' => '_sistema_url_validacao', 'x' => 100, 'y' => 100, 'tamanho' => 200,
    ],
    [
        'id' => 'el_barras', 'lado' => 'verso', 'tipo' => 'codigo_barras', 'chave_variavel' => 'codigo',
        'formato' => 'CODE128', 'x' => 400, 'y' => 100, 'largura' => 500, 'altura' => 120,
    ],
];
$benchmarkValues = [
    'nome' => 'Ação São Paulo', '_sistema_url_validacao' => $qrValue, 'codigo' => 'ABC123',
];
$batchCards = [];
for ($index = 0; $index < 50; ++$index) {
    $benchmarkPhoto = imagecreatetruecolor(100, 60);
    $photoColor = imagecolorallocate($benchmarkPhoto, 80 + $index * 3, 20 + $index, 40 + $index * 2);
    imagefill($benchmarkPhoto, 0, 0, $photoColor);
    ob_start();
    imagepng($benchmarkPhoto);
    $benchmarkPhotoPng = ob_get_clean();
    imagedestroy($benchmarkPhoto);
    $batchCards[] = [
        'design' => $benchmarkDesign,
        'values' => [...$benchmarkValues, '_sistema_url_validacao' => $qrValue . '/' . $index,
            'codigo' => 'ABC' . $index],
        'backgrounds' => ['frente' => $backgroundPng, 'verso' => $backgroundPng],
        'photo_assets' => ['foto' => $benchmarkPhotoPng],
    ];
}
$start = hrtime(true);
$benchmark = cardRenderPdfBatch($batchCards);
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$peakMemoryMb = memory_get_peak_usage(true) / 1_048_576;
if (($benchmark['valid'] ?? null) !== true || preg_match_all('/\/Type\s*\/Page\b/', $benchmark['pdf']) !== 100) {
    throw new RuntimeException('A batch of 50 cards must render as one 100-page CR80 PDF.');
}
fwrite(STDOUT, sprintf("BENCHMARK: PDF CR80 em lote, 50 carteirinhas/100 páginas, %.2f ms, pico PHP %.1f MiB.\n",
    $elapsedMs, $peakMemoryMb));
if (getenv('PDF_BENCHMARK_GATE') === 'true' && $elapsedMs > 1500) {
    throw new RuntimeException('The 50-card CR80 PDF batch must complete within the 1.5 second Gate 3 target.');
}
$invalidBatchCard = $batchCards[1];
$invalidBatchCard['values']['nome'] = '東京';
$failedBatch = cardRenderPdfBatch([$batchCards[0], $invalidBatchCard]);
if (($failedBatch['valid'] ?? null) !== false || ($failedBatch['pdf'] ?? null) !== ''
    || ($failedBatch['errors'][0]['code'] ?? null) !== 'unsupported_text_encoding'
    || !str_starts_with($failedBatch['errors'][0]['path'] ?? '', '$.cards[1]')) {
    throw new RuntimeException('A failed card in a batch must discard all PDF bytes and identify its index: '
        . json_encode([$failedBatch['valid'] ?? null, strlen($failedBatch['pdf'] ?? ''), $failedBatch['errors'] ?? []]));
}

restore_error_handler();
