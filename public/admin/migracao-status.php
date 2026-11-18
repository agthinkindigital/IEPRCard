<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_migration.php';
require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/admin_guard.php';

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

$state = adminMigrationStateRead();
echo json_encode([
    'status' => $state['status'] ?? 'unknown',
    'completed_tables' => (int) ($state['completed_tables'] ?? 0),
    'total_tables' => (int) ($state['total_tables'] ?? 27),
    'completed_records' => (int) ($state['completed_records'] ?? 0),
    'total_records' => $state['total_records'] ?? null,
    'current_table' => $state['current_table'] ?? null,
    'error' => $state['error'] ?? null,
]);
