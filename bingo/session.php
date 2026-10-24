<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Panneau de l'organisateur d'une session : tirage manuel ou automatique, phases, gagnants, vérification, écran.
$user = require_login();
$game = find_game((int) ($_GET['id'] ?? 0));
if (!$game || !can_manage($game, $user)) {
    flash('error', 'Session introuvable.');
    redirect('index.php');
}
$id = (int) $game['id'];
$displayUrl = app_url('ecran.php?t=' . $game['display_token']);
$quickMessages = ['Pause de 10 minutes 🍻', 'Vérification en cours… 🔎', 'Dernière partie !', 'Bonne chance à tous ! 🍀', 'Merci et bonne soirée ! 👋'];

page_open($game['name'], 'panel-page', ['assets/balls.css', 'assets/app.css'],
    ['id' => $id, 'csrf' => csrf_token(), 'type' => (int) $game['type'], 'max-cards' => (int) config('max_cards')]);
top_bar($user, '<div class="crumb" id="p-title">' . e($game['name']) . '</div>'
    . '<a class="btn btn-gold btn-small" href="' . e($displayUrl) . '" target="_blank" rel="noopener">📺 <span class="hide-sm">Ouvrir l\'écran</span></a>');
?>
<main class="panel-main">
    <section class="col-draw">
        <div class="panel draw-panel">
            <div class="now">
                <div id="p-ball"></div>
                <div class="now-info">
                    <div class="now-label" id="p-label">Aucune boule</div>
                    <div class="now-count"><b id="p-count">0</b> / <span id="p-max"><?= (int) $game['type'] ?></span> sorties</div>
                    <div class="now-bar"><span id="p-bar"></span></div>
                </div>
            </div>
            <div class="tabs" role="tablist">
                <button type="button" role="tab" data-mode="manual">✋ Manuel</button>
                <button type="button" role="tab" data-mode="auto">🎲 Automatique</button>
            </div>
            <div class="tab-body tab-manual">
                <form id="p-manual" class="manual-form" autocomplete="off">
                    <input type="text" id="p-number" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="N°" aria-label="Numéro sorti">
                    <button type="submit" class="btn btn-primary">Valider</button>
                </form>
                <div class="keypad" id="p-keypad">
                    <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $k): ?><button type="button" data-k="<?= $k ?>"><?= $k ?></button><?php endforeach; ?>
                    <button type="button" data-k="back" aria-label="Effacer">⌫</button><button type="button" data-k="0">0</button><button type="button" data-k="ok" class="ok" aria-label="Valider">✔</button>
                </div>
                <p class="hint">Tape le numéro au clavier puis <kbd>Entrée</kbd>, ou clique dans la grille.</p>
            </div>
            <div class="tab-body tab-auto">
                <button type="button" class="btn btn-gold btn-big" id="p-draw">🎲 Tirer une boule</button>
                <div class="auto-box">
                    <button type="button" class="btn btn-primary" id="p-auto">▶ Lancer le tirage automatique</button>
                    <label class="range">Une boule toutes les <b id="p-interval-val"><?= (int) $game['auto_interval'] ?></b> s
                        <input type="range" id="p-interval" min="<?= AUTO_MIN_INTERVAL ?>" max="60" step="1" value="<?= (int) $game['auto_interval'] ?>">
                    </label>
                    <div class="auto-progress" id="p-auto-progress" hidden><span></span></div>
                </div>
                <p class="hint">Le tirage continue tant qu'un écran ou ce panneau est ouvert. Il se met en pause tout seul quand tu annonces un BINGO.</p>
            </div>
            <button type="button" class="btn btn-undo" id="p-undo" disabled>↶ Annuler la dernière boule</button>
        </div>
        <div class="panel">
            <div class="panel-head"><h2>Ordre de sortie</h2><span class="muted small">la plus récente d'abord</span></div>
            <div class="history" id="p-history"><p class="muted small">Aucune boule sortie.</p></div>
        </div>
    </section>

    <section class="col-board">
        <div class="panel board-panel">
            <div class="panel-head"><h2>Grille</h2><span class="muted small">clic : ajouter / retirer un numéro</span></div>
            <div id="p-board"></div>
        </div>
    </section>

    <section class="col-side">
        <div class="panel phase-panel">
            <h2>Phase en cours</h2>
            <div class="phase-now">
                <button type="button" class="icon-btn" id="p-phase-prev" title="Phase précédente">‹</button>
                <div class="phase-text"><b id="p-phase-name"></b><small id="p-phase-prize"></small><span id="p-phase-pos"></span></div>
                <button type="button" class="icon-btn" id="p-phase-next" title="Phase suivante">›</button>
            </div>
            <button type="button" class="btn btn-bingo" id="p-bingo">🎉 BINGO ! Annoncer un gagnant</button>
            <div id="p-winners"></div>
        </div>

        <div class="panel overlay-status" id="p-overlay" hidden>
            <span id="p-overlay-text"></span>
            <button type="button" class="btn btn-small" id="p-overlay-clear">✕ Masquer de l'écran</button>
        </div>

        <div class="panel">
            <h2>🔎 Vérifier une grille</h2>
            <form id="p-check" class="inline-form">
                <input type="number" id="p-check-no" min="1" max="<?= (int) config('max_cards') ?>" placeholder="N° de la grille" required>
                <button type="submit" class="btn">Vérifier</button>
                <button type="button" class="btn" id="p-check-show" title="Vérifier et afficher la grille sur l'écran">📺 À l'écran</button>
            </form>
            <div id="p-check-result"></div>
        </div>

        <div class="panel">
            <h2>💬 Message à l'écran</h2>
            <form id="p-message">
                <textarea id="p-message-text" maxlength="200" rows="2" placeholder="Ex. Pause de 10 minutes"></textarea>
                <div class="chips">
                    <?php foreach ($quickMessages as $message): ?><button type="button" class="chip" data-message="<?= e($message) ?>"><?= e($message) ?></button><?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary">Afficher</button>
            </form>
        </div>

        <div class="panel">
            <h2>📺 Écran de projection</h2>
            <div class="copy-row">
                <input type="text" readonly id="p-display-url" value="<?= e($displayUrl) ?>">
                <button type="button" class="btn" data-copy="p-display-url">Copier</button>
            </div>
            <div class="row">
                <a class="btn btn-primary" id="p-display-open" href="<?= e($displayUrl) ?>" target="_blank" rel="noopener">Ouvrir l'écran</a>
                <button type="button" class="btn btn-ghost" id="p-new-link">Changer le lien</button>
            </div>
            <p class="hint">Ouvre ce lien sur l'ordinateur relié au projecteur, ou envoie-le aux joueurs pour qu'ils suivent sur leur téléphone.
                Connecté sur l'ordinateur de l'écran, tu peux aussi tirer depuis l'écran (touche <kbd>?</kbd>).</p>
        </div>

        <div class="panel">
            <h2>🖨️ Planches à imprimer</h2>
            <form action="cartons.php" method="post" target="_blank" class="print-form" id="p-print">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <label>Planches <input type="number" name="sheets" min="1" max="1000" value="10" required></label>
                <label>Grilles par planche (A4)
                    <select name="per">
                        <?php for ($n = 1; $n <= CARDS_PER_SHEET_MAX; $n++): ?>
                            <option value="<?= $n ?>" <?= $n === 3 ? 'selected' : '' ?>><?= $n ?> grille<?= $n > 1 ? 's' : '' ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Créer et imprimer</button>
            </form>
            <p class="hint" id="p-print-hint"></p>
            <div id="p-runs"></div>
            <details class="reprint">
                <summary>Réimprimer des grilles précises</summary>
                <form action="cartons.php" method="get" target="_blank" class="inline-form cards-form">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label>du n° <input type="number" name="from" min="1" max="<?= (int) config('max_cards') ?>" value="1"></label>
                    <label>au n° <input type="number" name="to" min="1" max="<?= (int) config('max_cards') ?>" value="3"></label>
                    <label>par <select name="per"><?php for ($n = 1; $n <= CARDS_PER_SHEET_MAX; $n++): ?><option value="<?= $n ?>" <?= $n === 3 ? 'selected' : '' ?>><?= $n ?></option><?php endfor; ?></select></label>
                    <button type="submit" class="btn">Imprimer</button>
                </form>
            </details>
            <p class="hint">Chaque grille a son propre numéro (jamais réutilisé d'une série à l'autre) : pour vérifier un gagnant, encode le numéro de sa grille.</p>
        </div>

        <div class="panel">
            <h2>⚙️ Réglages</h2>
            <form id="p-settings" class="settings">
                <label>Nom <input type="text" name="name" maxlength="80" value="<?= e($game['name']) ?>" required></label>
                <div class="field">Type
                    <div class="segmented small">
                        <label><input type="radio" name="type" value="90" <?= (int) $game['type'] === 90 ? 'checked' : '' ?>><span>90 boules</span></label>
                        <label><input type="radio" name="type" value="75" <?= (int) $game['type'] === 75 ? 'checked' : '' ?>><span>75 boules</span></label>
                    </div>
                </div>
                <div class="field">Thème de l'écran
                    <div class="themes">
                        <?php foreach (available_themes($user, $game) as $key => $label): ?>
                            <label class="theme-pick theme-<?= e($key) ?>"><input type="radio" name="theme" value="<?= e($key) ?>" <?= $game['theme'] === $key ? 'checked' : '' ?>><span><?= e($label) ?><?= isset(ADMIN_THEMES[$key]) ? ' 🔒' : '' ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="voice" <?= (int) $game['voice'] ? 'checked' : '' ?>> Annonce vocale des numéros sur l'écran</label>
                <label class="check"><input type="checkbox" name="suspense" <?= (int) $game['suspense'] ? 'checked' : '' ?>> Suspense (la boule tourne 2 s) pour les tirages au hasard</label>
                <label>Phases et lots <small>(une par ligne : <code>Nom | Lot</code>)</small>
                    <textarea name="phases" rows="4"><?= e(phases_to_text(game_phases($game))) ?></textarea>
                </label>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </form>
        </div>

        <div class="panel danger-zone">
            <button type="button" class="btn btn-danger" id="p-reset">↺ Réinitialiser la partie</button>
            <p class="hint">Efface les boules sorties et les gagnants pour rejouer. Les grilles imprimées restent valables.</p>
        </div>
    </section>
</main>

<dialog id="p-winner-dialog" class="dialog">
    <form method="dialog" id="p-winner-form">
        <h2>🎉 BINGO !</h2>
        <p class="muted" id="p-winner-phase"></p>
        <label>Nom du gagnant <small>(facultatif)</small> <input type="text" name="name" maxlength="60" autocomplete="off"></label>
        <label>N° de la grille <small>(facultatif)</small> <input type="number" name="card" min="1" max="<?= (int) config('max_cards') ?>"></label>
        <div class="row end">
            <button type="button" class="btn btn-ghost" value="cancel" id="p-winner-cancel">Annuler</button>
            <button type="submit" class="btn btn-bingo" value="ok">Afficher BINGO à l'écran</button>
        </div>
    </form>
</dialog>
<?php
page_close(['assets/common.js', 'assets/panel.js']);
