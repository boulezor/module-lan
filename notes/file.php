<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Sert un fichier envoyé, seulement à qui peut voir sa page (compte, ou lien public de la page).
$file = db_row('SELECT * FROM files WHERE uid = ?', [is_string($_GET['f'] ?? null) ? $_GET['f'] : '']);
$allowed = false;
if ($file) {
    $user = current_user();
    $allowed = ($user && find_page($user, (int) $file['page_id'], true))
        || in_array((int) $file['page_id'], $_SESSION['shared_pages'] ?? [], true);
}
$path = $file ? config('files_dir') . '/' . $file['stored_name'] : '';
if (!$allowed || !is_file($path)) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$inline = in_array($file['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true);
header('Content-Type: ' . ($inline ? $file['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['name']));
readfile($path);
