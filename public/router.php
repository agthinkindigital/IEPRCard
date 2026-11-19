<?php
declare(strict_types=1);

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
if (preg_match('~^/validar(?:/([^/]+))?/?$~D', $requestPath, $matches) === 1) {
    $_GET['codigo'] = $matches[1] ?? '';
    require __DIR__ . '/validar.php';
    return true;
}

$publicFile = realpath(__DIR__ . $requestPath);
if ($requestPath !== '/' && $publicFile !== false
    && str_starts_with($publicFile, realpath(__DIR__) . DIRECTORY_SEPARATOR) && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
