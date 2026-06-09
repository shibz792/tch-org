<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$legacy = APP_ROOT . '/personnel.json';
if (is_file($legacy) && (int) db()->query('SELECT COUNT(*) FROM personnel')->fetchColumn() === 0) {
    $records = json_decode((string) file_get_contents($legacy), true, 512, JSON_THROW_ON_ERROR);
    copy($legacy, STORAGE_PATH . '/migration/personnel-' . date('Ymd-His') . '.json');
    db()->beginTransaction();
    $departmentSeeds = [
        'Leadership' => '#243b36', 'Business Development' => '#2d6a8a', 'Operations' => '#0f766e',
        'Warehouse' => '#8a6d3b', 'Finance' => '#725a8a', 'Technology' => '#3f6f91',
        'Marketing' => '#a45d67', 'Customer Experience' => '#667d55',
    ];
    $departmentStmt = db()->prepare('INSERT OR IGNORE INTO departments(name,color,display_order) VALUES(?,?,?)');
    foreach ($departmentSeeds as $order => $color) $departmentStmt->execute([$order, $color, array_search($order, array_keys($departmentSeeds), true)]);
    $departmentIds = db()->query('SELECT name,id FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = db()->prepare('INSERT INTO personnel(id,name,title,department_id,manager_id,legacy_level,photo_path,is_cherry_global,display_order) VALUES(?,?,?,?,NULL,?,?,?,?)');
    foreach ($records as $index => $record) {
        $role = strtolower((string) ($record['role'] ?? ''));
        $department = match (true) {
            str_contains($role, 'general manager') => 'Leadership',
            str_contains($role, 'business development') => 'Business Development',
            str_contains($role, 'finance'), str_contains($role, 'account') => 'Finance',
            str_contains($role, 'marketing') => 'Marketing',
            str_contains($role, 'customer'), str_contains($role, 'crm') => 'Customer Experience',
            str_contains($role, 'warehouse') => 'Warehouse',
            str_contains($role, 'it'), str_contains($role, 'tech') => 'Technology',
            default => 'Operations',
        };
        $stmt->execute([
            $record['id'], $record['name'], $record['role'] ?? '', $departmentIds[$department], $record['level'] ?? null,
            $record['image'] ?? '', !empty($record['is_cherry_global']) ? 1 : 0, $index,
        ]);
    }
    $manager = db()->prepare('UPDATE personnel SET manager_id = ? WHERE id = ?');
    foreach ($records as $record) {
        $manager->execute([$record['reports_to'] ?? null, $record['id']]);
    }
    db()->commit();
    echo 'Migrated ' . count($records) . " personnel records.\n";
}

$email = getenv('ORGCHART_ADMIN_EMAIL');
$password = getenv('ORGCHART_ADMIN_PASSWORD');
$name = getenv('ORGCHART_ADMIN_NAME') ?: 'Organization Administrator';
if ($email && $password && (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        throw new RuntimeException('Admin email must be valid and password must contain at least 12 characters.');
    }
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    db()->prepare('INSERT INTO users(name,email,password_hash,force_password_change) VALUES(?,?,?,1)')->execute([$name, $email, $hash]);
    echo "Created initial administrator: $email\n";
}

echo "Database ready at " . DB_PATH . "\n";
