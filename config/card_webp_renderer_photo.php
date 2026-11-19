<?php
declare(strict_types=1);

/** @return array{code: string, path: string}|null */
function cardRenderPhotoElement(GdImage $canvas, array $element, array $photoAssets): ?array
{
    $photoBytes = $photoAssets['foto'] ?? null;
    if (!is_string($photoBytes) || $photoBytes === '') {
        return ['code' => 'missing_photo_asset', 'path' => '$.assets.foto'];
    }
    $decoded = cardRenderDecodeImage($photoBytes, '$.assets.foto');
    if (!isset($decoded['image'])) {
        return $decoded['errors'][0];
    }

    $width = max(1, (int) round($element['largura']));
    $height = max(1, (int) round($element['altura']));
    $x = (int) round($element['x']);
    $y = (int) round($element['y']);
    $radius = min((int) round($element['borda_raio'] ?? 0), intdiv(min($width, $height), 2));
    $borderWidth = min((int) round($element['borda_espessura'] ?? 0), intdiv(min($width, $height), 2));
    if ($borderWidth > 0) {
        $borderColor = imagecolorallocate($canvas,
            hexdec(substr($element['borda_cor'] ?? '#FFFFFF', 1, 2)),
            hexdec(substr($element['borda_cor'] ?? '#FFFFFF', 3, 2)),
            hexdec(substr($element['borda_cor'] ?? '#FFFFFF', 5, 2)));
        cardRenderDrawRoundedFrame($canvas, $x, $y, $width, $height, $radius, $borderColor);
    }
    $innerWidth = max(1, $width - 2 * $borderWidth);
    $innerHeight = max(1, $height - 2 * $borderWidth);
    $photoLayer = imagecreatetruecolor($innerWidth, $innerHeight);
    if ($photoLayer === false) {
        unset($decoded['image']);
        return ['code' => 'image_allocation_failed', 'path' => '$.design.elementos.' . $element['id']];
    }
    imagealphablending($photoLayer, false);
    imagesavealpha($photoLayer, true);
    $transparent = imagecolorallocatealpha($photoLayer, 0, 0, 0, 127);
    imagefill($photoLayer, 0, 0, $transparent);
    cardRenderCopyCover($photoLayer, $decoded['image'], 0, 0, $innerWidth, $innerHeight);
    $innerRadius = max(0, $radius - $borderWidth);
    if ($innerRadius > 0) {
        cardRenderClipRoundedCorners($photoLayer, $innerRadius, $transparent);
    }
    imagealphablending($canvas, true);
    imagecopy($canvas, $photoLayer, $x + $borderWidth, $y + $borderWidth, 0, 0, $innerWidth, $innerHeight);
    unset($photoLayer);
    unset($decoded['image']);
    return null;
}

function cardRenderDrawRoundedFrame(GdImage $canvas, int $x, int $y, int $width, int $height, int $radius, int $color): void
{
    if ($radius <= 0) {
        imagefilledrectangle($canvas, $x, $y, $x + $width - 1, $y + $height - 1, $color);
        return;
    }
    imagefilledrectangle($canvas, $x + $radius, $y, $x + $width - $radius - 1, $y + $height - 1, $color);
    imagefilledrectangle($canvas, $x, $y + $radius, $x + $width - 1, $y + $height - $radius - 1, $color);
    imagefilledellipse($canvas, $x + $radius, $y + $radius, 2 * $radius, 2 * $radius, $color);
    imagefilledellipse($canvas, $x + $width - $radius - 1, $y + $radius, 2 * $radius, 2 * $radius, $color);
    imagefilledellipse($canvas, $x + $radius, $y + $height - $radius - 1, 2 * $radius, 2 * $radius, $color);
    imagefilledellipse($canvas, $x + $width - $radius - 1, $y + $height - $radius - 1,
        2 * $radius, 2 * $radius, $color);
}

function cardRenderClipRoundedCorners(GdImage $image, int $radius, int $transparent): void
{
    $width = imagesx($image);
    $height = imagesy($image);
    $radius = min($radius, intdiv(min($width, $height), 2));
    $radiusSquared = $radius * $radius;
    for ($y = 0; $y < $radius; ++$y) {
        for ($x = 0; $x < $radius; ++$x) {
            $dx = $radius - $x - 0.5;
            $dy = $radius - $y - 0.5;
            if ($dx * $dx + $dy * $dy <= $radiusSquared) {
                continue;
            }
            imagesetpixel($image, $x, $y, $transparent);
            imagesetpixel($image, $width - $x - 1, $y, $transparent);
            imagesetpixel($image, $x, $height - $y - 1, $transparent);
            imagesetpixel($image, $width - $x - 1, $height - $y - 1, $transparent);
        }
    }
}
