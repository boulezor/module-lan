<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$lans = all_lans();
$lan = selected_lan($lans);
$pot = $lan ? lan_proposals((int) $lan['id']) : [];
$user = current_user();

page_header('LAN', 'accueil');
?>
<?php if (!$lan): ?>
    <h1>Pas encore de LAN prévue</h1>
    <p class="muted">En attendant, prépare ta <a href="ma-liste.php">liste de jeux</a> : tu pourras les proposer dès qu'une date sera annoncée.</p>
<?php else: ?>
    <?php if (count($lans) > 1): ?>
        <nav class="lan-tabs">
            <?php foreach ($lans as $item): ?>
                <a href="index.php?lan=<?= (int) $item['id'] ?>"<?= $item['id'] === $lan['id'] ? ' class="active"' : '' ?>><?= e($item['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <h1><?= e($lan['title']) ?></h1>
    <p class="lan-meta">
        📅 <?= e(format_date($lan['lan_date'])) ?>
        <span class="badge<?= (int) $lan['proposals_open'] ? ' badge-ok' : '' ?>">Propositions <?= (int) $lan['proposals_open'] ? 'ouvertes' : 'fermées' ?></span>
        <span class="badge<?= (int) $lan['votes_open'] ? ' badge-ok' : '' ?>">Votes <?= (int) $lan['votes_open'] ? 'ouverts' : 'fermés' ?></span>
    </p>

    <div class="cta-row">
        <?php if ((int) $lan['proposals_open']): ?>
            <a class="button" href="ma-liste.php">➕ Proposer des jeux de ma liste</a>
        <?php endif; ?>
        <?php if ((int) $lan['votes_open'] && $pot): ?>
            <a class="button btn-secondary" href="voter.php?lan=<?= (int) $lan['id'] ?>">🗳️ Voter</a>
        <?php endif; ?>
        <?php if (!$user): ?>
            <a href="connexion.php">Se connecter / créer un compte</a>
        <?php endif; ?>
    </div>

    <h2>Pot commun (<?= count($pot) ?>)</h2>
    <?php if (!$pot): ?>
        <p class="muted">Aucun jeu proposé pour l'instant.</p>
    <?php else: ?>
        <ul class="game-list compact">
            <?php foreach ($pot as $game): ?>
                <li class="game"><?php game_media($game); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
<?php page_footer();
