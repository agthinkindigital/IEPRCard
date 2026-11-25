<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';

$ci = filter_var(getenv('CI') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$driverAvailable = in_array('mysql', PDO::getAvailableDrivers(), true);
$host = getenv('TEST_MYSQL_HOST');
$database = getenv('TEST_MYSQL_DATABASE');
$username = getenv('TEST_MYSQL_USERNAME');

if ($driverAvailable) {
    $connectionFailure = null;
    try {
        dbConnect([
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'ieprcard',
            'DB_USERNAME' => 'probe',
            'DB_PASSWORD' => 'secret-that-must-not-appear',
        ]);
    } catch (RuntimeException $exception) {
        $connectionFailure = $exception;
    }

    if ($connectionFailure === null || str_contains($connectionFailure->getMessage(), 'secret-that-must-not-appear')) {
        throw new RuntimeException('MySQL connection failures must not expose credentials.');
    }

    fwrite(STDOUT, "PASS: MySQL connection failures hide credentials.\n");
}

if (!$driverAvailable || $host === false || $database === false || $username === false) {
    if ($ci) {
        throw new RuntimeException('CI requires pdo_mysql and TEST_MYSQL_HOST/DATABASE/USERNAME configuration.');
    }

    fwrite(STDOUT, "SKIP: MySQL smoke test needs pdo_mysql and TEST_MYSQL_* environment variables.\n");
    return 'skip';
}

putenv('DB_DRIVER=mysql');
putenv('DB_HOST=' . $host);
putenv('DB_PORT=' . (getenv('TEST_MYSQL_PORT') ?: '3306'));
putenv('DB_DATABASE=' . $database);
putenv('DB_USERNAME=' . $username);
putenv('DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));

$connection = dbConnection();
if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('DB_DRIVER=mysql must select the MySQL PDO driver.');
}
if ((bool) $connection->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
    throw new RuntimeException('MySQL must use native PDO prepared statements.');
}

dbExecute('CREATE TEMPORARY TABLE connection_contract_items (id INT AUTO_INCREMENT PRIMARY KEY, value VARCHAR(255) NOT NULL)');
$dbValue = "bound value'); DROP TABLE connection_contract_items;--";
$insertedRows = dbExecute('INSERT INTO connection_contract_items (value) VALUES (:value)', ['value' => $dbValue]);
$insertId = dbInsertId();
$item = dbFetchOne('SELECT id, value FROM connection_contract_items WHERE value = :value', ['value' => $dbValue]);
$items = dbFetchAll('SELECT id, value FROM connection_contract_items');

if ($insertedRows !== 1 || $insertId !== '1' || $item === null || $item['value'] !== $dbValue || (int) $item['id'] !== 1 || count($items) !== 1) {
    throw new RuntimeException('MySQL query helpers must bind values and return rows consistently.');
}

fwrite(STDOUT, "PASS: MySQL PDO connection and query helpers.\n");

require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
$schemaDrift = schemaGarantirEstrutura($connection, 'test');
schemaGarantirEstrutura($connection, 'test');
if (array_filter($schemaDrift) !== [] || array_filter(schemaInspecionar($connection)) !== []) {
    throw new RuntimeException('MySQL/MariaDB self-healing must create the canonical schema without drift: ' . json_encode([$schemaDrift, schemaInspecionar($connection)]));
}
$expectedSchemaTables = array_keys(\IEPRCard\Database\Schema\catalogoCanonico());
$actualSchemaTables = $connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
sort($expectedSchemaTables, SORT_STRING);
sort($actualSchemaTables, SORT_STRING);
if ($actualSchemaTables !== $expectedSchemaTables) {
    throw new RuntimeException('MySQL/MariaDB must contain exactly the canonical MVP tables.');
}

$migrations = schemaAplicarMigracoes($connection, 'test');
$migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
sort($migrationFiles, SORT_STRING);
$expectedMigrations = array_map(static fn(string $file): string => basename($file, '.php'), $migrationFiles);
if ($migrations !== $expectedMigrations || array_filter(schemaInspecionar($connection)) !== []) {
    throw new RuntimeException('MySQL/MariaDB versioned migrations must be repeatable and leave no schema drift.');
}

$backfillName = 'Synthetic tenant privacy backfill ' . bin2hex(random_bytes(4));
$connection->prepare(
    "INSERT INTO tenants (tipo, nome_organizacao, status_conta, criado_em) VALUES ('cliente', :name, 'ativo', :created)"
)->execute(['name' => $backfillName, 'created' => gmdate('Y-m-d H:i:s')]);
$backfillTenantId = (int) $connection->lastInsertId();
$tenantSubjectMigration = require dirname(__DIR__, 2) . '/database/migrations/0021_privacy_tenant_subject_backfill.php';
$tenantSubjectMigration($connection, 'test');
$tenantSubjectMigration($connection, 'test');
$tenantSubjectQuery = $connection->prepare(
    "SELECT s.id, s.tipo, s.tenant_id, COUNT(r.registro_id) AS linked_records FROM privacy_subjects s "
    . "JOIN privacy_subject_records r ON r.privacy_subject_id = s.id AND r.tipo_registro = 'tenant_contato' "
    . 'WHERE r.registro_id = :tenant GROUP BY s.id, s.tipo, s.tenant_id'
);
$tenantSubjectQuery->execute(['tenant' => $backfillTenantId]);
$tenantSubject = $tenantSubjectQuery->fetch(PDO::FETCH_ASSOC);
if (!is_array($tenantSubject) || $tenantSubject['tipo'] !== 'pessoa'
    || (int) $tenantSubject['tenant_id'] !== $backfillTenantId || (int) $tenantSubject['linked_records'] !== 1) {
    throw new RuntimeException('Tenant backfill must idempotently bind organization PII to one tenant-scoped privacy subject.');
}
$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(str_repeat("\x66", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
try {
    require_once dirname(__DIR__, 2) . '/config/titular_privacy_keys.php';
    $contactSubject = privacySubjectCreate($connection, 'contato', $backfillTenantId);
    $conversationRecordId = random_int(100000000, 999999999);
    privacySubjectLinkRecord($connection, (int) $contactSubject['id'], 'whatsapp_conversa', $conversationRecordId);
    $holderCpf = (string) random_int(10000000000, 99999999999);
    $connection->prepare(
        'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) VALUES (:tenant, :name, :cpf, 1, :created)'
    )->execute([
        'tenant' => $backfillTenantId, 'name' => 'Synthetic merge holder', 'cpf' => $holderCpf,
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $mergeHolderId = (int) $connection->lastInsertId();
    $holderSubject = privacySubjectEnsureTitular($connection, $mergeHolderId, $backfillTenantId);
    privacySubjectMergeRecordInto(
        $connection,
        'whatsapp_conversa',
        $conversationRecordId,
        'titular',
        $mergeHolderId
    );
    privacySubjectMergeRecordInto(
        $connection,
        'whatsapp_conversa',
        $conversationRecordId,
        'titular',
        $mergeHolderId
    );
    $mergedConversationSubject = privacySubjectFindForRecord($connection, 'whatsapp_conversa', $conversationRecordId);
    $oldContactState = $connection->prepare('SELECT estado FROM privacy_subjects WHERE id = :id');
    $oldContactState->execute(['id' => $contactSubject['id']]);
    if (!is_array($mergedConversationSubject) || $mergedConversationSubject['id'] !== $holderSubject['id']
        || $oldContactState->fetchColumn() !== 'anonimizado') {
        throw new RuntimeException('MySQL subject merge must be idempotent and tombstone the old contact key owner.');
    }
    $connection->prepare('DELETE FROM privacy_subject_records WHERE privacy_subject_id IN (:source, :target)')->execute([
        'source' => $contactSubject['id'], 'target' => $holderSubject['id'],
    ]);
    $connection->prepare('DELETE FROM privacy_subjects WHERE id IN (:source, :target)')->execute([
        'source' => $contactSubject['id'], 'target' => $holderSubject['id'],
    ]);
    $connection->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $mergeHolderId]);
} finally {
    $oldAnonymizationKey === false
        ? putenv('IEPR_ANONYMIZATION_KEY')
        : putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
}
$connection->prepare(
    "DELETE FROM privacy_subject_records WHERE tipo_registro = 'tenant_contato' AND registro_id = :tenant"
)->execute(['tenant' => $backfillTenantId]);
$connection->prepare('DELETE FROM privacy_subjects WHERE id = :id')->execute(['id' => $tenantSubject['id']]);
$connection->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $backfillTenantId]);

$financialTables = $connection->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('pagamentos_saas', 'pagamentos_titular', 'contas_recebedoras', 'repasses_titular', 'configuracoes_gateway')")->fetchAll(PDO::FETCH_COLUMN);
if ($financialTables !== []) {
    throw new RuntimeException('Reserved finance tables must not be created in the MVP schema.');
}

fwrite(STDOUT, "PASS: MySQL/MariaDB schema creation, drift inspection, and explicit migrations.\n");

return 'pass';
