<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}
if (preg_match('#^/uploads/([^/]+)$#', $path, $match)) {
    $normalizePath = static fn(string $target): string => rtrim($target, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
    $hasPersistentStorage = (bool) (getenv('ORGCHART_STORAGE_PATH') ?: getenv('RENDER_DISK_PATH'));
    $storagePath = $normalizePath((string) (getenv('ORGCHART_STORAGE_PATH') ?: getenv('RENDER_DISK_PATH') ?: __DIR__ . '/storage'));
    $uploadPath = getenv('ORGCHART_UPLOAD_PATH')
        ?: ($hasPersistentStorage ? $storagePath . '/uploads' : __DIR__ . '/uploads');
    $uploadPath = $normalizePath((string) $uploadPath);
    $name = basename($match[1]);
    $candidates = array_values(array_unique([
        $uploadPath . '/' . $name,
        __DIR__ . '/uploads/' . $name,
    ]));
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($candidate) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($candidate));
            readfile($candidate);
            exit;
        }
    }
    require_once __DIR__ . '/app/bootstrap.php';
    github_restore_upload_if_configured('uploads/' . $name);
    foreach (upload_paths('uploads/' . $name) as $candidate) {
        if (is_file($candidate)) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($candidate) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($candidate));
            readfile($candidate);
            exit;
        }
    }
    http_response_code(404);
    exit('Not found');
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
