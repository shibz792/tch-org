<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$delete = in_array('--delete', $argv, true);
$referenced = array_filter(array_column(personnel(true), 'photo_path'), fn($path) => str_starts_with((string) $path, 'uploads/'));
$uploadDirectories = array_values(array_unique([UPLOAD_PATH, APP_ROOT . '/uploads']));
$files = [];
foreach ($uploadDirectories as $directory) {
    foreach (glob($directory . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
        $files[] = 'uploads/' . basename($path);
    }
}
$files = array_values(array_unique($files));
$orphans = array_values(array_diff($files, $referenced));
foreach ($orphans as $orphan) {
    echo ($delete ? 'Deleting ' : 'Orphan: ') . $orphan . PHP_EOL;
    if ($delete) {
        foreach (upload_paths($orphan) as $path) {
            if (is_file($path)) unlink($path);
        }
    }
}
echo count($orphans) . ($delete ? ' orphan uploads deleted.' : ' orphan uploads found. Use --delete to remove them.') . PHP_EOL;
