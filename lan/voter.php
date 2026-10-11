<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$userId = (int) $user['id'];
$lans = lans_with_open('votes');
$lan = selected_lan($lans);
$options = vote_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lan = find_lan((int) post_string('lan_id'));
    $ratings = is_array($_POST['ratings'] ?? null) ? $_POST['ratings'] : [];
    $validOptions = array_column($options, null, 'id');

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif (!$lan || !(int) $lan['votes_open']) {
        flash('error', 'Les votes sont fermés pour cette LAN.');
    } else {
        // Un bulletin complet : chaque jeu du pot commun doit avoir une note
        $pot = lan_proposals((int) $lan['id']);
        $missing = array_filter($pot, fn($p) => !isset($validOptions[(int) ($ratings[$p['id']] ?? 0)]));
        if ($missing) {
            $n = count($missing);
            flash('error', "Ton vote n'est pas enregistré : il manque une note pour {$n} jeu" . ($n > 1 ? 'x' : '')
                . ' (' . implode(', ', array_map(fn($p) => "« {$p['title']} »", array_slice($missing, 0, 3))) . ($n > 3 ? '…' : '') . ').');
        } else {
            $alreadyVoted = (bool) db_row('SELECT 1 FROM lan_ratings WHERE lan_id = ? AND user_id = ? LIMIT 1', [$lan['id'], $userId]);
            db()->beginTransaction();
            foreach ($pot as $proposal) {
                db_exec('INSERT INTO lan_ratings (lan_id, proposal_id, user_id, option_id) VALUES (?, ?, ?, ?)
                         ON CONFLICT (proposal_id, user_id) DO UPDATE SET option_id = excluded.option_id, updated_at = CURRENT_TIMESTAMP',
                    [$lan['id'], $proposal['id'], $userId, (int) $ratings[$proposal['id']]]);
            }
            db()->commit();
            flash('success', $alreadyVoted ? 'Ton vote a été modifié.' : 'Merci, ton vote est enregistré !');
        }
    }
    redirect('voter.php' . ($lan ? '?lan=' . (int) $lan['id'] : ''));
}

$pot = $lan ? lan_proposals((int) $lan['id']) : [];
$myRatings = $lan ? db_rows('SELECT proposal_id, option_id, created_at, updated_at FROM lan_ratings WHERE lan_id = ? AND user_id = ?',
    [$lan['id'], $userId]) : [];
$mine = array_column($myRatings, 'option_id', 'proposal_id');
$submittedAt = $myRatings ? min(array_column($myRatings, 'created_at')) : null;
$modifiedAt = $myRatings ? max(array_column($myRatings, 'updated_at')) : null;
$toRate = count(array_filter($pot, fn($p) => !isset($mine[$p['id']])));

/** Horodatage SQLite (UTC) → « 14/11 à 18h05 » à l'heure locale. */
function local_time(string $utc): string
{
    return date('d/m \à H\hi', (int) strtotime($utc . ' UTC'));
}

/** Classe de couleur d'une option selon ses points */
function option_class(int $points): string
{
    return $points > 0 ? 'rate-pos' : ($points < 0 ? 'rate-neg' : 'rate-zero');
}

page_header('Voter', 'voter');
?>
<?php if (!$lan): ?>
    <h1>Voter</h1>
    <div class="alert alert-info">Les votes ne sont pas encore ouverts. L'organisateur les ouvrira quand le pot commun sera complet.</div>
<?php else: ?>
    <?php if (count($lans) > 1): ?>
        <nav class="lan-tabs">
            <?php foreach ($lans as $item): ?>
                <a href="voter.php?lan=<?= (int) $item['id'] ?>"<?= $item['id'] === $lan['id'] ? ' class="active"' : '' ?>><?= e($item['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <h1>Voter · <?= e($lan['title']) ?></h1>
    <p class="muted">📅 <?= e(format_lan_dates($lan)) ?></p>
    <p class="muted">Un vote par personne : donne ton avis sur <strong>chaque</strong> jeu du pot commun, puis envoie ton vote.
        Le classement additionne les points de tout le monde.</p>

    <?php if (!$pot): ?>
        <div class="alert alert-info">Le pot commun est vide.</div>
    <?php else: ?>
        <?php if ($submittedAt): ?>
            <div class="alert alert-success">
                ✔ Ton vote a déjà été soumis le <?= e(local_time($submittedAt)) ?><?= $modifiedAt !== $submittedAt ? ' (modifié le ' . e(local_time($modifiedAt)) . ')' : '' ?>.
                Tu peux encore le modifier ci-dessous tant que les votes sont ouverts.
            </div>
            <?php if ($toRate): ?>
                <div class="alert alert-warn">
                    <?= $toRate ?> jeu<?= $toRate > 1 ? 'x ont été ajoutés' : ' a été ajouté' ?> au pot commun depuis : note-<?= $toRate > 1 ? 'les' : 'le' ?> pour compléter ton vote.
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="vote-progress" id="vote-progress" data-total="<?= count($pot) ?>">
            Jeux notés : <strong><?= count($pot) - $toRate ?></strong> / <?= count($pot) ?>
        </p>
        <form method="post" id="vote-form">
            <?= csrf_field() ?>
            <input type="hidden" name="lan_id" value="<?= (int) $lan['id'] ?>">
            <ul class="game-list">
                <?php foreach ($pot as $game):
                    $current = (int) ($mine[$game['id']] ?? 0); ?>
                    <li class="game">
                        <?php game_media($game, function () use ($game, $options, $current) { ?>
                            <div class="rating" role="radiogroup" aria-label="Ton avis sur <?= e($game['title']) ?>">
                                <?php foreach ($options as $option): ?>
                                    <label class="rate <?= option_class((int) $option['points']) ?>">
                                        <input type="radio" name="ratings[<?= (int) $game['id'] ?>]" value="<?= (int) $option['id'] ?>" required
                                            <?= $current === (int) $option['id'] ? 'checked' : '' ?>>
                                        <span><?= e($option['label']) ?> <small><?= sprintf('%+d', (int) $option['points']) ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php }); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="sticky-submit">
                <button type="submit"><?= $submittedAt ? 'Modifier mon vote' : 'Envoyer mon vote' ?></button>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php page_footer();
