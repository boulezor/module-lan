<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$form = ['name' => remembered_name(), 'title' => '', 'description' => '', 'video_url' => '', 'store_url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && proposals_open()) {
    $form = [
        'name' => clean_line(post_string('name'), 40),
        'title' => clean_line(post_string('title'), 100),
        'description' => trim(mb_substr(post_string('description'), 0, 1000)),
        'video_url' => clean_url(post_string('video_url')),
        'store_url' => clean_url(post_string('store_url')),
    ];
    $imageUrl = clean_url(post_string('image_url'));
    $tags = clean_line(post_string('tags'), 300);
    $steamAppId = ctype_digit(post_string('steam_appid')) ? (int) post_string('steam_appid') : null;

    $errors = [];
    if (!csrf_valid()) {
        $errors[] = 'Session expirée, merci de réessayer.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Indique ton nom.';
    }
    if (normalize_key($form['title']) === '') {
        $errors[] = 'Indique le nom du jeu.';
    }

    // Sans JavaScript (ou si l'utilisateur a tout vidé), on complète automatiquement.
    if (!$errors && $form['description'] === '') {
        $info = lookup_game($form['title']);
        $form['description'] = $info['description'];
        $form['video_url'] = $form['video_url'] ?: $info['video_url'];
        $form['store_url'] = $form['store_url'] ?: $info['store_url'];
        $imageUrl = $imageUrl ?: $info['image_url'];
        $tags = $tags ?: $info['tags'];
        $steamAppId ??= $info['steam_appid'];
    }

    if (!$errors && ($dup = find_duplicate($form['title'], $steamAppId))) {
        $errors[] = "« {$dup['title']} » a déjà été proposé par {$dup['proposed_by']}.";
    }

    if (!$errors) {
        try {
            db()->prepare('INSERT INTO games (title, normalized, steam_appid, description, tags, image_url, video_url, store_url, proposed_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $form['title'], normalize_key($form['title']), $steamAppId, $form['description'], $tags,
                    $imageUrl, $form['video_url'] ?: youtube_search_url($form['title']), $form['store_url'], $form['name'],
                ]);
            remember_name($form['name']);
            flash('success', "Merci {$form['name']} ! « {$form['title']} » est ajouté à la liste.");
            redirect('index.php');
        } catch (PDOException $ex) {
            // Course entre deux propositions identiques simultanées
            $errors[] = "Ce jeu vient d'être proposé par quelqu'un d'autre.";
        }
    }
    foreach ($errors as $error) {
        flash('error', $error);
    }
}

$games = all_games();
page_header('Proposer un jeu', 'proposer');
?>
<h1>Proposer un jeu</h1>

<?php if (!proposals_open()): ?>
    <div class="alert alert-info">Les propositions sont fermées. Direction la page <a href="voter.php">Voter</a> !</div>
<?php else: ?>
<form method="post" class="card" id="propose-form" autocomplete="off">
    <?= csrf_field() ?>
    <label>Ton nom
        <input type="text" name="name" maxlength="40" required value="<?= e($form['name']) ?>">
    </label>

    <label>Nom du jeu
        <span class="input-row">
            <input type="text" name="title" id="title" maxlength="100" required value="<?= e($form['title']) ?>" placeholder="ex. Age of Empires II">
            <button type="button" id="lookup-btn" class="btn-secondary">Chercher</button>
        </span>
    </label>

    <div id="lookup-status" class="lookup-status" aria-live="polite"></div>

    <div id="suggestion" class="suggestion" hidden>
        <img id="preview-img" alt="" hidden>
        <p id="preview-tags" class="tags"></p>
    </div>
    <input type="hidden" name="image_url" id="image_url">
    <input type="hidden" name="tags" id="tags">
    <input type="hidden" name="steam_appid" id="steam_appid">

    <label>Description <small class="muted">(remplie automatiquement, modifiable)</small>
        <textarea name="description" id="description" rows="4" maxlength="1000"><?= e($form['description']) ?></textarea>
    </label>

    <label>Lien vidéo (YouTube…)
        <input type="url" name="video_url" id="video_url" value="<?= e($form['video_url']) ?>">
    </label>

    <label>Lien fiche du jeu (Steam, Wikipédia, site officiel…)
        <input type="url" name="store_url" id="store_url" value="<?= e($form['store_url']) ?>">
    </label>

    <button type="submit" id="submit-btn">Proposer ce jeu</button>
</form>
<?php endif; ?>

<h2>Déjà proposés (<?= count($games) ?>)</h2>
<?php if (!$games): ?>
    <p class="muted">Aucun jeu pour l'instant. Lance-toi !</p>
<?php else: ?>
    <ul class="game-list compact">
        <?php foreach ($games as $game): ?>
            <li class="game"><?php game_media($game); ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php page_footer();
