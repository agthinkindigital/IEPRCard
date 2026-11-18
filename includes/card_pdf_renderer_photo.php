<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/card_webp_renderer_photo.php';

/** @return array{code: string, path: string}|null */
function cardPdfDrawPhotoElement(
    FPDF $pdf,
    array $element,
    array $photoAssets,
    string $backgroundPng,
    float $offsetXmm = 0,
    float $offsetYmm = 0
): ?array
{
    if (!$pdf instanceof IEPRCardPdfDocument) {
        return ['code' => 'invalid_pdf_document', 'path' => '$.render'];
    }
    $background = $pdf->decodedBackground($backgroundPng);
    if (!$background instanceof GdImage) {
        return ['code' => 'invalid_image', 'path' => '$.fundos.' . $element['lado']];
    }
    $x = (int) round($element['x']);
    $y = (int) round($element['y']);
    $width = (int) round($element['largura']);
    $height = (int) round($element['altura']);
    $patch = imagecreatetruecolor($width, $height);
    if (!$patch instanceof GdImage) {
        return ['code' => 'image_allocation_failed', 'path' => '$.render.' . $element['lado']];
    }
    imagecopy($patch, $background, 0, 0, $x, $y, $width, $height);

    $localElement = $element;
    $localElement['x'] = 0;
    $localElement['y'] = 0;
    $error = cardRenderPhotoElement($patch, $localElement, $photoAssets);
    if ($error !== null) {
        imagedestroy($patch);
        return $error;
    }
    ob_start();
    $encoded = imagejpeg($patch, null, 92);
    $png = ob_get_clean();
    imagedestroy($patch);
    if (!$encoded || !is_string($png) || $png === '') {
        return ['code' => 'image_encode_failed', 'path' => '$.design.elementos.' . $element['id']];
    }

    $imagePath = $pdf->temporaryImagePath($png);
    if ($imagePath === null) {
        return ['code' => 'temporary_asset_failed', 'path' => '$.assets.foto'];
    }
    try {
        $pdf->Image($imagePath, $offsetXmm + $x * 25.4 / 300, $offsetYmm + $y * 25.4 / 300,
            $width * 25.4 / 300, $height * 25.4 / 300, 'jpg');
    } catch (Throwable) {
        return ['code' => 'image_embed_failed', 'path' => '$.design.elementos.' . $element['id']];
    }
    return null;
}
