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

/**
 * Description, points forts et points faibles d'un jeu.
 * Compatible avec l'ancien format où l'IA mettait « 👍 Points forts : … » dans la description.
 *
 * @return array{0: string, 1: list<string>, 2: list<string>}
 */
function game_text_parts(array $game): array
{
    $description = (string) $game['description'];
    $pros = array_filter(explode("\n", (string) ($game['pros'] ?? '')));
    $cons = array_filter(explode("\n", (string) ($game['cons'] ?? '')));
    if (!$pros && !$cons && preg_match_all('/^\s*(👍|👎)\s*Points (?:forts|faibles)\s*:\s*(.+)$/mu', $description, $matches, PREG_SET_ORDER)) {
        foreach ($matches as [$line, $emoji, $list]) {
            $items = array_filter(array_map('trim', preg_split('/\s*[,;]\s*/u', rtrim($list, '. '))));
            $emoji === '👍' ? array_push($pros, ...$items) : array_push($cons, ...$items);
            $description = str_replace($line, '', $description);
        }
        $description = trim($description);
    }
    return [$description, array_values($pros), array_values($cons)];
}

/** « 2 à 8 » → « 2 à 8 joueurs » (sauf si le mot y est déjà). */
function players_label(string $players): string
{
    return preg_match('/joueur|player|pers/i', $players) ? $players : $players . ' joueurs';
}

/** Petite fiche de jeu réutilisée sur toutes les pages (liste perso, pot commun, vote). */
function game_media(array $game, ?callable $footer = null): void
{
    ?>
    <?php if ($game['image_url'] !== ''): ?>
        <img class="game-img" src="<?= e($game['image_url']) ?>" alt="" loading="lazy">
    <?php endif; ?>
    <div class="game-body">
        <h3><?= e($game['title']) ?>
            <?php if (($game['players'] ?? '') !== ''): ?><span class="players">👥 <?= e(players_label($game['players'])) ?></span><?php endif; ?>
        </h3>
        <?php if ($game['tags'] !== ''): ?>
            <p class="tags"><?php foreach (explode(', ', $game['tags']) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?></p>
        <?php endif; ?>
        <?php [$description, $pros, $cons] = game_text_parts($game); ?>
        <?php if ($description !== ''): ?>
            <div class="desc-wrap">
                <p class="desc clamp"><?= nl2br(e($description)) ?></p>
                <button type="button" class="btn-link more" hidden>Voir plus ▾</button>
            </div>
        <?php endif; ?>
        <?php if ($pros || $cons): ?>
            <div class="proscons">
                <?php if ($pros): ?>
                    <div class="pros">
                        <h4>👍 Points forts</h4>
                        <ul><?php foreach ($pros as $point): ?><li><?= e($point) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <?php if ($cons): ?>
                    <div class="cons">
                        <h4>👎 Points faibles</h4>
                        <ul><?php foreach ($cons as $point): ?><li><?= e($point) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
            </div>
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
