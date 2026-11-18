<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_auth.php';

$session = [];
$token = adminAuthCsrfToken($session);
if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
    throw new RuntimeException('CSRF token must be generated from 32 random bytes.');
}
if (!adminAuthVerifyCsrf($session, $token)) {
    throw new RuntimeException('The session-bound CSRF token must validate.');
}
if (adminAuthVerifyCsrf($session, substr($token, 1))) {
    throw new RuntimeException('A modified CSRF token must be rejected.');
}
if (adminAuthVerifyCsrf([], $token)) {
    throw new RuntimeException('A CSRF token must not validate without its session value.');
}

fwrite(STDOUT, "PASS: CSRF tokens are random, session-bound, and compared safely.\n");

return 'pass';
