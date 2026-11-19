<?php
declare(strict_types=1);

/** @return array{code: string, path: string}|null */
function cardRenderQrElement(GdImage $canvas, array $element, string $value): ?array
{
    if (strlen($value) > 1024) {
        return ['code' => 'qr_value_too_large', 'path' => '$.values.' . $element['chave_variavel']];
    }
    $quietZone = max(4, (int) round($element['margem_quiet_zone'] ?? 4));
    $generator = new barcode_generator();
    $qr = cardRenderSilence(static fn () => $generator->render_image('qr-q', $value, [
        'sf' => 1,
        'p' => 0,
        'wq' => $quietZone,
        'bc' => 'FFFFFF',
        'cm' => '000000',
        'ms' => 's',
        'md' => 1,
    ]));
    if (!$qr instanceof GdImage) {
        return ['code' => 'qr_generation_failed', 'path' => '$.design.elementos.' . $element['id']];
    }

    $targetSize = max(1, (int) round($element['tamanho']));
    $scale = intdiv($targetSize, max(imagesx($qr), imagesy($qr)));
    if ($scale < 1) {
        unset($qr);
        return ['code' => 'qr_area_too_small', 'path' => '$.design.elementos.' . $element['id']];
    }
    $scaledWidth = imagesx($qr) * $scale;
    $scaledHeight = imagesy($qr) * $scale;
    $x = (int) round($element['x']) + intdiv($targetSize - $scaledWidth, 2);
    $y = (int) round($element['y']) + intdiv($targetSize - $scaledHeight, 2);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, (int) round($element['x']), (int) round($element['y']),
        (int) round($element['x']) + $targetSize - 1, (int) round($element['y']) + $targetSize - 1, $white);
    imagecopyresized($canvas, $qr, $x, $y, 0, 0, $scaledWidth, $scaledHeight, imagesx($qr), imagesy($qr));
    unset($qr);
    return null;
}

/** @return array{code: string, path: string}|null */
function cardRenderBarcodeElement(GdImage $canvas, array $element, string $value): ?array
{
    $path = '$.values.' . $element['chave_variavel'];
    if (strlen($value) > 256 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return ['code' => 'invalid_barcode_value', 'path' => $path];
    }
    if ($element['formato'] === 'EAN13'
        && (preg_match('/^[0-9]{13}$/D', $value) !== 1 || !cardRenderValidEan13($value))) {
        return ['code' => 'invalid_barcode_value', 'path' => $path];
    }

    $generator = new barcode_generator();
    $barcode = cardRenderSilence(static fn () => $generator->render_image(strtolower($element['formato']), $value, [
        'sf' => 1,
        'p' => 0,
        'bc' => 'FFFFFF',
        'cm' => '000000',
    ]));
    if (!$barcode instanceof GdImage) {
        return ['code' => 'barcode_generation_failed', 'path' => '$.design.elementos.' . $element['id']];
    }

    $targetWidth = max(1, (int) round($element['largura']));
    $targetHeight = max(1, (int) round($element['altura']));
    $scale = intdiv($targetWidth, imagesx($barcode));
    if ($scale < 1) {
        unset($barcode);
        return ['code' => 'barcode_area_too_small', 'path' => '$.design.elementos.' . $element['id']];
    }

    $drawWidth = imagesx($barcode) * $scale;
    $drawX = (int) round($element['x']) + intdiv($targetWidth - $drawWidth, 2);
    $drawY = (int) round($element['y']);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, (int) round($element['x']), $drawY,
        (int) round($element['x']) + $targetWidth - 1, $drawY + $targetHeight - 1, $white);
    imagecopyresized($canvas, $barcode, $drawX, $drawY, 0, 0,
        $drawWidth, $targetHeight, imagesx($barcode), imagesy($barcode));
    unset($barcode);
    return null;
}

function cardRenderValidEan13(string $value): bool
{
    $sum = 0;
    for ($index = 0; $index < 12; ++$index) {
        $sum += (int) $value[$index] * ($index % 2 === 0 ? 1 : 3);
    }
    return (10 - ($sum % 10)) % 10 === (int) $value[12];
}
