<?php
declare(strict_types=1);

/** @return array{ready: bool, capabilities: array<string, bool>, missing: list<string>} */
function renderingCapabilitiesCheck(): array
{
    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $gdLoaded = extension_loaded('gd');
    $capabilities = [
        'gd' => $gdLoaded && function_exists('gd_info'),
        'truecolor' => $gdLoaded && function_exists('imagecreatetruecolor'),
        'png_decode' => $gdLoaded && function_exists('imagecreatefrompng') && (bool) ($gdInfo['PNG Support'] ?? false),
        'jpeg_decode' => $gdLoaded && function_exists('imagecreatefromjpeg') && (bool) ($gdInfo['JPEG Support'] ?? false),
        'webp_decode' => $gdLoaded && function_exists('imagecreatefromwebp') && (bool) ($gdInfo['WebP Support'] ?? false),
        'webp_encode' => $gdLoaded && function_exists('imagewebp') && (bool) ($gdInfo['WebP Support'] ?? false),
        'freetype' => $gdLoaded && (bool) ($gdInfo['FreeType Support'] ?? false),
        'mbstring' => extension_loaded('mbstring'),
        'ttf_text' => $gdLoaded && function_exists('imagettftext'),
        'ttf_measure' => $gdLoaded && function_exists('imagettfbbox'),
    ];
    $missing = array_keys(array_filter($capabilities, static fn (bool $available): bool => !$available));

    return ['ready' => $missing === [], 'capabilities' => $capabilities, 'missing' => $missing];
}
