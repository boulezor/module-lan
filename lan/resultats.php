<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$configured = (string) config('admin_password') !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');
    $id = (int) post_string('id');
    $back = 'resultats.php' . (post_string('lan') !== '' ? '?lan=' . (int) post_string('lan') : '');

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
            case 'create_lan':
                $date = post_string('lan_date');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    flash('error', 'Date invalide.');
                    break;
                }
                $title = clean_line(post_string('title'), 80) ?: 'LAN du ' . date('d/m/Y', strtotime($date));
                db_exec('INSERT INTO lans (title, lan_date) VALUES (?, ?)', [$title, $date]);
                $back = 'resultats.php?lan=' . db()->lastInsertId();
                flash('success', "« {$title} » créée : chacun peut maintenant proposer des jeux de sa liste.");
                break;
            case 'toggle_proposals':
                db_exec('UPDATE lans SET proposals_open = 1 - proposals_open WHERE id = ?', [$id]);
                break;
            case 'toggle_votes':
                db_exec('UPDATE lans SET votes_open = 1 - votes_open WHERE id = ?', [$id]);
                break;
            case 'delete_lan':
                db_exec('DELETE FROM lans WHERE id = ?', [$id]);
                $back = 'resultats.php';
                flash('success', 'LAN supprimée (avec son pot commun et ses votes).');
                break;
            case 'reset_votes':
                db_exec('DELETE FROM lan_votes WHERE lan_id = ?', [$id]);
                flash('success', 'Votes remis à zéro pour cette LAN.');
                break;
            case 'delete_proposal':
                db_exec('DELETE FROM proposals WHERE id = ?', [$id]);
                flash('success', 'Jeu retiré du pot commun (et ses votes).');
                break;
            case 'delete_vote':
                db_exec('DELETE FROM lan_votes WHERE id = ?', [$id]);
                flash('success', 'Vote supprimé.');
                break;
            case 'reset_password':
                if ($target = find_user($id)) {
                    $password = random_password();
                    db_exec('UPDATE users SET password_hash = ?, remember_token = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                    flash('success', "Nouveau mot de passe de {$target['name']} : {$password}");
                }
                break;
            case 'delete_user':
                db_exec('DELETE FROM users WHERE id = ?', [$id]);
                flash('success', 'Compte supprimé (avec sa liste, ses propositions et ses votes).');
                break;
        }
    }
    redirect($back);
}

page_header('Résultats', 'resultats');

if (!is_admin()):
    ?>
    <h1>Résultats</h1>
    <?php if (!$configured): ?>
        <div class="alert alert-info">
            Aucun mot de passe n'est configuré. Crée le fichier
            <code><?= e(config('private_dir')) ?>/config.php</code> avec <code>admin_password</code>
            (modèle : <code>config.local.sample.php</code>).
        </div>
    <?php else: ?>
        <form method="post" class="card narrow">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <label>Mot de passe organisateur
                <input type="password" name="password" required autofocus>
            </label>
            <button type="submit">Accéder aux résultats</button>
        </form>
    <?php endif;
    page_footer();
    exit;
endif;

$lans = all_lans();
$lan = selected_lan($lans);
$lanId = $lan ? (int) $lan['id'] : 0;

$ranking = db_rows('SELECT p.*, u.name AS proposed_by, COUNT(v.id) AS votes
                    FROM proposals p JOIN users u ON u.id = p.user_id LEFT JOIN lan_votes v ON v.proposal_id = p.id
                    WHERE p.lan_id = ? GROUP BY p.id
                    ORDER BY votes DESC, p.title COLLATE NOCASE', [$lanId]);
$votesByProposal = [];
foreach (db_rows('SELECT v.id, v.proposal_id, u.name FROM lan_votes v JOIN users u ON u.id = v.user_id
                  WHERE v.lan_id = ? ORDER BY u.name COLLATE NOCASE', [$lanId]) as $vote) {
    $votesByProposal[$vote['proposal_id']][] = $vote;
}
$total = array_sum(array_column($ranking, 'votes'));
$top = $ranking ? (int) $ranking[0]['votes'] : 0;
$users = db_rows('SELECT u.*, (SELECT COUNT(*) FROM list_games g WHERE g.user_id = u.id) AS games
                  FROM users u ORDER BY u.name COLLATE NOCASE');

/** Bouton d'action admin (formulaire POST minimal) */
function admin_button(string $action, string $label, string $class = 'btn-secondary', ?int $id = null, ?string $confirm = null, int $lanId = 0): string
{
    return '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . ($id !== null ? '<input type="hidden" name="id" value="' . $id . '">' : '')
        . ($lanId ? '<input type="hidden" name="lan" value="' . $lanId . '">' : '')
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}
?>
<div class="title-row">
    <h1>Résultats</h1>
    <?= admin_button('logout', 'Se déconnecter') ?>
</div>

<?php if (str_starts_with((string) realpath(dirname(config('db_path'))), (string) realpath(APP_ROOT))): ?>
    <div class="alert alert-warn">
        ⚠️ La base de données est dans le dossier web (<code><?= e(config('db_path')) ?></code>).
        Normal en local ; sur le serveur, vérifie que le dossier <code>private</code> de Plesk existe.
    </div>
<?php endif; ?>

<details class="card"<?= $lans ? '' : ' open' ?>>
    <summary>➕ Créer une LAN</summary>
    <form method="post" class="create-lan">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_lan">
        <label>Date
            <input type="date" name="lan_date" required value="<?= date('Y-m-d', strtotime('+2 weeks')) ?>">
        </label>
        <label>Nom <small class="muted">(facultatif)</small>
            <input type="text" name="title" maxlength="80" placeholder="ex. LAN d'hiver">
        </label>
        <button type="submit">Créer</button>
    </form>
</details>

<?php if ($lan): ?>
    <?php if (count($lans) > 1): ?>
        <nav class="lan-tabs">
            <?php foreach ($lans as $item): ?>
                <a href="resultats.php?lan=<?= (int) $item['id'] ?>"<?= (int) $item['id'] === $lanId ? ' class="active"' : '' ?>><?= e($item['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <h2><?= e($lan['title']) ?> <small class="muted">· <?= e(format_date($lan['lan_date'])) ?></small></h2>

    <div class="stats">
        <div><strong><?= count($ranking) ?></strong> jeux dans le pot</div>
        <div><strong><?= $total ?></strong> votes</div>
        <div><strong><?= count($users) ?></strong> comptes</div>
    </div>

    <div class="card admin-bar">
        <span>Propositions : <strong><?= (int) $lan['proposals_open'] ? 'ouvertes' : 'fermées' ?></strong></span>
        <?= admin_button('toggle_proposals', (int) $lan['proposals_open'] ? 'Fermer' : 'Ouvrir', 'btn-secondary', $lanId, null, $lanId) ?>
        <span>Votes : <strong><?= (int) $lan['votes_open'] ? 'ouverts' : 'fermés' ?></strong></span>
        <?= admin_button('toggle_votes', (int) $lan['votes_open'] ? 'Fermer' : 'Ouvrir', 'btn-secondary', $lanId, null, $lanId) ?>
        <?= admin_button('reset_votes', 'Votes à zéro', 'btn-danger', $lanId, 'Supprimer tous les votes de cette LAN ?', $lanId) ?>
        <?= admin_button('delete_lan', 'Supprimer la LAN', 'btn-danger', $lanId, "Supprimer « {$lan['title']} », son pot commun et ses votes ?") ?>
    </div>

    <?php if (!$ranking): ?>
        <p class="muted">Aucun jeu dans le pot commun pour l'instant.</p>
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
                            <?php foreach ($votesByProposal[$game['id']] ?? [] as $vote): ?>
                                <span class="voter"><?= e($vote['name']) ?><?= admin_button('delete_vote', '×', 'btn-link', (int) $vote['id'], "Supprimer le vote de {$vote['name']} ?", $lanId) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="muted small">
                            proposé par <?= e($game['proposed_by']) ?>
                            <?= admin_button('delete_proposal', 'Retirer du pot', 'btn-link', (int) $game['id'], "Retirer « {$game['title']} » du pot commun ?", $lanId) ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
<?php endif; ?>

<h2>Comptes (<?= count($users) ?>)</h2>
<?php if (!$users): ?>
    <p class="muted">Personne ne s'est encore inscrit.</p>
<?php else: ?>
    <table class="users">
        <thead><tr><th>Pseudo</th><th>Email</th><th>Jeux</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td><?= (int) $u['games'] ?></td>
                <td class="actions">
                    <?= admin_button('reset_password', 'Nouveau mot de passe', 'btn-link', (int) $u['id'], "Générer un nouveau mot de passe pour {$u['name']} ?", $lanId) ?>
                    <?= admin_button('delete_user', 'Supprimer', 'btn-link', (int) $u['id'], "Supprimer le compte de {$u['name']} (liste, propositions et votes) ?", $lanId) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php page_footer();
