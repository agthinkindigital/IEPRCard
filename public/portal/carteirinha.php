<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';
require_once dirname(__DIR__, 2) . '/config/card_issuance_worker.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$respondNotFound = static function (int $status = 404): never {
    header('Cache-Control: private, no-store, max-age=0', true);
    header('Pragma: no-cache', true);
    http_response_code($status);
    exit;
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    $respondNotFound(405);
}

$cardId = filter_var($_GET['carteirinha_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$side = $_GET['lado'] ?? null;
if ($cardId === false || !in_array($side, ['frente', 'verso', 'pdf'], true)) {
    $respondNotFound();
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!portalAuthAllowAttempt($clientIp)) {
    $respondNotFound(filter_var($clientIp, FILTER_VALIDATE_IP) === false ? 503 : 429);
}

try {
    if (!portalAuthStartSession()) {
        $respondNotFound(503);
    }
    $pdo = dbConnection();
    $context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!$context instanceof TenantContext) {
        $respondNotFound();
    }
    if ($side === 'pdf') {
        $identity = portalAuthCurrentHolder($pdo, $context);
        $asset = is_array($identity) ? portalAuthPdfAsset($pdo, $identity, (int) $cardId) : null;
        if (!is_array($asset)) {
            $respondNotFound();
        }
        $bytes = cardIssuanceWorkerReadAsset($asset['tenant_id'], $asset['stored_key']);
        if (strlen($bytes) < 8 || !str_starts_with($bytes, '%PDF-')) {
            $respondNotFound(503);
        }
        header('Cache-Control: private, no-store, max-age=0', true);
        header('Pragma: no-cache', true);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="carteirinha.pdf"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        return;
    }

    $cards = portalAuthCards($pdo, $context);
    $card = null;
    foreach ($cards as $candidate) {
        if ($candidate['id'] === (int) $cardId) {
            $card = $candidate;
            break;
        }
    }
    if (!is_array($card)) {
        $respondNotFound();
    }
    $storedKey = $card[$side === 'frente' ? 'webp_frente_url' : 'webp_verso_url'];
    if (!is_string($storedKey) || $storedKey === '') {
        $respondNotFound();
    }
    $bytes = cardIssuanceWorkerReadAsset($context->tenantId ?? 0, $storedKey);
    if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        $respondNotFound(503);
    }
    header('Cache-Control: private, no-store, max-age=0', true);
    header('Pragma: no-cache', true);
    header('Content-Type: image/webp');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
} catch (Throwable) {
    $respondNotFound(503);
}
