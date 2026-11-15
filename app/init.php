<?php

header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

session_name('invoice_saas');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $httpsSetting = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = !in_array($httpsSetting, ['', 'off', '0'], true);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$db = openDatabase();
initializeDatabase($db);

if (!empty($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $lastActivityUpdate = (int) ($_SESSION['last_activity_update'] ?? 0);
    if ($userId > 0 && $lastActivityUpdate < time() - 300) {
        recordUserActivity($db, $userId);
        $_SESSION['last_activity_update'] = time();
    }
}

function currentUser(PDO $pdo): ?array
{
    return getCurrentUser($pdo);
}

function currentBusiness(PDO $pdo): ?array
{
    return getCurrentBusiness($pdo);
}

function compactFlash(string $key): ?string
{
    return getFlash($key);
}
