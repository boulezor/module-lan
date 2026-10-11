<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$lans = lans_with_open('votes');
$lan = selected_lan($lans);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lan = find_lan((int) post_string('lan_id'));
    $proposal = $lan ? db_row('SELECT * FROM proposals WHERE id = ? AND lan_id = ?', [(int) post_string('proposal_id'), $lan['id']]) : null;

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif (!$lan || !(int) $lan['votes_open']) {
        flash('error', 'Les votes sont fermés pour cette LAN.');
    } elseif (!$proposal) {
        flash('error', 'Choisis un jeu dans la liste.');
    } else {
        // Un vote par personne et par LAN : un nouveau choix remplace l'ancien
        db_exec('INSERT INTO lan_votes (lan_id, proposal_id, user_id) VALUES (?, ?, ?)
                 ON CONFLICT (lan_id, user_id) DO UPDATE SET proposal_id = excluded.proposal_id, updated_at = CURRENT_TIMESTAMP',
            [$lan['id'], $proposal['id'], $user['id']]);
        flash('success', "C'est noté : tu votes pour « {$proposal['title']} ».");
    }
    redirect('voter.php' . ($lan ? '?lan=' . (int) $lan['id'] : ''));
}

$pot = $lan ? lan_proposals((int) $lan['id']) : [];
$myVote = $lan ? db_row('SELECT v.*, p.title FROM lan_votes v JOIN proposals p ON p.id = v.proposal_id
                         WHERE v.lan_id = ? AND v.user_id = ?', [$lan['id'], $user['id']]) : null;

page_header('Voter', 'voter');
?>
<?php if (!$lan): ?>
    <h1>Voter</h1>
    <div class="alert alert-info">Aucun vote en cours pour l'instant.</div>
<?php else: ?>
    <?php if (count($lans) > 1): ?>
        <nav class="lan-tabs">
            <?php foreach ($lans as $item): ?>
                <a href="voter.php?lan=<?= (int) $item['id'] ?>"<?= $item['id'] === $lan['id'] ? ' class="active"' : '' ?>><?= e($item['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <h1>Voter · <?= e($lan['title']) ?></h1>
    <p class="muted">📅 <?= e(format_date($lan['lan_date'])) ?> — un seul vote par personne, modifiable tant que les votes sont ouverts.</p>

    <?php if (!$pot): ?>
        <div class="alert alert-info">Le pot commun est vide. <a href="ma-liste.php">Propose un jeu de ta liste !</a></div>
    <?php else: ?>
        <?php if ($myVote): ?>
            <div class="alert alert-info">Tu as voté pour <strong><?= e($myVote['title']) ?></strong>. Choisis un autre jeu pour changer d'avis.</div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="lan_id" value="<?= (int) $lan['id'] ?>">
            <ul class="game-list">
                <?php foreach ($pot as $game): ?>
                    <li>
                        <label class="game selectable">
                            <input type="radio" name="proposal_id" value="<?= (int) $game['id'] ?>" required
                                <?= $myVote && (int) $myVote['proposal_id'] === (int) $game['id'] ? 'checked' : '' ?>>
                            <?php game_media($game); ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="sticky-submit">
                <button type="submit"><?= $myVote ? 'Modifier mon vote' : 'Voter' ?></button>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php page_footer();
