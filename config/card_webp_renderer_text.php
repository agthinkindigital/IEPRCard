<?php
declare(strict_types=1);

/** @return array{code: string, path: string}|null */
function cardRenderTextElement(GdImage $canvas, array $element, string $value): ?array
{
    if ($value === '') {
        return null;
    }
    if (preg_match('/[\r\n]/', $value) === 1) {
        return ['code' => 'invalid_text_value', 'path' => '$.values.' . $element['chave_variavel']];
    }
    $text = match ($element['transformacao'] ?? 'none') {
        'uppercase' => mb_strtoupper($value, 'UTF-8'),
        'lowercase' => mb_strtolower($value, 'UTF-8'),
        'capitalize' => mb_convert_case($value, MB_CASE_TITLE, 'UTF-8'),
        default => $value,
    };
    $fontRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts');
    $fontPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts'
        . DIRECTORY_SEPARATOR . $element['fonte_arquivo']);
    if ($fontRoot === false || $fontPath === false
        || !str_starts_with($fontPath, $fontRoot . DIRECTORY_SEPARATOR)
        || !is_file($fontPath)) {
        return ['code' => 'font_not_found', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
    }

    $fontSize = max(8, (int) round($element['tamanho_pt'] * 300 / 72));
    $box = cardRenderTextBox($fontSize, $fontPath, $text);
    if (!is_array($box)) {
        return ['code' => 'font_measure_failed', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
    }
    $textWidth = max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]);
    if ($textWidth > $element['largura_max']) {
        if (($element['auto_reduzir_fonte_se_longo'] ?? false) !== true) {
            return ['code' => 'text_overflow', 'path' => '$.values.' . $element['chave_variavel']];
        }
        $minimumSize = 8;
        $minimumBox = cardRenderTextBox($minimumSize, $fontPath, $text);
        if (!is_array($minimumBox)) {
            return ['code' => 'font_measure_failed', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
        }
        $minimumWidth = max($minimumBox[0], $minimumBox[2], $minimumBox[4], $minimumBox[6])
            - min($minimumBox[0], $minimumBox[2], $minimumBox[4], $minimumBox[6]);
        if ($minimumWidth > $element['largura_max']) {
            return ['code' => 'text_overflow', 'path' => '$.values.' . $element['chave_variavel']];
        }
        $low = $minimumSize;
        $high = $fontSize - 1;
        $fontSize = $minimumSize;
        $box = $minimumBox;
        while ($low <= $high) {
            $candidate = intdiv($low + $high, 2);
            $candidateBox = cardRenderTextBox($candidate, $fontPath, $text);
            if (!is_array($candidateBox)) {
                return ['code' => 'font_measure_failed', 'path' => '$.design.elementos.' . $element['id'] . '.fonte_arquivo'];
            }
            $candidateWidth = max($candidateBox[0], $candidateBox[2], $candidateBox[4], $candidateBox[6])
                - min($candidateBox[0], $candidateBox[2], $candidateBox[4], $candidateBox[6]);
            if ($candidateWidth <= $element['largura_max']) {
                $fontSize = $candidate;
                $box = $candidateBox;
                $low = $candidate + 1;
            } else {
                $high = $candidate - 1;
            }
        }
    }

    $textWidth = max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]);

    $x = (int) round($element['x']);
    if ($element['alinhamento'] === 'center') {
        $x += (int) floor(($element['largura_max'] - $textWidth) / 2);
    } elseif ($element['alinhamento'] === 'right') {
        $x += (int) floor($element['largura_max'] - $textWidth);
    }
    $minX = min($box[0], $box[2], $box[4], $box[6]);
    $minY = min($box[1], $box[3], $box[5], $box[7]);
    $maxY = max($box[1], $box[3], $box[5], $box[7]);
    if ($element['y'] + $maxY - $minY > 638) {
        return ['code' => 'text_out_of_bounds', 'path' => '$.design.elementos.' . $element['id']];
    }
    $baseline = (int) round($element['y'] - $minY);
    $color = imagecolorallocate($canvas,
        hexdec(substr($element['cor_hex'], 1, 2)),
        hexdec(substr($element['cor_hex'], 3, 2)),
        hexdec(substr($element['cor_hex'], 5, 2)));
    $drawn = cardRenderSilence(static fn () => imagettftext($canvas, $fontSize, 0,
        $x - $minX, $baseline, $color, $fontPath, $text));
    return is_array($drawn) ? null : ['code' => 'text_draw_failed', 'path' => '$.design.elementos.' . $element['id']];
}

/** @return array<int, int>|null */
function cardRenderTextBox(int $fontSize, string $fontPath, string $text): ?array
{
    $box = cardRenderSilence(static fn () => imagettfbbox($fontSize, 0, $fontPath, $text));
    return is_array($box) && count($box) === 8 ? $box : null;
}
