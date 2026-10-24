<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Liste des sessions de bingo de l'organisateur (les administrateurs voient aussi celles des autres)
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $game = find_game((int) post_string('id'));
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (post_string('action') === 'create') {
        $name = clean_line(post_string('name'), 80) ?: 'Bingo du ' . date('d/m/Y');
        $id = create_game($user, $name, (int) post_string('type'));
        redirect('session.php?id=' . $id);
    } elseif (!$game || !can_manage($game, $user)) {
        flash('error', 'Session introuvable.');
    } elseif (post_string('action') === 'delete') {
        db_exec('DELETE FROM games WHERE id = ?', [$game['id']]);
        flash('success', "Session « {$game['name']} » supprimée.");
    } elseif (post_string('action') === 'duplicate') {
        $id = create_game($user, mb_substr($game['name'] . ' (copie)', 0, 80), (int) $game['type']);
        db_exec('UPDATE games SET theme = ?, voice = ?, suspense = ?, phases = ?, auto_interval = ?, draw_mode = ? WHERE id = ?',
            [$game['theme'], $game['voice'], $game['suspense'], $game['phases'], $game['auto_interval'], $game['draw_mode'], $id]);
        flash('success', 'Session dupliquée (mêmes réglages et phases, nouveaux cartons, aucun tirage).');
    }
    redirect('index.php');
}

$all = (int) $user['is_admin'] && ($_GET['tous'] ?? '') === '1';
$games = db_rows('SELECT g.*, u.name AS owner_name,
        (SELECT COUNT(*) FROM draws d WHERE d.game_id = g.id) AS drawn,
        (SELECT COUNT(*) FROM winners w WHERE w.game_id = g.id) AS winners
    FROM games g LEFT JOIN users u ON u.id = g.owner_id
    WHERE ? OR g.owner_id = ? ORDER BY g.updated_at DESC', [$all ? 1 : 0, $user['id']]);

page_open('Mes bingos', 'home');
top_bar($user);
?>
<main class="home-main">
    <section class="hero">
        <div class="hero-balls" aria-hidden="true">
            <?php foreach ([7, 23, 42, 68, 85] as $n): ?><span class="ball c<?= min(8, intdiv($n, 10)) ?>"><span class="ball-face"><b><?= $n ?></b></span></span><?php endforeach; ?>
        </div>
        <h1>Bingo</h1>
        <p>Crée une session, ouvre l'<strong>écran de projection</strong> sur le grand écran et tire les boules à la main ou automatiquement.</p>
    </section>
    <?= flashes_html() ?>

    <section class="panel create-panel">
        <h2>Nouvelle session</h2>
        <form method="post" class="create-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="text" name="name" maxlength="80" placeholder="Nom (ex. Bingo de la kermesse)" aria-label="Nom de la session">
            <div class="segmented" role="radiogroup" aria-label="Type de bingo">
                <label><input type="radio" name="type" value="90" checked><span>90 boules <small>quine, carton plein</small></span></label>
                <label><input type="radio" name="type" value="75"><span>75 boules <small>B-I-N-G-O</small></span></label>
            </div>
            <button type="submit" class="btn btn-primary">＋ Créer</button>
        </form>
    </section>

    <div class="list-head">
        <h2><?= $all ? 'Toutes les sessions' : 'Mes sessions' ?> <span class="muted">(<?= count($games) ?>)</span></h2>
        <?php if ((int) $user['is_admin']): ?>
            <a href="index.php<?= $all ? '' : '?tous=1' ?>" class="btn btn-ghost"><?= $all ? 'Mes sessions seulement' : 'Voir toutes les sessions' ?></a>
        <?php endif; ?>
    </div>
    <?php if (!$games): ?>
        <p class="empty">Aucune session pour l'instant : crée la première ci-dessus.</p>
    <?php endif; ?>
    <div class="game-grid">
        <?php foreach ($games as $g): ?>
            <article class="game-card theme-<?= e($g['theme']) ?>">
                <a class="game-link" href="session.php?id=<?= (int) $g['id'] ?>">
                    <span class="game-type"><?= (int) $g['type'] ?> boules</span>
                    <strong class="game-name"><?= e($g['name']) ?></strong>
                    <span class="game-progress"><span style="width: <?= round(100 * (int) $g['drawn'] / ((int) $g['type'] === 75 ? 75 : 90)) ?>%"></span></span>
                    <span class="game-meta">
                        <?= (int) $g['drawn'] ?> boule<?= (int) $g['drawn'] > 1 ? 's' : '' ?> sortie<?= (int) $g['drawn'] > 1 ? 's' : '' ?>
                        <?= (int) $g['winners'] ? ' · 🏆 ' . (int) $g['winners'] : '' ?>
                        <?= (int) $g['auto_running'] ? ' · <b class="live">● auto</b>' : '' ?>
                    </span>
                    <span class="game-date">Modifiée le <?= e(local_time($g['updated_at'])) ?><?= $all ? ' · ' . e((string) $g['owner_name']) : '' ?></span>
                </a>
                <div class="game-actions">
                    <a href="session.php?id=<?= (int) $g['id'] ?>" class="btn btn-small btn-primary">Ouvrir</a>
                    <a href="ecran.php?t=<?= e($g['display_token']) ?>" target="_blank" rel="noopener" class="btn btn-small">📺 Écran</a>
                    <?= action_button('duplicate', 'Dupliquer', (int) $g['id'], 'btn btn-small btn-ghost') ?>
                    <?= action_button('delete', 'Supprimer', (int) $g['id'], 'btn btn-small btn-ghost danger', "Supprimer la session « {$g['name']} » et tout son historique ?") ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php
page_close(['assets/simple.js']);
