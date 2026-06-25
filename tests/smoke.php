<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$failures = [];
$people = personnel(true);
$warehouses = warehouses();
$ids = array_map(fn($p) => (int) $p['id'], $people);
if (count($people) !== 25) $failures[] = 'Expected 25 migrated personnel.';
if (count($ids) !== count(array_unique($ids))) $failures[] = 'Duplicate personnel IDs found.';
foreach ($people as $person) {
    if ($person['manager_id'] !== null && !in_array((int) $person['manager_id'], $ids, true)) $failures[] = "Missing manager for {$person['name']}.";
    if ($person['manager_id'] && creates_cycle((int) $person['id'], (int) $person['manager_id'])) $failures[] = "Cycle found for {$person['name']}.";
    if ($person['warehouse_id'] !== null && !in_array((int) $person['warehouse_id'], array_map(fn($w) => (int) $w['id'], $warehouses), true)) $failures[] = "Missing warehouse for {$person['name']}.";
    if (str_starts_with($person['photo_path'], 'uploads/') && !is_file(APP_ROOT . '/' . $person['photo_path'])) $failures[] = "Missing photo for {$person['name']}.";
}
$referenced = array_filter(array_column($people, 'photo_path'), fn($p) => str_starts_with($p, 'uploads/'));
$files = array_map(fn($p) => 'uploads/' . basename($p), glob(UPLOAD_PATH . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []);
if (array_diff($files, $referenced)) $failures[] = 'Orphan uploads found.';
if (db()->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') $failures[] = 'SQLite integrity check failed.';
if (!in_array('TCH1', array_column($warehouses, 'code'), true) || !in_array('TCH2', array_column($warehouses, 'code'), true)) $failures[] = 'Default TCH1 and TCH2 warehouses are missing.';

db()->beginTransaction();
try {
    try {
        archive_person(1);
        $failures[] = 'Archiving a person with active reports should fail.';
    } catch (InvalidArgumentException $expected) {
    }
    try {
        delete_person(1);
        $failures[] = 'Deleting a person with direct reports should fail.';
    } catch (InvalidArgumentException $expected) {
    }
    db()->prepare("INSERT INTO personnel(name,title,status) VALUES('Delete Leaf Test','Test','archived')")->execute();
    $deleteLeafId = (int) db()->lastInsertId();
    delete_person($deleteLeafId);
    $stmt = db()->prepare('SELECT COUNT(*) FROM personnel WHERE id = ?');
    $stmt->execute([$deleteLeafId]);
    if ((int) $stmt->fetchColumn() !== 0) $failures[] = 'Deleting a leaf person should remove that record.';
    db()->prepare("INSERT INTO personnel(name,title,status) VALUES('Archived Manager Test','Test','archived')")->execute();
    $archivedManagerId = (int) db()->lastInsertId();
    try {
        validate_person(['name' => 'Active Report Test', 'title' => 'Test', 'manager_id' => $archivedManagerId]);
        $failures[] = 'Assigning an active person to an archived manager should fail.';
    } catch (InvalidArgumentException $expected) {
    }
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Smoke checks passed: 25 people, valid hierarchy, warehouses, uploads, and SQLite database.\n";
