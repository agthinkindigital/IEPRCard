<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';

require_once dirname(__DIR__, 2) . '/config/admin_guard.php';
require_once dirname(__DIR__, 2) . '/config/schema_update.php';

adminAuthSendSecurityHeaders();
adminExigirHostDaPlataforma();
header('Content-Type: application/json; charset=utf-8');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}
if (!adminAuthStartSession() || adminAuthCurrentUser(dbConnection()) === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Autenticação necessária.']);
    exit;
}

$state = schemaUpdateStateRead();
echo json_encode([
    'status' => $state['status'] ?? 'unknown',
    'job_id' => $state['job_id'] ?? null,
    'requested_by' => (int) ($state['requested_by'] ?? 0),
    'created_at' => $state['created_at'] ?? null,
    'started_at' => $state['started_at'] ?? null,
    'completed_at' => $state['completed_at'] ?? null,
    'release_id' => $state['release_id'] ?? null,
    'completed_migrations' => (int) ($state['completed_migrations'] ?? 0),
    'migration_count' => count($state['migrations'] ?? []),
    'result' => is_array($state['result'] ?? null) ? $state['result'] : null,
    'error' => $state['error'] ?? null,
    'history' => schemaUpdateAuditHistory(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
