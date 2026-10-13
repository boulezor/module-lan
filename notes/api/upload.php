<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * Envoi d'une image ou d'un fichier joint (multipart, champ « file »), stocké hors du dossier web.
 * Réponse au format attendu par les blocs Image / Pièce jointe d'Editor.js.
 */

$user = require_api_user();
$page = find_page($user, (int) ($_GET['page'] ?? 0));
if (!$page) {
    json_response(['success' => 0, 'error' => 'Page introuvable.'], 404);
}
$file = $_FILES['file'] ?? null;
$maxBytes = (int) config('max_upload_mb') * 1024 * 1024;

if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    $message = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'Fichier trop gros pour le serveur (limite PHP upload_max_filesize).' : 'Envoi du fichier impossible.';
    json_response(['success' => 0, 'error' => $message], 400);
}
if ($file['size'] > $maxBytes) {
    json_response(['success' => 0, 'error' => 'Fichier trop gros (max ' . config('max_upload_mb') . ' Mo).'], 400);
}

$mime = function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : 'application/octet-stream';
$imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (($_GET['kind'] ?? '') === 'image' && !in_array($mime, $imageTypes, true)) {
    json_response(['success' => 0, 'error' => 'Formats d\'image acceptés : JPG, PNG, GIF, WebP.'], 400);
}

$dir = config('files_dir');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$uid = random_token(16);
$stored = $uid . '.bin';
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
    json_response(['success' => 0, 'error' => 'Enregistrement du fichier impossible (droits du dossier privé ?).'], 500);
}
$name = clean_line(basename((string) $file['name']), 200) ?: 'fichier';
db_exec('INSERT INTO files (uid, stored_name, page_id, user_id, name, mime, size) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$uid, $stored, $page['id'], $user['id'], $name, $mime, (int) $file['size']]);

json_response(['success' => 1, 'file' => [
    'url' => 'file.php?f=' . $uid,
    'name' => $name,
    'size' => (int) $file['size'],
    'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
]]);
