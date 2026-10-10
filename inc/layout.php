<?php
declare(strict_types=1);

function page_header(string $title, string $active): void
{
    $nav = [
        'accueil' => ['index.php', 'LAN'],
        'liste' => ['ma-liste.php', 'Ma liste'],
        'voter' => ['voter.php', 'Voter'],
        'resultats' => ['resultats.php', 'Résultats 🔒'],
    ];
    $user = current_user();
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(config('site_title')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a class="brand" href="index.php">🎮 <?= e(config('site_title')) ?></a>
        <nav>
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= $href ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($user): ?>
                <form method="post" action="connexion.php" class="inline user-menu">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logout">
                    <span class="muted"><?= e($user['name']) ?></span>
                    <button type="submit" class="btn-link">Déconnexion</button>
                </form>
            <?php else: ?>
                <a href="connexion.php"<?= $active === 'connexion' ? ' class="active"' : '' ?>>Connexion</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="wrap">
    <?php foreach (take_flashes() as [$type, $message]): ?>
        <div class="alert alert-<?= e($type) ?>"><?= e($message) ?></div>
    <?php endforeach;
}

function page_footer(): void
{
    ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
    <?php
}

/** Petite fiche de jeu réutilisée sur toutes les pages (liste perso, pot commun, vote). */
function game_media(array $game, ?callable $footer = null): void
{
    ?>
    <?php if ($game['image_url'] !== ''): ?>
        <img class="game-img" src="<?= e($game['image_url']) ?>" alt="" loading="lazy">
    <?php endif; ?>
    <div class="game-body">
        <h3><?= e($game['title']) ?></h3>
        <?php if ($game['tags'] !== ''): ?>
            <p class="tags"><?php foreach (explode(', ', $game['tags']) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?></p>
        <?php endif; ?>
        <?php if ($game['description'] !== ''): ?>
            <p class="desc"><?= e($game['description']) ?></p>
        <?php endif; ?>
        <?php if (($game['comment'] ?? '') !== ''): ?>
            <p class="comment">💬 <strong><?= e($game['proposed_by'] ?? 'Mon commentaire') ?> :</strong> <?= nl2br(e($game['comment'])) ?></p>
        <?php endif; ?>
        <p class="links">
            <?php if ($game['video_url'] !== ''): ?><a href="<?= e($game['video_url']) ?>" target="_blank" rel="noopener">▶ Vidéo</a><?php endif; ?>
            <?php if ($game['store_url'] !== ''): ?><a href="<?= e($game['store_url']) ?>" target="_blank" rel="noopener">🔗 Fiche</a><?php endif; ?>
            <?php if (isset($game['proposed_by'])): ?><span class="muted">proposé par <?= e($game['proposed_by']) ?></span><?php endif; ?>
        </p>
        <?php if ($footer) { $footer(); } ?>
    </div>
    <?php
}
