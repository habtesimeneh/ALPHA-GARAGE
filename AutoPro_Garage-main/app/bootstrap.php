<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

$config = require APP_ROOT . '/app/config/app.php';
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone']);
require_once APP_ROOT . '/app/helpers/functions.php';

/* ---- error handling: never leak internals to the browser ---- */
ini_set('display_errors', $config['debug'] ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', storage_path('logs/php-error.log'));
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    app_log(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!empty($GLOBALS['config']['debug'])) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
        exit;
    }
    abort(500, 'An unexpected error occurred. It has been logged; please try again or contact the administrator.');
});

/* ---- autoload services & models ---- */
spl_autoload_register(function (string $class): void {
    foreach (['services', 'models'] as $dir) {
        $f = APP_ROOT . "/app/$dir/$class.php";
        if (is_file($f)) { require_once $f; return; }
    }
});
require_once APP_ROOT . '/app/middleware/auth.php';
require_once APP_ROOT . '/app/middleware/permissions.php';

/* ---- security headers (no inline JS/CSS is used anywhere, so a strict CSP is possible) ---- */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://images.unsplash.com; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store, max-age=0');

/* ---- hardened session ---- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

// Explicit trusted test-mode session isolation:
$isTestMode = getenv('GMS_TEST_MODE') === '1'
    || (getenv('GMS_STORAGE_PATH') !== false && trim((string) getenv('GMS_STORAGE_PATH')) !== '');

if ($isTestMode) {
    $sessDir = storage_path('sessions');
    if (!is_dir($sessDir) && !@mkdir($sessDir, 0700, true) && !is_dir($sessDir)) {
        app_log('Cannot create test session directory: ' . $sessDir);
    }
    if (is_dir($sessDir)) {
        session_save_path($sessDir);
    }
    session_name('GMSSESSID_TEST');
} else {
    session_name($config['session']['name']);
}

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

/* ---- remember-me: restore session from a valid persistent cookie ---- */
if (($GLOBALS['config']['remember']['enabled'] ?? false) && empty($_SESSION['uid']) && isset($_COOKIE['GMS_REMEMBER'])) {
    $restored = AuthService::restoreFromRememberCookie();
    if ($restored) {
        $_SESSION['last_activity'] = time();
    }
}

/* ---- global CSRF protection for every state-changing request ---- */
if (is_post()) {
    verify_csrf();
}
