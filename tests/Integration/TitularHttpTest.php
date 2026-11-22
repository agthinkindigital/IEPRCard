<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_staging_support.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_photo_archive.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$titularHttpRoot = dirname(__DIR__, 2);
$titularHttpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-titular-http-' . bin2hex(random_bytes(6));
mkdir($titularHttpDirectory, 0700, true);
$titularHttpDatabase = $titularHttpDirectory . DIRECTORY_SEPARATOR . 'titular.sqlite';
$titularHttpPreviousEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'TENANT_BASE_DOMAIN', 'PLATFORM_HOSTNAME', 'IEPR_PRIVATE_STORAGE_PATH', 'IEPR_INTEGRATION_KEY', 'IEPR_ANONYMIZATION_KEY'] as $key) {
    $value = getenv($key);
    $titularHttpPreviousEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $titularHttpDatabase);
putenv('TENANT_BASE_DOMAIN=ieprcard.test');
putenv('PLATFORM_HOSTNAME=admin.ieprcard.test');
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $titularHttpDirectory . DIRECTORY_SEPARATOR . 'private');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

$titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
schemaGarantirEstrutura($titularHttpPdo, 'test');
schemaAplicarMigracoes($titularHttpPdo, 'test');
$titularHttpSuffix = bin2hex(random_bytes(5));
$titularHttpTenant = batchImportStagingTestCreateTenant($titularHttpPdo, $titularHttpSuffix);
$titularHttpFixture = $titularHttpTenant['fixture'];
$titularHttpPdo->prepare('UPDATE tenants SET limite_titulares = 100 WHERE id = :tenant')
    ->execute(['tenant' => $titularHttpFixture['tenant_id']]);
foreach (['titular.ver', 'titular.importar'] as $feature) {
    $titularHttpPdo->prepare(
        'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)'
    )->execute(['plan' => $titularHttpTenant['plan_id'], 'feature' => $feature]);
}
$titularHttpPdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')
    ->execute([
        'permissions' => json_encode(['titular.ver', 'titular.importar'], JSON_THROW_ON_ERROR),
        'tenant' => $titularHttpFixture['tenant_id'],
    ]);
$titularHttpPdo->prepare(
    'INSERT INTO titulares (tenant_id, nome_completo, cpf, matricula, ativo) VALUES (:tenant, :name, :cpf, :registration, 1)'
)->execute([
    'tenant' => $titularHttpFixture['tenant_id'], 'name' => 'Ana Consulta', 'cpf' => '52998224725', 'registration' => 'A-100',
]);
$titularHttpPdo->prepare(
    'INSERT INTO titulares (tenant_id, nome_completo, cpf, matricula, ativo) VALUES (:tenant, :name, :cpf, :registration, 1)'
)->execute([
    'tenant' => $titularHttpFixture['tenant_id'], 'name' => 'Bruno Sem CPF', 'cpf' => null, 'registration' => 'B-100',
]);
$titularHttpOther = tenantTestSeed($titularHttpPdo);
$titularHttpPdo->prepare(
    'INSERT INTO titulares (tenant_id, nome_completo, cpf, matricula, ativo) VALUES (:tenant, :name, :cpf, :registration, 1)'
)->execute([
    'tenant' => $titularHttpOther['tenant_id'], 'name' => 'Titular de outra sede', 'cpf' => '11144477735', 'registration' => 'X-1',
]);
$titularHttpPdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')
    ->execute([
        'permissions' => json_encode(['titular.ver', 'titular.criar', 'titular.importar'], JSON_THROW_ON_ERROR),
        'tenant' => $titularHttpFixture['tenant_id'],
    ]);
$titularHttpPdo->prepare(
    'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, cpf, matricula, ativo) '
    . 'VALUES (:tenant, :unit, :name, :cpf, :registration, 1)'
)->execute([
    'tenant' => $titularHttpFixture['tenant_id'], 'unit' => $titularHttpFixture['subcliente_id'],
    'name' => 'Titular da unidade', 'cpf' => '11144477735', 'registration' => 'U-100',
]);
$titularHttpUnitSlugQuery = $titularHttpPdo->prepare('SELECT slug_subdominio FROM tenants WHERE id = :id');
$titularHttpUnitSlugQuery->execute(['id' => $titularHttpFixture['subcliente_id']]);
$titularHttpUnitSlug = (string) $titularHttpUnitSlugQuery->fetchColumn();
$titularHttpPdo->prepare(
    'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
    . 'VALUES (:tenant, :unit, :role, :name, :email, :password, 1)'
)->execute([
    'tenant' => $titularHttpFixture['tenant_id'], 'unit' => $titularHttpFixture['subcliente_id'],
    'role' => 'responsavel_sub', 'name' => 'Responsável da unidade',
    'email' => 'unit-reader-' . $titularHttpSuffix . '@example.test', 'password' => password_hash('unused', PASSWORD_DEFAULT),
]);
$titularHttpUnitUserId = (int) $titularHttpPdo->lastInsertId();
unset($titularHttpPdo);

$titularHttpOldSessionName = session_name();
$titularHttpOldSessionId = session_id();
$titularHttpOldSavePath = session_save_path();
$titularHttpOldCookieParams = session_get_cookie_params();
$titularHttpOldSessionSettings = [];
foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_trans_sid', 'session.gc_maxlifetime'] as $setting) {
    $titularHttpOldSessionSettings[$setting] = ini_get($setting);
}
$titularHttpHadCookie = array_key_exists('IEPRCARDTENANT', $_COOKIE);
$titularHttpOldCookie = $_COOKIE['IEPRCARDTENANT'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_save_path($titularHttpDirectory);
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start titular HTTP test session.');
$titularHttpSessionId = session_id();
$titularHttpCsrf = bin2hex(random_bytes(32));
$_SESSION = [
    'tenant_user_id' => $titularHttpTenant['owner_id'],
    'tenant_id' => $titularHttpFixture['tenant_id'],
    'subcliente_id' => null,
    'papel_sistema' => 'titular_cliente',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => $titularHttpCsrf,
];
session_write_close();
session_id('');
unset($_COOKIE['IEPRCARDTENANT']);
if (!tenantAuthStartSession(false)) throw new RuntimeException('Could not start titular unit test session.');
$titularHttpUnitSessionId = session_id();
$_SESSION = [
    'tenant_user_id' => $titularHttpUnitUserId,
    'tenant_id' => $titularHttpFixture['tenant_id'],
    'subcliente_id' => $titularHttpFixture['subcliente_id'],
    'papel_sistema' => 'responsavel_sub',
    'tenant_authenticated_at' => time(),
    'last_activity_at' => time(),
    'csrf_token' => bin2hex(random_bytes(32)),
];
session_write_close();
$titularHttpUnitCsrf = (string) $_SESSION['csrf_token'];

$titularHttpProbe = stream_socket_server('tcp://127.0.0.1:0', $titularHttpErrorCode, $titularHttpErrorMessage);
if ($titularHttpProbe === false) throw new RuntimeException('Could not allocate titular HTTP test port.');
$titularHttpAddress = stream_socket_get_name($titularHttpProbe, false);
fclose($titularHttpProbe);
$titularHttpPort = (int) substr((string) $titularHttpAddress, strrpos((string) $titularHttpAddress, ':') + 1);
$titularHttpNullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$titularHttpProcess = proc_open([
    PHP_BINARY,
    '-d', 'session.save_path=' . $titularHttpDirectory,
    '-S', '127.0.0.1:' . $titularHttpPort,
    '-t', $titularHttpRoot . DIRECTORY_SEPARATOR . 'public',
], [
    0 => ['file', $titularHttpNullDevice, 'r'],
    1 => ['file', $titularHttpDirectory . DIRECTORY_SEPARATOR . 'server.out.log', 'a'],
    2 => ['file', $titularHttpDirectory . DIRECTORY_SEPARATOR . 'server.err.log', 'a'],
], $titularHttpPipes, $titularHttpRoot);
if (!is_resource($titularHttpProcess)) throw new RuntimeException('Could not start titular HTTP test server.');

try {
    $titularHttpUrl = 'http://127.0.0.1:' . $titularHttpPort . '/tenant/titulares.php';
    $titularHttpHost = $titularHttpFixture['hostname'];
    $titularHttpStatus = 0;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$titularHttpStatus] = titularHttpRequest($titularHttpUrl, 'GET', null, null, 'unknown.ieprcard.test');
        if ($titularHttpStatus !== 0) break;
        usleep(100_000);
    }
    if ($titularHttpStatus !== 404) {
        throw new RuntimeException('Unknown tenant hosts must not reach the titular screen.');
    }
    [$titularHttpStatus] = titularHttpRequest($titularHttpUrl, 'GET', null, null, $titularHttpHost);
    if ($titularHttpStatus !== 401) {
        throw new RuntimeException('The titular screen must require an authenticated tenant session.');
    }
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Ana Consulta')
        || !str_contains((string) $titularHttpBody, '***.***.***-25')
        || !str_contains((string) $titularHttpBody, 'Bruno Sem CPF')
        || str_contains((string) $titularHttpBody, '52998224725')
        || str_contains((string) $titularHttpBody, 'Titular de outra sede')
        || !str_contains((string) $titularHttpBody, 'Novo titular')
        || !str_contains((string) $titularHttpBody, 'Importar lista')) {
        throw new RuntimeException('The titular screen must list only scoped, privacy-safe records and expose approved actions.');
    }

    $titularHttpUnitHost = $titularHttpUnitSlug . '.' . $titularHttpFixture['slug'] . '.ieprcard.test';
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpUnitSessionId,
        $titularHttpUnitHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Titular da unidade')
        || str_contains((string) $titularHttpBody, 'Ana Consulta')
        || str_contains((string) $titularHttpBody, 'Titular de outra sede')
        || str_contains((string) $titularHttpBody, 'name="subcliente_id"')) {
        throw new RuntimeException('A unit operator must see and target only the unit fixed by the authenticated session.');
    }
    $titularHttpUnitCreate = http_build_query([
        'action' => 'create_titular',
        'subcliente_id' => (string) $titularHttpOther['subcliente_id'],
        'nome_completo' => 'Criado na unidade',
        'matricula' => 'U-200',
        'csrf_token' => $titularHttpUnitCsrf,
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpUnitCreate,
        'IEPRCARDTENANT=' . $titularHttpUnitSessionId,
        $titularHttpUnitHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpUnitCreated = $titularHttpPdo->prepare(
        'SELECT subcliente_id FROM titulares WHERE tenant_id = :tenant AND matricula = :registration'
    );
    $titularHttpUnitCreated->execute(['tenant' => $titularHttpFixture['tenant_id'], 'registration' => 'U-200']);
    $titularHttpCreatedUnitId = $titularHttpUnitCreated->fetchColumn();
    unset($titularHttpUnitCreated, $titularHttpPdo);
    if ($titularHttpStatus !== 303 || (int) $titularHttpCreatedUnitId !== $titularHttpFixture['subcliente_id']) {
        throw new RuntimeException('A forged unit target must be ignored in favor of the session-bound unit.');
    }

    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpPageRows = $titularHttpPdo->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, matricula, ativo) VALUES (:tenant, :name, :registration, 1)'
    );
    for ($pageIndex = 1; $pageIndex <= 25; $pageIndex++) {
        $titularHttpPageRows->execute([
            'tenant' => $titularHttpFixture['tenant_id'],
            'name' => 'Pessoa paginada ' . $pageIndex,
            'registration' => 'P-' . $pageIndex,
        ]);
    }
    unset($titularHttpPageRows, $titularHttpPdo);
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl . '?pagina=1',
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Página 1 de 2')
        || !str_contains((string) $titularHttpBody, 'Próxima')) {
        throw new RuntimeException('The titular list must paginate with the approved bounded default.');
    }
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl . '?pagina=2',
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Página 2 de 2')
        || !str_contains((string) $titularHttpBody, 'Anterior')) {
        throw new RuntimeException('The next list page must retain its page state and provide a path back.');
    }

    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpDisableRead = $titularHttpPdo->prepare(
        "UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = 'titular.ver'"
    );
    $titularHttpDisableRead->execute(['plan' => $titularHttpTenant['plan_id']]);
    unset($titularHttpDisableRead, $titularHttpPdo);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpRestoreRead = $titularHttpPdo->prepare(
        "UPDATE plano_feature_flags SET habilitado = 1 WHERE plano_id = :plan AND feature_key = 'titular.ver'"
    );
    $titularHttpRestoreRead->execute(['plan' => $titularHttpTenant['plan_id']]);
    unset($titularHttpRestoreRead, $titularHttpPdo);
    if ($titularHttpStatus !== 403) {
        throw new RuntimeException('The titular page must enforce the active plan and permission flag on every request.');
    }

    $titularHttpInvalidCsrf = http_build_query([
        'action' => 'create_titular',
        'nome_completo' => 'Carla Nova',
        'csrf_token' => substr($titularHttpCsrf, 1),
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpInvalidCsrf,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 403) {
        throw new RuntimeException('Individual titular creation must reject invalid CSRF.');
    }

    $titularHttpCreate = http_build_query([
        'action' => 'create_titular',
        'nome_completo' => 'Carla Nova',
        'cpf' => '',
        'matricula' => 'C-300',
        'cargo_funcao' => 'Membro',
        'categoria_id' => '',
        'whatsapp' => '',
        'csrf_token' => $titularHttpCsrf,
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpCreate,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 303) {
        throw new RuntimeException('Valid individual titular creation must use post-redirect-get.');
    }
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpCreated = $titularHttpPdo->prepare(
        'SELECT id FROM titulares WHERE tenant_id = :tenant AND matricula = :registration LIMIT 1'
    );
    $titularHttpCreated->execute(['tenant' => $titularHttpFixture['tenant_id'], 'registration' => 'C-300']);
    if ($titularHttpCreated->fetchColumn() === false) {
        [$noticeStatus, $noticeBody] = titularHttpRequest(
            $titularHttpUrl,
            'GET',
            null,
            'IEPRCARDTENANT=' . $titularHttpSessionId,
            $titularHttpHost
        );
        preg_match('/<p class="notice"[^>]*>(.*?)<\/p>/s', (string) $noticeBody, $noticeMatch);
        throw new RuntimeException('Individual titular creation must persist through the existing domain service: '
            . json_encode(['http' => $noticeStatus, 'notice' => strip_tags((string) ($noticeMatch[1] ?? ''))], JSON_THROW_ON_ERROR));
    }
    unset($titularHttpCreated, $titularHttpPdo);

    $titularHttpCsv = "nome;matricula\nImportado HTTP;I-101\n";
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpDisableCreate = $titularHttpPdo->prepare(
        "UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = 'titular.criar'"
    );
    $titularHttpDisableCreate->execute(['plan' => $titularHttpTenant['plan_id']]);
    unset($titularHttpDisableCreate, $titularHttpPdo);
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'consultar e cancelar lotes existentes')
        || str_contains((string) $titularHttpBody, 'name="action" value="stage_import"')) {
        throw new RuntimeException('Import permission without create permission must retain read/cancel access but hide upload controls.');
    }
    [$titularHttpStatus] = titularHttpMultipartRequest(
        $titularHttpUrl,
        ['action' => 'stage_import', 'csrf_token' => $titularHttpCsrf],
        ['arquivo' => ['name' => 'denied.csv', 'type' => 'text/csv', 'contents' => $titularHttpCsv]],
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpRestoreCreate = $titularHttpPdo->prepare(
        "UPDATE plano_feature_flags SET habilitado = 1 WHERE plano_id = :plan AND feature_key = 'titular.criar'"
    );
    $titularHttpRestoreCreate->execute(['plan' => $titularHttpTenant['plan_id']]);
    unset($titularHttpRestoreCreate, $titularHttpPdo);
    if ($titularHttpStatus !== 403) {
        throw new RuntimeException('Import staging must enforce create permission on the selected destination.');
    }

    [$titularHttpStatus] = titularHttpMultipartRequest(
        $titularHttpUrl,
        [
            'action' => 'stage_import',
            'csrf_token' => $titularHttpCsrf,
        ],
        ['arquivo' => ['name' => 'titulares.csv', 'type' => 'text/csv', 'contents' => $titularHttpCsv]],
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 303) {
        throw new RuntimeException('A valid CSV upload must stage the file and use post-redirect-get.');
    }
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpLotQuery = $titularHttpPdo->prepare(
        "SELECT id FROM lotes_importacao WHERE tenant_id = :tenant AND origem_tipo = 'csv' ORDER BY id DESC LIMIT 1"
    );
    $titularHttpLotQuery->execute(['tenant' => $titularHttpFixture['tenant_id']]);
    $titularHttpLotId = (int) $titularHttpLotQuery->fetchColumn();
    unset($titularHttpLotQuery, $titularHttpPdo);
    if ($titularHttpLotId < 1) {
        throw new RuntimeException('The uploaded file must persist as a private pending import lot.');
    }
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl . '?lote=' . $titularHttpLotId,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Aguardando mapeamento')
        || !str_contains((string) $titularHttpBody, 'Importado HTTP')
        || str_contains((string) $titularHttpBody, 'private://')
        || str_contains((string) $titularHttpBody, $titularHttpDirectory . DIRECTORY_SEPARATOR . 'private')) {
        throw new RuntimeException('The staged import must reopen a bounded preview without exposing private storage details: ' . (string) $titularHttpBody);
    }

    $titularHttpConfirm = http_build_query([
        'action' => 'confirm_import',
        'lote_id' => (string) $titularHttpLotId,
        'mapping' => [0 => 'nome_completo', 1 => 'matricula'],
        'csrf_token' => $titularHttpCsrf,
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpConfirm,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 303) {
        throw new RuntimeException('An approved mapping must confirm the whole import and redirect to the stable lot result.');
    }
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpLotState = $titularHttpPdo->prepare(
        'SELECT status_lote, arquivo_original_url, processados_sucesso FROM lotes_importacao WHERE id = :id'
    );
    $titularHttpLotState->execute(['id' => $titularHttpLotId]);
    $titularHttpStoredState = $titularHttpLotState->fetch(PDO::FETCH_ASSOC);
    $titularHttpImported = $titularHttpPdo->prepare(
        'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND lote_origem_id = :lot AND matricula = :registration'
    );
    $titularHttpImported->execute([
        'tenant' => $titularHttpFixture['tenant_id'], 'lot' => $titularHttpLotId, 'registration' => 'I-101',
    ]);
    $titularHttpImportedCount = (int) $titularHttpImported->fetchColumn();
    unset($titularHttpLotState, $titularHttpImported, $titularHttpPdo);
    if (!is_array($titularHttpStoredState) || $titularHttpStoredState['status_lote'] !== 'concluido'
        || $titularHttpStoredState['arquivo_original_url'] !== null
        || (int) $titularHttpStoredState['processados_sucesso'] !== 1 || $titularHttpImportedCount !== 1) {
        throw new RuntimeException('Confirmed import must create the mapped titular, finalize the lot, and remove the private source.');
    }
    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl . '?lote=' . $titularHttpLotId,
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Importação concluída')
        || !str_contains((string) $titularHttpBody, '1 titular cadastrado')
        || str_contains((string) $titularHttpBody, 'Revisar e confirmar')) {
        throw new RuntimeException('Reloading a completed lot must display its final state without offering confirmation again.');
    }
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpConfirm,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpPostReplayCount = $titularHttpPdo->prepare(
        'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND lote_origem_id = :lot'
    );
    $titularHttpPostReplayCount->execute(['tenant' => $titularHttpFixture['tenant_id'], 'lot' => $titularHttpLotId]);
    $titularHttpFinalCount = (int) $titularHttpPostReplayCount->fetchColumn();
    unset($titularHttpPostReplayCount, $titularHttpPdo);
    if ($titularHttpStatus !== 303 || $titularHttpFinalCount !== 1) {
        throw new RuntimeException('Submitting a confirmed lot again must not create duplicate titulares.');
    }

    [$titularHttpStatus, $titularHttpBody] = titularHttpRequest(
        $titularHttpUrl . '?busca=Ana+Consulta&situacao=ativo',
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 200 || !str_contains((string) $titularHttpBody, 'Ana Consulta')
        || str_contains((string) $titularHttpBody, 'Bruno Sem CPF')) {
        throw new RuntimeException('List filters must search within the authorized tenant scope.');
    }
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl . '?subcliente_id=' . $titularHttpOther['subcliente_id'],
        'GET',
        null,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 403) {
        throw new RuntimeException('A forged foreign unit filter must be rejected.');
    }

    [$titularHttpStatus] = titularHttpMultipartRequest(
        $titularHttpUrl,
        ['action' => 'stage_import', 'csrf_token' => $titularHttpCsrf],
        ['arquivo' => ['name' => 'cancelar.csv', 'type' => 'text/csv', 'contents' => "nome;matricula\nNaoImportar;N-200\n"]],
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    if ($titularHttpStatus !== 303) throw new RuntimeException('A second file must create a separately addressable pending lot.');
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpPending = $titularHttpPdo->prepare(
        "SELECT id, arquivo_original_url FROM lotes_importacao WHERE tenant_id = :tenant AND origem_tipo = 'csv' "
        . "AND status_lote = 'aguardando_mapa' ORDER BY id DESC LIMIT 1"
    );
    $titularHttpPending->execute(['tenant' => $titularHttpFixture['tenant_id']]);
    $titularHttpPendingRow = $titularHttpPending->fetch(PDO::FETCH_ASSOC);
    unset($titularHttpPending, $titularHttpPdo);
    if (!is_array($titularHttpPendingRow)) throw new RuntimeException('The staged lot must remain available until confirmed or cancelled.');
    $titularHttpPendingId = (int) $titularHttpPendingRow['id'];
    $titularHttpPendingPath = batchImportStagingResolveStoredPath(
        $titularHttpFixture['tenant_id'],
        (string) $titularHttpPendingRow['arquivo_original_url']
    );
    $titularHttpPendingPhotoPath = batchImportPhotoArchiveResolvePath(
        $titularHttpFixture['tenant_id'],
        (string) $titularHttpPendingRow['arquivo_original_url']
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpEncryptedPhoto = $titularHttpPdo->prepare('SELECT COUNT(*) FROM importacao_linhas_staging WHERE lote_id = :lot AND foto_cifrada IS NOT NULL');
    $titularHttpEncryptedPhoto->execute(['lot' => $titularHttpPendingId]);
    $titularHttpEncryptedPhotoCount = (int) $titularHttpEncryptedPhoto->fetchColumn();
    unset($titularHttpEncryptedPhoto, $titularHttpPdo);
    if (!is_string($titularHttpPendingPath) || is_file($titularHttpPendingPath) || is_file($titularHttpPendingPhotoPath)
        || $titularHttpEncryptedPhotoCount !== 0) {
        throw new RuntimeException('Pending source files must be removed after each row is encrypted.');
    }
    $titularHttpInvalidMapping = http_build_query([
        'action' => 'confirm_import',
        'lote_id' => (string) $titularHttpPendingId,
        'mapping' => [0 => 'nome_completo', 1 => 'nome_completo'],
        'csrf_token' => $titularHttpCsrf,
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpInvalidMapping,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpAfterInvalidMapping = $titularHttpPdo->prepare(
        'SELECT status_lote FROM lotes_importacao WHERE id = :id'
    );
    $titularHttpAfterInvalidMapping->execute(['id' => $titularHttpPendingId]);
    $titularHttpPendingStatus = $titularHttpAfterInvalidMapping->fetchColumn();
    $titularHttpBeforeCancel = $titularHttpPdo->prepare(
        'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND lote_origem_id = :lot'
    );
    $titularHttpBeforeCancel->execute(['tenant' => $titularHttpFixture['tenant_id'], 'lot' => $titularHttpPendingId]);
    $titularHttpPendingTitularCount = (int) $titularHttpBeforeCancel->fetchColumn();
    unset($titularHttpAfterInvalidMapping, $titularHttpBeforeCancel, $titularHttpPdo);
    if ($titularHttpStatus !== 303 || $titularHttpPendingStatus !== 'aguardando_mapa' || $titularHttpPendingTitularCount !== 0) {
        throw new RuntimeException('Duplicate mapping targets must be rejected without partially confirming the lot.');
    }
    $titularHttpCancel = http_build_query([
        'action' => 'cancel_import', 'lote_id' => (string) $titularHttpPendingId, 'csrf_token' => $titularHttpCsrf,
    ]);
    [$titularHttpStatus] = titularHttpRequest(
        $titularHttpUrl,
        'POST',
        $titularHttpCancel,
        'IEPRCARDTENANT=' . $titularHttpSessionId,
        $titularHttpHost
    );
    $titularHttpPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $titularHttpDatabase]);
    $titularHttpCancelled = $titularHttpPdo->prepare(
        'SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = :id'
    );
    $titularHttpCancelled->execute(['id' => $titularHttpPendingId]);
    $titularHttpCancelledRow = $titularHttpCancelled->fetch(PDO::FETCH_ASSOC);
    unset($titularHttpCancelled, $titularHttpPdo);
    usleep(200_000);
    clearstatcache(true, $titularHttpPendingPath);
    clearstatcache(true, $titularHttpPendingPhotoPath);
    if ($titularHttpStatus !== 303 || !is_array($titularHttpCancelledRow)
        || $titularHttpCancelledRow['status_lote'] !== 'cancelado'
        || $titularHttpCancelledRow['arquivo_original_url'] !== null
        || is_file($titularHttpPendingPath) || is_file($titularHttpPendingPhotoPath)) {
        throw new RuntimeException('Cancellation must finalize the lot and remove its private source without creating titulares: '
            . json_encode(['status' => $titularHttpStatus, 'lot' => $titularHttpCancelledRow], JSON_THROW_ON_ERROR));
    }
} finally {
    proc_terminate($titularHttpProcess);
    proc_close($titularHttpProcess);
    unset($titularHttpPdo);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_save_path($titularHttpOldSavePath);
    session_name($titularHttpOldSessionName);
    session_id($titularHttpOldSessionId);
    session_set_cookie_params($titularHttpOldCookieParams);
    foreach ($titularHttpOldSessionSettings as $setting => $value) {
        if (is_string($value)) ini_set($setting, $value);
    }
    if ($titularHttpHadCookie) $_COOKIE['IEPRCARDTENANT'] = $titularHttpOldCookie;
    else unset($_COOKIE['IEPRCARDTENANT']);
    foreach ($titularHttpPreviousEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    for ($cleanupAttempt = 0; $cleanupAttempt < 20; $cleanupAttempt++) {
        if (!is_dir($titularHttpDirectory)) break;
        $titularHttpCleanup = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($titularHttpDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($titularHttpCleanup as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($titularHttpDirectory);
        if (!is_dir($titularHttpDirectory)) break;
        usleep(100_000);
    }
}

fwrite(STDOUT, "PASS: titular screen requires tenant authentication and returns a scoped, masked list.\n");
return 'pass';

/** @return array{int,string|null} */
function titularHttpRequest(string $url, string $method, ?string $body, ?string $cookie, string $host): array
{
    $parts = parse_url($url);
    $port = (int) ($parts['port'] ?? 80);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $content = $body ?? '';
    $requestTarget = (string) ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $headers = [
        $method . ' ' . $requestTarget . " HTTP/1.1",
        'Host: ' . $host,
        'Connection: close',
        'Accept: text/html',
    ];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($content);
    }
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $content);
    $response = stream_get_contents($socket);
    fclose($socket);
    [$responseHead, $responseBody] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
    $status = preg_match('/\s(\d{3})\s/', explode("\r\n", $responseHead)[0] ?? '', $match) === 1
        ? (int) $match[1]
        : 0;

    return [$status, $responseBody];
}

/** @param array<string,string> $fields @param array<string,array{name:string,type:string,contents:string}> $files @return array{int,string|null} */
function titularHttpMultipartRequest(string $url, array $fields, array $files, string $cookie, string $host): array
{
    $boundary = '----------------IEPRCard' . bin2hex(random_bytes(12));
    $parts = [];
    foreach ($fields as $name => $value) {
        $parts[] = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
            . $value . "\r\n";
    }
    foreach ($files as $name => $file) {
        $parts[] = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['name'] . '"' . "\r\n"
            . 'Content-Type: ' . $file['type'] . "\r\n\r\n"
            . $file['contents'] . "\r\n";
    }
    $body = implode('', $parts) . '--' . $boundary . "--\r\n";
    $partsUrl = parse_url($url);
    $port = (int) ($partsUrl['port'] ?? 80);
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 4);
    if ($socket === false) return [0, null];
    $requestTarget = (string) ($partsUrl['path'] ?? '/') . (isset($partsUrl['query']) ? '?' . $partsUrl['query'] : '');
    $headers = [
        'POST ' . $requestTarget . " HTTP/1.1",
        'Host: ' . $host,
        'Connection: close',
        'Cookie: ' . $cookie,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Content-Length: ' . strlen($body),
    ];
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);
    [$responseHead, $responseBody] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
    $status = preg_match('/\s(\d{3})\s/', explode("\r\n", $responseHead)[0] ?? '', $match) === 1
        ? (int) $match[1]
        : 0;

    return [$status, $responseBody];
}
