<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway_webhook.php';

$inputPath = $argv[1] ?? '';
$barrierPath = $argv[2] ?? '';
$callbackTable = $argv[3] ?? '';
if (!is_file($inputPath) || $barrierPath === '' || preg_match('/^ieprcard_gowa_cb_[a-f0-9]{10}$/D', $callbackTable) !== 1) {
    fwrite(STDERR, "Invalid webhook race worker configuration.\n");
    exit(2);
}
$input = json_decode((string) file_get_contents($inputPath), true);
if (!is_array($input) || !is_string($input['token'] ?? null) || !is_array($input['payload'] ?? null)) {
    fwrite(STDERR, "Invalid webhook race test fixture.\n");
    exit(2);
}
$deadline = microtime(true) + 10;
while (!is_file($barrierPath) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($barrierPath)) {
    fwrite(STDERR, "Webhook race barrier timed out.\n");
    exit(3);
}

try {
    $pdo = dbConnect([
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => getenv('TEST_MYSQL_HOST') ?: '',
        'DB_PORT' => getenv('TEST_MYSQL_PORT') ?: '3306',
        'DB_DATABASE' => getenv('TEST_MYSQL_DATABASE') ?: '',
        'DB_USERNAME' => getenv('TEST_MYSQL_USERNAME') ?: '',
        'DB_PASSWORD' => getenv('TEST_MYSQL_PASSWORD') ?: '',
    ]);
    $result = whatsappGatewayProcessWebhook(
        $pdo,
        $input['token'],
        $input['payload'],
        static function () use ($pdo, $callbackTable): void {
            $pdo->exec('INSERT INTO `' . $callbackTable . '` (called) VALUES (1)');
        }
    );
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) {
    fwrite(STDERR, "Webhook race worker failed.\n");
    exit(1);
}
