<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Page partagée publiquement, en lecture seule, via un lien secret.
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$page = preg_match('/^[a-f0-9]{32}$/', $token)
    ? db_row('SELECT * FROM pages WHERE share_token = ? AND trashed_at IS NULL', [$token]) : null;
if (!$page) {
    http_response_code(404);
    page_open('Page introuvable', 'auth');
    echo '<main class="auth-box"><h1>Page introuvable</h1><p>Ce lien de partage n\'existe pas ou n\'est plus actif.</p></main>';
    page_close();
    exit;
}

// Autorise l'affichage des images de cette page pour ce visiteur
$_SESSION['shared_pages'] = array_values(array_unique([...($_SESSION['shared_pages'] ?? []), (int) $page['id']]));

$boot = [
    'readOnly' => true,
    'page' => [
        'id' => (int) $page['id'], 'title' => $page['title'], 'icon' => $page['icon'], 'cover' => $page['cover'],
        'content' => $page['content'] !== '' ? json_decode($page['content'], true) : ['blocks' => []],
    ],
    'tree' => [], // les liens vers d'autres pages ne sont pas suivis en lecture publique
];
header('X-Robots-Tag: noindex, nofollow');
page_open($page['title'] ?: 'Sans titre', 'share');
?>
<main class="share-view">
    <div id="view"></div>
    <footer class="share-footer muted small">Page partagée en lecture seule · <?= e(config('site_title')) ?></footer>
</main>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
page_close(array_merge(editor_scripts(), ['assets/tools.js', 'assets/share.js']));
