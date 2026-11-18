<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (strtolower(getenv('APP_ENV') ?: 'dev') !== 'dev') {
    fwrite(STDERR, "O inicializador local só pode executar com APP_ENV=dev.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/conn.php';
require_once __DIR__ . '/migrations/runner.php';

try {
    $driver = strtolower(getenv('DB_DRIVER') ?: 'sqlite');
    if ($driver === 'mysql') {
        $host = strtolower(getenv('DB_HOST') ?: 'localhost');
        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('O launcher local não aplica migrações em um MySQL remoto.');
        }
    }

    $pdo = dbConnection();
    schemaAplicarMigracoes($pdo, 'dev');
    fwrite(STDOUT, 'Banco local pronto (' . $driver . ').' . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
