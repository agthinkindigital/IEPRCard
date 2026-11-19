<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/card_webp_renderer.php';
require_once __DIR__ . '/libs/fpdf.php';
require_once __DIR__ . '/card_pdf_renderer_text.php';
require_once __DIR__ . '/card_pdf_renderer_photo.php';
require_once __DIR__ . '/card_pdf_renderer_codes.php';

if (!class_exists('IEPRCardPdfDocument', false)) {
    class IEPRCardPdfDocument extends FPDF
    {
        /** @var array<string, string> */
        private array $backgroundCache = [];
        /** @var array<string, string> */
        private array $bleedBackgroundCache = [];
        /** @var array<string, GdImage> */
        private array $decodedBackgroundCache = [];
        /** @var array<string, resource> */
        private array $temporaryAssets = [];

        public function usePdf14(): void
        {
            $this->PDFVersion = '1.4';
        }

        public function cachedBackground(string $source): ?string
        {
            return $this->backgroundCache[hash('sha256', $source)] ?? null;
        }

        public function cacheBackground(string $source, string $png): void
        {
            $this->backgroundCache[hash('sha256', $source)] = $png;
        }

        public function bleedBackground(string $png): ?string
        {
            $key = hash('sha256', $png);
            if (isset($this->bleedBackgroundCache[$key])) {
                return $this->bleedBackgroundCache[$key];
            }
            $bleed = cardPdfCreateBleedBackground($png);
            if ($bleed !== null) {
                $this->bleedBackgroundCache[$key] = $bleed;
            }
            return $bleed;
        }

        public function decodedBackground(string $png): ?GdImage
        {
            $key = hash('sha256', $png);
            if (isset($this->decodedBackgroundCache[$key])) {
                return $this->decodedBackgroundCache[$key];
            }
            $decoded = cardRenderDecodeImage($png, '$.render.background');
            if (!isset($decoded['image'])) {
                return null;
            }
            $this->decodedBackgroundCache[$key] = $decoded['image'];
            return $decoded['image'];
        }

        public function temporaryImagePath(string $bytes): ?string
        {
            $key = hash('sha256', $bytes);
            if (isset($this->temporaryAssets[$key])) {
                $metadata = stream_get_meta_data($this->temporaryAssets[$key]);
                return is_string($metadata['uri'] ?? null) ? $metadata['uri'] : null;
            }
            $temporary = tmpfile();
            if (!is_resource($temporary) || fwrite($temporary, $bytes) !== strlen($bytes)) {
                if (is_resource($temporary)) {
                    fclose($temporary);
                }
                return null;
            }
            $metadata = stream_get_meta_data($temporary);
            if (!is_string($metadata['uri'] ?? null)) {
                fclose($temporary);
                return null;
            }
            $this->temporaryAssets[$key] = $temporary;
            return $metadata['uri'];
        }

        public function cleanupTemporaryAssets(): void
        {
            foreach ($this->temporaryAssets as $temporary) {
                fclose($temporary);
            }
            $this->temporaryAssets = [];
            foreach ($this->decodedBackgroundCache as $image) {
                unset($image);
            }
            $this->decodedBackgroundCache = [];
        }
    }
}

/** @return array{valid: bool, pdf: string, errors: list<array{code: string, path: string}>} */
function cardRenderPdfSides(
    mixed $designSource,
    mixed $values,
    mixed $backgrounds,
    mixed $photoAssets = [],
    ?IEPRCardPdfDocument $document = null,
    ?string $onlySide = null,
    bool $addPages = true,
    float $offsetXmm = 0,
    float $offsetYmm = 0,
    bool $withBleed = false
): array
{
    $ownsDocument = $document === null;
    $validation = designMapValidate($designSource);
    if (!$validation['valid']) {
        return cardPdfFailure('invalid_design', '$.design');
    }
    if (is_string($designSource)) {
        try {
            $design = json_decode($designSource, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return cardPdfFailure('invalid_design', '$.design');
        }
    } else {
        $design = $designSource;
    }
    if (!cardRenderIsMap($values) || !cardRenderIsMap($backgrounds) || !cardRenderIsMap($photoAssets)) {
        return cardPdfFailure('invalid_render_input', '$');
    }

    $runtime = renderingCapabilitiesCheck();
    if (!$runtime['ready']) {
        return cardPdfFailure('rendering_unavailable', '$.runtime');
    }
    $valueErrors = cardRenderValidateValues($design, $values);
    if ($valueErrors !== []) {
        return ['valid' => false, 'pdf' => '', 'errors' => $valueErrors];
    }

    $pdf = $document ?? new IEPRCardPdfDocument('L', 'mm', [85.6, 53.98]);
    if ($ownsDocument) {
        $pdf->usePdf14();
    }
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);
    foreach (['frente', 'verso'] as $side) {
        if ($onlySide !== null && $onlySide !== $side) {
            continue;
        }
        if (!isset($backgrounds[$side]) || !is_string($backgrounds[$side])) {
            return cardPdfFailure('missing_background', '$.fundos.' . $side);
        }
        $backgroundSource = $backgrounds[$side];
        $backgroundPng = $pdf->cachedBackground($backgroundSource);
        if ($backgroundPng === null) {
            $decoded = cardRenderDecodeImage($backgroundSource, '$.fundos.' . $side);
            if (!isset($decoded['image'])) {
                return ['valid' => false, 'pdf' => '', 'errors' => $decoded['errors']];
            }
            $background = imagecreatetruecolor(1011, 638);
            if (!$background instanceof GdImage) {
                unset($decoded['image']);
                return cardPdfFailure('image_allocation_failed', '$.render.' . $side);
            }
            $white = imagecolorallocate($background, 255, 255, 255);
            imagefill($background, 0, 0, $white);
            cardRenderCopyCover($background, $decoded['image'], 0, 0, 1011, 638);
            unset($decoded['image']);
            ob_start();
            $encoded = imagepng($background);
            $backgroundPng = ob_get_clean();
            unset($background);
            if (!$encoded || !is_string($backgroundPng) || $backgroundPng === '') {
                return cardPdfFailure('image_encode_failed', '$.render.' . $side);
            }
            $pdf->cacheBackground($backgroundSource, $backgroundPng);
        }
        $imageBytes = $withBleed ? $pdf->bleedBackground($backgroundPng) : $backgroundPng;
        if ($imageBytes === null) {
            return cardPdfFailure('image_encode_failed', '$.fundos.' . $side);
        }
        $imagePath = $pdf->temporaryImagePath($imageBytes);
        if ($imagePath === null) {
            return cardPdfFailure('temporary_asset_failed', '$.fundos.' . $side);
        }
        if ($addPages) {
            $pdf->AddPage('L');
        }
        try {
            $pdf->Image($imagePath, $offsetXmm, $offsetYmm,
                $withBleed ? 91.6 : 85.6, $withBleed ? 59.98 : 53.98, 'png');
        } catch (Throwable) {
            return cardPdfFailure('image_embed_failed', '$.fundos.' . $side);
        }

        foreach ($design['elementos'] as $element) {
            if ($element['lado'] !== $side) {
                continue;
            }
            $elementError = match ($element['tipo']) {
                'texto_dinamico' => cardPdfDrawTextElement(
                    $pdf,
                    $element,
                    $values[$element['chave_variavel']] ?? '',
                    $offsetXmm + ($withBleed ? 3 : 0),
                    $offsetYmm + ($withBleed ? 3 : 0)
                ),
                'foto_titular' => cardPdfDrawPhotoElement(
                    $pdf,
                    $element,
                    $photoAssets,
                    $backgroundPng,
                    $offsetXmm + ($withBleed ? 3 : 0),
                    $offsetYmm + ($withBleed ? 3 : 0)
                ),
                'qrcode_validacao', 'codigo_barras' => cardPdfDrawCodeElement(
                    $pdf,
                    $element,
                    $values[$element['chave_variavel']] ?? '',
                    $offsetXmm + ($withBleed ? 3 : 0),
                    $offsetYmm + ($withBleed ? 3 : 0)
                ),
                default => ['code' => 'unsupported_element', 'path' => '$.design.elementos.' . $element['id']],
            };
            if ($elementError !== null) {
                return cardPdfFailure($elementError['code'], $elementError['path']);
            }
        }
    }

    if (!$ownsDocument) {
        return ['valid' => true, 'pdf' => '', 'errors' => []];
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

require_once __DIR__ . '/card_pdf_renderer_a4.php';
require_once __DIR__ . '/card_pdf_renderer_batch.php';
