<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';

require_once dirname(__DIR__, 2) . '/config/mcp_oauth.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

try {
    $metadata = mcpOauthProtectedResourceMetadata();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"temporarily_unavailable"}';
}
