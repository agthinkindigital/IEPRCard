<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/backup_destinations.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-titular-key-race-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true)) {
    throw new RuntimeException('Could not create temporary titular key concurrency storage.');
}
$storage = $temp . DIRECTORY_SEPARATOR . 'objects';
if (!mkdir($storage, 0700, true)) {
    throw new RuntimeException('Could not create temporary S3 fixture storage.');
}
$databasePath = $temp . DIRECTORY_SEPARATOR . 'race.sqlite';
$barrier = $temp . DIRECTORY_SEPARATOR . 'start';
$oldIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
$oldAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(str_repeat("\x71", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(str_repeat("\x72", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$processes = [];
$pipes = [];
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $databasePath]);
schemaAplicarMigracoes($pdo, 'test');
schemaGarantirEstrutura($pdo, 'test');
$email = 'titular-key-race-' . bin2hex(random_bytes(5)) . '@example.test';
$pdo->prepare(
    "INSERT INTO usuarios (papel_sistema, nome, email, senha_hash, ativo) "
    . "VALUES ('superadmin', 'Key race test', :email, :password, 1)"
)->execute(['email' => $email, 'password' => password_hash('synthetic-test-password', PASSWORD_DEFAULT)]);
$identity = [
    'id' => (int) $pdo->lastInsertId(), 'papel_sistema' => 'superadmin',
    'tenant_id' => null, 'subcliente_id' => null,
];
$destination = backupDestinationSave($pdo, $identity, [
    'name' => 'Key race fixture', 'bucket' => 'key-race-test', 'region' => 'us-east-1',
    'endpoint' => null, 'path_style' => false,
    'access_key' => 'synthetic-access-key', 'secret_key' => 'synthetic-secret-key',
]);
$pdo->prepare("UPDATE destinos_backup SET ativo = 1, ultimo_teste_status = 'success' WHERE id = :id")
    ->execute(['id' => $destination['id']]);
$pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'Race tenant', 'ativo')")
    ->execute();
$tenantId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
    . 'VALUES (:tenant, :name, :cpf, 1, :created)'
)->execute([
    'tenant' => $tenantId, 'name' => 'Synthetic race holder', 'cpf' => '52998224725',
    'created' => gmdate('Y-m-d H:i:s'),
]);
$holderId = (int) $pdo->lastInsertId();
$worker = __DIR__ . DIRECTORY_SEPARATOR . 'TitularKeyProvisionRaceWorker.php';

try {
    for ($workerNumber = 0; $workerNumber < 2; $workerNumber++) {
        $processes[] = proc_open(
            [PHP_BINARY, $worker, $databasePath, $storage, $barrier, (string) $tenantId, (string) $holderId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$workerNumber],
            dirname(__DIR__, 2)
        );
        if (!is_resource($processes[$workerNumber])) {
            throw new RuntimeException('Could not start titular key provision worker.');
        }
        fclose($pipes[$workerNumber][0]);
    }
    file_put_contents($barrier, 'start');
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($pipes[$index][1]);
        $stderr = stream_get_contents($pipes[$index][2]);
        fclose($pipes[$index][1]);
        fclose($pipes[$index][2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !str_contains((string) $stdout, 'PASS:') || $stderr !== '') {
            throw new RuntimeException('Concurrent titular key provision failed: ' . trim((string) $stderr));
        }
    }
    $rows = $pdo->query('SELECT referencia_hmac, estado FROM titular_chaves_backup')->fetchAll(PDO::FETCH_ASSOC);
    $reference = titularPrivacyEnsureReference($pdo, $tenantId, $holderId);
    $referenceHmac = hash_hmac('sha256', $reference, titularAnonymizationEncryptionKey());
    $keyObject = titularPrivacyObjectKey('keys', $reference, titularAnonymizationEncryptionKey());
    $objectPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $keyObject) . '.json';
    $envelope = is_file($objectPath) ? file_get_contents($objectPath) : false;
    if (count($rows) !== 1 || ($rows[0]['estado'] ?? null) !== 'ativa'
        || !hash_equals($referenceHmac, (string) ($rows[0]['referencia_hmac'] ?? ''))
        || !is_string($envelope) || count(glob($storage . DIRECTORY_SEPARATOR . '*.json') ?: []) !== 1) {
        throw new RuntimeException('Concurrent provisioning must leave one ledger and one envelope for a holder.');
    }
    titularPrivacyValidateEnvelope($envelope, $referenceHmac, titularAnonymizationEncryptionKey());
    fwrite(STDOUT, "PASS: concurrent provisioners share one stable titular DEK envelope.\n");
} finally {
    foreach ($processes as $index => $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($pipes[$index] ?? [] as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
    }
    $pdo->prepare('DELETE FROM titular_chaves_backup WHERE destino_id = :id')->execute(['id' => $destination['id']]);
    $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $holderId]);
    $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
    $pdo->prepare('DELETE FROM destinos_backup WHERE id = :id')->execute(['id' => $destination['id']]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $identity['id']]);
    unset($pdo);
    foreach (glob($storage . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    @rmdir($storage);
    foreach (glob($temp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    @rmdir($temp);
    if ($oldIntegrationKey === false) putenv('IEPR_INTEGRATION_KEY');
    else putenv('IEPR_INTEGRATION_KEY=' . $oldIntegrationKey);
    if ($oldAnonymizationKey === false) putenv('IEPR_ANONYMIZATION_KEY');
    else putenv('IEPR_ANONYMIZATION_KEY=' . $oldAnonymizationKey);
}

return 'pass';
