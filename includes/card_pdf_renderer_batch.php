<?php
declare(strict_types=1);

/** @param list<array{design: mixed, values: mixed, backgrounds: mixed, photo_assets?: mixed}> $cards
 *  @return array{valid: bool, pdf: string, errors: list<array{code: string, path: string}>}
 */
function cardRenderPdfBatch(array $cards): array
{
    if (!array_is_list($cards) || $cards === []) {
        return cardPdfFailure('invalid_batch', '$.cards');
    }
    if (count($cards) > 50) {
        return cardPdfFailure('batch_size_exceeded', '$.cards');
    }
    $pdf = new IEPRCardPdfDocument('L', 'mm', [85.6, 53.98]);
    $pdf->usePdf14();
    foreach ($cards as $index => $card) {
        if (!is_array($card) || !array_key_exists('design', $card)
            || !array_key_exists('values', $card) || !array_key_exists('backgrounds', $card)) {
            return cardPdfFailure('invalid_batch_card', '$.cards[' . $index . ']');
        }
        $result = cardRenderPdfSides(
            $card['design'],
            $card['values'],
            $card['backgrounds'],
            $card['photo_assets'] ?? [],
            $pdf
        );
        if (!$result['valid']) {
            $error = $result['errors'][0];
            return cardPdfFailure($error['code'], '$.cards[' . $index . ']' . substr($error['path'], 1));
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

/** @return array{valid: false, pdf: '', errors: list<array{code: string, path: string}>} */
function cardPdfFailure(string $code, string $path): array
{
    return ['valid' => false, 'pdf' => '', 'errors' => [['code' => $code, 'path' => $path]]];
}
