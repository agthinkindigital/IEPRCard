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
if ($segments === [] || preg_match('/^(?:backup-test|iepr-second-backup-[a-z-]+)$/D', $segments[0]) !== 1) {
    http_response_code(404);
    exit;
}
$bucket = $segments[0];

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && array_key_exists('versions', $_GET)) {
    $prefix = (string) ($_GET['prefix'] ?? '');
    $prefixPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '/' . $prefix);
    header('Content-Type: application/xml');
    $version = is_file($prefixPath)
        ? '<Version><Key>' . htmlspecialchars($prefix, ENT_XML1) . '</Key><VersionId>local-v1</VersionId>'
            . '<IsLatest>true</IsLatest><LastModified>2026-11-22T00:00:00.000Z</LastModified>'
            . '<ETag>"' . md5_file($prefixPath) . '"</ETag><Size>' . filesize($prefixPath)
            . '</Size><StorageClass>STANDARD</StorageClass></Version>'
        : '';
    echo '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ListVersionsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>' . htmlspecialchars($bucket, ENT_XML1) . '</Name><Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>'
        . '<KeyMarker></KeyMarker><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>' . $version . '</ListVersionsResult>';
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && (string) ($_GET['list-type'] ?? '') === '2') {
    $prefix = (string) ($_GET['prefix'] ?? '');
    $prefixPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '/' . $prefix);
    $contents = is_file($prefixPath)
        ? '<Contents><Key>' . htmlspecialchars($prefix, ENT_XML1) . '</Key>'
            . '<LastModified>2026-11-22T00:00:00.000Z</LastModified><ETag>"' . md5_file($prefixPath)
            . '"</ETag><Size>' . filesize($prefixPath) . '</Size><StorageClass>STANDARD</StorageClass></Contents>'
        : '';
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Name>' . htmlspecialchars($bucket, ENT_XML1) . '</Name><Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>'
        . '<KeyCount>' . (is_file($prefixPath) ? '1' : '0') . '</KeyCount><MaxKeys>1000</MaxKeys>'
        . '<IsTruncated>false</IsTruncated>' . $contents . '</ListBucketResult>';
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && array_key_exists('delete', $_GET)) {
    $xml = (string) file_get_contents('php://input');
    if (preg_match_all('/<Object>\s*<Key>(.*?)<\/Key>\s*<VersionId>(.*?)<\/VersionId>\s*<\/Object>/s', $xml, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $deletedKey = html_entity_decode($match[1], ENT_XML1);
            if ($match[2] === 'local-v1' && preg_match('~^[A-Za-z0-9._/-]+$~D', $deletedKey) === 1) {
                $deletedPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '/' . $deletedKey);
                if (is_file($deletedPath)) unlink($deletedPath);
                if (is_file($deletedPath . '.meta.json')) unlink($deletedPath . '.meta.json');
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
$objectPath = $storage . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '/' . $objectKey);

switch (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
    case 'PUT':
        file_put_contents($objectPath, file_get_contents('php://input'));
        $metadata = [];
        foreach ($_SERVER as $serverKey => $serverValue) {
            if (str_starts_with((string) $serverKey, 'HTTP_X_AMZ_META_') && is_string($serverValue)) {
                $metadata[strtolower(str_replace('_', '-', substr((string) $serverKey, strlen('HTTP_X_AMZ_META_'))))] = $serverValue;
            }
        }
        file_put_contents($objectPath . '.meta.json', json_encode($metadata, JSON_THROW_ON_ERROR));
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
        if (is_file($objectPath . '.meta.json')) unlink($objectPath . '.meta.json');
        http_response_code(204);
        break;
    case 'HEAD':
        if (!is_file($objectPath)) {
            http_response_code(404);
            break;
        }
        http_response_code(200);
        $metadataPath = $objectPath . '.meta.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : [];
        if (is_array($metadata)) {
            foreach ($metadata as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    header('x-amz-meta-' . $name . ': ' . $value);
                }
            }
        }
        break;
    default:
        http_response_code(405);
}
