<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/conn.php';
require_once $root . '/config/schema_self_healing.php';
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-http-correlation-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create HTTP correlation test storage.');
}

$oldEnvironment = [];
foreach (['APP_ENV', 'DB_DRIVER', 'DB_SQLITE_PATH'] as $key) {
    $value = getenv($key);
    $oldEnvironment[$key] = $value === false ? null : $value;
}
putenv('APP_ENV=dev');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $directory . DIRECTORY_SEPARATOR . 'http-correlation.sqlite');
$database = dbConnect();
schemaGarantirEstrutura($database, 'dev');
unset($database);
unset($database);

$portProbe = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($portProbe === false) {
    throw new RuntimeException('Could not allocate an HTTP correlation test port.');
}
$address = stream_socket_get_name($portProbe, false);
fclose($portProbe);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'router.php'], [
    0 => ['file', $nullDevice, 'r'],
    1 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.out.log', 'a'],
    2 => ['file', $directory . DIRECTORY_SEPARATOR . 'php.err.log', 'a'],
], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the HTTP correlation test server.');
}

try {
    $baseUrl = 'http://127.0.0.1:' . $port;
    $url = $baseUrl . '/index.php';
    $status = 0;
    $responseHeaders = [];
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "X-Correlation-ID: client-controlled-id\r\n",
            'timeout' => 2,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        if (is_array($responseHeaders) && isset($responseHeaders[0])
            && preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
        usleep(100_000);
    }

    $correlationId = null;
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'X-Correlation-ID:') === 0) {
            $correlationId = trim(substr($header, strlen('X-Correlation-ID:')));
            break;
        }
    }
    if ($status !== 200 || !is_string($response) || $response === '') {
        throw new RuntimeException('The HTTP request did not reach the local page successfully.');
    }
    if (!is_string($correlationId)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $correlationId) !== 1
        || $correlationId === 'client-controlled-id') {
        throw new RuntimeException('Every HTTP response must return a server-generated UUID v4 correlation ID.');
    }
    $eventPdo = dbConnect();
    $eventQuery = $eventPdo->prepare('SELECT correlation_id, categoria, evento_chave, rota, status_http, duracao_ms FROM eventos_tecnicos WHERE correlation_id = :correlation');
    $eventQuery->execute(['correlation' => $correlationId]);
    $requestEvents = $eventQuery->fetchAll(PDO::FETCH_ASSOC);
    if (count($requestEvents) !== 1 || $requestEvents[0]['categoria'] !== 'system'
        || $requestEvents[0]['evento_chave'] !== 'http.response' || $requestEvents[0]['rota'] !== 'system.index'
        || (int) $requestEvents[0]['status_http'] !== 200 || (int) $requestEvents[0]['duracao_ms'] < 0) {
        throw new RuntimeException('An HTTP request must persist exactly one minimized event with its server correlation ID and canonical route.');
    }
    unset($eventQuery, $eventPdo);

    $publicRoot = $root . DIRECTORY_SEPARATOR . 'public';
    $entrypoints = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicRoot));
    foreach ($iterator as $entry) {
        if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
            $entrypoints[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($publicRoot) + 1));
        }
    }
    sort($entrypoints, SORT_STRING);
    if ($entrypoints === [] || !in_array('tenant/titular-foto.php', $entrypoints, true)) {
        throw new RuntimeException('The HTTP correlation coverage inventory must include every public entrypoint, including the private titular photo route.');
    }

    $seenCorrelationIds = [$correlationId => true];
    foreach ($entrypoints as $entrypoint) {
        $requestPath = match ($entrypoint) {
            '.well-known/oauth-authorization-server.php' => '/.well-known/oauth-authorization-server',
            '.well-known/oauth-protected-resource-mcp.php' => '/.well-known/oauth-protected-resource/mcp',
            'oauth/token.php' => '/oauth/token',
            'mcp.php' => '/mcp',
            'validar.php' => '/validar',
            'portal/index.php' => '/portal',
            'portal/carteirinha.php' => '/portal/carteirinha/1/frente',
            'admin/eventos-tecnicos.php' => '/admin/eventos-tecnicos.php',
            'telemetria.php' => '/telemetria',
            default => '/' . $entrypoint,
        };
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'header' => "X-Correlation-ID: client-controlled-id\r\n",
            'timeout' => 2,
        ]]);
        $entrypointResponse = @file_get_contents($baseUrl . $requestPath, false, $context);
        $entrypointHeaders = $http_response_header ?? [];
        $entrypointCorrelationId = null;
        $contentType = '';
        foreach ($entrypointHeaders as $header) {
            if (stripos($header, 'X-Correlation-ID:') === 0) {
                $entrypointCorrelationId = trim(substr($header, strlen('X-Correlation-ID:')));
            }
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = strtolower(trim(substr($header, strlen('Content-Type:'))));
            }
        }
        if (!is_string($entrypointCorrelationId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $entrypointCorrelationId) !== 1
            || $entrypointCorrelationId === 'client-controlled-id') {
            throw new RuntimeException('Public PHP entrypoint did not return a server-generated correlation ID: ' . $entrypoint);
        }
        if (isset($seenCorrelationIds[$entrypointCorrelationId])) {
            throw new RuntimeException('Separate HTTP requests must receive distinct correlation IDs.');
        }
        if (str_starts_with($entrypoint, '.well-known/') && !str_starts_with($contentType, 'application/json')) {
            throw new RuntimeException('OAuth metadata routes must reach their JSON PHP entrypoint.');
        }
        $seenCorrelationIds[$entrypointCorrelationId] = true;
    }

    $eventPdo = dbConnect();
    $placeholders = implode(',', array_fill(0, count($seenCorrelationIds), '?'));
    $eventLookup = $eventPdo->prepare('SELECT correlation_id, rota FROM eventos_tecnicos WHERE correlation_id IN (' . $placeholders . ')');
    $eventLookup->execute(array_keys($seenCorrelationIds));
    $persistedEvents = $eventLookup->fetchAll(PDO::FETCH_ASSOC);
    $persistedIds = array_column($persistedEvents, 'correlation_id');
    sort($persistedIds, SORT_STRING);
    $expectedIds = array_keys($seenCorrelationIds);
    sort($expectedIds, SORT_STRING);
    if ($persistedIds !== $expectedIds || in_array('client-controlled-id', $persistedIds, true)) {
        throw new RuntimeException('Each covered HTTP entrypoint must persist exactly one event using only the server-generated correlation ID.');
    }
    unset($eventLookup, $eventPdo);
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach ($oldEnvironment as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
