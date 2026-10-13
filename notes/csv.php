<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Export CSV d'une base de données (séparateur « ; » et BOM UTF-8 : s'ouvre directement dans Excel en français).
$user = require_login();
$db = find_page($user, (int) ($_GET['id'] ?? 0));
if (!$db || $db['kind'] !== 'database') {
    http_response_code(404);
    exit('Base de données introuvable.');
}
$config = db_config($db);
$filename = (trim((string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $db['title'])) ?: 'base') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Nom', ...array_column($config['properties'], 'name')], ';');
foreach (db_rows_of((int) $db['id']) as $row) {
    $props = (array) $row['props'];
    $line = [$row['title']];
    foreach ($config['properties'] as $prop) {
        $line[] = prop_text($prop, $props[$prop['id']] ?? null);
    }
    fputcsv($out, $line, ';');
}
fclose($out);
