<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';

$pdo = dbConnect([
    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => ':memory:',
]);

if (!$pdo instanceof PDO) {
    throw new RuntimeException('dbConnect() must return a PDO instance.');
}

$foreignKeysEnabled = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
if ($foreignKeysEnabled !== 1) {
    throw new RuntimeException('SQLite foreign-key enforcement must be enabled.');
}

fwrite(STDOUT, "PASS: SQLite connection enables foreign keys.\n");

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=:memory:');
$dbValue = "bound value'); DROP TABLE connection_contract_items;--";
dbExecute('CREATE TABLE connection_contract_items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');
$insertedRows = dbExecute('INSERT INTO connection_contract_items (value) VALUES (:value)', ['value' => $dbValue]);
$insertId = dbInsertId();
$item = dbFetchOne('SELECT id, value FROM connection_contract_items WHERE value = :value', ['value' => $dbValue]);

if ($insertedRows !== 1 || $insertId !== '1' || $item === null || $item['value'] !== $dbValue || $item['id'] !== 1) {
    throw new RuntimeException('Database helpers must bind values and return inserted rows consistently.');
}

fwrite(STDOUT, "PASS: query helpers bind values and return rows and insert IDs.\n");

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Asia/Tokyo');
try {
    $timestamp = dbAgora();
} finally {
    date_default_timezone_set($previousTimezone);
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC'));
if ($date === false || $timestamp !== gmdate('Y-m-d H:i:s')) {
    throw new RuntimeException('dbAgora() must return an ISO timestamp in UTC.');
}

fwrite(STDOUT, "PASS: dbAgora() returns a UTC ISO timestamp.\n");

$sqliteFile = tempnam(sys_get_temp_dir(), 'ieprcard-sqlite-');
if ($sqliteFile === false) {
    throw new RuntimeException('Could not create a temporary SQLite file.');
}

try {
    $filePdo = dbConnect([
        'DB_DRIVER' => 'sqlite',
        'DB_SQLITE_PATH' => $sqliteFile,
    ]);
    $journalMode = strtolower((string) $filePdo->query('PRAGMA journal_mode')->fetchColumn());
    if ($journalMode !== 'wal') {
        throw new RuntimeException('SQLite file connections must use WAL journal mode.');
    }
} finally {
    unset($filePdo);
    foreach ([$sqliteFile, $sqliteFile . '-wal', $sqliteFile . '-shm'] as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

fwrite(STDOUT, "PASS: SQLite file connections use WAL journal mode.\n");

$invalidConfigurations = [
    ['DB_DRIVER' => 'postgres'],
    ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ''],
    ['DB_DRIVER' => 'mysql', 'DB_DATABASE' => 'iepr;dbname=other', 'DB_USERNAME' => 'tester'],
    ['DB_DRIVER' => 'mysql', 'DB_DATABASE' => 'ieprcard', 'DB_USERNAME' => 'tester', 'DB_PORT' => '70000'],
];

foreach ($invalidConfigurations as $configuration) {
    try {
        dbConnect($configuration);
        throw new RuntimeException('Invalid database configuration was accepted.');
    } catch (InvalidArgumentException) {
        continue;
    }
}

fwrite(STDOUT, "PASS: unsupported drivers and unsafe database settings are rejected.\n");

require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
$expectedMvpTables = [
    'planos', 'plano_feature_flags', 'tenants', 'dominios_tenant', 'perfis_acesso',
    'permissoes', 'usuario_permissoes', 'usuarios', 'categorias_titular',
    'designs_carteirinha', 'lotes_importacao', 'titulares', 'carteirinhas_emitidas',
    'telemetria_eventos', 'telemetria_diaria', 'auditoria_processos', 'acessos_suporte',
    'mensagens_whatsapp', 'ia_config', 'ia_config_global', 'webhooks_recebidos', 'outbox_eventos',
    'reservas_cota', 'mcp_api_keys', 'mcp_audit_logs', 'configuracoes_operacionais',
    'metricas_lotes_diarias', 'solicitacoes_anonimizacao',
];
$schemaPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaGarantirEstrutura($schemaPdo, 'test');
schemaGarantirEstrutura($schemaPdo, 'test');
$createdTables = $schemaPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
sort($expectedMvpTables, SORT_STRING);

if ($createdTables !== $expectedMvpTables) {
    throw new RuntimeException('Development self-healing must create exactly the canonical 28 MVP tables.');
}
$schemaDrift = schemaInspecionar($schemaPdo);
foreach (['missing_tables', 'missing_columns', 'missing_indexes', 'missing_foreign_keys'] as $driftType) {
    if (($schemaDrift[$driftType] ?? []) !== []) {
        throw new RuntimeException('A fresh SQLite schema must match the complete canonical catalog: ' . $driftType);
    }
}

fwrite(STDOUT, "PASS: dev/test self-healing creates the canonical 28 tables idempotently.\n");

$existingPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
$existingPdo->exec('CREATE TABLE webhooks_recebidos (id INTEGER PRIMARY KEY AUTOINCREMENT, evento_id VARCHAR(180) NOT NULL, origem VARCHAR(60) NOT NULL, payload_hash TEXT NOT NULL)');
$existingPdo->exec("INSERT INTO webhooks_recebidos (evento_id, origem, payload_hash) VALUES ('evt-keep', 'unit', 'abc123')");
schemaGarantirEstrutura($existingPdo, 'test');
$preservedWebhook = $existingPdo->query("SELECT evento_id, estado FROM webhooks_recebidos WHERE evento_id = 'evt-keep'")->fetch(PDO::FETCH_ASSOC);
if ($preservedWebhook === false || $preservedWebhook['evento_id'] !== 'evt-keep' || $preservedWebhook['estado'] !== 'recebido') {
    throw new RuntimeException('Self-healing must add safe columns and preserve existing rows.');
}
$existingDrift = schemaInspecionar($existingPdo);
if (!in_array('webhooks_recebidos.payload_hash', $existingDrift['mismatched_columns'] ?? [], true)) {
    throw new RuntimeException('Schema inspection must report existing columns with types that differ from the canonical catalog.');
}

fwrite(STDOUT, "PASS: self-healing preserves existing records while adding safe columns.\n");

require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
$migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
foreach ($migrationFiles as $migrationFile) {
    $migrationSource = file_get_contents($migrationFile);
    if ($migrationSource === false || preg_match('/\b(?:DROP\s+(?:TABLE|COLUMN)|RENAME\s+(?:TABLE|COLUMN))\b/i', $migrationSource)) {
        throw new RuntimeException('Versioned migrations must not drop or rename database structures.');
    }
}
$migrationPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
$firstMigrationRun = schemaAplicarMigracoes($migrationPdo, 'test');
$secondMigrationRun = schemaAplicarMigracoes($migrationPdo, 'test');
$migrationDrift = schemaInspecionar($migrationPdo);
if ($firstMigrationRun !== ['0001_mvp_schema', '0002_catalogo_permissoes', '0003_mcp_oauth_client_registry', '0004_personal_data_lifecycle', '0005_normalized_telemetry_scope', '0006_titular_batch_origin', '0007_card_issuance_jobs', '0008_individual_card_issuance', '0009_ai_global_config', '0010_portal_pdf_access', '0011_seed_pdf_permission'] || $secondMigrationRun !== $firstMigrationRun
    || array_filter($migrationDrift) !== []) {
    throw new RuntimeException('Versioned migrations must apply explicitly, be repeatable, and produce the canonical SQLite schema: ' . json_encode([$firstMigrationRun, $secondMigrationRun, $migrationDrift]));
}

fwrite(STDOUT, "PASS: explicit versioned migrations are repeatable and leave no schema drift.\n");

$productionPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
$productionDrift = schemaGarantirEstrutura($productionPdo, 'production');
$productionTables = $productionPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

if ($productionTables !== [] || count($productionDrift['missing_tables'] ?? []) !== 28) {
    throw new RuntimeException('Production drift checks must report missing tables without executing DDL.');
}

fwrite(STDOUT, "PASS: production drift checks do not execute DDL.\n");

$previousAppEnvironment = getenv('APP_ENV');
putenv('APP_ENV=production');
try {
    $defaultEnvironmentPdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
    schemaGarantirEstrutura($defaultEnvironmentPdo);
    $defaultEnvironmentTables = $defaultEnvironmentPdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
} finally {
    $previousAppEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousAppEnvironment);
}
if ($defaultEnvironmentTables !== []) {
    throw new RuntimeException('The default runtime environment must inspect schema without executing DDL.');
}

fwrite(STDOUT, "PASS: schema inspection defaults safely to production behavior.\n");

return 'pass';
