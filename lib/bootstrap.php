<?php

declare(strict_types=1);

$GLOBALS['facturasv_config'] = require dirname(__DIR__) . '/config/config.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (appConfig('app.env') === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set('UTC');

$isHttps = requestIsHttps();
$rememberSession = (($_COOKIE['facturasv_remember'] ?? '') === '1');
$sessionLifetime = $rememberSession ? 2592000 : 0;

// Las sesiones persistentes deben sobrevivir al recolector de PHP; la cookie
// sigue siendo la que decide si el navegador conserva la sesión al cerrarse.
ini_set('session.gc_maxlifetime', '2592000');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name(appConfig('app.session_name'));
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
