<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$current = isset($_GET['p']) ? find_page($user, (int) $_GET['p'], true) : null;

$boot = [
    'csrf' => csrf_token(),
    'user' => ['id' => (int) $user['id'], 'name' => $user['name'], 'isAdmin' => (bool) $user['is_admin']],
    'siteTitle' => config('site_title'),
    'teamName' => config('team_name'),
    'aiEnabled' => openai_key() !== '',
    'aiActions' => array_map(fn($a) => $a[0], ai_actions()),
    'maxUploadMb' => (int) config('max_upload_mb'),
    'page' => $current ? page_payload($user, $current) : null,
    'missing' => isset($_GET['p']) && !$current,
];
page_open($current ? ($current['title'] ?: 'Sans titre') : 'Accueil', 'app-body');
?>
<div class="app" id="app">
    <aside class="sidebar" id="sidebar">
        <div class="sb-head">
            <button type="button" class="sb-user" id="user-menu-btn" aria-haspopup="menu">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                <span class="sb-user-name"><?= e($user['name']) ?></span>
                <span class="chev">⌄</span>
            </button>
            <button type="button" class="icon-btn sb-close" id="sidebar-close" title="Fermer le menu">✕</button>
        </div>
        <nav class="sb-nav">
            <button type="button" class="sb-link" id="open-search">🔍 Rechercher <kbd>Ctrl K</kbd></button>
            <a class="sb-link" href="index.php" data-nav="home">🏠 Accueil</a>
            <button type="button" class="sb-link" id="open-trash">🗑️ Corbeille</button>
        </nav>
        <div class="sb-scroll">
            <section class="sb-section" id="sb-favorites" hidden>
                <div class="sb-title"><span>Favoris</span></div>
                <div class="sb-list" data-list="favorites"></div>
            </section>
            <section class="sb-section" data-space="team">
                <div class="sb-title" data-drop-space="team"><span><?= e(config('team_name')) ?></span>
                    <button type="button" class="icon-btn" data-new-page="team" title="Nouvelle page d'équipe">＋</button></div>
                <div class="sb-list" data-list="team"></div>
            </section>
            <section class="sb-section" id="sb-templates">
                <div class="sb-title"><button type="button" class="sb-fold" id="templates-fold">▸ Modèles</button></div>
                <div class="sb-list" data-list="templates" hidden></div>
            </section>
            <section class="sb-section" data-space="private">
                <div class="sb-title" data-drop-space="private"><span>Privé</span>
                    <button type="button" class="icon-btn" data-new-page="private" title="Nouvelle page privée">＋</button></div>
                <div class="sb-list" data-list="private"></div>
            </section>
        </div>
    </aside>
    <div class="sb-overlay" id="sidebar-overlay"></div>

    <main class="main">
        <header class="topbar">
            <button type="button" class="icon-btn" id="sidebar-open" title="Menu">☰</button>
            <nav class="breadcrumb" id="breadcrumb"></nav>
            <span class="save-status" id="save-status"></span>
            <div class="top-actions" id="top-actions" hidden>
                <button type="button" class="top-btn" id="ai-page-btn" title="Assistant IA sur la page">✨ IA</button>
                <button type="button" class="top-btn" id="share-btn">Partager</button>
                <button type="button" class="icon-btn" id="fav-btn" title="Favori">☆</button>
                <button type="button" class="icon-btn" id="page-menu-btn" title="Plus d'actions">⋯</button>
            </div>
        </header>
        <div class="conflict-bar" id="conflict-bar" hidden></div>
        <div class="view" id="view"></div>
    </main>
</div>

<div class="menu" id="menu" hidden></div>
<div class="modal-backdrop" id="modal" hidden><div class="modal" id="modal-box" role="dialog" aria-modal="true"></div></div>
<div class="toast" id="toast" hidden></div>

<form method="post" action="login.php" id="logout-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="logout"></form>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
page_close(array_merge(editor_scripts(), ['assets/tools.js', 'assets/app.js']));
