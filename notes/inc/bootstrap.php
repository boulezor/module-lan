<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require __DIR__ . '/helpers.php';

// Sessions rangées dans le dossier privé de l'appli et gardées 14 jours :
// le nettoyage automatique du serveur (souvent après 24 min d'inactivité) ne les efface plus,
// donc une page laissée ouverte longtemps garde un jeton de sécurité valide.
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
session_name('notes_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/blocks.php';
require __DIR__ . '/pages.php';
require __DIR__ . '/database.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/layout.php';

date_default_timezone_set(config('timezone'));
