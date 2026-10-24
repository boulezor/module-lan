<?php
declare(strict_types=1);

function page_open(string $title, string $bodyClass = '', array $styles = ['assets/balls.css', 'assets/app.css'], array $bodyData = []): void
{
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(config('site_title')) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎱</text></svg>">
    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= asset($style) ?>">
    <?php endforeach; ?>
    <meta name="theme-color" content="#14102b">
    <?php $school = school_theme(); ?>
    <style>:root { --sm-1: <?= e($school['color1']) ?>; --sm-2: <?= e($school['color2']) ?>; }</style>
</head>
<body class="<?= e($bodyClass) ?>"<?php foreach ($bodyData as $key => $value): ?> data-<?= e($key) ?>="<?= e((string) $value) ?>"<?php endforeach; ?>>
    <?php
}

function page_close(array $scripts = []): void
{
    foreach ($scripts as $script) {
        echo '<script src="' . asset($script) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function flashes_html(): string
{
    $html = '';
    foreach (take_flashes() as [$type, $message]) {
        $html .= '<div class="alert alert-' . e($type) . '">' . e($message) . '</div>';
    }
    return $html;
}

/** Petite page centrée (connexion, installation, invitation). */
function auth_open(string $title): void
{
    page_open($title, 'auth');
    echo '<main class="auth-box"><div class="auth-logo"><span class="logo-ball">B</span> ' . e(config('site_title')) . '</div><h1>' . e($title) . '</h1>' . flashes_html();
}

function auth_close(): void
{
    echo '</main>';
    page_close();
}

/** En-tête des pages de l'organisateur */
function top_bar(array $user, string $extra = ''): void
{
    ?>
    <header class="topbar">
        <a href="index.php" class="brand"><span class="logo-ball">B</span> <?= e(config('site_title')) ?></a>
        <?= $extra ?>
        <nav class="topnav">
            <a href="compte.php" title="Mon compte">👤 <span class="hide-sm"><?= e($user['name']) ?></span></a>
            <?php if ((int) $user['is_admin']): ?><a href="admin.php" title="Administration">⚙️ <span class="hide-sm">Admin</span></a><?php endif; ?>
        </nav>
    </header>
    <?php
}

/** Page simple (administration, compte) */
function simple_open(string $title, array $user): void
{
    page_open($title, 'simple');
    top_bar($user);
    ?>
    <main class="simple-main">
        <h1><?= e($title) ?></h1>
        <?= flashes_html() ?>
    <?php
}

function simple_close(array $scripts = []): void
{
    echo '</main>';
    page_close($scripts);
}

/** Petit formulaire POST d'une action (boutons des listes) */
function action_button(string $action, string $label, int $id, string $class = 'btn-link', ?string $confirm = null): string
{
    return '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}
