<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/request_correlation.php';
require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/tenant_resolver.php';
require_once dirname(__DIR__, 2) . '/config/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/titular_management.php';

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: no-store, private, max-age=0');

$pdo = dbConnection();
if (!tenantAuthStartSession()) {
    http_response_code(404);
    exit;
}
$context = tenantResolverResolve($pdo, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($context === null || !in_array($context->tipo, ['cliente', 'subcliente'], true) || $context->tenantId === null) {
    http_response_code(404);
    exit;
}
$identity = tenantAuthCurrentUser($pdo, $context);
$holderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($identity === null || $holderId === false || $holderId === null
    || (int) ($identity['tenant_id'] ?? 0) !== $context->tenantId) {
    http_response_code(404);
    exit;
}

$scope = tenantScopeBuildPredicate($identity, null, 't');
$query = $pdo->prepare(
    'SELECT t.subcliente_id, t.foto_url FROM titulares t WHERE t.id = :id AND ' . $scope['sql'] . ' LIMIT 1'
);
$query->execute(['id' => $holderId] + $scope['params']);
$holder = $query->fetch(PDO::FETCH_ASSOC);
if (!is_array($holder) || !pode(
    $pdo,
    $context,
    $identity,
    'titular.ver',
    $holder['subcliente_id'] === null ? null : (int) $holder['subcliente_id']
)) {
    http_response_code(404);
    exit;
}
$storedKey = $holder['foto_url'] ?? null;
if (!is_string($storedKey) || $storedKey === '') {
    http_response_code(404);
    exit;
}
try {
    $path = titularManagementResolvePhotoPath($context->tenantId, $storedKey);
} catch (Throwable) {
    http_response_code(404);
    exit;
}
$mimeType = match (strtolower(pathinfo($storedKey, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => null,
};
if ($mimeType === null || !is_readable($path)) {
    http_response_code(404);
    exit;
}
$size = filesize($path);
if (!is_int($size) || $size < 1 || $size > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="titular-photo.' . pathinfo($storedKey, PATHINFO_EXTENSION) . '"');
readfile($path);
