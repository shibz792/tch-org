<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$delete = in_array('--delete', $argv, true);
$referenced = array_filter(array_column(personnel(true), 'photo_path'), fn($path) => str_starts_with((string) $path, 'uploads/'));
$files = array_map(fn($path) => 'uploads/' . basename($path), glob(UPLOAD_PATH . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []);
$orphans = array_values(array_diff($files, $referenced));
foreach ($orphans as $orphan) {
    echo ($delete ? 'Deleting ' : 'Orphan: ') . $orphan . PHP_EOL;
    if ($delete) unlink(APP_ROOT . '/' . $orphan);
}
echo count($orphans) . ($delete ? ' orphan uploads deleted.' : ' orphan uploads found. Use --delete to remove them.') . PHP_EOL;

