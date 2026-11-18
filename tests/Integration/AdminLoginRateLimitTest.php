<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$storage = tempnam(sys_get_temp_dir(), 'ieprcard-login-limit-');
if ($storage === false) {
    throw new RuntimeException('Could not create rate-limit test storage.');
}
unlink($storage);
$clientIp = '203.0.113.17';

try {
    for ($attempt = 0; $attempt < 60; $attempt++) {
        if (!adminAuthAllowAttempt($clientIp, 120, $storage)) {
            throw new RuntimeException('The first 60 login attempts in a minute must be permitted.');
        }
    }
    if (adminAuthAllowAttempt($clientIp, 120, $storage)) {
        throw new RuntimeException('The 61st login attempt in the same minute must be limited.');
    }
    if (!adminAuthAllowAttempt($clientIp, 180, $storage)) {
        throw new RuntimeException('The login attempt counter must reset in the next minute.');
    }
    if (adminAuthAllowAttempt('not-an-ip', 180, $storage)) {
        throw new RuntimeException('Invalid client IP values must fail closed.');
    }
    $stored = file_get_contents($storage);
    if (!is_string($stored) || str_contains($stored, $clientIp)) {
        throw new RuntimeException('Rate-limit storage must not retain a raw client IP.');
    }
} finally {
    if (is_file($storage)) {
        unlink($storage);
    }
}

fwrite(STDOUT, "PASS: admin login rate limit is atomic, resets by minute, and stores no raw IP.\n");

return 'pass';
