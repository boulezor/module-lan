<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$configured = (string) config('admin_password') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif ($action === 'login' && $configured) {
        if (hash_equals((string) config('admin_password'), post_string('password'))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
        } else {
            sleep(1); // freine les essais en rafale
            flash('error', 'Mot de passe incorrect.');
        }
    } elseif (is_admin()) {
        switch ($action) {
            case 'logout':
                unset($_SESSION['admin']);
                break;
            case 'toggle_proposals':
                set_setting('proposals_open', proposals_open() ? '0' : '1');
                break;
            case 'toggle_votes':
                set_setting('votes_open', votes_open() ? '0' : '1');
                break;
            case 'delete_game':
                db()->prepare('DELETE FROM games WHERE id = ?')->execute([(int) post_string('id')]);
                flash('success', 'Jeu supprimé (et ses votes).');
                break;
            case 'delete_vote':
                db()->prepare('DELETE FROM votes WHERE id = ?')->execute([(int) post_string('id')]);
                flash('success', 'Vote supprimé.');
                break;
            case 'reset_votes':
                db()->exec('DELETE FROM votes');
                flash('success', 'Tous les votes ont été remis à zéro.');
                break;
        }
    }
    redirect('resultats.php');
}

page_header('Résultats', 'resultats');

if (!is_admin()):
    ?>
    <h1>Résultats</h1>
    <?php if (!$configured): ?>
        <div class="alert alert-info">
            Aucun mot de passe n'est configuré. Copie <code>config.local.sample.php</code> en
            <code>config.local.php</code> et définis <code>admin_password</code>.
        </div>
    <?php else: ?>
        <form method="post" class="card narrow">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <label>Mot de passe
                <input type="password" name="password" required autofocus>
            </label>
            <button type="submit">Accéder aux résultats</button>
        </form>
    <?php endif;
    page_footer();
    exit;
endif;

$ranking = db()->query('SELECT g.*, COUNT(v.id) AS votes
                        FROM games g LEFT JOIN votes v ON v.game_id = g.id
                        GROUP BY g.id
                        ORDER BY votes DESC, g.title COLLATE NOCASE')->fetchAll();
$votesByGame = [];
foreach (db()->query('SELECT id, game_id, voter_name FROM votes ORDER BY voter_name COLLATE NOCASE') as $vote) {
    $votesByGame[$vote['game_id']][] = $vote;
}
$total = array_sum(array_column($ranking, 'votes'));
$top = $ranking ? (int) $ranking[0]['votes'] : 0;

/** Bouton d'action admin (formulaire POST minimal) */
function admin_button(string $action, string $label, string $class = 'btn-secondary', ?int $id = null, ?string $confirm = null): string
{
    return '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . ($id !== null ? '<input type="hidden" name="id" value="' . $id . '">' : '')
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}
?>
<div class="title-row">
    <h1>Résultats</h1>
    <?= admin_button('logout', 'Se déconnecter') ?>
</div>

<div class="stats">
    <div><strong><?= count($ranking) ?></strong> jeux proposés</div>
    <div><strong><?= $total ?></strong> votes</div>
</div>

<div class="card admin-bar">
    <span>Propositions : <strong><?= proposals_open() ? 'ouvertes' : 'fermées' ?></strong></span>
    <?= admin_button('toggle_proposals', proposals_open() ? 'Fermer' : 'Ouvrir') ?>
    <span>Votes : <strong><?= votes_open() ? 'ouverts' : 'fermés' ?></strong></span>
    <?= admin_button('toggle_votes', votes_open() ? 'Fermer' : 'Ouvrir') ?>
    <?= admin_button('reset_votes', 'Remettre les votes à zéro', 'btn-danger', null, 'Supprimer TOUS les votes ?') ?>
</div>

<?php if (!$ranking): ?>
    <p class="muted">Aucun jeu proposé pour l'instant.</p>
<?php else: ?>
    <ol class="ranking">
        <?php
        $rank = 0;
        $previous = null;
        foreach ($ranking as $i => $game):
            $votes = (int) $game['votes'];
            if ($votes !== $previous) {
                $rank = $i + 1; // ex aequo : même rang (1, 1, 3…)
                $previous = $votes;
            }
            $percent = $total ? round($votes * 100 / $total) : 0;
            ?>
            <li class="rank-item<?= $votes > 0 && $votes === $top ? ' leader' : '' ?>">
                <div class="rank-num"><?= $rank ?></div>
                <div class="rank-main">
                    <div class="rank-head">
                        <strong><?= e($game['title']) ?></strong>
                        <span><?= $votes ?> vote<?= $votes > 1 ? 's' : '' ?> · <?= $percent ?> %</span>
                    </div>
                    <div class="bar"><span style="width: <?= $top ? round($votes * 100 / $top) : 0 ?>%"></span></div>
                    <div class="voters">
                        <?php foreach ($votesByGame[$game['id']] ?? [] as $vote): ?>
                            <span class="voter"><?= e($vote['voter_name']) ?><?= admin_button('delete_vote', '×', 'btn-link', (int) $vote['id'], "Supprimer le vote de {$vote['voter_name']} ?") ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted small">
                        proposé par <?= e($game['proposed_by']) ?>
                        <?= admin_button('delete_game', 'Supprimer le jeu', 'btn-link', (int) $game['id'], "Supprimer « {$game['title']} » et ses votes ?") ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
<?php page_footer();
