<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (!cloudinary_is_configured()) {
    fwrite(STDERR, "Set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, and CLOUDINARY_API_SECRET before running this script.\n");
    exit(1);
}

$stmt = db()->query("SELECT id, name, photo_path FROM personnel WHERE photo_path LIKE 'uploads/%' ORDER BY id");
$people = $stmt->fetchAll();
$updated = 0;

foreach ($people as $person) {
    $localPath = null;
    foreach (upload_paths((string) $person['photo_path']) as $candidate) {
        if (is_file($candidate)) {
            $localPath = $candidate;
            break;
        }
    }
    if (!$localPath) {
        echo "Missing local file for {$person['name']}: {$person['photo_path']}\n";
        continue;
    }
    $url = cloudinary_upload($localPath);
    $update = db()->prepare('UPDATE personnel SET photo_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([$url, (int) $person['id']]);
    $updated++;
    echo "Uploaded {$person['name']} photo.\n";
}

echo "Updated $updated personnel photo path" . ($updated === 1 ? '' : 's') . ".\n";
