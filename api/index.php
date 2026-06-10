<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$route = $_GET['route'] ?? 'hierarchy';
$config = settings();

if ($route === 'hierarchy') {
    json_response([
        'hierarchy' => public_hierarchy_data(),
        'departments' => array_map(fn($d) => ['id'=>(int)$d['id'],'name'=>$d['name'],'color'=>$d['color']], departments()),
        'warehouses' => array_map(fn($w) => [
            'id'=>(int)$w['id'], 'code'=>$w['code'], 'name'=>$w['name'], 'location'=>$w['location'],
        ], warehouses(false)),
        'settings' => [
            'organization_name' => $config['organization_name'],
            'organization_tagline' => $config['organization_tagline'],
            'primary_color' => $config['primary_color'],
            'accent_color' => $config['accent_color'],
        ],
    ]);
}

if ($route === 'person' && isset($_GET['id'])) {
    $stmt = db()->prepare("SELECT p.*, d.name AS department, d.color AS department_color, m.name AS manager_name,
        w.code AS warehouse_code, w.name AS warehouse_name, w.location AS warehouse_location
        FROM personnel p LEFT JOIN departments d ON d.id=p.department_id LEFT JOIN personnel m ON m.id=p.manager_id
        LEFT JOIN warehouses w ON w.id=p.warehouse_id
        WHERE p.id=? AND p.status='active'");
    $stmt->execute([(int) $_GET['id']]);
    $person = $stmt->fetch();
    if (!$person) json_response(null, 404, ['Person not found.']);
    $teamStmt = db()->prepare("SELECT p.id,p.name,p.title,p.photo_path,p.is_cherry_global,d.name AS department,w.code AS warehouse_code
        FROM personnel p LEFT JOIN departments d ON d.id=p.department_id LEFT JOIN warehouses w ON w.id=p.warehouse_id
        WHERE p.manager_id=? AND p.status='active' ORDER BY p.display_order,p.name");
    $teamStmt->execute([(int) $person['id']]);
    $directReports = array_map(fn($report) => [
        'id'=>(int)$report['id'], 'name'=>$report['name'], 'title'=>$report['title'],
        'photo_path'=>$report['photo_path'], 'department'=>$report['department'],
        'warehouse_code'=>$report['warehouse_code'],
        'is_cherry_global'=>(bool)($report['is_cherry_global'] ?? false),
    ], $teamStmt->fetchAll());
    if (($config['show_email'] ?? '0') !== '1') unset($person['email']);
    if (($config['show_phone'] ?? '0') !== '1') unset($person['phone']);
    $public = [
        'id'=>(int)$person['id'], 'name'=>$person['name'], 'title'=>$person['title'],
        'department'=>$person['department'], 'department_color'=>$person['department_color'],
        'location'=>$person['location'], 'bio'=>$person['bio'], 'manager_name'=>$person['manager_name'],
        'warehouse_id'=>$person['warehouse_id'] ? (int)$person['warehouse_id'] : null,
        'warehouse_code'=>$person['warehouse_code'], 'warehouse_name'=>$person['warehouse_name'],
        'warehouse_location'=>$person['warehouse_location'],
        'photo_path'=>$person['photo_path'], 'is_cherry_global'=>(bool)$person['is_cherry_global'],
        'direct_reports'=>$directReports,
    ];
    if (($config['show_email'] ?? '0') === '1') $public['email'] = $person['email'];
    if (($config['show_phone'] ?? '0') === '1') $public['phone'] = $person['phone'];
    json_response($public);
}

json_response(null, 404, ['Endpoint not found.']);
