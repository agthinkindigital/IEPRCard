<?php
declare(strict_types=1);

function adminAuthSendSecurityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    if (adminAuthRequestIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
