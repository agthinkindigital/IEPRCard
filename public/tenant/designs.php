<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/design_editor.php';
require_once dirname(__DIR__, 2) . '/config/card_webp_renderer_images.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

$pdo = dbConnection();
$context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($context === null || $context->tipo !== 'cliente' || $context->tenantId === null || $context->subclienteId !== null) {
    http_response_code(404);
    exit('Página não encontrada.');
}
if (!tenantAuthStartSession()) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$identity = tenantAuthCurrentUser($pdo, $context);
if ($identity === null) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$tenantId = $context->tenantId;
$canView = pode($pdo, $context, $identity, 'design.ver');
$canCreate = pode($pdo, $context, $identity, 'design.criar');
$canEdit = pode($pdo, $context, $identity, 'design.editar');
$canMap = pode($pdo, $context, $identity, 'design.mapear');
if (!$canView) {
    http_response_code(403);
    exit('Sua conta não tem permissão para consultar designs.');
}

$designId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (array_key_exists('id', $_GET) && $designId === false) {
    http_response_code(400);
    exit('Identificador de design inválido.');
}
$design = $designId === false ? null : designEditorFind($pdo, $tenantId, (int) $designId);
if ($designId !== false && $design === null) {
    http_response_code(404);
    exit('Design não encontrado.');
}
$hasPendingDesignJobs = $design !== null && designEditorHasPendingJobs($pdo, $tenantId, (int) $design['id']);
if (isset($_GET['asset'])) {
    $side = is_string($_GET['asset']) ? $_GET['asset'] : '';
    $column = ['frente' => 'bg_frente_url', 'verso' => 'bg_verso_url'][$side] ?? null;
    $path = $column !== null && $design !== null
        ? designEditorResolveBackground($tenantId, (string) $design[$column])
        : null;
    $imageInfo = is_string($path) ? getimagesize($path) : false;
    $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
    if (!is_string($path) || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        http_response_code(404);
        exit('Imagem não encontrada.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="background.' . ($mime === 'image/jpeg' ? 'jpg' : substr($mime, 6)) . '"');
    readfile($path);
    exit;
}
$notice = (string) ($_SESSION['design_editor_notice'] ?? '');
unset($_SESSION['design_editor_notice']);
$error = '';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('A solicitação expirou. Atualize a página e tente novamente.');
    }
    if (($_POST['action'] ?? '') === 'preview') {
        $previewMapValidation = designMapValidate($_POST['mapa_campos_json'] ?? null);
        if (!$previewMapValidation['valid']) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => $previewMapValidation['errors'][0]['code'], 'field' => $previewMapValidation['errors'][0]['path']]));
        }
        $previewMap = is_string($_POST['mapa_campos_json']) ? json_decode($_POST['mapa_campos_json'], true) : $_POST['mapa_campos_json'];
        $previewUploads = [];
        foreach (['frente' => 'fundo_frente', 'verso' => 'fundo_verso'] as $side => $field) {
            $file = $_FILES[$field] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ((int) ($file['error'] ?? UPLOAD_ERR_CANT_WRITE) !== UPLOAD_ERR_OK
                || !isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['error' => 'invalid_upload']));
            }
            $bytes = file_get_contents((string) $file['tmp_name']);
            $decoded = is_string($bytes) ? cardRenderDecodeImage($bytes, '$.fundo.' . $side) : ['errors' => [['code' => 'invalid_image']]];
            if (!isset($decoded['image'])) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['error' => 'invalid_upload']));
            }
            unset($decoded['image']);
            $previewUploads[$side] = $bytes;
        }
        $previewResult = designEditorPreview($tenantId, $design ?? [], $previewMap, $previewUploads);
        header('Content-Type: application/json; charset=utf-8');
        if (!$previewResult['valid']) {
            http_response_code(422);
            exit(json_encode(['error' => 'preview_failed']));
        }
        exit(json_encode(['images' => [
            'frente' => 'data:image/webp;base64,' . base64_encode($previewResult['images']['frente']),
            'verso' => 'data:image/webp;base64,' . base64_encode($previewResult['images']['verso']),
        ]]));
    }
    $postedId = filter_var($_POST['design_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $isEdit = $postedId !== false;
    if (($isEdit && (!$canEdit || $design === null || (int) $design['id'] !== (int) $postedId || (int) $design['ativo'] !== 0))
        || (!$isEdit && !$canCreate)) {
        http_response_code(403);
        exit('Sua conta não pode salvar este design.');
    }
    if ($isEdit && designEditorHasPendingJobs($pdo, $tenantId, (int) $postedId)) {
        http_response_code(409);
        exit('Este design tem emissões pendentes e não pode ser alterado até que elas terminem.');
    }
    if (!$canMap) {
        http_response_code(403);
        exit('Sua conta não tem permissão para alterar o mapeamento.');
    }
    $validated = designEditorValidate($_POST);
    if (!$validated['valid']) {
        $error = 'Revise o campo ' . (string) ($validated['path'] ?? 'do design') . ' (' . (string) ($validated['code'] ?? 'invalid') . ').';
    } else {
        $newBackgroundKeys = [];
        try {
            $backgrounds = designEditorStoreBackgrounds($tenantId, $design ?? [], $_FILES);
            $oldKeys = array_values(array_filter([
                (string) ($design['bg_frente_url'] ?? ''),
                (string) ($design['bg_verso_url'] ?? ''),
            ]));
            $newBackgroundKeys = array_values(array_diff(array_filter($backgrounds), $oldKeys));
            $pdo->beginTransaction();
            $parameters = [
                'name' => trim((string) $_POST['nome_layout']),
                'front' => $backgrounds['frente'] !== '' ? $backgrounds['frente'] : null,
                'back' => $backgrounds['verso'] !== '' ? $backgrounds['verso'] : null,
                'map' => json_encode($validated['map'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'mapping' => json_encode($validated['mapping'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if ($isEdit) {
                $update = $pdo->prepare(
                    'UPDATE designs_carteirinha SET nome_layout = :name, bg_frente_url = :front, bg_verso_url = :back, '
                    . 'mapa_campos_json = :map, mapeamento_fixo_json = :mapping, ativo = 0 '
                    . 'WHERE id = :id AND tenant_id = :tenant AND ativo = 0'
                );
                $update->execute($parameters + ['id' => (int) $postedId, 'tenant' => $tenantId]);
                if ($update->rowCount() !== 1) {
                    $stillDraft = $pdo->prepare('SELECT 1 FROM designs_carteirinha WHERE id = :id AND tenant_id = :tenant AND ativo = 0 LIMIT 1');
                    $stillDraft->execute(['id' => (int) $postedId, 'tenant' => $tenantId]);
                    if ($stillDraft->fetchColumn() === false) throw new DomainException('design_not_editable');
                }
                $savedId = (int) $postedId;
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, largura_px, altura_px, '
                    . 'bg_frente_url, bg_verso_url, mapa_campos_json, mapeamento_fixo_json, compartilhado_subclientes, ativo, criado_em) '
                    . 'VALUES (:tenant, :name, :orientation, 1011, 638, :front, :back, :map, :mapping, 0, 0, :created)'
                );
                $insert->execute($parameters + ['tenant' => $tenantId, 'orientation' => 'horizontal', 'created' => dbAgora()]);
                $savedId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            $newBackgroundKeys = [];
            $_SESSION['design_editor_notice'] = 'Rascunho salvo.';
            session_write_close();
            header('Location: /tenant/designs.php?id=' . $savedId, true, 303);
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newBackgroundKeys as $key) designEditorDeleteBackground($tenantId, $key);
            $message = $exception->getMessage();
            if (in_array($message, ['invalid_upload_frente', 'invalid_upload_verso'], true)) {
                $side = $message === 'invalid_upload_frente' ? 'da frente' : 'do verso';
                $error = 'O fundo ' . $side . ' deve ser PNG, JPEG ou WebP válido, até 10 MB e 4 megapixels.';
            } else {
                $error = 'Este design não pode mais ser editado.';
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newBackgroundKeys as $key) designEditorDeleteBackground($tenantId, $key);
            $error = str_contains(strtolower($exception->getMessage()), 'unique')
                ? 'Já existe um design com esse nome.'
                : 'Não foi possível salvar o design. Tente novamente.';
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newBackgroundKeys as $key) designEditorDeleteBackground($tenantId, $key);
            $error = 'Não foi possível salvar o design. Verifique os fundos e tente novamente.';
        }
    }
}

$csrfToken = tenantAuthCsrfToken($_SESSION);
session_write_close();
$defaults = designEditorDefaults();
$map = $defaults['map'];
$mapping = $defaults['mapping'];
$backgroundPreview = ['frente' => null, 'verso' => null];
if (is_array($design)) {
    $decodedMap = json_decode((string) ($design['mapa_campos_json'] ?? ''), true);
    $decodedMapping = json_decode((string) ($design['mapeamento_fixo_json'] ?? ''), true);
    if (is_array($decodedMap) && designMapValidate($decodedMap)['valid']) $map = $decodedMap;
    if (is_array($decodedMapping)) $mapping = $decodedMapping;
    $frontPath = designEditorResolveBackground($tenantId, (string) ($design['bg_frente_url'] ?? ''));
    $backPath = designEditorResolveBackground($tenantId, (string) ($design['bg_verso_url'] ?? ''));
    if (is_string($frontPath)) $backgroundPreview['frente'] = '/tenant/designs.php?id=' . (int) $design['id'] . '&asset=frente';
    if (is_string($backPath)) $backgroundPreview['verso'] = '/tenant/designs.php?id=' . (int) $design['id'] . '&asset=verso';
}
if (isset($_POST['mapa_campos_json']) && is_string($_POST['mapa_campos_json'])) {
    $postedMap = json_decode($_POST['mapa_campos_json'], true);
    if (is_array($postedMap)) $map = $postedMap;
    if (isset($_POST['mapeamento_fixo_json'])) {
        $postedMapping = json_decode((string) $_POST['mapeamento_fixo_json'], true);
        if (is_array($postedMapping)) $mapping = $postedMapping;
    }
}
$preview = designEditorPreview($tenantId, is_array($design) ? $design : [], $map);
$previewData = $preview['valid'] ? [
    'frente' => 'data:image/webp;base64,' . base64_encode($preview['images']['frente']),
    'verso' => 'data:image/webp;base64,' . base64_encode($preview['images']['verso']),
] : $backgroundPreview;
$designs = designEditorList($pdo, $tenantId);
$canSave = $canMap && (($design === null && $canCreate) || ($design !== null && $canEdit && (int) $design['ativo'] === 0 && !$hasPendingDesignJobs));

require dirname(__DIR__, 2) . '/views/tenant/designs.php';
