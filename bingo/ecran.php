<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Écran de projection : public par lien secret, en lecture seule.
// Si l'organisateur est connecté sur cet ordinateur, il peut aussi piloter le tirage d'ici (clavier ou barre du bas).
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$game = preg_match('/^[a-f0-9]{24}$/', $token) ? db_row('SELECT * FROM games WHERE display_token = ?', [$token]) : null;
if (!$game) {
    http_response_code(404);
    auth_open('Écran introuvable');
    echo "<p>Ce lien d'écran n'est plus valable : la session a été supprimée ou son lien a été changé. Demande le nouveau lien à l'organisateur.</p>";
    auth_close();
    exit;
}
$manager = can_manage($game, current_user());
$data = ['token' => $token, 'type' => (int) $game['type']];
if ($manager) {
    $data += ['id' => (int) $game['id'], 'csrf' => csrf_token()];
}
page_open($game['name'], 'screen theme-' . $game['theme'] . ($manager ? ' is-manager' : ''), ['assets/balls.css', 'assets/screen.css'], $data);
?>
<div class="screen-bg" aria-hidden="true"></div>
<div class="screen-wrap">
    <header class="screen-top">
        <div class="screen-title"><span class="logo-ball">B</span> <span id="s-name"><?= e($game['name']) ?></span></div>
        <div class="screen-phase" id="s-phase"></div>
        <div class="screen-count"><b id="s-count">0</b><span id="s-max">/ <?= (int) $game['type'] ?></span></div>
    </header>
    <main class="screen-main">
        <section class="screen-left">
            <div class="stage">
                <div class="stage-glow" aria-hidden="true"></div>
                <div class="big-ball-wrap" id="s-ball"></div>
                <div class="stage-label" id="s-label">En attente du premier tirage…</div>
            </div>
            <div class="recent">
                <div class="recent-title">Boules précédentes</div>
                <div class="recent-balls" id="s-recent"></div>
            </div>
        </section>
        <section class="screen-board">
            <div id="s-board"></div>
        </section>
    </main>
    <div class="auto-bar" id="s-auto" hidden><span></span><em>Tirage automatique</em></div>
</div>

<div class="overlay" id="s-overlay" hidden></div>
<canvas id="confetti" aria-hidden="true"></canvas>
<div class="offline" id="s-offline" hidden>Connexion perdue… nouvelle tentative</div>

<div class="start" id="s-start">
    <div class="start-card">
        <div class="start-balls"><span class="ball c3"><span class="ball-face"><b>7</b></span></span><span class="ball c7"><span class="ball-face"><b>77</b></span></span></div>
        <button type="button" class="start-btn">▶ Lancer l'écran</button>
        <p>Plein écran et annonce vocale des numéros.<br><small>Touche <kbd>F</kbd> : plein écran<?= $manager ? ' · <kbd>?</kbd> : raccourcis de l\'organisateur' : '' ?></small></p>
    </div>
</div>

<?php if ($manager): ?>
<div class="presenter" id="s-presenter">
    <button type="button" data-do="draw" title="Tirer une boule au hasard (Espace)">🎲 Tirer</button>
    <form id="s-manual" autocomplete="off"><input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="N°" aria-label="Numéro sorti"><button type="submit">✔</button></form>
    <button type="button" data-do="undo" title="Annuler la dernière boule (Retour arrière)">↶ Annuler</button>
    <button type="button" data-do="auto" id="s-auto-btn" title="Tirage automatique (A)">▶ Auto</button>
    <button type="button" data-do="clear" title="Masquer l'incrustation (Échap)">✕ Masquer</button>
    <button type="button" data-do="sound" id="s-sound" title="Son (S)">🔊</button>
    <button type="button" data-do="full" title="Plein écran (F)">⛶</button>
    <a href="session.php?id=<?= (int) $game['id'] ?>" target="_blank" rel="noopener" title="Panneau de l'organisateur">⚙</a>
</div>
<div class="help" id="s-help" hidden>
    <h2>Raccourcis de l'organisateur</h2>
    <dl>
        <dt><kbd>0</kbd>…<kbd>9</kbd> puis <kbd>Entrée</kbd></dt><dd>encoder le numéro sorti</dd>
        <dt><kbd>Espace</kbd></dt><dd>tirer une boule au hasard</dd>
        <dt><kbd>Retour arrière</kbd></dt><dd>annuler la dernière boule</dd>
        <dt><kbd>A</kbd></dt><dd>lancer / mettre en pause le tirage automatique</dd>
        <dt><kbd>Échap</kbd></dt><dd>masquer le message, le carton ou l'écran BINGO</dd>
        <dt><kbd>S</kbd> · <kbd>F</kbd></dt><dd>son · plein écran</dd>
    </dl>
    <p>La barre d'outils apparaît quand la souris bouge.</p>
</div>
<?php endif; ?>
<?php
page_close(['assets/common.js', 'assets/screen.js']);
