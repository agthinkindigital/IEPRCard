<?php
declare(strict_types=1);

function cardPdfCreateBleedBackground(string $backgroundPng): ?string
{
    $decoded = cardRenderDecodeImage($backgroundPng, '$.fundos');
    if (!isset($decoded['image'])) {
        return null;
    }
    $source = $decoded['image'];
    $bleed = imagecreatetruecolor(1082, 709);
    if (!$bleed instanceof GdImage) {
        imagedestroy($source);
        return null;
    }
    $white = imagecolorallocate($bleed, 255, 255, 255);
    imagefill($bleed, 0, 0, $white);
    imagecopy($bleed, $source, 35, 35, 0, 0, 1011, 638);
    imagecopyresized($bleed, $source, 35, 0, 0, 0, 1011, 35, 1011, 1);
    imagecopyresized($bleed, $source, 35, 673, 0, 637, 1011, 36, 1011, 1);
    imagecopyresized($bleed, $source, 0, 35, 0, 0, 35, 638, 1, 638);
    imagecopyresized($bleed, $source, 1046, 35, 1010, 0, 36, 638, 1, 638);
    foreach ([[0, 0, 0, 0], [1046, 0, 1010, 0], [0, 673, 0, 637], [1046, 673, 1010, 637]] as $corner) {
        $color = imagecolorat($source, $corner[2], $corner[3]);
        imagefilledrectangle($bleed, $corner[0], $corner[1], $corner[0] + 35, $corner[1] + 35, $color);
    }
    imagedestroy($source);
    ob_start();
    $encoded = imagepng($bleed);
    $png = ob_get_clean();
    imagedestroy($bleed);
    return $encoded && is_string($png) && $png !== '' ? $png : null;
}

/** @param list<array{design: mixed, values: mixed, backgrounds: mixed, photo_assets?: mixed}> $cards
 *  @return array{valid: bool, pdf: string, errors: list<array{code: string, path: string}>}
 */
function cardRenderPdfA4Batch(array $cards, string $layout = '8up'): array
{
    if (!array_is_list($cards) || $cards === []) {
        return cardPdfFailure('invalid_batch', '$.cards');
    }
    if (count($cards) > 50) {
        return cardPdfFailure('batch_size_exceeded', '$.cards');
    }
    $settings = match ($layout) {
        '8up' => ['columns' => 2, 'rows' => 4, 'bleed' => 3.0, 'gap' => 0.0],
        '10up' => ['columns' => 2, 'rows' => 5, 'bleed' => 0.0, 'gap' => 4.0],
        default => null,
    };
    if ($settings === null) {
        return cardPdfFailure('invalid_a4_layout', '$.layout');
    }
    $capacity = $settings['columns'] * $settings['rows'];
    $cellWidth = 85.6 + 2 * $settings['bleed'];
    $cellHeight = 53.98 + 2 * $settings['bleed'];
    $gridWidth = $settings['columns'] * $cellWidth + ($settings['columns'] - 1) * $settings['gap'];
    $gridHeight = $settings['rows'] * $cellHeight + ($settings['rows'] - 1) * $settings['gap'];
    $originX = (210 - $gridWidth) / 2;
    $originY = (297 - $gridHeight) / 2;
    $pdf = new IEPRCardPdfDocument('P', 'mm', [210, 297]);
    $pdf->usePdf14();
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);

    foreach (array_chunk(array_keys($cards), $capacity) as $sheetIndex => $indices) {
        foreach (['frente', 'verso'] as $side) {
            $pdf->AddPage('P');
            foreach ($indices as $slot => $cardIndex) {
                $row = intdiv($slot, $settings['columns']);
                $column = $slot % $settings['columns'];
                if ($side === 'verso') {
                    $column = $settings['columns'] - $column - 1;
                }
                $cellX = $originX + $column * ($cellWidth + $settings['gap']);
                $cellY = $originY + $row * ($cellHeight + $settings['gap']);
                $trimX = $cellX + $settings['bleed'];
                $trimY = $cellY + $settings['bleed'];
                $card = $cards[$cardIndex];
                if (!is_array($card) || !isset($card['design']) || !isset($card['values'])
                    || !isset($card['backgrounds'])) {
                    return cardPdfFailure('invalid_batch_card', '$.cards[' . $cardIndex . ']');
                }
                $result = cardRenderPdfSides(
                    $card['design'],
                    $card['values'],
                    $card['backgrounds'],
                    $card['photo_assets'] ?? [],
                    $pdf,
                    $side,
                    false,
                    $cellX,
                    $cellY,
                    $settings['bleed'] > 0
                );
                if (!$result['valid']) {
                    $error = $result['errors'][0];
                    return cardPdfFailure($error['code'], '$.cards[' . $cardIndex . ']' . substr($error['path'], 1));
                }
                cardPdfDrawCropMarks($pdf, $trimX, $trimY);
            }
        }
    }

    try {
        $bytes = $pdf->Output('S');
    } catch (Throwable) {
        return cardPdfFailure('pdf_generation_failed', '$.render');
    }
    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-1.4')) {
        return cardPdfFailure('pdf_generation_failed', '$.render');
    }
    $pdf->cleanupTemporaryAssets();
    return ['valid' => true, 'pdf' => $bytes, 'errors' => []];
}

function cardPdfDrawCropMarks(FPDF $pdf, float $x, float $y): void
{
    $cardWidth = 85.6;
    $cardHeight = 53.98;
    $length = 1.2;
    $gap = 0.5;
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.15);
    foreach ([
        [$x, $y, 1, 1], [$x + $cardWidth, $y, -1, 1],
        [$x, $y + $cardHeight, 1, -1], [$x + $cardWidth, $y + $cardHeight, -1, -1],
    ] as [$cornerX, $cornerY, $directionX, $directionY]) {
        $pdf->Line($cornerX + $directionX * $gap, $cornerY,
            $cornerX + $directionX * ($gap + $length), $cornerY);
        $pdf->Line($cornerX, $cornerY + $directionY * $gap,
            $cornerX, $cornerY + $directionY * ($gap + $length));
    }
}

