<?php
declare(strict_types=1);

$heartbeat = getenv('IEPRCARD_LOCAL_HEARTBEAT');
if ($heartbeat !== false && $heartbeat !== '') {
    @file_put_contents($heartbeat, gmdate('c'), LOCK_EX);
}

$dbState = 'Não verificado';
$dbMessage = 'Configure o banco local para validar a conexão.';
$pdoExtensions = array_filter(['pdo_sqlite', 'pdo_mysql'], static fn(string $extension): bool => extension_loaded($extension));

try {
    $driver = strtolower(getenv('DB_DRIVER') ?: 'sqlite');
    $databaseHost = strtolower(getenv('DB_HOST') ?: 'localhost');
    if ($driver === 'mysql' && !in_array($databaseHost, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('O ambiente local aceita apenas MySQL/MariaDB no próprio computador.');
    }
    require_once dirname(__DIR__) . '/config/conn.php';
    require_once dirname(__DIR__) . '/config/schema_self_healing.php';
    $pdo = dbConnection();
    $drift = schemaInspecionar($pdo);
    $hasDrift = array_filter($drift) !== [];
    $dbState = $hasDrift ? 'Conectado; schema precisa de atenção' : 'Conectado e com schema válido';
    $dbMessage = $hasDrift ? 'O banco não corresponde integralmente ao schema canônico.' : 'Driver: ' . (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $exception) {
    $dbState = 'Indisponível';
    $dbMessage = $exception->getMessage();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IEPRCard · ambiente local</title>
    <style>
        :root { color-scheme: light; font: 16px/1.5 system-ui, sans-serif; color: #172033; background: #f3f5f8; }
        body { margin: 0; padding: 3rem 1rem; }
        main { max-width: 720px; margin: auto; padding: 2rem; background: #fff; border: 1px solid #dce2ea; border-radius: 12px; }
        h1 { margin-top: 0; font-size: 1.7rem; }
        .status { padding: 1rem; border-left: 4px solid #73839b; background: #f7f8fa; overflow-wrap: anywhere; }
        .ok { border-color: #15803d; } .error { border-color: #b42318; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    <h1>IEPRCard · ambiente local</h1>
    <p>O servidor PHP está ativo em <code><?= e('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?></code>.</p>
    <section class="status <?= $dbState === 'Indisponível' ? 'error' : ($dbState === 'Conectado e com schema válido' ? 'ok' : '') ?>" aria-live="polite">
        <strong>Banco de dados: <?= e($dbState) ?></strong>
        <p><?= e($dbMessage) ?></p>
    </section>
    <?php if ($dbState === 'Indisponível' && $pdoExtensions === []): ?>
        <p>Habilite <code>pdo_sqlite</code> para SQLite ou <code>pdo_mysql</code> para MySQL na configuração do PHP e abra o launcher novamente.</p>
    <?php endif; ?>
    <p>Esta página confirma apenas o ambiente local. A área administrativa será exibida após a implementação dos módulos de autenticação.</p>
</main>
</body>
</html>
