<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Export Markdown d'une page (téléchargement).
$user = require_login();
$page = find_page($user, (int) ($_GET['p'] ?? 0), true);
if (!$page) {
    http_response_code(404);
    exit('Page introuvable.');
}
$content = $page['content'] !== '' ? json_decode($page['content'], true) : ['blocks' => []];
$markdown = '# ' . trim($page['icon'] . ' ' . ($page['title'] ?: 'Sans titre')) . "\n\n"
    . blocks_to_markdown($content, fn(int $id) => ($p = find_page($user, $id)) ? trim($p['icon'] . ' ' . $p['title']) : null);
$markdown = preg_replace_callback('#\(file\.php\?f=([a-f0-9]{32})\)#', fn($m) => '(' . app_url('file.php?f=' . $m[1]) . ')', $markdown);

$filename = (trim((string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $page['title'])) ?: 'page') . '.md';
header('Content-Type: text/markdown; charset=utf-8');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
echo $markdown;
