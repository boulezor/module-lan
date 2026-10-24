<?php
declare(strict_types=1);
define('APP_ROOT', __DIR__);
require __DIR__ . '/inc/helpers.php';

// Logo du thème « École Saint-Martin » (rangé dans le dossier privé, visible par l'écran de projection public)
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
foreach ($types as $ext => $mime) {
    $path = config('private_dir') . '/theme/logo.' . $ext;
    if (is_file($path)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
http_response_code(404);
