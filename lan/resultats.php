<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$configured = (string) config('admin_password') !== '';

/**
 * Champs du formulaire de LAN (création / modification).
 *
 * @return array{0: ?string, 1: array{title: string, starts_at: string, ends_at: string}} [erreur, valeurs]
 */
function lan_from_post(): array
{
    $start = parse_datetime_local(post_string('starts_at'));
    $end = parse_datetime_local(post_string('ends_at'));
    if ($start === '' || $end === '') {
        return ['Date de début ou de fin invalide.', []];
    }
    if ($end < $start) {
        return ['La fin doit être après le début.', []];
    }
    $title = clean_line(post_string('title'), 80) ?: 'LAN du ' . date('d/m/Y', (int) strtotime($start));
    return [null, ['title' => $title, 'starts_at' => $start, 'ends_at' => $end]];
}

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
                [$error, $lan] = lan_from_post();
                if ($error) {
                    flash('error', $error);
                    break;
                }
                // Votes fermés au départ : l'organisateur les ouvre quand le pot commun est prêt
                db_exec('INSERT INTO lans (title, lan_date, starts_at, ends_at, proposals_open, votes_open) VALUES (?, ?, ?, ?, 1, 0)',
                    [$lan['title'], substr($lan['starts_at'], 0, 10), $lan['starts_at'], $lan['ends_at']]);
                $back = 'resultats.php?lan=' . db()->lastInsertId();
                flash('success', "« {$lan['title']} » créée : chacun peut proposer des jeux de sa liste. Les votes sont fermés pour l'instant.");
                break;
            case 'update_lan':
                [$error, $lan] = lan_from_post();
                if ($error) {
                    flash('error', $error);
                    break;
                }
                db_exec('UPDATE lans SET title = ?, lan_date = ?, starts_at = ?, ends_at = ? WHERE id = ?',
                    [$lan['title'], substr($lan['starts_at'], 0, 10), $lan['starts_at'], $lan['ends_at'], $id]);
                flash('success', 'LAN mise à jour.');
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
                db_exec('DELETE FROM lan_ratings WHERE lan_id = ?', [$id]);
                flash('success', 'Votes remis à zéro pour cette LAN.');
                break;
            case 'delete_proposal':
                db_exec('DELETE FROM proposals WHERE id = ?', [$id]);
                flash('success', 'Jeu retiré du pot commun (et ses votes).');
                break;
            case 'delete_ballot':
                db_exec('DELETE FROM lan_ratings WHERE lan_id = ? AND user_id = ?', [(int) post_string('lan'), $id]);
                flash('success', 'Vote supprimé : la personne peut revoter.');
                break;
            case 'add_from_lists':
                $lan = find_lan($id);
                $ids = array_map('intval', is_array($_POST['games'] ?? null) ? $_POST['games'] : []);
                if (!$lan || !$ids) {
                    flash('warn', 'Aucun jeu sélectionné.');
                    break;
                }
                $added = 0;
                foreach ($ids as $gameId) {
                    $game = db_row('SELECT * FROM list_games WHERE id = ?', [$gameId]);
                    if ($game && ($owner = find_user((int) $game['user_id']))) {
                        [$ok, $message] = propose_to_lan($owner, $game, $lan, true);
                        $ok ? $added++ : flash('warn', $message);
                    }
                }
                flash('success', "{$added} jeu" . ($added > 1 ? 'x ajoutés' : ' ajouté') . " au pot commun de « {$lan['title']} ».");
                $back .= '&listes=1';
                break;
            case 'save_ai':
                if (!empty($_POST['remove_key'])) {
                    set_setting('openai_api_key', null);
                    flash('success', 'Clé OpenAI supprimée.');
                } elseif (($key = trim(post_string('openai_api_key'))) !== '') {
                    set_setting('openai_api_key', $key);
                    set_setting('openai_last_error', null);
                    flash('success', 'Clé OpenAI enregistrée. Utilise « Tester » pour vérifier qu\'elle fonctionne.');
                }
                $model = clean_line(post_string('openai_model'), 60);
                set_setting('openai_model', $model !== '' && $model !== (string) config('openai_model') ? $model : null);
                if (empty($_POST['remove_key']) && trim(post_string('openai_api_key')) === '') {
                    flash('success', 'Réglages enregistrés.');
                }
                break;
            case 'save_youtube':
                if (!empty($_POST['remove_key'])) {
                    set_setting('youtube_api_key', null);
                    flash('success', 'Clé YouTube supprimée.');
                } elseif (($key = trim(post_string('youtube_api_key'))) !== '') {
                    set_setting('youtube_api_key', $key);
                    set_setting('youtube_last_error', null);
                    flash('success', 'Clé YouTube enregistrée. Utilise « Tester » pour vérifier qu\'elle fonctionne.');
                }
                break;
            case 'test_youtube':
                if (youtube_key() === '') {
                    flash('error', 'Aucune clé YouTube enregistrée.');
                    break;
                }
                $test = youtube_find_trailer('Rocket League');
                if ($test['url']) {
                    set_setting('youtube_last_error', null);
                    flash('success', "YouTube répond : « {$test['title']} » ({$test['url']}). Les bandes-annonces sont actives.");
                } else {
                    flash('error', 'YouTube ne répond pas : ' . $test['error']);
                }
                break;
            case 'test_ai':
                if (openai_key() === '') {
                    flash('error', 'Aucune clé OpenAI enregistrée.');
                    break;
                }
                $test = openai_chat('Réponds uniquement : OK', 300);
                if ($test['text'] !== null) {
                    set_setting('openai_last_error', null);
                    flash('success', 'OpenAI répond (modèle ' . openai_model() . ') : « ' . mb_substr($test['text'], 0, 60) . ' ». Les descriptions IA sont actives.');
                } else {
                    flash('error', 'OpenAI ne répond pas : ' . $test['error']);
                }
                break;
            case 'save_options':
                $posted = is_array($_POST['options'] ?? null) ? $_POST['options'] : [];
                $remaining = count(vote_options());
                foreach (vote_options() as $option) {
                    $row = $posted[$option['id']] ?? null;
                    if (!is_array($row)) {
                        continue;
                    }
                    if (!empty($row['delete'])) {
                        if ($remaining > 1) {
                            db_exec('DELETE FROM vote_options WHERE id = ?', [$option['id']]);
                            $remaining--;
                        } else {
                            flash('error', 'Il faut garder au moins une option de vote.');
                        }
                        continue;
                    }
                    $label = clean_line(is_string($row['label'] ?? null) ? $row['label'] : '', 40);
                    if ($label !== '') {
                        db_exec('UPDATE vote_options SET label = ?, points = ? WHERE id = ?',
                            [$label, max(-100, min(100, (int) ($row['points'] ?? 0))), $option['id']]);
                    }
                }
                $newLabel = clean_line(post_string('new_label'), 40);
                if ($newLabel !== '') {
                    db_exec('INSERT INTO vote_options (label, points) VALUES (?, ?)',
                        [$newLabel, max(-100, min(100, (int) post_string('new_points')))]);
                }
                flash('success', 'Options de vote enregistrées.');
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

// Classement : somme des points de toutes les notes
$ranking = db_rows('SELECT p.*, u.name AS proposed_by, COALESCE(SUM(o.points), 0) AS score, COUNT(r.id) AS ratings
                    FROM proposals p
                    JOIN users u ON u.id = p.user_id
                    LEFT JOIN lan_ratings r ON r.proposal_id = p.id
                    LEFT JOIN vote_options o ON o.id = r.option_id
                    WHERE p.lan_id = ? GROUP BY p.id
                    ORDER BY score DESC, ratings DESC, p.title COLLATE NOCASE', [$lanId]);
$ratingsByProposal = [];
foreach (db_rows('SELECT r.proposal_id, u.name, o.label, o.points FROM lan_ratings r
                  JOIN users u ON u.id = r.user_id JOIN vote_options o ON o.id = r.option_id
                  WHERE r.lan_id = ? ORDER BY o.points DESC, u.name COLLATE NOCASE', [$lanId]) as $rating) {
    $ratingsByProposal[$rating['proposal_id']][] = $rating;
}
// Bulletins : une ligne par personne ayant voté
$ballots = db_rows('SELECT u.id, u.name, COUNT(r.id) AS rated, MIN(r.created_at) AS submitted_at
                    FROM lan_ratings r JOIN users u ON u.id = r.user_id
                    WHERE r.lan_id = ? GROUP BY u.id ORDER BY u.name COLLATE NOCASE', [$lanId]);
$maxAbs = max([1, ...array_map(fn($g) => abs((int) $g['score']), $ranking)]);
$topScore = $ranking ? (int) $ranking[0]['score'] : 0;
$listGames = $lan ? all_list_games_grouped() : [];
$listGamesAvailable = count(array_filter($listGames, fn($g) => !matching_proposal($ranking, $g)));
$options = vote_options();
$optionUsage = array_column(db_rows('SELECT option_id, COUNT(*) AS n FROM lan_ratings GROUP BY option_id'), 'n', 'option_id');
$users = db_rows('SELECT u.*, (SELECT COUNT(*) FROM list_games g WHERE g.user_id = u.id) AS games
                  FROM users u ORDER BY u.name COLLATE NOCASE');

/** 'AAAA-MM-JJ HH:MM' → valeur d'un champ datetime-local */
function datetime_local(string $value): string
{
    return strlen($value) >= 16 ? str_replace(' ', 'T', substr($value, 0, 16)) : substr($value, 0, 10) . 'T18:00';
}

/** Champs communs aux formulaires de création / modification de LAN */
function lan_fields(array $lan): void
{
    ?>
    <label>Nom <small class="muted">(facultatif)</small>
        <input type="text" name="title" maxlength="80" placeholder="ex. LAN d'hiver" value="<?= e($lan['title']) ?>">
    </label>
    <div class="datetime-row">
        <label>Début
            <input type="datetime-local" name="starts_at" required value="<?= e(datetime_local($lan['starts_at'])) ?>">
        </label>
        <label>Fin
            <input type="datetime-local" name="ends_at" required value="<?= e(datetime_local($lan['ends_at'])) ?>">
        </label>
    </div>
    <?php
}

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
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_lan">
        <?php
        $friday = strtotime('friday +1 week');
        lan_fields(['title' => '', 'starts_at' => date('Y-m-d', $friday) . ' 18:00', 'ends_at' => date('Y-m-d', strtotime('+2 days', $friday)) . ' 18:00']);
        ?>
        <p class="muted small">Les propositions sont ouvertes dès la création ; les votes restent fermés jusqu'à ce que tu les ouvres.</p>
        <button type="submit">Créer la LAN</button>
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

    <h2><?= e($lan['title']) ?></h2>
    <p class="muted">📅 <?= e(format_lan_dates($lan)) ?></p>

    <div class="stats">
        <div><strong><?= count($ranking) ?></strong> jeux dans le pot</div>
        <div><strong><?= count($ballots) ?></strong> personne<?= count($ballots) > 1 ? 's ont' : ' a' ?> voté</div>
        <div><strong><?= count($users) ?></strong> comptes</div>
    </div>

    <div class="card admin-bar">
        <span>Propositions : <strong><?= (int) $lan['proposals_open'] ? 'ouvertes' : 'fermées' ?></strong></span>
        <?= admin_button('toggle_proposals', (int) $lan['proposals_open'] ? 'Fermer' : 'Ouvrir', 'btn-secondary', $lanId, null, $lanId) ?>
        <span>Votes : <strong><?= (int) $lan['votes_open'] ? 'ouverts' : 'fermés' ?></strong></span>
        <?= admin_button('toggle_votes', (int) $lan['votes_open'] ? 'Fermer les votes' : 'Ouvrir les votes', (int) $lan['votes_open'] ? 'btn-secondary' : '', $lanId,
            !(int) $lan['votes_open'] && (int) $lan['proposals_open'] ? 'Les propositions sont encore ouvertes : un jeu ajouté après un vote devra être noté en plus. Ouvrir les votes quand même ?' : null, $lanId) ?>
        <?= admin_button('reset_votes', 'Votes à zéro', 'btn-danger', $lanId, 'Supprimer tous les votes de cette LAN ?', $lanId) ?>
    </div>

    <details class="card">
        <summary>✏️ Modifier la LAN (nom, dates)</summary>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_lan">
            <input type="hidden" name="id" value="<?= $lanId ?>">
            <input type="hidden" name="lan" value="<?= $lanId ?>">
            <?php lan_fields($lan); ?>
            <div class="form-actions">
                <button type="submit">Enregistrer</button>
            </div>
        </form>
        <p><?= admin_button('delete_lan', 'Supprimer la LAN', 'btn-danger', $lanId, "Supprimer « {$lan['title']} », son pot commun et ses votes ?") ?></p>
    </details>

    <details class="card"<?= isset($_GET['listes']) ? ' open' : '' ?>>
        <summary>📚 Jeux de toutes les listes (<?= count($listGames) ?>, dont <?= $listGamesAvailable ?> pas encore dans le pot)</summary>
        <?php if (!$listGames): ?>
            <p class="muted">Les listes sont vides pour l'instant.</p>
        <?php else: ?>
            <form method="post" id="lists-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_from_lists">
                <input type="hidden" name="id" value="<?= $lanId ?>">
                <input type="hidden" name="lan" value="<?= $lanId ?>">
                <p class="muted small">Coche les jeux à ajouter au pot commun de « <?= e($lan['title']) ?> » (même si les propositions sont fermées).
                    Le jeu est ajouté au nom de la première personne qui l'a dans sa liste.</p>
                <p class="lists-tools">
                    <button type="button" class="btn-link" data-check="all">Tout cocher</button>
                    <button type="button" class="btn-link" data-check="none">Tout décocher</button>
                </p>
                <ul class="pick-list">
                    <?php foreach ($listGames as $game):
                        $inPot = matching_proposal($ranking, $game); ?>
                        <li class="<?= $inPot ? 'in-pot' : '' ?>">
                            <label class="inline-check">
                                <input type="checkbox" name="games[]" value="<?= (int) $game['id'] ?>"<?= $inPot ? ' checked disabled' : '' ?>>
                                <strong><?= e($game['title']) ?></strong>
                                <?php if ($game['players'] !== ''): ?><span class="players">👥 <?= e(players_label($game['players'])) ?></span><?php endif; ?>
                            </label>
                            <span class="muted small">
                                <?= $inPot ? '✔ déjà dans le pot · ' : '' ?>liste<?= count($game['owners']) > 1 ? 's' : '' ?> de <?= e(implode(', ', array_unique($game['owners']))) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($listGamesAvailable): ?>
                    <button type="submit">Ajouter la sélection au pot commun</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </details>

    <h3>Classement</h3>
    <?php if (!$ranking): ?>
        <p class="muted">Aucun jeu dans le pot commun pour l'instant.</p>
    <?php else: ?>
        <ol class="ranking">
            <?php
            $rank = 0;
            $previous = null;
            foreach ($ranking as $i => $game):
                $score = (int) $game['score'];
                if ($score !== $previous) {
                    $rank = $i + 1; // ex aequo : même rang (1, 1, 3…)
                    $previous = $score;
                }
                $ratings = $ratingsByProposal[$game['id']] ?? [];
                $counts = [];
                foreach ($ratings as $rating) {
                    $counts[$rating['label']] = ($counts[$rating['label']] ?? 0) + 1;
                }
                ?>
                <li class="rank-item<?= (int) $game['ratings'] > 0 && $score === $topScore ? ' leader' : '' ?>">
                    <div class="rank-num"><?= $rank ?></div>
                    <div class="rank-main">
                        <div class="rank-head">
                            <strong><?= e($game['title']) ?>
                                <?php if ($game['players'] !== ''): ?><span class="players">👥 <?= e(players_label($game['players'])) ?></span><?php endif; ?>
                            </strong>
                            <span><span class="score <?= $score > 0 ? 'score-pos' : ($score < 0 ? 'score-neg' : '') ?>"><?= sprintf('%+d', $score) ?> pts</span>
                                · <?= (int) $game['ratings'] ?> avis</span>
                        </div>
                        <div class="bar"><span class="<?= $score < 0 ? 'neg' : '' ?>" style="width: <?= round(abs($score) * 100 / $maxAbs) ?>%"></span></div>
                        <?php if ($counts): ?>
                            <div class="breakdown">
                                <?php foreach ($counts as $label => $n): ?><span><?= e($label) ?> ×<?= $n ?></span><?php endforeach; ?>
                            </div>
                            <details class="small">
                                <summary class="muted">Détail des avis</summary>
                                <div class="voters">
                                    <?php foreach ($ratings as $rating): ?>
                                        <span class="voter"><?= e($rating['name']) ?> : <?= e($rating['label']) ?> (<?= sprintf('%+d', (int) $rating['points']) ?>)</span>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        <div class="muted small">
                            proposé par <?= e($game['proposed_by']) ?>
                            <?= admin_button('delete_proposal', 'Retirer du pot', 'btn-link', (int) $game['id'], "Retirer « {$game['title']} » du pot commun (et ses votes) ?", $lanId) ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <h3>Votes reçus (<?= count($ballots) ?>)</h3>
    <?php if (!$ballots): ?>
        <p class="muted">Personne n'a encore voté.</p>
    <?php else: ?>
        <div class="voters">
            <?php foreach ($ballots as $ballot):
                $complete = (int) $ballot['rated'] >= count($ranking); ?>
                <span class="voter"><?= e($ballot['name']) ?>
                    <?= $complete ? '✔' : '<span class="muted">(' . (int) $ballot['rated'] . '/' . count($ranking) . ')</span>' ?>
                    <?= admin_button('delete_ballot', '×', 'btn-link', (int) $ballot['id'], "Supprimer le vote de {$ballot['name']} ? (il pourra revoter)", $lanId) ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2>🤖 Descriptions automatiques (IA)</h2>
<?php
$configKey = (string) config('openai_api_key') !== '';
$activeKey = openai_key();
$lastError = setting('openai_last_error');
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ai">
    <input type="hidden" name="lan" value="<?= $lanId ?>">
    <?php if ($activeKey === ''): ?>
        <p>Pas de clé : les descriptions viennent de <strong>Steam</strong>, sinon de <strong>Wikipédia</strong> (3-4 phrases).</p>
    <?php else: ?>
        <p>✔ Clé active <code>…<?= e(substr($activeKey, -4)) ?></code><?= $configKey ? ' (définie dans le fichier de configuration)' : '' ?> :
            l'IA rédige 5 à 10 phrases avec points forts / faibles et pré-remplit le nombre de joueurs.</p>
    <?php endif; ?>
    <?php if ($lastError !== ''): ?>
        <div class="alert alert-warn">Dernière erreur OpenAI : <?= e($lastError) ?></div>
    <?php endif; ?>
    <?php if (!$configKey): ?>
        <label>Clé API OpenAI <small class="muted">(jamais réaffichée ; laisser vide pour garder la clé actuelle)</small>
            <input type="password" name="openai_api_key" autocomplete="off" placeholder="sk-…">
        </label>
    <?php endif; ?>
    <label>Modèle <small class="muted">(par défaut : <?= e((string) config('openai_model')) ?>)</small>
        <input type="text" name="openai_model" maxlength="60" value="<?= e(openai_model()) ?>">
    </label>
    <p class="muted small">Conseil : fixe une limite de dépenses mensuelle sur ton compte OpenAI. Chaque description coûte une fraction de centime.</p>
    <div class="form-actions">
        <button type="submit">Enregistrer</button>
        <?php if ($activeKey !== '' && !$configKey): ?>
            <label class="inline-check"><input type="checkbox" name="remove_key" value="1"> Supprimer la clé</label>
        <?php endif; ?>
    </div>
</form>
<?php if ($activeKey !== ''): ?>
    <p><?= admin_button('test_ai', '🔌 Tester la connexion à OpenAI', 'btn-secondary', null, null, $lanId) ?></p>
<?php endif; ?>

<h2>▶ Bandes-annonces YouTube</h2>
<?php
$ytConfigKey = (string) config('youtube_api_key') !== '';
$ytKey = youtube_key();
$ytError = setting('youtube_last_error');
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_youtube">
    <input type="hidden" name="lan" value="<?= $lanId ?>">
    <?php if ($ytKey === ''): ?>
        <p>Pas de clé : le lien « Vidéo » ouvre une recherche YouTube « <em>nom du jeu</em> official trailer ».</p>
    <?php else: ?>
        <p>✔ Clé active <code>…<?= e(substr($ytKey, -4)) ?></code><?= $ytConfigKey ? ' (définie dans le fichier de configuration)' : '' ?> :
            le lien « Vidéo » mène directement à la bande-annonce officielle (sinon, recherche YouTube).</p>
    <?php endif; ?>
    <?php if ($ytError !== ''): ?>
        <div class="alert alert-warn">Dernière erreur YouTube : <?= e($ytError) ?></div>
    <?php endif; ?>
    <?php if (!$ytConfigKey): ?>
        <label>Clé API YouTube Data v3 <small class="muted">(gratuite ; jamais réaffichée ; laisser vide pour garder la clé actuelle)</small>
            <input type="password" name="youtube_api_key" autocomplete="off" placeholder="AIza…">
        </label>
        <div class="form-actions">
            <button type="submit">Enregistrer</button>
            <?php if ($ytKey !== ''): ?>
                <label class="inline-check"><input type="checkbox" name="remove_key" value="1"> Supprimer la clé</label>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>
<?php if ($ytKey !== ''): ?>
    <p><?= admin_button('test_youtube', '🔌 Tester la recherche YouTube', 'btn-secondary', null, null, $lanId) ?></p>
<?php endif; ?>

<?php if ($lan && $ranking): ?>
    <div class="card regen" id="regen"
         data-ids="<?= e(json_encode(array_map(fn($g) => (int) $g['id'], $ranking))) ?>"
         data-titles="<?= e(json_encode(array_column($ranking, 'title'), JSON_UNESCAPED_UNICODE)) ?>"
         data-csrf="<?= e(csrf_token()) ?>">
        <p><strong>🔄 Régénérer les fiches du pot commun de « <?= e($lan['title']) ?> »</strong> (<?= count($ranking) ?> jeux)</p>
        <p class="muted small">
            Refait la recherche pour chaque jeu<?= $activeKey !== '' ? ' avec l\'IA' : ' (Steam / Wikipédia : pas de clé IA)' ?> :
            description, points forts et points faibles sont remplacés. Le commentaire perso n'est jamais modifié ;
            le nombre de joueurs, l'image et les liens ne sont complétés que s'ils sont vides
            (un lien de recherche YouTube est remplacé par la bande-annonce si une clé YouTube est active).
            La fiche dans la liste perso du propriétaire est mise à jour aussi.
        </p>
        <button type="button" class="btn-secondary" data-confirm-text="Régénérer les fiches des <?= count($ranking) ?> jeux du pot commun ?">Régénérer les <?= count($ranking) ?> fiches</button>
        <div class="regen-progress" hidden>
            <div class="bar"><span style="width: 0%"></span></div>
            <p class="regen-status small"></p>
        </div>
    </div>
<?php endif; ?>

<h2>Options de vote</h2>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_options">
    <input type="hidden" name="lan" value="<?= $lanId ?>">
    <p class="muted small">Chaque personne choisit une option pour chaque jeu ; le classement additionne les points.
        Modifier les points d'une option recalcule aussi les votes déjà faits.</p>
    <table class="users options-table">
        <thead><tr><th>Libellé</th><th>Points</th><th>Supprimer</th></tr></thead>
        <tbody>
        <?php foreach ($options as $option): $used = (int) ($optionUsage[$option['id']] ?? 0); ?>
            <tr>
                <td><input type="text" name="options[<?= (int) $option['id'] ?>][label]" maxlength="40" required value="<?= e($option['label']) ?>"></td>
                <td><input type="number" name="options[<?= (int) $option['id'] ?>][points]" min="-100" max="100" required value="<?= (int) $option['points'] ?>"></td>
                <td><label class="inline-check"><input type="checkbox" name="options[<?= (int) $option['id'] ?>][delete]" value="1">
                    <?php if ($used): ?><small class="muted">efface aussi <?= $used ?> avis</small><?php endif; ?></label></td>
            </tr>
        <?php endforeach; ?>
            <tr>
                <td><input type="text" name="new_label" maxlength="40" placeholder="➕ Nouvelle option"></td>
                <td><input type="number" name="new_points" min="-100" max="100" value="0"></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    <div class="form-actions" style="margin-top: 12px;">
        <button type="submit">Enregistrer les options</button>
    </div>
</form>

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
