<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (!github_persistence_enabled()) {
    fwrite(STDERR, "Set ORGCHART_GITHUB_TOKEN before running this script.\n");
    exit(1);
}

github_persist_database_if_configured('manual seed');
echo "Persisted database to GitHub branch " . GITHUB_PERSISTENCE_BRANCH . ".\n";

$count = 0;
foreach (glob(UPLOAD_PATH . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
    github_persist_upload_if_configured('uploads/' . basename($path), $path);
    $count++;
}

echo "Persisted $count upload file" . ($count === 1 ? '' : 's') . " to GitHub.\n";
