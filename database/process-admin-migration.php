<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/admin_migration_preflight.php';

$statePath = adminMigrationStatePath();
$workerLock = @fopen($statePath . '.worker.lock', 'c');
if ($workerLock === false) {
    fwrite(STDERR, "Não foi possível preparar o worker de migração.\n");
    exit(1);
}
@chmod($statePath . '.worker.lock', 0600);
if (!flock($workerLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Já existe um worker de migração em execução.\n");
    fclose($workerLock);
    exit(0);
}

$state = adminMigrationStateRead($statePath);
if (($state['status'] ?? null) === 'running' && is_string($state['job_id'] ?? null)) {
    adminMigrationStateTransition($state['job_id'], 'running', [
        'status' => 'failed',
        'completed_at' => time(),
        'updated_at' => time(),
        'completed_tables' => 0,
        'completed_records' => 0,
        'current_table' => null,
        'tables' => [],
        'result' => null,
        'error' => 'O worker anterior foi interrompido. A transferência não foi confirmada; valide novamente antes de enfileirar outra cópia.',
    ], $statePath);
    fwrite(STDERR, "O worker anterior foi interrompido; o estado foi marcado para revisão administrativa.\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}
if (($state['status'] ?? null) !== 'queued' || !is_string($state['job_id'] ?? null)) {
    fwrite(STDOUT, "Nenhuma migração está aguardando execução.\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(0);
}

$jobId = $state['job_id'];
if (!adminMigrationStateTransition($jobId, 'queued', [
    'status' => 'running',
    'started_at' => time(),
    'updated_at' => time(),
    'total_tables' => count(\IEPRCard\Database\Schema\catalogoCanonico()),
])) {
    fwrite(STDERR, "A fila mudou antes do worker assumir o trabalho.\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}

try {
    $preflight = adminMigrationPreflight();
    if (!$preflight['ready']) {
        $details = array_map(
            static fn(array $check): string => $check['label'] . ': ' . $check['detail'],
            array_filter($preflight['checks'], static fn(array $check): bool => $check['status'] === 'Bloqueado')
        );
        throw new RuntimeException(implode(' ', $details));
    }

    $sourcePath = trim((string) (getenv('MIGRATION_SOURCE_SQLITE_PATH') ?: ''));
    if ($sourcePath === '' && strtolower((string) getenv('DB_DRIVER')) === 'sqlite') {
        $sourcePath = trim((string) (getenv('DB_SQLITE_PATH') ?: ''));
    }
    $source = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sourcePath]);
    $target = dbConnect(adminMigrationTargetConfiguration());
    $progress = [];
    $result = ieprMigrarSqliteParaMysql($source, $target, static function (array $update) use ($jobId, $statePath, &$progress): void {
        $progress[(string) $update['table']] = $update['table_report'];
        if (!adminMigrationStateTransition($jobId, 'running', [
            'updated_at' => time(),
            'completed_tables' => (int) $update['completed_tables'],
            'total_tables' => (int) $update['total_tables'],
            'completed_records' => (int) $update['completed_records'],
            'current_table' => (string) $update['table'],
            'tables' => $progress,
        ], $statePath)) {
            throw new RuntimeException('Não foi possível atualizar o progresso da migração.');
        }
    });
    unset($source, $target);

    if (!adminMigrationStateTransition($jobId, 'running', [
        'status' => 'completed',
        'completed_at' => time(),
        'updated_at' => time(),
        'completed_tables' => count($result['tables']),
        'total_tables' => count($result['tables']),
        'completed_records' => (int) $result['total_records'],
        'total_records' => (int) $result['total_records'],
        'result' => $result,
        'error' => null,
    ], $statePath)) {
        throw new RuntimeException('Não foi possível finalizar o estado da migração.');
    }
    fwrite(STDOUT, "Migração concluída. Verifique o resultado pela interface administrativa.\n");
} catch (Throwable $error) {
    $message = str_contains($error->getMessage(), 'revertida no destino')
        ? 'A transferência falhou e foi revertida no destino.'
        : 'A cópia não foi concluída. Revise as pré-verificações, a conexão e as permissões do destino antes de tentar novamente.';
    adminMigrationStateTransition($jobId, 'running', [
        'status' => 'failed',
        'completed_at' => time(),
        'updated_at' => time(),
        'completed_tables' => 0,
        'completed_records' => 0,
        'current_table' => null,
        'tables' => [],
        'result' => null,
        'error' => $message,
    ], $statePath);
    fwrite(STDERR, $message . "\n");
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
    exit(1);
}

flock($workerLock, LOCK_UN);
fclose($workerLock);
