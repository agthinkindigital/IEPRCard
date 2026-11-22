<?php
declare(strict_types=1);

function whatsappConversationSafeRegistrationFailure(string $code): string
{
    return match ($code) {
        'TITULAR_CREATE_QUOTA_EXCEEDED', 'TITULAR_CREATE_QUOTA_UNCONFIGURED', 'ISSUANCE_QUOTA_EXCEEDED', 'ISSUANCE_QUOTA_UNCONFIGURED' => 'A cota disponível não permite concluir o cadastro e a emissão. Procure a instituição.',
        'TITULAR_CREATE_CPF_CONFLICT' => 'Este CPF já consta no sistema. Procure a instituição para continuar.',
        'TITULAR_CREATE_TARGET_INVALID', 'ISSUANCE_DESIGN_NOT_FOUND' => 'A categoria ou o design deixou de estar disponível. Procure a instituição.',
        'TITULAR_CREATE_ROOT_INACTIVE', 'TITULAR_CREATE_PLAN_UNAVAILABLE', 'ISSUANCE_DENIED' => 'O cadastro não está disponível para esta instituição no momento.',
        default => 'Não foi possível concluir o cadastro agora. Procure a instituição para continuar.',
    };
}

/** @return list<array{id:int,name:string}> */
function whatsappConversationCategoryChoices(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare('SELECT id, nome_categoria FROM categorias_titular WHERE tenant_id = :tenant AND ativo = 1 ORDER BY nome_categoria, id LIMIT 20');
    $query->execute(['tenant' => $tenantId]);
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nome_categoria']], $query->fetchAll(PDO::FETCH_ASSOC));
}

/** @return list<array{id:int,name:string}> */
function whatsappConversationDesignChoices(PDO $pdo, int $tenantId, ?int $subclientId, ?int $categoryId): array
{
    $defaultDesignId = null;
    if ($categoryId !== null) {
        $category = $pdo->prepare('SELECT design_padrao_id FROM categorias_titular WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1');
        $category->execute(['id' => $categoryId, 'tenant' => $tenantId]);
        $defaultValue = $category->fetchColumn();
        $defaultDesignId = $defaultValue === false || $defaultValue === null ? null : (int) $defaultValue;
    }
    $sql = 'SELECT id, nome_layout FROM designs_carteirinha WHERE tenant_id = :tenant AND ativo = 1';
    if ($subclientId !== null) $sql .= ' AND compartilhado_subclientes = 1';
    $sql .= ' ORDER BY nome_layout, id LIMIT 30';
    $query = $pdo->prepare($sql);
    $query->execute(['tenant' => $tenantId]);
    $choices = array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nome_layout']], $query->fetchAll(PDO::FETCH_ASSOC));
    usort($choices, static fn(array $left, array $right): int => ($left['id'] === $defaultDesignId ? -1 : 0) <=> ($right['id'] === $defaultDesignId ? -1 : 0));
    return $choices;
}

/** @param list<array{id:int,name:string}> $choices */
function whatsappConversationCategoryPrompt(array $choices): string
{
    $lines = ['Escolha uma categoria ativa respondendo com o número:'];
    foreach ($choices as $index => $choice) $lines[] = ($index + 1) . ' - ' . $choice['name'];
    $lines[] = '0 - Sem categoria';
    return implode("\n", $lines);
}

/** @param list<array{id:int,name:string}> $choices */
function whatsappConversationDesignPrompt(array $choices): string
{
    $lines = ['Escolha um design ativo para sua carteirinha:'];
    foreach ($choices as $index => $choice) $lines[] = ($index + 1) . ' - ' . $choice['name'];
    return implode("\n", $lines);
}

/** @param array<string,mixed> $draft */
function whatsappConversationConfirmationText(array $draft): string
{
    $cpf = (string) ($draft['cpf'] ?? '');
    if (preg_match('/^[0-9]{11}$/D', $cpf) === 1) {
        $cpf = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }
    return 'Confira seu cadastro antes de enviar: Nome: ' . (string) ($draft['nome_completo'] ?? '')
        . '; CPF: ' . $cpf
        . '; WhatsApp: número desta conversa; Categoria: ' . (string) ($draft['categoria_nome'] ?? 'Sem categoria')
        . '; Design: ' . (string) ($draft['design_nome'] ?? '')
        . '; Foto: ' . (empty($draft['foto_url']) ? 'não incluída' : 'incluída')
        . '. Responda CONFIRMAR para cadastrar e emitir sua carteirinha ou SAIR para cancelar.';
}
function whatsappConversationNormalizePhoto(string $encoded, string $mimeType): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('WHATSAPP_PHOTO_PROCESSING_UNAVAILABLE');
    }
    if (str_starts_with($encoded, 'data:')) {
        if (preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/sD', $encoded, $matches) !== 1
            || !hash_equals(strtolower($mimeType), strtolower($matches[1]))) {
            throw new DomainException('WHATSAPP_PHOTO_INVALID');
        }
        $encoded = $matches[2];
    }
    if (strlen($encoded) > 1_500_000 || !in_array(strtolower($mimeType), ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new DomainException('WHATSAPP_PHOTO_INVALID');
    }
    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 1_000_000) {
        throw new DomainException('WHATSAPP_PHOTO_INVALID');
    }
    $imageInfo = @getimagesizefromstring($bytes);
    if (!is_array($imageInfo) || !in_array((string) ($imageInfo['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp'], true)
        || !hash_equals((string) $imageInfo['mime'], strtolower($mimeType))
        || (int) ($imageInfo[0] ?? 0) < 1 || (int) ($imageInfo[1] ?? 0) < 1
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 10_000_000) {
        throw new DomainException('WHATSAPP_PHOTO_INVALID');
    }
    $source = @imagecreatefromstring($bytes);
    if (!$source instanceof GdImage) {
        throw new DomainException('WHATSAPP_PHOTO_INVALID');
    }
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min(1, 1600 / max($sourceWidth, $sourceHeight));
    $width = max(1, (int) round($sourceWidth * $scale));
    $height = max(1, (int) round($sourceHeight * $scale));
    $target = imagecreatetruecolor($width, $height);
    if (!$target instanceof GdImage) {
        imagedestroy($source);
        throw new RuntimeException('WHATSAPP_PHOTO_PROCESSING_FAILED');
    }
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
    ob_start();
    $ok = imagejpeg($target, null, 84);
    $normalized = ob_get_clean();
    imagedestroy($source);
    imagedestroy($target);
    if (!$ok || !is_string($normalized) || $normalized === '' || strlen($normalized) > 1_000_000) {
        throw new DomainException('WHATSAPP_PHOTO_INVALID');
    }
    return $normalized;
}

/** @param array<string,mixed> $draft */
function whatsappConversationDeleteDraftPhoto(int $tenantId, array $draft): void
{
    $key = $draft['foto_url'] ?? null;
    if (!is_string($key) || !str_starts_with($key, 'private://')) return;
    cardIssuanceWorkerDeleteAsset($tenantId, $key);
}

/** @param array<string,mixed> $conversation @param array<string,mixed> $draft */
