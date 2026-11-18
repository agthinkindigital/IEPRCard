<?php
declare(strict_types=1);

/** @return array{code: string, path: string}|null */
function cardPdfDrawTextElement(
    FPDF $pdf,
    array $element,
    string $value,
    float $offsetXmm = 0,
    float $offsetYmm = 0
): ?array
{
    if ($value === '') {
        return null;
    }
    if (preg_match('/[\r\n]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
        return ['code' => 'invalid_text_value', 'path' => '$.values.' . $element['chave_variavel']];
    }
    $text = match ($element['transformacao'] ?? 'none') {
        'uppercase' => mb_strtoupper($value, 'UTF-8'),
        'lowercase' => mb_strtolower($value, 'UTF-8'),
        'capitalize' => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        default => $value,
    };
    $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    if (!is_string($encoded)
        || mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252') !== $text) {
        return ['code' => 'unsupported_text_encoding', 'path' => '$.values.' . $element['chave_variavel']];
    }

    $fontNames = [
        'Inter-Regular.otf' => ['style' => '', 'metrics' => 'Inter-Regular.otf'],
        'Inter-Bold.otf' => ['style' => 'B', 'metrics' => 'Inter-Bold.otf'],
    ];
    $font = $fontNames[$element['fonte_arquivo']] ?? null;
    $fontPath = $font === null ? false : realpath(dirname(__DIR__) . '/assets/fonts/' . $font['metrics']);
    $fontRoot = realpath(dirname(__DIR__) . '/assets/fonts');
    if ($font === null || $fontPath === false || $fontRoot === false
        || !str_starts_with($fontPath, $fontRoot . DIRECTORY_SEPARATOR)) {
        return ['code' => 'font_not_found', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
    }

    $fontDirectory = dirname(__DIR__) . '/assets/fonts/pdf';
    $definition = $font['style'] === 'B' ? 'Inter-Bold.json' : 'Inter-Regular.json';
    $pdf->AddFont('Inter', $font['style'], $definition, $fontDirectory);
    $pointSize = (float) $element['tamanho_pt'];
    $pdf->SetFont('Inter', $font['style'], $pointSize);
    $textWidth = $pdf->GetStringWidth($encoded);
    $maxWidth = $element['largura_max'] * 25.4 / 300;
    if ($textWidth > $maxWidth) {
        if (($element['auto_reduzir_fonte_se_longo'] ?? false) !== true) {
            return ['code' => 'text_overflow', 'path' => '$.values.' . $element['chave_variavel']];
        }
        $pdf->SetFont('Inter', $font['style'], 8);
        if ($pdf->GetStringWidth($encoded) > $maxWidth) {
            return ['code' => 'text_overflow', 'path' => '$.values.' . $element['chave_variavel']];
        }
        $low = 8.0;
        $high = $pointSize;
        for ($iteration = 0; $iteration < 16; ++$iteration) {
            $candidate = ($low + $high) / 2;
            $pdf->SetFont('Inter', $font['style'], $candidate);
            if ($pdf->GetStringWidth($encoded) <= $maxWidth) {
                $low = $candidate;
            } else {
                $high = $candidate;
            }
        }
        $pointSize = $low;
        $pdf->SetFont('Inter', $font['style'], $pointSize);
        $textWidth = $pdf->GetStringWidth($encoded);
    }

    $fontPixels = max(8, (int) round($pointSize * 300 / 72));
    $box = cardRenderTextBox($fontPixels, $fontPath, $text);
    if (!is_array($box)) {
        return ['code' => 'font_measure_failed', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
    }
    $minY = min($box[1], $box[3], $box[5], $box[7]);
    $maxY = max($box[1], $box[3], $box[5], $box[7]);
    if ($element['y'] + $maxY - $minY > 638) {
        return ['code' => 'text_out_of_bounds', 'path' => '$.design.elementos.' . $element['id']];
    }

    $x = $offsetXmm + $element['x'] * 25.4 / 300;
    if ($element['alinhamento'] === 'center') {
        $x += ($maxWidth - $textWidth) / 2;
    } elseif ($element['alinhamento'] === 'right') {
        $x += $maxWidth - $textWidth;
    }
    $baseline = $offsetYmm + ($element['y'] - $minY) * 25.4 / 300;
    $pdf->SetTextColor(
        hexdec(substr($element['cor_hex'], 1, 2)),
        hexdec(substr($element['cor_hex'], 3, 2)),
        hexdec(substr($element['cor_hex'], 5, 2))
    );
    $pdf->Text($x, $baseline, $encoded);
    return null;
}
