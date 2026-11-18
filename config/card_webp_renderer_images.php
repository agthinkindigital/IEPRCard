<?php
declare(strict_types=1);

/** @return array{image?: GdImage, errors: list<array{code: string, path: string}>} */
function cardRenderDecodeImage(string $bytes, string $path): array
{
    if ($bytes === '' || strlen($bytes) > 10485760) {
        return ['errors' => [['code' => 'invalid_image', 'path' => $path]]];
    }
    $info = cardRenderSilence(static fn () => getimagesizefromstring($bytes));
    if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])
        || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
        || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
        || $info[0] * $info[1] > 4000000) {
        return ['errors' => [['code' => 'invalid_image', 'path' => $path]]];
    }
    $image = cardRenderSilence(static fn () => imagecreatefromstring($bytes));
    if (!$image instanceof GdImage) {
        return ['errors' => [['code' => 'invalid_image', 'path' => $path]]];
    }
    return ['image' => $image, 'errors' => []];
}

function cardRenderSilence(callable $operation): mixed
{
    set_error_handler(static fn (): bool => true);
    try {
        return $operation();
    } catch (Throwable) {
        return null;
    } finally {
        restore_error_handler();
    }
}

function cardRenderCopyCover(GdImage $destination, GdImage $source, int $x, int $y, int $width, int $height): void
{
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth === $width && $sourceHeight === $height) {
        imagecopy($destination, $source, $x, $y, 0, 0, $width, $height);
        return;
    }
    $scale = max($width / $sourceWidth, $height / $sourceHeight);
    $cropWidth = $width / $scale;
    $cropHeight = $height / $scale;
    $sourceX = ($sourceWidth - $cropWidth) / 2;
    $sourceY = ($sourceHeight - $cropHeight) / 2;
    imagecopyresampled($destination, $source, $x, $y, (int) round($sourceX), (int) round($sourceY),
        $width, $height, (int) round($cropWidth), (int) round($cropHeight));
}
