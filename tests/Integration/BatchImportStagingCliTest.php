<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_staging.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';

$suffix = bin2hex(random_bytes(6));
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-stage-cli-' . $suffix;
$databasePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'cleanup.sqlite';
$storagePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage';
$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH', 'IEPR_PRIVATE_STORAGE_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}

try {
    mkdir($temporaryRoot, 0700, true);
    $pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    schemaAplicarMigracoes($pdo, 'test');
    $suffixSlug = 'cleanup-' . $suffix;
    $tenantInsert = $pdo->prepare(
        "INSERT INTO tenants (tipo, nome_organizacao, slug_subdominio, status_conta) "
        . "VALUES ('cliente', :name, :slug, 'ativo')"
    );
    $tenantInsert->execute(['name' => 'Staging cleanup', 'slug' => $suffixSlug]);
    $tenantId = (int) $pdo->lastInsertId();
    $storedKey = 'private://' . $tenantId . '/' . str_repeat('a', 48) . '.csv';
    $lot = $pdo->prepare(
        'INSERT INTO lotes_importacao (tenant_id, origem_tipo, arquivo_original_url, colunas_detectadas_json, '
        . 'total_registros, status_lote, criado_em) '
        . 'VALUES (:tenant, :origin, :source, :columns, 1, :status, :created)'
    );
    $lot->execute([
        'tenant' => $tenantId,
        'origin' => 'csv',
        'source' => $storedKey,
        'columns' => '["nome_completo"]',
        'status' => 'aguardando_mapa',
        'created' => gmdate('Y-m-d H:i:s', time() - 86401),
    ]);
    $lotId = (int) $pdo->lastInsertId();
    $storedPath = $storagePath . DIRECTORY_SEPARATOR . $tenantId . DIRECTORY_SEPARATOR . str_repeat('a', 48) . '.csv';
    if (!mkdir(dirname($storedPath), 0700, true) || file_put_contents($storedPath, 'temporary source') === false) {
        throw new RuntimeException('Could not prepare the CLI cleanup source file.');
    }
    $tenantInsert->closeCursor();
    $lot->closeCursor();
    unset($tenantInsert, $lot);
    unset($pdo);

    putenv('APP_ENV=test');
    putenv('DB_DRIVER=sqlite');
    putenv('DB_SQLITE_PATH=' . $databasePath);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    $arguments = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDirectory = ini_get('extension_dir');
        if (!str_starts_with($extensionDirectory, DIRECTORY_SEPARATOR)) {
            $extensionDirectory = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDirectory;
        }
        $sqliteExtension = rtrim($extensionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php_pdo_sqlite.dll';
        if (is_file($sqliteExtension)) {
            $arguments = ['-d', 'extension=pdo_sqlite'];
        }
    }
    $root = dirname(__DIR__, 2);
    $command = array_merge([PHP_BINARY], $arguments, [$root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'cleanup-import-staging.php']);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the import staging cleanup CLI.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $verify = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
    $state = $verify->prepare('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = :id');
    $state->execute(['id' => $lotId]);
    $record = $state->fetch(PDO::FETCH_ASSOC);
    if ($exitCode !== 0 || !str_contains((string) $output, 'Expired import lots: 1')
        || !is_array($record) || $record['status_lote'] !== 'expirado' || $record['arquivo_original_url'] !== null
        || is_file($storedPath) || str_contains((string) $errors, 'cleanup.sqlite')) {
        throw new RuntimeException('The cleanup CLI must expire the lot and remove its private file: ' . $errors . $output);
    }
    $state->closeCursor();
    unset($state, $verify);
    fwrite(STDOUT, "PASS: import cleanup CLI expires stale lots and removes their private files.\n");
} finally {
    if (isset($state) && $state instanceof PDOStatement) {
        unset($state);
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        unset($pdo);
    }
    if (isset($verify) && $verify instanceof PDO) {
        unset($verify);
    }
    foreach ($oldEnvironment as $key => $value) {
        $value === null ? putenv($key) : putenv($key . '=' . $value);
    }
    batchImportStagingTestRemoveTree($temporaryRoot);
}

return 'pass';
