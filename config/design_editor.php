<?php
declare(strict_types=1);

require_once __DIR__ . '/design_map_contract.php';
require_once __DIR__ . '/design_mapping.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/privacy_subjects.php';
require_once __DIR__ . '/privacy_snapshot_labels.php';

/** @return list<array{id:int,name:string}> */
function designEditorTitularChoices(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare('SELECT id, nome_completo FROM titulares WHERE tenant_id = :tenant ORDER BY nome_completo, id');
    $query->execute(['tenant' => $tenantId]);
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nome_completo']], $query->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array<string,array{classificacao:string,titular_id:int|null}> */
function designEditorBackgroundPrivacy(PDO $pdo, int $designId): array
{
    $query = $pdo->prepare('SELECT lado, classificacao, titular_id FROM design_fundos_privacidade WHERE design_id = :design');
    $query->execute(['design' => $designId]);
    $result = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(string) $row['lado']] = ['classificacao' => (string) $row['classificacao'], 'titular_id' => $row['titular_id'] === null ? null : (int) $row['titular_id']];
    }
    return $result;
}

/** @param array<string,string> $backgrounds @param array<string,mixed> $input */
function designEditorSaveBackgroundPrivacy(PDO $pdo, int $tenantId, int $designId, array $backgrounds, array $input): void
{
    foreach (['frente', 'verso'] as $side) {
        $classification = $input['fundo_' . $side . '_classificacao'] ?? '';
        $holderId = filter_var($input['fundo_' . $side . '_titular_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $key = $backgrounds[$side] ?? '';
        $exists = $pdo->prepare('SELECT id FROM design_fundos_privacidade WHERE design_id = :design AND lado = :side LIMIT 1');
        $exists->execute(['design' => $designId, 'side' => $side]);
        $rowId = $exists->fetchColumn();
        if ($key === '' && $rowId === false) continue;
        if (!is_string($classification) || !in_array($classification, ['decorativo', 'titular'], true)) {
            throw new DomainException('design_background_classification_invalid');
        }
        if ($classification === 'titular') {
            if ($holderId === false) throw new DomainException('design_background_holder_required');
            $holder = $pdo->prepare('SELECT id FROM titulares WHERE id = :id AND tenant_id = :tenant LIMIT 1');
            $holder->execute(['id' => $holderId, 'tenant' => $tenantId]);
            if ($holder->fetchColumn() === false) throw new DomainException('design_background_holder_invalid');
        } else {
            $holderId = null;
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($rowId === false) {
            $insert = $pdo->prepare(
                'INSERT INTO design_fundos_privacidade (tenant_id, design_id, lado, classificacao, titular_id, asset_url, criado_em, atualizado_em) '
                . 'VALUES (:tenant, :design, :side, :classification, :holder, :asset, :created, :updated)'
            );
            $insert->execute(['tenant' => $tenantId, 'design' => $designId, 'side' => $side, 'classification' => $classification,
                'holder' => $holderId, 'asset' => $key === '' ? null : $key, 'created' => $now, 'updated' => $now]);
            $rowId = (int) $pdo->lastInsertId();
        } else {
            $update = $pdo->prepare(
                'UPDATE design_fundos_privacidade SET classificacao = :classification, titular_id = :holder, '
                . 'asset_url = COALESCE(:asset, asset_url), atualizado_em = :updated WHERE id = :id AND tenant_id = :tenant'
            );
            $update->execute(['classification' => $classification, 'holder' => $holderId, 'asset' => $key === '' ? null : $key,
                'updated' => $now, 'id' => $rowId, 'tenant' => $tenantId]);
        }
        $pdo->prepare("DELETE FROM privacy_subject_records WHERE tipo_registro = 'design_fundo' AND registro_id = :id")
            ->execute(['id' => $rowId]);
        $subject = $classification === 'titular'
            ? privacySubjectEnsureTitular($pdo, (int) $holderId, $tenantId)
            : privacySubjectEnsureForRecord($pdo, 'tenant_contato', $tenantId, $tenantId);
        privacySubjectLinkRecord($pdo, (int) $subject['id'], 'design_fundo', (int) $rowId);
    }
}

/** @return list<array<string,mixed>> */
function designEditorList(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare(
        'SELECT id, nome_layout, orientacao, ativo, criado_em FROM designs_carteirinha '
        . 'WHERE tenant_id = :tenant ORDER BY criado_em DESC, id DESC'
    );
    $statement->execute(['tenant' => $tenantId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function designEditorFind(PDO $pdo, int $tenantId, int $designId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, tenant_id, nome_layout, orientacao, largura_px, altura_px, bg_frente_url, bg_verso_url, '
        . 'mapa_campos_json, mapeamento_fixo_json, ativo, criado_em FROM designs_carteirinha '
        . 'WHERE id = :id AND tenant_id = :tenant LIMIT 1'
    );
    $statement->execute(['id' => $designId, 'tenant' => $tenantId]);
    $design = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($design) ? $design : null;
}

function designEditorHasPendingJobs(PDO $pdo, int $tenantId, int $designId): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM lotes_importacao WHERE tenant_id = :tenant AND design_id = :design "
        . "AND status_lote IN ('aguardando_mapa', 'na_fila', 'processando') LIMIT 1"
    );
    $statement->execute(['tenant' => $tenantId, 'design' => $designId]);
    return $statement->fetchColumn() !== false;
}

/** @return array{map: array<string,mixed>, mapping: array<string,mixed>} */
function designEditorDefaults(): array
{
    return [
        'map' => ['versao_schema' => '1.0', 'dimensoes' => ['largura_px' => 1011, 'altura_px' => 638, 'dpi' => 300, 'padrao' => 'CR80'], 'elementos' => []],
        'mapping' => ['versao_schema' => '1.0', 'mapeamentos' => (object) []],
    ];
}

/** @param array<string,mixed> $input @return array{valid:bool,code?:string,path?:string,map?:array,mapping?:array} */
function designEditorValidate(array $input): array
{
    $name = trim((string) ($input['nome_layout'] ?? ''));
    if ($name === '' || strlen($name) > 120) return ['valid' => false, 'code' => 'invalid_name', 'path' => 'nome_layout'];
    try {
        privacySnapshotAssertNoPiiLabel('designs_carteirinha', ['nome_layout' => $name]);
    } catch (RuntimeException) {
        return ['valid' => false, 'code' => 'label_contains_personal_data', 'path' => 'nome_layout'];
    }
    $mapRaw = $input['mapa_campos_json'] ?? null;
    $mapValidation = designMapValidate($mapRaw);
    if (!$mapValidation['valid']) {
        $error = $mapValidation['errors'][0];
        return ['valid' => false, 'code' => $error['code'], 'path' => 'mapa_campos_json' . $error['path']];
    }
    try {
        $map = is_string($mapRaw) ? json_decode($mapRaw, true, 512, JSON_THROW_ON_ERROR) : $mapRaw;
        $mappingRaw = $input['mapeamento_fixo_json'] ?? '';
        $mapping = is_string($mappingRaw) ? json_decode($mappingRaw, true, 512, JSON_THROW_ON_ERROR) : $mappingRaw;
    } catch (JsonException) {
        return ['valid' => false, 'code' => 'invalid_json', 'path' => 'mapeamento_fixo_json'];
    }
    if (!is_array($mapping) || array_is_list($mapping)
        || count(array_diff(array_keys($mapping), ['versao_schema', 'mapeamentos'])) > 0
        || ($mapping['versao_schema'] ?? null) !== '1.0'
        || !isset($mapping['mapeamentos']) || !is_array($mapping['mapeamentos'])
        || (array_is_list($mapping['mapeamentos']) && $mapping['mapeamentos'] !== [])) {
        return ['valid' => false, 'code' => 'invalid_mapping', 'path' => 'mapeamento_fixo_json'];
    }
    $variables = [];
    foreach ($map['elementos'] as $element) $variables[$element['chave_variavel']] = true;
    foreach ($mapping['mapeamentos'] as $target => $definition) {
        if (!is_string($target) || str_starts_with($target, '_sistema_') || !isset($variables[$target])
            || !designMappingDefinitionIsValid($definition)) {
            return ['valid' => false, 'code' => 'invalid_mapping', 'path' => 'mapeamento_fixo_json.mapeamentos'];
        }
    }
    return ['valid' => true, 'map' => $map, 'mapping' => $mapping];
}

/** @param array<string,mixed> $design @param array<string,mixed> $uploads @return array<string,string> */
function designEditorStoreBackgrounds(int $tenantId, array $design, array $uploads): array
{
    $keys = ['frente' => (string) ($design['bg_frente_url'] ?? ''), 'verso' => (string) ($design['bg_verso_url'] ?? '')];
    $validatedUploads = [];
    foreach ($keys as $side => $current) {
        $file = $uploads['fundo_' . $side] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ((int) ($file['error'] ?? UPLOAD_ERR_CANT_WRITE) !== UPLOAD_ERR_OK
            || !isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('invalid_upload_' . $side);
        }
        $bytes = file_get_contents((string) $file['tmp_name']);
        if (!is_string($bytes) || !function_exists('cardRenderDecodeImage')) throw new DomainException('invalid_upload_' . $side);
        $decoded = cardRenderDecodeImage($bytes, '$.fundo.' . $side);
        if (!isset($decoded['image'])) throw new DomainException('invalid_upload_' . $side);
        unset($decoded['image']);
        $info = getimagesizefromstring($bytes);
        $extension = match ($info['mime'] ?? '') { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => null };
        if ($extension === null) throw new DomainException('invalid_upload_' . $side);
        $validatedUploads[$side] = ['bytes' => $bytes, 'extension' => $extension];
    }
    if ($validatedUploads === []) return $keys;

    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($directory)) throw new RuntimeException('DESIGN_STORAGE_UNAVAILABLE');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('DESIGN_STORAGE_UNAVAILABLE');
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('DESIGN_STORAGE_UNAVAILABLE');
    }
    $written = [];
    try {
        foreach ($validatedUploads as $side => $upload) {
            $key = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
            $path = $realDirectory . DIRECTORY_SEPARATOR . $key;
            if (file_put_contents($path, $upload['bytes'], LOCK_EX) !== strlen($upload['bytes'])) {
                @unlink($path);
                throw new RuntimeException('DESIGN_STORAGE_UNAVAILABLE');
            }
            $written[] = $path;
            if (DIRECTORY_SEPARATOR === '/') @chmod($path, 0600);
            $keys[$side] = 'private://' . $tenantId . '/' . $key;
        }
    } catch (Throwable $exception) {
        foreach ($written as $path) @unlink($path);
        throw $exception;
    }
    return $keys;
}

function designEditorResolveBackground(int $tenantId, string $key): ?string
{
    if ($key === '') return null;
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|png|webp)$/D', $key, $matches) !== 1
        || (int) $matches[1] !== $tenantId) return null;
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    $realDirectory = realpath($directory);
    if (is_link($directory) || !is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) return null;
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path) || !is_file($path)) return null;
    $realPath = realpath($path);
    return is_string($realPath) && batchImportStagingPathWithin($realPath, $root) ? $realPath : null;
}

function designEditorDeleteBackground(int $tenantId, string $key): void
{
    try {
        $path = designEditorResolveBackground($tenantId, $key);
        if (is_string($path)) @unlink($path);
    } catch (Throwable) {
    }
}

/** @return array{valid:bool,images?:array<string,string>} */
function designEditorPreview(int $tenantId, array $design, array $map, array $uploads = []): array
{
    require_once __DIR__ . '/card_webp_renderer.php';
    $backgrounds = [];
    foreach (['frente' => 'bg_frente_url', 'verso' => 'bg_verso_url'] as $side => $column) {
        $bytes = $uploads[$side] ?? false;
        if (!is_string($bytes)) {
            $path = designEditorResolveBackground($tenantId, (string) ($design[$column] ?? ''));
            $bytes = is_string($path) ? file_get_contents($path) : false;
        }
        if (!is_string($bytes)) {
            $canvas = imagecreatetruecolor(1011, 638);
            if (!$canvas instanceof GdImage) return ['valid' => false];
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            ob_start();
            imagepng($canvas);
            $bytes = (string) ob_get_clean();
            unset($canvas);
        }
        $backgrounds[$side] = $bytes;
    }
    $values = [];
    foreach ($map['elementos'] as $element) {
        $key = $element['chave_variavel'];
        $values[$key] = match ($element['tipo']) {
            'qrcode_validacao' => 'https://example.test/preview/synthetic',
            'codigo_barras' => ($element['formato'] ?? '') === 'EAN13' ? '5901234123457' : 'IEPR-PREVIEW-001',
            default => 'Prévia fictícia',
        };
    }
    $rendered = cardRenderWebpSides($map, $values, $backgrounds);
    return $rendered['valid'] ? ['valid' => true, 'images' => $rendered['images']] : ['valid' => false];
}
