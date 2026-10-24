<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Cartons numérotés à imprimer (toujours identiques pour une session : le n° sert à vérifier un gagnant)
$user = require_login();
$game = find_game((int) ($_GET['id'] ?? 0));
if (!$game || !can_manage($game, $user)) {
    flash('error', 'Session introuvable.');
    redirect('index.php');
}
$max = (int) config('max_cards');
$from = max(1, min($max, (int) ($_GET['from'] ?? 1)));
$to = max($from, min($max, (int) ($_GET['to'] ?? $from + 23)));
$type = (int) $game['type'];
$perPage = $type === 75 ? 4 : 6;

page_open('Cartons ' . $from . ' à ' . $to, 'print-page', ['assets/balls.css', 'assets/app.css']);
?>
<div class="print-bar no-print">
    <a href="session.php?id=<?= (int) $game['id'] ?>" class="btn btn-ghost">← Retour</a>
    <span><strong><?= $to - $from + 1 ?> cartons</strong> (n° <?= $from ?> à <?= $to ?>) · <?= $perPage ?> par page A4</span>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Imprimer</button>
</div>
<div class="sheets">
<?php foreach (array_chunk(range($from, $to), $perPage) as $page): ?>
    <section class="sheet sheet-<?= $type ?>">
        <?php foreach ($page as $no): $grid = card_grid($game, $no); ?>
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
    </section>
<?php endforeach; ?>
</div>
<?php
page_close();
