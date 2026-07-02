<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (!db_is_pgsql()) {
    fwrite(STDERR, "Set ORGCHART_DATABASE_URL or DATABASE_URL before running this transfer.\n");
    exit(1);
}

$sourcePath = $argv[1] ?? APP_ROOT . '/storage/orgchart.sqlite';
if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite source database not found: $sourcePath\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$tables = ['departments', 'warehouses', 'personnel', 'users', 'settings', 'audit_logs'];
$target = db();

$target->beginTransaction();
try {
    $target->exec('TRUNCATE TABLE audit_logs, login_attempts, personnel, departments, warehouses, users, settings RESTART IDENTITY CASCADE');

    foreach ($tables as $table) {
        $columns = array_column($source->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!$columns) {
            continue;
        }
        $rows = $source->query("SELECT " . implode(',', $columns) . " FROM $table ORDER BY 1")->fetchAll();
        if (!$rows) {
            continue;
        }
        $sql = sprintf(
            'INSERT INTO %s(%s) VALUES(%s)',
            $table,
            implode(',', $columns),
            implode(',', array_fill(0, count($columns), '?'))
        );
        $stmt = $target->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute(array_map(fn($column) => $row[$column], $columns));
        }
        if (in_array('id', $columns, true)) {
            db_reset_identity($table);
        }
        echo "Transferred " . count($rows) . " rows into $table.\n";
    }

    $target->commit();
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    throw $e;
}

echo "SQLite data transferred into PostgreSQL.\n";
