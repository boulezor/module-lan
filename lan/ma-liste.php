<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$userId = (int) $user['id'];
$openLans = lans_with_open('proposals');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');
    $id = (int) post_string('id');

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
        redirect('ma-liste.php');
    }

    switch ($action) {
        case 'save':
            $game = game_from_post();
            $existing = $id ? find_user_game($userId, $id) : null;
            if (normalize_key($game['title']) === '') {
                flash('error', 'Indique le nom du jeu.');
                redirect($id ? "ma-liste.php?edit={$id}" : 'ma-liste.php');
            }
            if ($dup = find_list_duplicate($userId, $game['title'], $game['steam_appid'], $existing ? $id : 0)) {
                flash('error', "« {$dup['title']} » est déjà dans ta liste.");
                redirect($id ? "ma-liste.php?edit={$id}" : 'ma-liste.php');
            }

            $values = [$game['title'], normalize_key($game['title']), $game['steam_appid'], $game['description'],
                       $game['tags'], $game['image_url'], $game['video_url'], $game['store_url'], $game['comment'], $game['players']];
            if ($existing) {
                db_exec('UPDATE list_games SET title = ?, normalized = ?, steam_appid = ?, description = ?, tags = ?,
                         image_url = ?, video_url = ?, store_url = ?, comment = ?, players = ? WHERE id = ? AND user_id = ?', [...$values, $id, $userId]);
                // Les fiches déjà dans un pot commun encore ouvert suivent la description, les liens et le commentaire
                db_exec('UPDATE proposals SET description = ?, tags = ?, image_url = ?, video_url = ?, store_url = ?, comment = ?, players = ?
                         WHERE list_game_id = ? AND user_id = ? AND lan_id IN (SELECT id FROM lans WHERE proposals_open = 1)',
                    [$game['description'], $game['tags'], $game['image_url'], $game['video_url'], $game['store_url'],
                     $game['comment'], $game['players'], $id, $userId]);
                flash('success', "« {$game['title']} » mis à jour.");
            } else {
                db_exec('INSERT INTO list_games (title, normalized, steam_appid, description, tags, image_url, video_url, store_url, comment, players, user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $userId]);
                flash('success', "« {$game['title']} » ajouté à ta liste.");

                $lan = find_lan((int) post_string('propose_lan'));
                if ($lan) {
                    [$ok, $message] = propose_to_lan($user, find_user_game($userId, (int) db()->lastInsertId()), $lan);
                    flash($ok ? 'success' : 'warn', $message);
                }
            }
            break;

        case 'delete':
            if ($game = find_user_game($userId, $id)) {
                db_exec('DELETE FROM list_games WHERE id = ? AND user_id = ?', [$id, $userId]);
                flash('success', "« {$game['title']} » retiré de ta liste (il reste dans les pots communs où tu l'as proposé).");
            }
            break;

        case 'propose':
            $game = find_user_game($userId, $id);
            $lan = find_lan((int) post_string('lan_id'));
            if ($game && $lan) {
                [$ok, $message] = propose_to_lan($user, $game, $lan);
                flash($ok ? 'success' : 'warn', $message);
            }
            break;

        case 'withdraw':
            $proposal = db_row('SELECT p.*, l.title AS lan_title, l.proposals_open FROM proposals p JOIN lans l ON l.id = p.lan_id
                                WHERE p.id = ? AND p.user_id = ?', [$id, $userId]);
            if ($proposal && (int) $proposal['proposals_open']) {
                db_exec('DELETE FROM proposals WHERE id = ?', [$id]);
                flash('success', "« {$proposal['title']} » retiré du pot commun de « {$proposal['lan_title']} ».");
            }
            break;

        case 'password':
            if (mb_strlen(post_string('password')) < 4) {
                flash('error', 'Mot de passe trop court (4 caractères minimum).');
            } else {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(post_string('password'), PASSWORD_DEFAULT), $userId]);
                flash('success', 'Mot de passe modifié.');
            }
            break;
    }
    redirect('ma-liste.php');
}

$games = user_games($userId);
$editing = isset($_GET['edit']) ? find_user_game($userId, (int) $_GET['edit']) : null;

// Pots communs ouverts, pour afficher l'état de chaque jeu (proposé / déjà proposé par un autre / à proposer)
$potsByLan = [];
foreach ($openLans as $lan) {
    $potsByLan[$lan['id']] = lan_proposals((int) $lan['id']);
}

/** Proposition du pot commun qui correspond à ce jeu (même nom ou même fiche Steam). */
function matching_proposal(array $pot, array $game): ?array
{
    foreach ($pot as $proposal) {
        if ($proposal['normalized'] === $game['normalized']
            || ($game['steam_appid'] !== null && (int) $proposal['steam_appid'] === (int) $game['steam_appid'])) {
            return $proposal;
        }
    }
    return null;
}

page_header('Ma liste', 'liste');
?>
<h1>Ma liste de jeux</h1>
<p class="muted">Ta liste perso, à garder d'une LAN à l'autre. Quand une LAN est annoncée, envoie tes jeux dans son pot commun.</p>

<?php if (!$openLans): ?>
    <div class="alert alert-info">Aucune LAN n'est ouverte aux propositions pour l'instant. Tu peux déjà préparer ta liste !</div>
<?php endif; ?>

<?php render_game_form($editing, $openLans); ?>

<h2>Mes jeux (<?= count($games) ?>)</h2>
<?php if (!$games): ?>
    <p class="muted">Ta liste est vide.</p>
<?php else: ?>
    <ul class="game-list">
        <?php foreach ($games as $game): ?>
            <li class="game">
                <?php game_media($game, function () use ($game, $openLans, $potsByLan, $userId) { ?>
                    <div class="game-actions">
                        <?php foreach ($openLans as $lan):
                            $match = matching_proposal($potsByLan[$lan['id']], $game); ?>
                            <div class="lan-status">
                                <?php if ($match && (int) $match['user_id'] === $userId): ?>
                                    <span class="badge badge-ok">✔ Proposé · <?= e($lan['title']) ?></span>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="withdraw">
                                        <input type="hidden" name="id" value="<?= (int) $match['id'] ?>">
                                        <button type="submit" class="btn-link">retirer</button>
                                    </form>
                                <?php elseif ($match): ?>
                                    <span class="badge">Déjà proposé par <?= e($match['proposed_by']) ?> · <?= e($lan['title']) ?></span>
                                <?php else: ?>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="propose">
                                        <input type="hidden" name="id" value="<?= (int) $game['id'] ?>">
                                        <input type="hidden" name="lan_id" value="<?= (int) $lan['id'] ?>">
                                        <button type="submit" class="btn-small">➕ Proposer pour <?= e($lan['title']) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="lan-status">
                            <a href="ma-liste.php?edit=<?= (int) $game['id'] ?>#game-form">Modifier</a>
                            <form method="post" class="inline" data-confirm="Retirer « <?= e($game['title']) ?> » de ta liste ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $game['id'] ?>">
                                <button type="submit" class="btn-link">Supprimer</button>
                            </form>
                        </div>
                    </div>
                <?php }); ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<details class="card account">
    <summary>Mon compte</summary>
    <p class="muted"><?= e($user['name']) ?> · <?= e($user['email']) ?></p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Nouveau mot de passe
            <input type="password" name="password" required minlength="4" autocomplete="new-password">
        </label>
        <button type="submit" class="btn-secondary">Changer le mot de passe</button>
    </form>
</details>
<?php page_footer();
