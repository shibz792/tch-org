<?php
declare(strict_types=1);

function settings(): array
{
    return db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function departments(): array
{
    return db()->query('SELECT * FROM departments ORDER BY display_order, name')->fetchAll();
}

function warehouses(bool $includeInactive = true): array
{
    $where = $includeInactive ? '' : "WHERE status = 'active'";
    return db()->query("SELECT w.*,
        (SELECT COUNT(*) FROM personnel p WHERE p.warehouse_id = w.id AND p.status = 'active') AS personnel_count
        FROM warehouses w $where ORDER BY w.display_order, w.code")->fetchAll();
}

function personnel(bool $includeArchived = false): array
{
    $where = $includeArchived ? '' : "WHERE p.status = 'active'";
    return db()->query("SELECT p.*, d.name AS department, d.color AS department_color,
        w.code AS warehouse_code, w.name AS warehouse_name, w.location AS warehouse_location,
        (SELECT COUNT(*) FROM personnel c WHERE c.manager_id = p.id AND c.status = 'active') AS direct_reports
        FROM personnel p
        LEFT JOIN departments d ON d.id = p.department_id
        LEFT JOIN warehouses w ON w.id = p.warehouse_id $where
        ORDER BY p.display_order, p.name")->fetchAll();
}

function hierarchy_data(): array
{
    $people = personnel();
    $activeIds = array_fill_keys(array_map(fn($person) => (int) $person['id'], $people), true);
    $byManager = [];
    foreach ($people as $person) {
        $managerId = $person['manager_id'] ? (int) $person['manager_id'] : null;
        $byManager[$managerId && isset($activeIds[$managerId]) ? $managerId : 'root'][] = $person;
    }
    $build = function ($managerId, array $trail = []) use (&$build, &$byManager): array {
        $nodes = [];
        foreach ($byManager[$managerId] ?? [] as $person) {
            if (in_array((int) $person['id'], $trail, true)) {
                continue;
            }
            $person['children'] = $build((int) $person['id'], [...$trail, (int) $person['id']]);
            $nodes[] = $person;
        }
        return $nodes;
    };
    return $build('root');
}

function hierarchy_issues(): array
{
    $issues = [];
    $stmt = db()->query("SELECT p.id, p.name, m.name AS manager_name, m.status AS manager_status
        FROM personnel p
        JOIN personnel m ON m.id = p.manager_id
        WHERE p.status = 'active' AND m.status <> 'active'
        ORDER BY p.name");
    foreach ($stmt as $row) {
        $issues[] = sprintf('%s reports to archived manager %s. Move them to an active manager or top level.', $row['name'], $row['manager_name']);
    }

    $stmt = db()->query("SELECT p.id, p.name, COUNT(c.id) AS active_reports
        FROM personnel p
        JOIN personnel c ON c.manager_id = p.id AND c.status = 'active'
        WHERE p.status = 'archived'
        GROUP BY p.id, p.name
        ORDER BY p.name");
    foreach ($stmt as $row) {
        $issues[] = sprintf('%s is archived but still has %d active direct report%s.', $row['name'], (int) $row['active_reports'], (int) $row['active_reports'] === 1 ? '' : 's');
    }

    return $issues;
}

function public_hierarchy_data(): array
{
    $clean = function (array $nodes) use (&$clean): array {
        return array_map(fn($person) => [
            'id' => (int) $person['id'],
            'name' => $person['name'],
            'title' => $person['title'],
            'department_id' => $person['department_id'] ? (int) $person['department_id'] : null,
            'department' => $person['department'],
            'department_color' => $person['department_color'],
            'location' => $person['location'],
            'warehouse_id' => $person['warehouse_id'] ? (int) $person['warehouse_id'] : null,
            'warehouse_code' => $person['warehouse_code'],
            'warehouse_name' => $person['warehouse_name'],
            'photo_path' => $person['photo_path'],
            'is_cherry_global' => (bool) $person['is_cherry_global'],
            'direct_reports' => (int) $person['direct_reports'],
            'children' => $clean($person['children'] ?? []),
        ], $nodes);
    };
    return $clean(hierarchy_data());
}

function validate_person(array $input, ?int $id = null): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $title = trim((string) ($input['title'] ?? ''));
    $status = $input['status'] ?? 'active';
    $managerId = ($input['manager_id'] ?? '') === '' ? null : (int) $input['manager_id'];
    $departmentId = ($input['department_id'] ?? '') === '' ? null : (int) $input['department_id'];
    $warehouseId = ($input['warehouse_id'] ?? '') === '' ? null : (int) $input['warehouse_id'];
    $email = trim((string) ($input['email'] ?? '')) ?: null;
    $errors = [];
    if ($name === '') $errors[] = 'Name is required.';
    if (!in_array($status, ['active', 'archived'], true)) $errors[] = 'Invalid status.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is invalid.';
    if ($id && $managerId === $id) $errors[] = 'A person cannot report to themselves.';
    if ($managerId) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM personnel WHERE id = ? AND status = 'active'");
        $stmt->execute([$managerId]);
        if (!$stmt->fetchColumn()) $errors[] = 'Selected manager must be active.';
    }
    if ($departmentId) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
        $stmt->execute([$departmentId]);
        if (!$stmt->fetchColumn()) $errors[] = 'Selected department does not exist.';
    }
    if ($warehouseId) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM warehouses WHERE id = ?');
        $stmt->execute([$warehouseId]);
        if (!$stmt->fetchColumn()) $errors[] = 'Selected warehouse does not exist.';
    }
    if ($managerId && $id && creates_cycle($id, $managerId)) $errors[] = 'That reporting line would create a circular hierarchy.';
    if ($id && $status === 'archived') {
        $stmt = db()->prepare("SELECT COUNT(*) FROM personnel WHERE manager_id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $activeReports = (int) $stmt->fetchColumn();
        if ($activeReports > 0) {
            $errors[] = 'Move or archive this person’s active direct reports before archiving them.';
        }
    }
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    return [
        'name' => $name, 'title' => $title, 'department_id' => $departmentId, 'warehouse_id' => $warehouseId,
        'location' => trim((string) ($input['location'] ?? '')), 'email' => $email,
        'phone' => trim((string) ($input['phone'] ?? '')), 'bio' => trim((string) ($input['bio'] ?? '')),
        'status' => $status, 'manager_id' => $managerId,
        'display_order' => max(0, (int) ($input['display_order'] ?? 0)),
        'is_cherry_global' => !empty($input['is_cherry_global']) ? 1 : 0,
    ];
}

function archive_person(int $id): void
{
    $stmt = db()->prepare('SELECT * FROM personnel WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new InvalidArgumentException('Person not found.');
    if ($before['status'] === 'archived') throw new InvalidArgumentException('Person is already archived.');

    $stmt = db()->prepare("SELECT COUNT(*) FROM personnel WHERE manager_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Cannot archive this person while they have active direct reports. Move or archive their direct reports first.');
    }

    db()->prepare("UPDATE personnel SET status='archived', manager_id=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
    audit('archive', 'personnel', $id, $before, ['status' => 'archived', 'manager_id' => null]);
}

function delete_person(int $id): void
{
    $stmt = db()->prepare('SELECT * FROM personnel WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) throw new InvalidArgumentException('Person not found.');

    $stmt = db()->prepare('SELECT COUNT(*) FROM personnel WHERE manager_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('Cannot delete this person while they have direct reports. Move or delete those reporting lines first.');
    }

    db()->prepare('DELETE FROM personnel WHERE id = ?')->execute([$id]);
    audit('delete', 'personnel', $id, $before, null);
}

function creates_cycle(int $id, int $managerId): bool
{
    $visited = [$id];
    $current = $managerId;
    $stmt = db()->prepare('SELECT manager_id FROM personnel WHERE id = ?');
    while ($current) {
        if (in_array($current, $visited, true)) return true;
        $visited[] = $current;
        $stmt->execute([$current]);
        $current = (int) ($stmt->fetchColumn() ?: 0);
    }
    return false;
}

function save_person(array $input, ?int $id = null, ?array $file = null): int
{
    $data = validate_person($input, $id);
    $photo = $file ? safe_upload($file) : '';
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
        if ($id) {
            $stmt = db()->prepare('SELECT * FROM personnel WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch();
            if (!$before) throw new InvalidArgumentException('Person not found.');
            $photo = $photo ?: $before['photo_path'];
            $sql = 'UPDATE personnel SET name=?, title=?, department_id=?, warehouse_id=?, location=?, email=?, phone=?, bio=?, status=?, manager_id=?, display_order=?, is_cherry_global=?, photo_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?';
            db()->prepare($sql)->execute([...array_values($data), $photo, $id]);
            audit('update', 'personnel', $id, $before, [...$data, 'photo_path' => $photo]);
        } else {
            $sql = 'INSERT INTO personnel(name,title,department_id,warehouse_id,location,email,phone,bio,status,manager_id,display_order,is_cherry_global,photo_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)';
            db()->prepare($sql)->execute([...array_values($data), $photo]);
            $id = (int) db()->lastInsertId();
            audit('create', 'personnel', $id, null, [...$data, 'photo_path' => $photo]);
        }
        if ($ownsTransaction) db()->commit();
        return $id;
    } catch (Throwable $e) {
        if ($ownsTransaction && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

function create_backup(): string
{
    $file = STORAGE_PATH . '/backups/orgchart-' . date('Ymd-His') . '.sqlite';
    $quoted = str_replace("'", "''", $file);
    db()->exec("VACUUM INTO '$quoted'");
    $files = glob(STORAGE_PATH . '/backups/*.sqlite') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, 10) as $old) unlink($old);
    audit('backup', 'database', null, null, ['file' => basename($file)]);
    return $file;
}
