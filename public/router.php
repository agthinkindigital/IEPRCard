<?php
declare(strict_types=1);

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
if (preg_match('~^/validar(?:/([^/]+))?/?$~D', $requestPath, $matches) === 1) {
    if (isset($matches[1]) && $matches[1] !== '') {
        $_GET['codigo'] = rawurldecode($matches[1]);
    } elseif (!is_string($_GET['codigo'] ?? null)) {
        $_GET['codigo'] = '';
    }
    require __DIR__ . '/validar.php';
    return true;
}

if (preg_match('~^/portal/carteirinha/([1-9][0-9]*)/([^/]+)$~D', $requestPath, $matches) === 1) {
    $_GET['carteirinha_id'] = $matches[1];
    $_GET['lado'] = rawurldecode($matches[2]);
    require __DIR__ . '/portal/carteirinha.php';
    return true;
}

if (preg_match('~^/portal/?$~D', $requestPath) === 1) {
    require __DIR__ . '/portal/index.php';
    return true;
}

$decodedPath = rawurldecode($requestPath);
$publicFile = realpath(__DIR__ . $decodedPath);
if ($requestPath !== '/' && $publicFile !== false
    && str_starts_with($publicFile, realpath(__DIR__) . DIRECTORY_SEPARATOR) && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
