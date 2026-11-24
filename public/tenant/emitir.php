<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/design_mapping.php';
require_once dirname(__DIR__, 2) . '/config/design_editor.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker_storage.php';
require_once dirname(__DIR__, 2) . '/config/card_webp_renderer.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_staging_support.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

$pdo = dbConnection();
if (!tenantAuthStartSession()) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($context === null || !in_array($context->tipo, ['cliente', 'subcliente'], true) || $context->tenantId === null) {
    http_response_code(404);
    exit('Página não encontrada.');
}
$identity = tenantAuthCurrentUser($pdo, $context);
if ($identity === null) {
    http_response_code(401);
    exit('Acesso restrito à sessão da instituição.');
}
$tenantId = $context->tenantId;
$identityUnitId = ($identity['subcliente_id'] ?? null) === null ? null : (int) $identity['subcliente_id'];
$selectedUnitId = $identityUnitId;
if ($identityUnitId === null && isset($_REQUEST['subcliente_id']) && $_REQUEST['subcliente_id'] !== '') {
    $parsedUnitId = filter_var($_REQUEST['subcliente_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parsedUnitId === false) {
        http_response_code(400);
        exit('Unidade inválida.');
    }
    $unitQuery = $pdo->prepare('SELECT id FROM tenants WHERE id = :unit AND parent_id = :tenant AND tipo = \'subcliente\' AND status_conta = \'ativo\' LIMIT 1');
    $unitQuery->execute(['unit' => (int) $parsedUnitId, 'tenant' => $tenantId]);
    if ($unitQuery->fetchColumn() === false || !pode($pdo, $context, $identity, 'carteirinha.gerar', (int) $parsedUnitId)) {
        http_response_code(404);
        exit('Origem não encontrada.');
    }
    $selectedUnitId = (int) $parsedUnitId;
}
if (!pode($pdo, $context, $identity, 'carteirinha.gerar', $selectedUnitId)) {
    http_response_code(403);
    exit('Sua conta não tem permissão para emitir carteirinhas.');
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método não permitido.');
}
$error = '';
$notice = (string) ($_SESSION['tenant_issuance_notice'] ?? '');
unset($_SESSION['tenant_issuance_notice']);
if ($method === 'POST') {
    if (!tenantAuthVerifyCsrf($_SESSION, $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('A solicitação expirou. Atualize a página e tente novamente.');
    }
    $sourceType = is_string($_POST['source_type'] ?? null) ? $_POST['source_type'] : '';
    $sourceId = filter_var($_POST['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $designId = filter_var($_POST['design_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $outputMode = is_string($_POST['output_mode'] ?? null) ? $_POST['output_mode'] : '';
    $requestKey = is_string($_POST['idempotency_key'] ?? null) ? $_POST['idempotency_key'] : '';
    $mappingOverride = ($_POST['mapping_mode'] ?? 'fixed') === 'custom'
        ? ($_POST['mapping_json'] ?? null)
        : null;
    try {
        if (!in_array($sourceType, ['lot', 'holder'], true) || $sourceId === false || $designId === false) {
            throw new DomainException('ISSUANCE_REQUEST_INVALID');
        }
        if (!in_array($outputMode, ['webp', 'pdf_cr80', 'pdf_a4_8up', 'pdf_a4_10up'], true)
            || $mappingOverride !== null && (!is_string($mappingOverride) || strlen($mappingOverride) > 262144)) {
            throw new DomainException('ISSUANCE_REQUEST_INVALID');
        }
        if (!pode($pdo, $context, $identity, 'carteirinha.gerar', $selectedUnitId)) {
            throw new DomainException('ISSUANCE_DENIED');
        }
        $targetSourceLot = $sourceType === 'lot' ? (int) $sourceId : null;
        $targetHolder = $sourceType === 'holder' ? (int) $sourceId : null;
        if (($_POST['action'] ?? '') === 'preview') {
            $design = issuanceWizardFindDesign($pdo, $tenantId, $selectedUnitId, (int) $designId);
            if ($design === null) throw new DomainException('ISSUANCE_DESIGN_NOT_FOUND');
            $quantity = cardIssuanceSourceQuantity(
                $pdo, $tenantId, $selectedUnitId, $targetSourceLot, $targetHolder, $design,
                $outputMode, $mappingOverride
            );
            $sample = issuanceWizardFindSample($pdo, $tenantId, $selectedUnitId, $sourceType, (int) $sourceId);
            if ($sample === null) throw new DomainException('ISSUANCE_SOURCE_NOT_FOUND');
            $designMap = json_decode((string) $design['mapa_campos_json'], true, 512, JSON_THROW_ON_ERROR);
            $resolvedMapping = issuanceWizardDecodeMapping($mappingOverride)
                ?? json_decode((string) $design['mapeamento_fixo_json'], true, 512, JSON_THROW_ON_ERROR);
            $extra = json_decode((string) ($sample['dados_extras_json'] ?? '{}'), true);
            $record = ['titular' => $sample + ['dados_extras' => is_array($extra) ? $extra : []]];
            $systemContext = [];
            foreach ($designMap['elementos'] as $element) {
                if (($element['tipo'] ?? null) === 'qrcode_validacao') $systemContext['_sistema_url_validacao'] = 'https://preview.invalid/validation';
                if (($element['tipo'] ?? null) === 'codigo_barras') $resolvedMapping['mapeamentos'][$element['chave_variavel']] = ['tipo' => 'valor', 'valor' => 'IEPR-PREVIEW-0001'];
            }
            $resolved = designMappingResolve($designMap, $resolvedMapping, $record, $systemContext);
            if (!$resolved['valid']) throw new DomainException('ISSUANCE_MAPPING_INVALID');
            foreach ($designMap['elementos'] as $element) {
                if (($element['tipo'] ?? null) === 'codigo_barras') $resolved['values'][$element['chave_variavel']] = 'IEPR-PREVIEW-0001';
            }
            $backgrounds = [];
            foreach (['frente' => 'bg_frente_url', 'verso' => 'bg_verso_url'] as $side => $column) {
                $key = (string) ($design[$column] ?? '');
                $backgrounds[$side] = $key === '' ? null : cardIssuanceWorkerReadAsset($tenantId, $key);
            }
            $photoAssets = [];
            if (is_string($sample['foto_url'] ?? null) && $sample['foto_url'] !== '') {
                $photoAssets['foto'] = cardIssuanceWorkerReadAsset($tenantId, $sample['foto_url']);
            }
            $preview = cardRenderWebpSides($designMap, $resolved['values'], $backgrounds, $photoAssets);
            if (!$preview['valid']) throw new DomainException('ISSUANCE_MAPPING_INVALID');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'valid' => true, 'quantity' => $quantity,
                'images' => [
                    'frente' => 'data:image/webp;base64,' . base64_encode($preview['images']['frente']),
                    'verso' => 'data:image/webp;base64,' . base64_encode($preview['images']['verso']),
                ],
                'message' => 'Prévia renderizada com uma amostra autorizada; nenhum job ou cota foi criado.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (($_POST['action'] ?? '') !== 'enqueue') throw new DomainException('ISSUANCE_REQUEST_INVALID');
        $created = cardIssuanceCreateJob(
            $pdo, $context, $identity, $tenantId, $selectedUnitId, $targetSourceLot, (int) $designId,
            $outputMode, $requestKey, null, issuanceWizardDecodeMapping($mappingOverride), $targetHolder
        );
        $_SESSION['tenant_issuance_notice'] = $created['reused']
            ? 'Esta solicitação já estava na fila. Job #' . $created['job_id'] . ' reutilizado.'
            : 'Emissão adicionada à fila como job #' . $created['job_id'] . '.';
        $redirect = '/tenant/emitir.php' . ($selectedUnitId === null ? '' : '?subcliente_id=' . $selectedUnitId);
        session_write_close();
        header('Location: ' . $redirect, true, 303);
        exit;
    } catch (DomainException $exception) {
        if (($_POST['action'] ?? '') === 'preview') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['valid' => false, 'error' => issuanceWizardPublicError($exception->getMessage())], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = issuanceWizardPublicError($exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Issuance request failed: ' . get_class($exception));
        if (($_POST['action'] ?? '') === 'preview') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['valid' => false, 'error' => 'Não foi possível validar esta prévia. Revise os dados e tente novamente.'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = 'Não foi possível adicionar a emissão à fila. Revise os dados e tente novamente.';
    }
}

$csrfToken = tenantAuthCsrfToken($_SESSION);
$idempotencyKey = bin2hex(random_bytes(32));
session_write_close();
$units = issuanceWizardListUnits($pdo, $context, $identity);
$sources = issuanceWizardListSources($pdo, $tenantId, $selectedUnitId);
$designs = issuanceWizardListDesigns($pdo, $tenantId, $selectedUnitId);
$quota = issuanceWizardQuota($pdo, $tenantId, $selectedUnitId);
$recentJobs = issuanceWizardListRecentJobs($pdo, $tenantId, $selectedUnitId);
$fieldOptions = issuanceWizardFieldOptions($pdo, $tenantId, $selectedUnitId);
require dirname(__DIR__, 2) . '/views/tenant/emitir.php';

/** @return list<array{id:int,name:string}> */
function issuanceWizardListUnits(PDO $pdo, TenantContext $context, array $identity): array
{
    if ($context->tipo !== 'cliente' || ($identity['subcliente_id'] ?? null) !== null) return [];
    $query = $pdo->prepare("SELECT id, nome_organizacao FROM tenants WHERE parent_id = :tenant AND tipo = 'subcliente' AND status_conta = 'ativo' ORDER BY nome_organizacao");
    $query->execute(['tenant' => (int) $context->tenantId]);
    $units = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (pode($pdo, $context, $identity, 'carteirinha.gerar', (int) $row['id'])) {
            $units[] = ['id' => (int) $row['id'], 'name' => (string) $row['nome_organizacao']];
        }
    }
    return $units;
}

/** @return array{lots:list<array<string,mixed>>,holders:list<array<string,mixed>>} */
function issuanceWizardListSources(PDO $pdo, int $tenantId, ?int $unitId): array
{
    $lotScope = $unitId === null ? 'li.subcliente_id IS NULL' : 'li.subcliente_id = :lot_unit';
    $holderScope = $unitId === null ? 't.subcliente_id IS NULL' : 't.subcliente_id = :holder_unit';
    $params = ['tenant' => $tenantId];
    if ($unitId !== null) {
        $params['lot_unit'] = $unitId;
        $params['holder_unit'] = $unitId;
    }
    $lots = $pdo->prepare(
        "SELECT li.id, li.criado_em, COUNT(t.id) AS quantidade FROM lotes_importacao li "
        . "INNER JOIN titulares t ON t.lote_origem_id = li.id AND t.tenant_id = li.tenant_id AND {$holderScope} "
        . "WHERE li.tenant_id = :tenant AND {$lotScope} AND li.status_lote = 'concluido' "
        . "AND li.origem_tipo <> 'emissao' AND li.lote_origem_id IS NULL AND li.titular_origem_id IS NULL "
        . 'GROUP BY li.id, li.criado_em ORDER BY li.id DESC LIMIT 100'
    );
    $lots->execute($params);
    $holders = $pdo->prepare('SELECT id, nome_completo, matricula, lote_origem_id FROM titulares WHERE tenant_id = :tenant AND '
        . ($unitId === null ? 'subcliente_id IS NULL' : 'subcliente_id = :unit') . ' AND ativo = 1 ORDER BY id DESC LIMIT 100');
    $holders->execute(['tenant' => $tenantId] + ($unitId === null ? [] : ['unit' => $unitId]));
    return ['lots' => $lots->fetchAll(PDO::FETCH_ASSOC), 'holders' => $holders->fetchAll(PDO::FETCH_ASSOC)];
}

/** @return list<array<string,mixed>> */
function issuanceWizardListDesigns(PDO $pdo, int $tenantId, ?int $unitId): array
{
    $sql = 'SELECT id, nome_layout, orientacao, mapa_campos_json, mapeamento_fixo_json FROM designs_carteirinha WHERE tenant_id = :tenant AND ativo = 1';
    if ($unitId !== null) $sql .= ' AND compartilhado_subclientes = 1';
    $sql .= ' ORDER BY criado_em DESC, id DESC';
    $query = $pdo->prepare($sql);
    $query->execute(['tenant' => $tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function issuanceWizardFindDesign(PDO $pdo, int $tenantId, ?int $unitId, int $designId): ?array
{
    $sql = 'SELECT id, mapa_campos_json, mapeamento_fixo_json, bg_frente_url, bg_verso_url FROM designs_carteirinha WHERE id = :id AND tenant_id = :tenant AND ativo = 1';
    if ($unitId !== null) $sql .= ' AND compartilhado_subclientes = 1';
    $query = $pdo->prepare($sql . ' LIMIT 1');
    $query->execute(['id' => $designId, 'tenant' => $tenantId]);
    $design = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($design) ? $design : null;
}

/** @return array<string,mixed>|null */
function issuanceWizardFindSample(PDO $pdo, int $tenantId, ?int $unitId, string $sourceType, int $sourceId): ?array
{
    if ($sourceType === 'holder') {
        $query = $pdo->prepare(
            'SELECT id, nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, data_batismo_ou_admissao, '
            . 'whatsapp, email_login, foto_url, dados_extras_json FROM titulares '
            . 'WHERE id = :id AND tenant_id = :tenant AND ativo = 1 AND ((subcliente_id = :unit_match) OR (subcliente_id IS NULL AND :unit_null IS NULL)) LIMIT 1'
        );
        $query->execute(['id' => $sourceId, 'tenant' => $tenantId, 'unit_match' => $unitId, 'unit_null' => $unitId]);
    } else {
        $query = $pdo->prepare(
            'SELECT t.id, t.nome_completo, t.cpf, t.rg, t.cargo_funcao, t.matricula, t.data_nascimento, '
            . 't.data_batismo_ou_admissao, t.whatsapp, t.email_login, t.foto_url, t.dados_extras_json '
            . 'FROM titulares t INNER JOIN lotes_importacao li ON li.id = t.lote_origem_id AND li.tenant_id = t.tenant_id '
            . 'WHERE li.id = :id AND li.tenant_id = :lot_tenant AND li.status_lote = \'concluido\' AND li.origem_tipo <> \'emissao\' '
            . 'AND t.tenant_id = :holder_tenant AND ((t.subcliente_id = :unit_match) OR (t.subcliente_id IS NULL AND :unit_null IS NULL)) '
            . 'ORDER BY t.id LIMIT 1'
        );
        $query->execute(['id' => $sourceId, 'lot_tenant' => $tenantId, 'holder_tenant' => $tenantId, 'unit_match' => $unitId, 'unit_null' => $unitId]);
    }
    $sample = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($sample) ? $sample : null;
}

/** @return array{limit:int|null,used:int,available:int|null} */
function issuanceWizardQuota(PDO $pdo, int $tenantId, ?int $unitId): array
{
    $rootQuery = $pdo->prepare("SELECT limite_carteirinhas, consumo_carteirinhas FROM tenants WHERE id = :tenant AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1");
    $rootQuery->execute(['tenant' => $tenantId]);
    $root = $rootQuery->fetch(PDO::FETCH_ASSOC) ?: [];
    $rootLimit = ($root['limite_carteirinhas'] ?? null) === null ? null : (int) $root['limite_carteirinhas'];
    $rootUsed = (int) ($root['consumo_carteirinhas'] ?? 0);
    $rootAvailable = $rootLimit === null ? null : max(0, $rootLimit - $rootUsed);
    if ($unitId === null) return ['limit' => $rootLimit, 'used' => $rootUsed, 'available' => $rootAvailable];
    $unitQuery = $pdo->prepare("SELECT limite_carteirinhas, consumo_carteirinhas FROM tenants WHERE id = :unit AND parent_id = :tenant AND tipo = 'subcliente' LIMIT 1");
    $unitQuery->execute(['unit' => $unitId, 'tenant' => $tenantId]);
    $unit = $unitQuery->fetch(PDO::FETCH_ASSOC) ?: [];
    $unitLimit = ($unit['limite_carteirinhas'] ?? null) === null ? null : (int) $unit['limite_carteirinhas'];
    $unitUsed = (int) ($unit['consumo_carteirinhas'] ?? 0);
    $unitAvailable = $unitLimit === null ? $rootAvailable : max(0, $unitLimit - $unitUsed);
    $available = $rootAvailable === null ? null : ($unitAvailable === null ? $rootAvailable : min($rootAvailable, $unitAvailable));
    return ['limit' => min($rootLimit ?? PHP_INT_MAX, $unitLimit ?? PHP_INT_MAX), 'used' => $rootUsed + $unitUsed, 'available' => $available];
}

/** @return list<array{id:int,design:string,quantity:int,status:string,created:string,done:int,failed:int}> */
function issuanceWizardListRecentJobs(PDO $pdo, int $tenantId, ?int $unitId): array
{
    $scope = $unitId === null ? 'li.subcliente_id IS NULL' : 'li.subcliente_id = :unit';
    $query = $pdo->prepare(
        'SELECT li.id, d.nome_layout, li.total_registros, li.status_lote, li.criado_em, li.processados_sucesso, li.processados_erro '
        . 'FROM lotes_importacao li LEFT JOIN designs_carteirinha d ON d.id = li.design_id AND d.tenant_id = li.tenant_id '
        . 'WHERE li.tenant_id = :tenant AND ' . $scope . " AND li.origem_tipo = 'emissao' ORDER BY li.id DESC LIMIT 10"
    );
    $query->execute(['tenant' => $tenantId] + ($unitId === null ? [] : ['unit' => $unitId]));
    $jobs = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jobs[] = [
            'id' => (int) $row['id'], 'design' => (string) ($row['nome_layout'] ?? 'Design indisponível'),
            'quantity' => (int) $row['total_registros'], 'status' => (string) $row['status_lote'],
            'created' => (string) ($row['criado_em'] ?? ''), 'done' => (int) $row['processados_sucesso'],
            'failed' => (int) $row['processados_erro'],
        ];
    }
    return $jobs;
}

/** @return array<string,string> */
function issuanceWizardFieldOptions(PDO $pdo, int $tenantId, ?int $unitId): array
{
    $fields = [
        'titular.nome_completo' => 'Nome completo', 'titular.cpf' => 'CPF', 'titular.rg' => 'RG',
        'titular.cargo_funcao' => 'Cargo/função', 'titular.matricula' => 'Matrícula',
        'titular.data_nascimento' => 'Data de nascimento', 'titular.data_batismo_ou_admissao' => 'Data de admissão/batismo',
        'titular.whatsapp' => 'WhatsApp', 'titular.email_login' => 'E-mail',
    ];
    $query = $pdo->prepare('SELECT dados_extras_json FROM titulares WHERE tenant_id = :tenant AND '
        . ($unitId === null ? 'subcliente_id IS NULL' : 'subcliente_id = :unit')
        . ' AND ativo = 1 ORDER BY id DESC LIMIT 100');
    $query->execute(['tenant' => $tenantId] + ($unitId === null ? [] : ['unit' => $unitId]));
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $extra = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($extra)) continue;
        foreach (array_keys($extra) as $key) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,119}$/D', $key) !== 1) continue;
            $path = 'titular.dados_extras.' . $key;
            $fields[$path] = 'Campo extra: ' . $key;
        }
    }
    return $fields;
}

function issuanceWizardDecodeMapping(mixed $mapping): mixed
{
    if ($mapping === null || !is_string($mapping)) return $mapping;
    try { return json_decode($mapping, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new DomainException('ISSUANCE_MAPPING_INVALID'); }
}

function issuanceWizardPublicError(string $code): string
{
    return match ($code) {
        'ISSUANCE_SOURCE_NOT_FOUND' => 'A origem não está disponível no seu escopo ou não pode mais ser usada.',
        'ISSUANCE_SOURCE_EMPTY_OR_TOO_LARGE' => 'A origem está vazia ou excede o limite da emissão.',
        'ISSUANCE_PDF_MAX_50' => 'PDF consolidado aceita até 50 titulares por job.',
        'ISSUANCE_MAPPING_INVALID' => 'Revise o mapeamento e os campos obrigatórios do design.',
        'ISSUANCE_DESIGN_NOT_FOUND' => 'O design não está ativo ou não está disponível para esta unidade.',
        'COTA_EXCEDIDA' => 'A cota disponível não cobre esta emissão. Nenhum job foi criado.',
        'TENANT_QUOTA_UNCONFIGURED' => 'A cota de carteirinhas ainda não foi configurada.',
        'ISSUANCE_IDEMPOTENCY_CONFLICT' => 'Esta chave já foi usada por uma solicitação diferente. Atualize a página e revise a emissão.',
        'ISSUANCE_DENIED' => 'Sua conta não tem permissão para emitir neste escopo.',
        default => 'Revise a origem, o design, o modo de saída e tente novamente.',
    };
}
