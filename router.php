<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}
if (preg_match('#^/(app|storage|scripts|tests)(/|$)#', $path) || preg_match('/(?:\.sqlite|personnel\.json|\.log)$/', $path)) {
    http_response_code(404);
    exit('Not found');
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) return false;
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}
require __DIR__ . '/index.php';
