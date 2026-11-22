<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/ai_global_config.php';

$provider = $argv[1] ?? '';
$userId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$iterations = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
if ($userId === false || $iterations === false) {
    fwrite(STDERR, "Invalid test worker arguments.\n");
    exit(2);
}

$pdo = dbConnect([
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '',
    'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
    'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
    'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '',
    'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
]);
$identity = ['id' => (int) $userId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
for ($index = 0; $index < $iterations; $index++) {
    aiGlobalConfigSetActive($pdo, $identity, $provider);
}

fwrite(STDOUT, "PASS: provider-selection worker completed.\n");
