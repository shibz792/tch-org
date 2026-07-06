<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const SESSION_TTL = 3600;

$normalizePath = static fn(string $path): string => rtrim($path, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
$hasPersistentStorage = (bool) (getenv('ORGCHART_STORAGE_PATH') ?: getenv('RENDER_DISK_PATH'));
$storagePath = $normalizePath((string) (getenv('ORGCHART_STORAGE_PATH') ?: getenv('RENDER_DISK_PATH') ?: APP_ROOT . '/storage'));
$dbPath = $normalizePath((string) (getenv('ORGCHART_DB_PATH') ?: $storagePath . '/orgchart.sqlite'));
$databaseUrl = (string) (getenv('ORGCHART_DATABASE_URL') ?: getenv('DATABASE_URL') ?: '');
$uploadPath = getenv('ORGCHART_UPLOAD_PATH')
    ?: ($hasPersistentStorage ? $storagePath . '/uploads' : APP_ROOT . '/uploads');

define('STORAGE_PATH', $storagePath);
define('DB_PATH', $dbPath);
define('DATABASE_URL', $databaseUrl);
define('DB_DRIVER', $databaseUrl !== '' ? 'pgsql' : 'sqlite');
define('UPLOAD_PATH', $normalizePath((string) $uploadPath));
define('GITHUB_PERSISTENCE_TOKEN', (string) getenv('ORGCHART_GITHUB_TOKEN'));
define('GITHUB_PERSISTENCE_REPO', (string) (getenv('ORGCHART_GITHUB_REPO') ?: 'shibz792/tch-org'));
define('GITHUB_PERSISTENCE_BRANCH', (string) (getenv('ORGCHART_GITHUB_BRANCH') ?: 'data'));
define('GITHUB_PERSISTENCE_BASE_BRANCH', (string) (getenv('ORGCHART_GITHUB_BASE_BRANCH') ?: 'main'));
define('GITHUB_PERSISTENCE_DB_PATH', (string) (getenv('ORGCHART_GITHUB_DB_PATH') ?: 'storage/orgchart.sqlite'));

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Pacific/Auckland');

foreach ([STORAGE_PATH, dirname(DB_PATH), STORAGE_PATH . '/backups', STORAGE_PATH . '/logs', STORAGE_PATH . '/migration', UPLOAD_PATH] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0750, true);
    }
}

require_once __DIR__ . '/github_persistence.php';
github_restore_database_if_configured();

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('orgchart_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > SESSION_TTL) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://i.ibb.co https://res.cloudinary.com https://*.supabase.co; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/services.php';
github_restore_referenced_uploads_if_configured();
