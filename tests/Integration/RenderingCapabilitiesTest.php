<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/rendering_capabilities.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ob_start();
$report = renderingCapabilitiesCheck();
$unexpectedOutput = ob_get_clean();

$expectedCapabilities = [
    'gd', 'truecolor', 'png_decode', 'jpeg_decode', 'webp_decode', 'webp_encode',
    'freetype', 'mbstring', 'ttf_text', 'ttf_measure',
];
if ($unexpectedOutput !== '' || !is_array($report)
    || array_keys($report['capabilities'] ?? []) !== $expectedCapabilities
    || !is_bool($report['ready'] ?? null) || !is_array($report['missing'] ?? null)) {
    throw new RuntimeException('The runtime report should be silent and expose a stable capability contract.');
}

$expectedMissing = array_keys(array_filter($report['capabilities'], static fn (bool $available): bool => !$available));
if ($report['missing'] !== $expectedMissing || $report['ready'] !== ($expectedMissing === [])) {
    throw new RuntimeException('The readiness flag and missing capabilities must reflect the active PHP runtime.');
}

if (getenv('CI') === 'true' && !$report['ready']) {
    throw new RuntimeException('CI requires the complete GD, WebP, and FreeType profile for card rendering.');
}

restore_error_handler();
