<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_DRIVER === 'pgsql') {
        $parts = parse_url(DATABASE_URL);
        if (!$parts || empty($parts['host']) || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL must be a valid PostgreSQL connection string.');
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s%s',
            $parts['host'],
            (int) ($parts['port'] ?? 5432),
            ltrim($parts['path'], '/'),
            !empty($query['sslmode']) ? ';sslmode=' . $query['sslmode'] : ';sslmode=require'
        );
        $pdo = new PDO($dsn, urldecode((string) ($parts['user'] ?? '')), urldecode((string) ($parts['pass'] ?? '')), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } else {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function db_is_pgsql(): bool
{
    return DB_DRIVER === 'pgsql';
}

function db_last_insert_id(string $table): int
{
    if (db_is_pgsql()) {
        return (int) db()->query("SELECT currval(pg_get_serial_sequence('$table', 'id'))")->fetchColumn();
    }
    return (int) db()->lastInsertId();
}

function db_insert_ignore_sql(string $table, array $columns, string $conflictColumn): string
{
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnList = implode(',', $columns);
    if (db_is_pgsql()) {
        return "INSERT INTO $table($columnList) VALUES($placeholders) ON CONFLICT($conflictColumn) DO NOTHING";
    }
    return "INSERT OR IGNORE INTO $table($columnList) VALUES($placeholders)";
}

function db_table_has_column(string $table, string $column): bool
{
    if (db_is_pgsql()) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    $columns = db()->query("PRAGMA table_info($table)")->fetchAll();
    return in_array($column, array_column($columns, 'name'), true);
}

function db_reset_identity(string $table, string $column = 'id'): void
{
    if (!db_is_pgsql()) {
        return;
    }
    db()->exec("SELECT setval(pg_get_serial_sequence('$table', '$column'), COALESCE((SELECT MAX($column) FROM $table), 1), true)");
}

function initialize_database(): void
{
    $schema = db_is_pgsql() ? pgsql_schema() : sqlite_schema();
    db()->exec($schema);

    if (!db_table_has_column('personnel', 'warehouse_id')) {
        db()->exec('ALTER TABLE personnel ADD COLUMN warehouse_id INTEGER REFERENCES warehouses(id) ON DELETE SET NULL');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_personnel_warehouse ON personnel(warehouse_id)');
    }

    if (db_is_pgsql()) {
        db()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_warehouses_code_lower ON warehouses (lower(code))');
        db()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_lower ON users (lower(email))');
    }

    $warehouse = db()->prepare(db_insert_ignore_sql('warehouses', ['code', 'name', 'display_order'], db_is_pgsql() ? 'code' : 'code'));
    $warehouse->execute(['TCH1', 'TCH Warehouse 1', 1]);
    $warehouse->execute(['TCH2', 'TCH Warehouse 2', 2]);

    $defaults = [
        'organization_name' => 'Tech Cargo Hub',
        'organization_tagline' => 'People, teams and reporting lines',
        'primary_color' => '#00a99d',
        'accent_color' => '#b8dc78',
        'show_email' => '1',
        'show_phone' => '1',
    ];
    $stmt = db()->prepare(db_insert_ignore_sql('settings', ['key', 'value'], 'key'));
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function sqlite_schema(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS departments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL DEFAULT '#0f766e',
    description TEXT NOT NULL DEFAULT '',
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS personnel (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
    warehouse_id INTEGER REFERENCES warehouses(id) ON DELETE SET NULL,
    location TEXT NOT NULL DEFAULT '',
    email TEXT UNIQUE,
    phone TEXT NOT NULL DEFAULT '',
    bio TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    manager_id INTEGER REFERENCES personnel(id) ON DELETE SET NULL,
    legacy_level INTEGER,
    display_order INTEGER NOT NULL DEFAULT 0,
    photo_path TEXT NOT NULL DEFAULT '',
    is_cherry_global INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(manager_id IS NULL OR manager_id <> id)
);
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    force_password_change INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER,
    before_json TEXT,
    after_json TEXT,
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_personnel_manager ON personnel(manager_id);
CREATE INDEX IF NOT EXISTS idx_personnel_department ON personnel(department_id);
CREATE INDEX IF NOT EXISTS idx_personnel_warehouse ON personnel(warehouse_id);
CREATE INDEX IF NOT EXISTS idx_personnel_status ON personnel(status);
CREATE INDEX IF NOT EXISTS idx_warehouses_status ON warehouses(status);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
SQL;
}

function pgsql_schema(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS departments (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL DEFAULT '#0f766e',
    description TEXT NOT NULL DEFAULT '',
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS personnel (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
    warehouse_id INTEGER REFERENCES warehouses(id) ON DELETE SET NULL,
    location TEXT NOT NULL DEFAULT '',
    email TEXT UNIQUE,
    phone TEXT NOT NULL DEFAULT '',
    bio TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
    manager_id INTEGER REFERENCES personnel(id) ON DELETE SET NULL,
    legacy_level INTEGER,
    display_order INTEGER NOT NULL DEFAULT 0,
    photo_path TEXT NOT NULL DEFAULT '',
    is_cherry_global INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK(manager_id IS NULL OR manager_id <> id)
);
CREATE TABLE IF NOT EXISTS users (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','disabled')),
    force_password_change INTEGER NOT NULL DEFAULT 1,
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id INTEGER,
    before_json TEXT,
    after_json TEXT,
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    identifier TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_personnel_manager ON personnel(manager_id);
CREATE INDEX IF NOT EXISTS idx_personnel_department ON personnel(department_id);
CREATE INDEX IF NOT EXISTS idx_personnel_warehouse ON personnel(warehouse_id);
CREATE INDEX IF NOT EXISTS idx_personnel_status ON personnel(status);
CREATE INDEX IF NOT EXISTS idx_warehouses_status ON warehouses(status);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
SQL;
}

initialize_database();
