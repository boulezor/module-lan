<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$token = voter_token();

/** Vote déjà enregistré depuis ce navigateur */
function my_vote(string $token): ?array
{
    $stmt = db()->prepare('SELECT v.*, g.title FROM votes v JOIN games g ON g.id = v.game_id WHERE v.token = ? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && votes_open()) {
    $name = clean_line(post_string('name'), 40);
    $gameId = (int) post_string('game_id');
    $key = normalize_key($name);

    $stmt = db()->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();

    $stmt = db()->prepare('SELECT * FROM votes WHERE voter_normalized = ?');
    $stmt->execute([$key]);
    $existing = $stmt->fetch();
    $mine = my_vote($token);

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif ($key === '') {
        flash('error', 'Indique ton nom.');
    } elseif (!$game) {
        flash('error', 'Choisis un jeu dans la liste.');
    } elseif ($existing && !hash_equals($existing['token'], $token)) {
        flash('error', "« {$existing['voter_name']} » a déjà voté. Un seul vote par personne !");
    } elseif ($mine && $mine['voter_normalized'] !== $key) {
        flash('error', "Ce navigateur a déjà voté au nom de « {$mine['voter_name']} ».");
    } else {
        if ($existing) {
            db()->prepare('UPDATE votes SET game_id = ?, voter_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$gameId, $name, $existing['id']]);
            flash('success', "Vote modifié : tu votes maintenant pour « {$game['title']} ».");
        } else {
            db()->prepare('INSERT INTO votes (game_id, voter_name, voter_normalized, token) VALUES (?, ?, ?, ?)')
                ->execute([$gameId, $name, $key, $token]);
            flash('success', "Merci {$name}, ton vote pour « {$game['title']} » est enregistré !");
        }
        remember_name($name);
        redirect('voter.php');
    }
}

$mine = my_vote($token);
$games = all_games();
page_header('Voter', 'voter');
?>
<h1>Voter pour un jeu</h1>

<?php if (!votes_open()): ?>
    <div class="alert alert-info">Les votes sont fermés.<?= $mine ? ' Tu as voté pour « ' . e($mine['title']) . ' ».' : '' ?></div>
<?php elseif (!$games): ?>
    <div class="alert alert-info">Aucun jeu proposé pour l'instant. <a href="index.php">Propose le premier !</a></div>
<?php else: ?>
    <p class="muted">Un seul vote par personne. Tu peux le modifier depuis ce même appareil tant que les votes sont ouverts.</p>
    <?php if ($mine): ?>
        <div class="alert alert-info">Tu as voté pour <strong><?= e($mine['title']) ?></strong>. Choisis un autre jeu pour changer d'avis.</div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <label class="card">Ton nom
            <input type="text" name="name" maxlength="40" required
                   value="<?= e($mine['voter_name'] ?? remembered_name()) ?>"<?= $mine ? ' readonly' : '' ?>>
        </label>

        <ul class="game-list">
            <?php foreach ($games as $game): ?>
                <li>
                    <label class="game selectable">
                        <input type="radio" name="game_id" value="<?= (int) $game['id'] ?>" required
                            <?= $mine && (int) $mine['game_id'] === (int) $game['id'] ? 'checked' : '' ?>>
                        <?php game_media($game); ?>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sticky-submit">
            <button type="submit"><?= $mine ? 'Modifier mon vote' : 'Voter' ?></button>
        </div>
    </form>
<?php endif; ?>
<?php page_footer();
