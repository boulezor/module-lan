<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Organisateur : création du planning (import d'un texte ou créneau par créneau), retards, rappels push.
if (!is_admin()) {
    flash('info', 'Connecte-toi d\'abord comme organisateur (page Résultats).');
    redirect('resultats.php');
}
$lans = all_lans();
$lan = find_lan((int) ($_REQUEST['lan'] ?? 0)) ?? planning_lan(null) ?? ($lans[0] ?? null);
if (!$lan) {
    flash('info', 'Crée d\'abord une LAN.');
    redirect('resultats.php');
}
$lanId = (int) $lan['id'];

/** Valeurs d'un créneau envoyées par formulaire, ou message d'erreur */
function slot_from_post(int $lanId): array|string
{
    $start = parse_datetime_local(post_string('starts_at'));
    $end = parse_datetime_local(post_string('ends_at'));
    $title = clean_line(post_string('title'), 80);
    $kind = array_key_exists(post_string('kind'), SLOT_KINDS) ? post_string('kind') : 'game';
    $proposal = (int) post_string('proposal_id');
    if ($start === '' || $end === '' || $end <= $start) {
        return 'Heures invalides (la fin doit être après le début).';
    }
    if ($proposal && ($game = db_row('SELECT title FROM proposals WHERE id = ? AND lan_id = ?', [$proposal, $lanId]))) {
        $title = $title !== '' ? $title : $game['title'];
    } else {
        $proposal = $kind === 'game' && $title !== '' ? match_proposal_id($lanId, $title) : null;
    }
    if ($title === '') {
        return 'Donne un titre au créneau.';
    }
    return ['starts_at' => $start, 'ends_at' => $end, 'kind' => $kind, 'title' => $title, 'proposal_id' => $proposal ?: null,
        'note' => clean_line(post_string('note'), 120)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post_string('id');
    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } else {
        switch (post_string('action')) {
            case 'import':
                [$slots, $errors] = parse_schedule($lan, post_string('text'));
                if ($errors || !$slots) {
                    flash('error', $errors ? implode(' ', array_slice($errors, 0, 4)) : 'Aucun créneau trouvé : une ligne par créneau, ex. « 16:00 - 17:30 Far Far West ».');
                    $_SESSION['import_text'] = post_string('text');
                    break;
                }
                db()->beginTransaction();
                if (post_string('replace') === '1') {
                    db_exec('DELETE FROM schedule_slots WHERE lan_id = ?', [$lanId]);
                }
                foreach ($slots as $slot) {
                    db_exec('INSERT INTO schedule_slots (lan_id, starts_at, ends_at, kind, title, proposal_id) VALUES (?, ?, ?, ?, ?, ?)', [
                        $lanId, $slot['starts_at'], $slot['ends_at'], $slot['kind'], $slot['title'],
                        $slot['kind'] === 'game' ? match_proposal_id($lanId, $slot['title']) : null,
                    ]);
                }
                db()->commit();
                flash('success', count($slots) . ' créneaux importés. Vérifie-les ci-dessous, puis ouvre le planning.');
                break;
            case 'add_slot':
            case 'update_slot':
                $slot = slot_from_post($lanId);
                if (is_string($slot)) {
                    flash('error', $slot);
                    break;
                }
                if (post_string('action') === 'add_slot') {
                    db_exec('INSERT INTO schedule_slots (lan_id, starts_at, ends_at, kind, title, proposal_id, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$lanId, ...array_values($slot)]);
                    flash('success', 'Créneau ajouté.');
                } else {
                    $old = db_row('SELECT * FROM schedule_slots WHERE id = ? AND lan_id = ?', [$id, $lanId]);
                    db_exec('UPDATE schedule_slots SET starts_at = ?, ends_at = ?, kind = ?, title = ?, proposal_id = ?, note = ? WHERE id = ? AND lan_id = ?',
                        [...array_values($slot), $id, $lanId]);
                    if ($old && $old['starts_at'] !== $slot['starts_at'] && slot_ts($slot['starts_at']) > time()) {
                        db_exec('DELETE FROM push_log WHERE slot_id = ?', [$id]); // rappel renvoyé à la nouvelle heure
                    }
                    flash('success', 'Créneau modifié.');
                }
                break;
            case 'delete_slot':
                db_exec('DELETE FROM schedule_slots WHERE id = ? AND lan_id = ?', [$id, $lanId]);
                flash('success', 'Créneau supprimé.');
                break;
            case 'clear':
                db_exec('DELETE FROM schedule_slots WHERE lan_id = ?', [$lanId]);
                flash('success', 'Planning vidé.');
                break;
            case 'shift':
                if ($slot = db_row('SELECT * FROM schedule_slots WHERE id = ? AND lan_id = ?', [$id, $lanId])) {
                    $minutes = max(-240, min(240, (int) post_string('minutes')));
                    flash('success', 'Décalage de ' . $minutes . ' min appliqué à ' . shift_slots($slot, $minutes) . ' créneaux.');
                }
                break;
            case 'remind':
                db_exec('UPDATE lans SET remind_minutes = ? WHERE id = ?', [max(0, min(60, (int) post_string('remind_minutes'))), $lanId]);
                flash('success', 'Rappels enregistrés.');
                break;
            case 'test_all':
                $sent = push_to_all(['title' => '📣 Test du planning LAN', 'body' => 'Les rappels fonctionnent sur cet appareil.', 'tag' => 'test', 'url' => 'planning.php']);
                flash($sent ? 'success' : 'error', $sent ? "Notification envoyée à {$sent} appareil(s)." : 'Aucun appareil abonné pour l\'instant.');
                break;
        }
    }
    redirect('planning-admin.php?lan=' . $lanId);
}

$slots = lan_slots($lanId);
$pot = lan_proposals($lanId);
$devices = db_rows('SELECT u.name, COUNT(*) AS n FROM push_subscriptions s LEFT JOIN users u ON u.id = s.user_id GROUP BY s.user_id ORDER BY u.name');
$cronAt = (int) setting('push_cron_at');
$importText = $_SESSION['import_text'] ?? '';
unset($_SESSION['import_text']);

/** 'AAAA-MM-JJ HH:MM' → valeur d'un champ datetime-local */
function dt_local(string $value): string
{
    return str_replace(' ', 'T', substr($value, 0, 16));
}

function hidden_fields(int $lanId, string $action, int $id = 0): string
{
    return csrf_field() . '<input type="hidden" name="lan" value="' . $lanId . '"><input type="hidden" name="action" value="' . e($action) . '">'
        . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
}

page_header('Planning (organisateur)', 'planning');
?>
<div class="title-row">
    <h1>🗓️ Planning · <?= e($lan['title']) ?></h1>
    <a class="button btn-secondary" href="planning.php?lan=<?= $lanId ?>">Voir le planning</a>
</div>
<?php if (count($lans) > 1): ?>
    <nav class="lan-tabs">
        <?php foreach ($lans as $item): ?>
            <a href="planning-admin.php?lan=<?= (int) $item['id'] ?>"<?= (int) $item['id'] === $lanId ? ' class="active"' : '' ?>><?= e($item['title']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<details class="card"<?= $slots && $importText === '' ? '' : ' open' ?>>
    <summary>📋 Importer un planning (copier-coller)</summary>
    <form method="post">
        <?= hidden_fields($lanId, 'import') ?>
        <p class="muted small">Un jour par titre (« Vendredi 13 »), puis une ligne par créneau « 16:00 - 17:30 Nom du jeu ».
            Après minuit, le créneau passe tout seul au lendemain. « Pause », « Repas », « Marge »… deviennent des pauses ;
            « Revanche » / « jeu préféré » prend automatiquement le jeu le mieux noté.</p>
        <textarea name="text" rows="12" placeholder="Vendredi 13&#10;16:00 - 17:30 Far Far West&#10;17:30 - 19:00 Machine Party&#10;20:30 - 21:15 Pause repas&#10;22:45 - 00:15 You Suck at Parking&#10;&#10;Samedi 14&#10;11:00 - 12:30 Toxic Commando"><?= e($importText) ?></textarea>
        <label class="inline-check"><input type="checkbox" name="replace" value="1" checked> Remplacer le planning actuel</label>
        <button type="submit">Importer</button>
    </form>
</details>

<section class="card">
    <h2 class="card-title">🔔 Rappels push</h2>
    <form method="post" class="inline-form">
        <?= hidden_fields($lanId, 'remind') ?>
        <label>Prévenir
            <select name="remind_minutes">
                <?php foreach ([0 => 'seulement au début du jeu', 5 => '5 min avant', 10 => '10 min avant', 15 => '15 min avant', 20 => '20 min avant'] as $value => $label): ?>
                    <option value="<?= $value ?>"<?= (int) $lan['remind_minutes'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn-secondary">Enregistrer</button>
    </form>
    <p class="muted small">Chacun active les rappels depuis la page Planning (sur iPhone : d'abord « Sur l'écran d'accueil »).
        Notification <?= (int) $lan['remind_minutes'] ? (int) $lan['remind_minutes'] . ' min avant chaque créneau, puis' : '' ?> au début de chaque créneau.</p>
    <p><strong><?= array_sum(array_column($devices, 'n')) ?></strong> appareil(s) abonné(s)<?= $devices ? ' : ' . e(implode(', ', array_map(fn($d) => ($d['name'] ?? '?') . ((int) $d['n'] > 1 ? ' ×' . $d['n'] : ''), $devices))) : '' ?>
        <?php if ($devices): ?>
            <form method="post" class="inline"><?= hidden_fields($lanId, 'test_all') ?><button type="submit" class="btn-secondary small-btn">Envoyer un test à tous</button></form>
        <?php endif; ?>
    </p>
    <p class="muted small">
        <?= $cronAt > time() - 300 ? '✅ Tâche planifiée active (dernier passage à ' . date('H:i', $cronAt) . ').' : '⚠️ Pas de tâche planifiée : les rappels partent quand une page Planning est ouverte (par exemple sur la TV).' ?>
        Pour qu'ils partent toujours à l'heure : Plesk › <em>Tâches planifiées</em> › <em>Ajouter</em> ›
        <em>Exécuter un script PHP</em> <code><?= e(APP_ROOT) ?>/cron.php</code>, toutes les minutes (<code>* * * * *</code>).
    </p>
</section>

<section class="card">
    <h2 class="card-title">Créneaux (<?= count($slots) ?>)</h2>
    <?php if (!$slots): ?>
        <p class="muted">Aucun créneau : importe un planning ci-dessus ou ajoute-les un par un.</p>
    <?php endif; ?>
    <div class="slot-admin">
        <?php $day = null; foreach ($slots as $slot): ?>
            <?php if (slot_day($slot['starts_at']) !== $day): $day = slot_day($slot['starts_at']); ?>
                <h3><?= e(day_label($day)) ?></h3>
            <?php endif; ?>
            <form method="post" class="slot-row c-<?= e($slot['kind']) ?>">
                <?= hidden_fields($lanId, 'update_slot', (int) $slot['id']) ?>
                <input type="datetime-local" name="starts_at" value="<?= e(dt_local($slot['starts_at'])) ?>" required aria-label="Début">
                <input type="datetime-local" name="ends_at" value="<?= e(dt_local($slot['ends_at'])) ?>" required aria-label="Fin">
                <select name="kind" aria-label="Type"><?php foreach (SLOT_KINDS as $key => $label): ?><option value="<?= $key ?>"<?= $slot['kind'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <input type="text" name="title" value="<?= e($slot['title']) ?>" maxlength="80" required aria-label="Titre">
                <select name="proposal_id" aria-label="Jeu du pot commun">
                    <option value="">— jeu du pot (image) —</option>
                    <?php foreach ($pot as $game): ?><option value="<?= (int) $game['id'] ?>"<?= (int) $slot['proposal_id'] === (int) $game['id'] ? ' selected' : '' ?>><?= e($game['title']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="note" value="<?= e($slot['note']) ?>" maxlength="120" placeholder="Note (ex. 2 parties à 4)" aria-label="Note">
                <span class="slot-actions">
                    <button type="submit" class="small-btn">💾</button>
                    <?php if ((int) $slot['ratings']): ?><span class="avg">★ <?= round((float) $slot['avg_stars'], 1) ?></span><?php endif; ?>
                </span>
            </form>
            <div class="slot-row-tools">
                <form method="post" class="inline"><?= hidden_fields($lanId, 'shift', (int) $slot['id']) ?>
                    <select name="minutes" aria-label="Décalage"><?php foreach ([-30, -15, -5, 5, 10, 15, 30, 60] as $m): ?><option value="<?= $m ?>"<?= $m === 15 ? ' selected' : '' ?>><?= $m > 0 ? '+' : '' ?><?= $m ?> min</option><?php endforeach; ?></select>
                    <button type="submit" class="btn-link">décaler ce créneau et la suite</button>
                </form>
                <form method="post" class="inline" data-confirm="Supprimer ce créneau ?"><?= hidden_fields($lanId, 'delete_slot', (int) $slot['id']) ?><button type="submit" class="btn-link danger">supprimer</button></form>
            </div>
        <?php endforeach; ?>
    </div>

    <h3>➕ Ajouter un créneau</h3>
    <form method="post" class="slot-row">
        <?php $lastEnd = $slots ? end($slots)['ends_at'] : substr($lan['starts_at'], 0, 16); ?>
        <?= hidden_fields($lanId, 'add_slot') ?>
        <input type="datetime-local" name="starts_at" value="<?= e(dt_local($lastEnd)) ?>" required aria-label="Début">
        <input type="datetime-local" name="ends_at" value="<?= e(dt_local(date('Y-m-d H:i', slot_ts($lastEnd) + 5400))) ?>" required aria-label="Fin">
        <select name="kind" aria-label="Type"><?php foreach (SLOT_KINDS as $key => $label): ?><option value="<?= $key ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        <input type="text" name="title" maxlength="80" placeholder="Titre (ou choisis un jeu →)" aria-label="Titre">
        <select name="proposal_id" aria-label="Jeu du pot commun">
            <option value="">— jeu du pot —</option>
            <?php foreach ($pot as $game): ?><option value="<?= (int) $game['id'] ?>"><?= e($game['title']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="note" maxlength="120" placeholder="Note" aria-label="Note">
        <span class="slot-actions"><button type="submit" class="small-btn">Ajouter</button></span>
    </form>
    <?php if ($slots): ?>
        <p><form method="post" class="inline" data-confirm="Supprimer tout le planning de cette LAN (et ses notes) ?"><?= hidden_fields($lanId, 'clear') ?><button type="submit" class="btn-danger small-btn">Vider le planning</button></form></p>
    <?php endif; ?>
</section>
<?php
page_footer();
