<?php
declare(strict_types=1);

function s3CompatibleDeleteObjectPermanently(object $client, string $bucket, string $objectKey): void
{
    $keyMarker = null;
    $versionMarker = null;
    $pages = 0;
    $foundVersion = false;
    do {
        if (++$pages > 1000) {
            throw new RuntimeException('ANONYMIZATION_OBJECT_VERSION_LIMIT');
        }
        $arguments = ['Bucket' => $bucket, 'Prefix' => $objectKey, 'MaxKeys' => 1000];
        if (is_string($keyMarker)) $arguments['KeyMarker'] = $keyMarker;
        if (is_string($versionMarker)) $arguments['VersionIdMarker'] = $versionMarker;
        $page = $client->listObjectVersions($arguments);
        $objects = [];
        foreach (['Versions', 'DeleteMarkers'] as $section) {
            foreach (($page[$section] ?? []) as $entry) {
                if (($entry['Key'] ?? null) !== $objectKey || !is_string($entry['VersionId'] ?? null)) {
                    continue;
                }
                $foundVersion = true;
                $objects[] = ['Key' => $objectKey, 'VersionId' => $entry['VersionId']];
            }
        }
        foreach (array_chunk($objects, 1000) as $batch) {
            $result = $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => $batch, 'Quiet' => true],
            ]);
            if (!empty($result['Errors'])) {
                throw new RuntimeException('ANONYMIZATION_OBJECT_VERSION_DELETE_FAILED');
            }
        }
        $truncated = (bool) ($page['IsTruncated'] ?? false);
        $keyMarker = $truncated && is_string($page['NextKeyMarker'] ?? null) ? $page['NextKeyMarker'] : null;
        $versionMarker = $truncated && is_string($page['NextVersionIdMarker'] ?? null)
            ? $page['NextVersionIdMarker'] : null;
        if ($truncated && $keyMarker === null) {
            throw new RuntimeException('ANONYMIZATION_OBJECT_VERSION_PAGINATION_INVALID');
        }
    } while ($truncated);

    $verify = $client->listObjectVersions(['Bucket' => $bucket, 'Prefix' => $objectKey, 'MaxKeys' => 1000]);
    foreach (['Versions', 'DeleteMarkers'] as $section) {
        foreach (($verify[$section] ?? []) as $entry) {
            if (($entry['Key'] ?? null) === $objectKey) {
                throw new RuntimeException('ANONYMIZATION_OBJECT_VERSION_PURGE_UNVERIFIED');
            }
        }
    }
    if (!$foundVersion) {
        try {
            $client->headObject(['Bucket' => $bucket, 'Key' => $objectKey]);
            throw new RuntimeException('ANONYMIZATION_OBJECT_PURGE_UNVERIFIED');
        } catch (Throwable $exception) {
            if ($exception->getMessage() === 'ANONYMIZATION_OBJECT_PURGE_UNVERIFIED') throw $exception;
            $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : 0;
            if ($status !== 404 && $exception->getCode() !== 'NoSuchKey') {
                throw new RuntimeException('ANONYMIZATION_OBJECT_PURGE_UNVERIFIED');
            }
        }
    }
}
