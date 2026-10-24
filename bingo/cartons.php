<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

/*
 * Planches à imprimer : une planche = une feuille A4 de 1 à 6 grilles. Chaque grille a son numéro (toujours la même
 * pour une session), qui sert à vérifier un gagnant. POST : nouvelle série (numéros suivants) ; GET : impression.
 */
$user = require_login();
$game = find_game((int) ($_REQUEST['id'] ?? 0));
if (!$game || !can_manage($game, $user)) {
    flash('error', 'Session introuvable.');
    redirect('index.php');
}
$id = (int) $game['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $run = csrf_valid() ? create_print_run($game, (int) post_string('sheets'), (int) post_string('per')) : 'Session expirée, réessaie.';
    if (is_string($run)) {
        flash('error', $run);
        redirect('session.php?id=' . $id);
    }
    touch_game($id);
    redirect("cartons.php?id={$id}&from={$run['from']}&to={$run['to']}&per={$run['per']}");
}

$max = (int) config('max_cards');
$per = max(1, min(CARDS_PER_SHEET_MAX, (int) ($_GET['per'] ?? CARDS_PER_SHEET_MAX)));
$from = max(1, min($max, (int) ($_GET['from'] ?? 1)));
$to = max($from, min($max, $from + 999, (int) ($_GET['to'] ?? $from + $per - 1)));
$type = (int) $game['type'];
$layout = sheet_layout($type, $per);
$school = $game['theme'] === 'saintmartin' ? school_theme() : null;
$title = $school ? $school['title'] : $game['name'];
$sheets = array_chunk(range($from, $to), $per);

page_open('Planches ' . $from . ' à ' . $to, 'print-page', ['assets/balls.css', 'assets/app.css']);
?>
<div class="print-bar no-print">
    <a href="session.php?id=<?= $id ?>" class="btn btn-ghost">← Retour</a>
    <span><strong><?= count($sheets) ?> planche<?= count($sheets) > 1 ? 's' : '' ?> de <?= $per ?> grille<?= $per > 1 ? 's' : '' ?></strong>
        · grilles n° <?= $from ?> à <?= $to ?></span>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Imprimer</button>
</div>
<div class="sheets">
<?php foreach ($sheets as $numbers): ?>
    <section class="sheet sheet-<?= $type ?><?= $school ? ' sheet-school' : '' ?>" style="--cols: <?= $layout['cols'] ?>; --cell: <?= $layout['cell'] ?>mm;<?= e(theme_style($game['theme'])) ?>">
        <header class="sheet-head">
            <?php if ($school && $school['logo']): ?><img src="<?= e($school['logo']) ?>" alt="" class="sheet-logo"><?php endif; ?>
            <span class="sheet-title"><?= e($title) ?></span>
            <span class="sheet-info"><?= count($numbers) > 1 ? 'Planche de ' . count($numbers) . ' grilles · n° ' . $numbers[0] . ' à ' . end($numbers) : 'Grille n° ' . $numbers[0] ?></span>
        </header>
        <div class="tickets">
        <?php foreach ($numbers as $no): $grid = card_grid($game, $no); ?>
            <article class="ticket ticket-<?= $type ?>">
                <header><span class="ticket-name"><?= e($game['name']) ?></span><span class="ticket-no">N° <?= $no ?></span></header>
                <table>
                    <?php if ($type === 75): ?>
                        <thead><tr><?php foreach (str_split('BINGO') as $i => $l): ?><th class="l<?= $i ?>"><?= $l ?></th><?php endforeach; ?></tr></thead>
                    <?php endif; ?>
                    <tbody>
                    <?php foreach ($grid as $row): ?>
                        <tr><?php foreach ($row as $n): ?>
                            <?php if ($n === null): ?><td class="blank"></td>
                            <?php elseif ($n === 0): ?><td class="free">★</td>
                            <?php else: ?><td><?= $n ?></td><?php endif; ?>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </article>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>
<?php
page_close();
