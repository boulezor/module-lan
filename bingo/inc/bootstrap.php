<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require __DIR__ . '/helpers.php';

// Sessions rangées dans le dossier privé et gardées 14 jours : le panneau de l'organisateur
// peut rester ouvert toute une soirée sans « jeton expiré ».
$sessionDir = config('private_dir') . '/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '200');
}
ini_set('session.gc_maxlifetime', (string) (14 * 86400));

// Nom de session propre à l'appli : plusieurs applis partagent le même domaine
session_name('bingo_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/game.php';
require __DIR__ . '/layout.php';

date_default_timezone_set(config('timezone'));
