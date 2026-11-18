<?php
declare(strict_types=1);

/** @return array{code: string, path: string}|null */
function cardPdfDrawCodeElement(FPDF $pdf, array $element, string $value): ?array
{
    $path = '$.values.' . $element['chave_variavel'];
    $idPath = '$.design.elementos.' . $element['id'];
    if ($element['tipo'] === 'qrcode_validacao') {
        if (strlen($value) > 1024) {
            return ['code' => 'qr_value_too_large', 'path' => $path];
        }
        $quietZone = max(4, (int) round($element['margem_quiet_zone'] ?? 4));
        $generator = new barcode_generator();
        $bitmap = cardRenderSilence(static fn () => $generator->render_image('qr-q', $value, [
            'sf' => 1, 'p' => 0, 'wq' => $quietZone, 'bc' => 'FFFFFF', 'cm' => '000000', 'ms' => 's', 'md' => 1,
        ]));
        if (!$bitmap instanceof GdImage) {
            return ['code' => 'qr_generation_failed', 'path' => $idPath];
        }
        $target = max(1, (int) round($element['tamanho']));
        $scale = intdiv($target, max(imagesx($bitmap), imagesy($bitmap)));
        if ($scale < 1) {
            imagedestroy($bitmap);
            return ['code' => 'qr_area_too_small', 'path' => $idPath];
        }
        $width = imagesx($bitmap) * $scale;
        $height = imagesy($bitmap) * $scale;
        $x = (int) round($element['x']) + intdiv($target - $width, 2);
        $y = (int) round($element['y']) + intdiv($target - $height, 2);
    } else {
        if (strlen($value) > 256 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return ['code' => 'invalid_barcode_value', 'path' => $path];
        }
        if ($element['formato'] === 'EAN13'
            && (preg_match('/^[0-9]{13}$/D', $value) !== 1 || !cardRenderValidEan13($value))) {
            return ['code' => 'invalid_barcode_value', 'path' => $path];
        }
        $generator = new barcode_generator();
        $bitmap = cardRenderSilence(static fn () => $generator->render_image(strtolower($element['formato']), $value, [
            'sf' => 1, 'p' => 0, 'bc' => 'FFFFFF', 'cm' => '000000',
        ]));
        if (!$bitmap instanceof GdImage) {
            return ['code' => 'barcode_generation_failed', 'path' => $idPath];
        }
        $targetWidth = max(1, (int) round($element['largura']));
        $targetHeight = max(1, (int) round($element['altura']));
        $scale = intdiv($targetWidth, imagesx($bitmap));
        if ($scale < 1) {
            imagedestroy($bitmap);
            return ['code' => 'barcode_area_too_small', 'path' => $idPath];
        }
        $width = imagesx($bitmap) * $scale;
        $height = $targetHeight;
        $x = (int) round($element['x']) + intdiv($targetWidth - $width, 2);
        $y = (int) round($element['y']);
    }

    cardPdfDrawBitmapVector($pdf, $bitmap, $x, $y, $width, $height);
    imagedestroy($bitmap);
    return null;
}

function cardPdfDrawBitmapVector(FPDF $pdf, GdImage $bitmap, int $x, int $y, int $width, int $height): void
{
    $pdfX = $x * 25.4 / 300;
    $pdfY = $y * 25.4 / 300;
    $scaleX = $width / imagesx($bitmap) * 25.4 / 300;
    $scaleY = $height / imagesy($bitmap) * 25.4 / 300;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($pdfX, $pdfY, $scaleX * imagesx($bitmap), $scaleY * imagesy($bitmap), 'F');
    $pdf->SetFillColor(0, 0, 0);

    $active = [];
    $trueColor = imageistruecolor($bitmap);
    for ($row = 0, $rows = imagesy($bitmap); $row < $rows; ++$row) {
        $runs = [];
        $runStart = null;
        for ($column = 0, $columns = imagesx($bitmap); $column <= $columns; ++$column) {
            $black = false;
            if ($column < $columns) {
                $pixel = imagecolorat($bitmap, $column, $row);
                if ($trueColor) {
                    $black = ($pixel & 0xFFFFFF) === 0;
                } else {
                    $color = imagecolorsforindex($bitmap, $pixel);
                    $black = $color['red'] < 128 && $color['green'] < 128 && $color['blue'] < 128;
                }
            }
            if ($black && $runStart === null) {
                $runStart = $column;
            } elseif (!$black && $runStart !== null) {
                $runs[$runStart . ':' . $column] = [$runStart, $column];
                $runStart = null;
            }
        }
        foreach ($active as $key => $run) {
            if (!isset($runs[$key])) {
                $pdf->Rect($pdfX + $run[0] * $scaleX, $pdfY + $run[2] * $scaleY,
                    ($run[1] - $run[0]) * $scaleX, ($row - $run[2]) * $scaleY, 'F');
                unset($active[$key]);
            }
        }
        foreach ($runs as $key => [$start, $end]) {
            if (!isset($active[$key])) {
                $active[$key] = [$start, $end, $row];
            }
        }
    }
    foreach ($active as [$start, $end, $startRow]) {
        $pdf->Rect($pdfX + $start * $scaleX, $pdfY + $startRow * $scaleY,
            ($end - $start) * $scaleX, (imagesy($bitmap) - $startRow) * $scaleY, 'F');
    }
}
