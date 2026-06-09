<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const STORAGE_PATH = APP_ROOT . '/storage';
const DB_PATH = STORAGE_PATH . '/orgchart.sqlite';
const UPLOAD_PATH = APP_ROOT . '/uploads';
const SESSION_TTL = 3600;

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Pacific/Auckland');

foreach ([STORAGE_PATH, STORAGE_PATH . '/backups', STORAGE_PATH . '/logs', UPLOAD_PATH] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0750, true);
    }
}

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
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://i.ibb.co; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/services.php';

