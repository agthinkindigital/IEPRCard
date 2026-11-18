<?php
declare(strict_types=1);

require_once __DIR__ . '/design_map_contract.php';
require_once __DIR__ . '/rendering_capabilities.php';
require_once dirname(__DIR__) . '/includes/libs/barcode.php';
require_once __DIR__ . '/card_webp_renderer_images.php';
require_once __DIR__ . '/card_webp_renderer_text.php';
require_once __DIR__ . '/card_webp_renderer_photo.php';
require_once __DIR__ . '/card_webp_renderer_codes.php';

/** @return array{valid: bool, images: array<string, string>, errors: list<array{code: string, path: string}>} */
function cardRenderWebpSides(mixed $designSource, mixed $values, mixed $backgrounds, mixed $photoAssets = []): array
{
    $designValidation = designMapValidate($designSource);
    if (!$designValidation['valid']) {
        return cardRenderFailure('invalid_design', '$.design');
    }
    if (is_string($designSource)) {
        try {
            $design = json_decode($designSource, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return cardRenderFailure('invalid_design', '$.design');
        }
    } else {
        $design = $designSource;
    }
    if (!cardRenderIsMap($values) || !cardRenderIsMap($backgrounds) || !cardRenderIsMap($photoAssets)) {
        return cardRenderFailure('invalid_render_input', '$');
    }

    $runtime = renderingCapabilitiesCheck();
    if (!$runtime['ready']) {
        return cardRenderFailure('rendering_unavailable', '$.runtime');
    }

    $errors = cardRenderValidateValues($design, $values);
    if ($errors !== []) {
        return ['valid' => false, 'images' => [], 'errors' => $errors];
    }

    $images = [];
    foreach (['frente', 'verso'] as $side) {
        if (!isset($backgrounds[$side]) || !is_string($backgrounds[$side])) {
            return cardRenderFailure('missing_background', '$.fundos.' . $side);
        }
        $background = cardRenderDecodeImage($backgrounds[$side], '$.fundos.' . $side);
        if (!isset($background['image'])) {
            return ['valid' => false, 'images' => [], 'errors' => $background['errors']];
        }

        $canvas = imagecreatetruecolor(1011, 638);
        if ($canvas === false) {
            imagedestroy($background['image']);
            return cardRenderFailure('image_allocation_failed', '$.render');
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        cardRenderCopyCover($canvas, $background['image'], 0, 0, 1011, 638);
        imagedestroy($background['image']);

        foreach ($design['elementos'] as $index => $element) {
            if ($element['lado'] === $side) {
                $elementResult = match ($element['tipo']) {
                    'texto_dinamico' => cardRenderTextElement($canvas, $element, $values[$element['chave_variavel']] ?? ''),
                    'foto_titular' => cardRenderPhotoElement($canvas, $element, $photoAssets),
                    'qrcode_validacao' => cardRenderQrElement($canvas, $element, $values[$element['chave_variavel']] ?? ''),
                    'codigo_barras' => cardRenderBarcodeElement($canvas, $element, $values[$element['chave_variavel']] ?? ''),
                    default => ['code' => 'unsupported_element', 'path' => '$.design.elementos[' . $index . ']'],
                };
                if ($elementResult !== null) {
                    imagedestroy($canvas);
                    return cardRenderFailure($elementResult['code'], $elementResult['path']);
                }
            }
        }

        ob_start();
        $encoded = imagewebp($canvas, null, 85);
        $webp = ob_get_clean();
        imagedestroy($canvas);
        if (!$encoded || !is_string($webp) || $webp === '') {
            return cardRenderFailure('image_encode_failed', '$.render.' . $side);
        }
        $images[$side] = $webp;
    }

    return ['valid' => true, 'images' => $images, 'errors' => []];
}

function cardRenderIsMap(mixed $value): bool
{
    return is_array($value) && (!array_is_list($value) || $value === []);
}

/** @return list<array{code: string, path: string}> */
function cardRenderValidateValues(array $design, array $values): array
{
    $variables = [];
    foreach ($design['elementos'] as $index => $element) {
        $key = $element['chave_variavel'];
        $variables[$key] = true;
        if (!array_key_exists($key, $values)) {
            if (($element['tipo'] === 'texto_dinamico' && ($element['obrigatorio'] ?? false) === true)
                || $element['tipo'] === 'qrcode_validacao'
                || $element['tipo'] === 'codigo_barras') {
                return [['code' => 'missing_required_value', 'path' => '$.values.' . $key]];
            }
            continue;
        }
        if (!is_string($values[$key]) || strlen($values[$key]) > 8192) {
            return [['code' => 'invalid_value', 'path' => '$.values.' . $key]];
        }
        if ((($element['tipo'] === 'texto_dinamico' && ($element['obrigatorio'] ?? false) === true)
                || $element['tipo'] === 'qrcode_validacao'
                || $element['tipo'] === 'codigo_barras') && trim($values[$key]) === '') {
            return [['code' => 'missing_required_value', 'path' => '$.values.' . $key]];
        }
    }
    foreach (array_keys($values) as $key) {
        if (!is_string($key) || !isset($variables[$key])) {
            return [['code' => 'unknown_value', 'path' => '$.values']];
        }
    }
    return [];
}

/** @return array{valid: false, images: array{}, errors: list<array{code: string, path: string}>} */
function cardRenderFailure(string $code, string $path): array
{
    return ['valid' => false, 'images' => [], 'errors' => [['code' => $code, 'path' => $path]]];
}
