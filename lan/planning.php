<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Planning en direct : jeu en cours, prochain jeu, rappels push, notes des jeux joués, palmarès.
$lan = planning_lan((int) ($_GET['lan'] ?? 0));
$state = $lan ? planning_state($lan, current_user()) : null;

page_header('Planning', 'planning');
if (!$lan || !$state['slots']): ?>
    <h1>Planning</h1>
    <p class="muted">Le planning n'est pas encore publié.<?= is_admin() && $lan ? ' <a href="planning-admin.php?lan=' . (int) $lan['id'] . '">Créer le planning</a>' : '' ?></p>
<?php else: ?>
    <div class="planning-head">
        <div>
            <h1><?= e($lan['title']) ?></h1>
            <p class="muted">📅 <?= e(format_lan_dates($lan)) ?></p>
        </div>
        <div class="planning-tools">
            <button type="button" class="btn-secondary" id="tv-btn" title="Grand écran (TV / projecteur)">📺 Grand écran</button>
            <?php if (is_admin()): ?><a class="button btn-secondary" href="planning-admin.php?lan=<?= (int) $lan['id'] ?>">✏️ Modifier</a><?php endif; ?>
        </div>
    </div>

    <section class="now-card" id="now" aria-live="polite"></section>
    <div class="now-admin" id="now-admin"></div>

    <section class="card push-card" id="push" hidden></section>

    <section id="rate-box"></section>

    <h2>Programme</h2>
    <div class="days" id="days"></div>

    <h2>🏆 Palmarès des jeux joués</h2>
    <p class="muted small">Moyenne des notes ⭐ de chacun. Le jeu en tête est rejoué lors de la « revanche ».</p>
    <ol class="palmares" id="palmares"></ol>

    <script id="planning-state" type="application/json"><?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif;
page_footer($state && $state['slots'] ? ['assets/planning.js'] : []);
