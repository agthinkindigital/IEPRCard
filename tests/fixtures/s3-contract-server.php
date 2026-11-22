<?php
declare(strict_types=1);

$storage = getenv('IEPR_S3_TEST_STORAGE') ?: '';
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($storage === '' || !str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) {
    http_response_code(403);
    exit;
}

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
if (count($segments) < 2 || $segments[0] !== 'backup-test') {
    http_response_code(404);
    exit;
}

$objectKey = implode('/', array_slice($segments, 1));
if ($objectKey === '' || preg_match('~^[A-Za-z0-9._/-]+$~D', $objectKey) !== 1
    || preg_match('#(?:^|/)\.\.?(/|$)#', $objectKey) === 1) {
    http_response_code(400);
    exit;
}
$objectPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $objectKey);

switch (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
    case 'PUT':
        file_put_contents($objectPath, file_get_contents('php://input'));
        http_response_code(200);
        header('ETag: "' . md5_file($objectPath) . '"');
        break;
    case 'GET':
        if (!is_file($objectPath)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($objectPath));
        readfile($objectPath);
        break;
    case 'DELETE':
        if (is_file($objectPath)) {
            unlink($objectPath);
        }
        http_response_code(204);
        break;
    default:
        http_response_code(405);
}
