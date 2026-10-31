<?php
declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$publicFiles = [
    '/' => [dirname(__DIR__) . '/index.html', 'text/html; charset=utf-8'],
    '/styles.css' => [dirname(__DIR__) . '/styles.css', 'text/css; charset=utf-8'],
    '/app.js' => [dirname(__DIR__) . '/app.js', 'text/javascript; charset=utf-8'],
    '/auth.js' => [__DIR__ . '/auth.js', 'text/javascript; charset=utf-8'],
];

if (isset($publicFiles[$path]) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    [$file, $contentType] = $publicFiles[$path];
    if (is_file($file)) {
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}

require __DIR__ . '/index.php';
