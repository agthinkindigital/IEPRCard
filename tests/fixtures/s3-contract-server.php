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
if ($segments === [] || $segments[0] !== 'backup-test') {
    http_response_code(404);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && array_key_exists('versions', $_GET)) {
    $prefix = (string) ($_GET['prefix'] ?? '');
    $prefixPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $prefix);
    header('Content-Type: application/xml');
    $version = is_file($prefixPath)
        ? '<Version><Key>' . htmlspecialchars($prefix, ENT_XML1) . '</Key><VersionId>local-v1</VersionId>'
            . '<IsLatest>true</IsLatest><LastModified>2026-11-22T00:00:00.000Z</LastModified>'
            . '<ETag>"' . md5_file($prefixPath) . '"</ETag><Size>' . filesize($prefixPath)
            . '</Size><StorageClass>STANDARD</StorageClass></Version>'
        : '';
    echo '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ListVersionsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>backup-test</Name><Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>'
        . '<KeyMarker></KeyMarker><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>' . $version . '</ListVersionsResult>';
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && array_key_exists('delete', $_GET)) {
    $xml = (string) file_get_contents('php://input');
    if (preg_match_all('/<Object>\s*<Key>(.*?)<\/Key>\s*<VersionId>(.*?)<\/VersionId>\s*<\/Object>/s', $xml, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $deletedKey = html_entity_decode($match[1], ENT_XML1);
            if ($match[2] === 'local-v1' && preg_match('~^[A-Za-z0-9._/-]+$~D', $deletedKey) === 1) {
                $deletedPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $deletedKey);
                if (is_file($deletedPath)) unlink($deletedPath);
            }
        }
    }
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/" />';
    exit;
}

if (count($segments) < 2) {
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
    case 'HEAD':
        http_response_code(is_file($objectPath) ? 200 : 404);
        break;
    default:
        http_response_code(405);
}
