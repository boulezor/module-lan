<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Nom de session propre à l'appli : plusieurs applis partagent le même sous-domaine
session_name('lan_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/games.php';
require __DIR__ . '/lookup.php';
require __DIR__ . '/layout.php';

date_default_timezone_set(config('timezone'));
