<?php
declare(strict_types=1);

function page_open(string $title, string $bodyClass = ''): void
{
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(config('site_title')) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
    <link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#ffffff">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Notes">
</head>
<body class="<?= e($bodyClass) ?>">
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

/** Scripts de l'éditeur (Editor.js et ses blocs, intégrés dans assets/vendor). */
function editor_scripts(): array
{
    return array_map(fn($name) => "assets/vendor/editorjs/{$name}.js",
        ['editorjs', 'header', 'list', 'quote', 'code', 'delimiter', 'table', 'image', 'attaches', 'embed',
         'marker', 'underline', 'inline-code', 'drag-drop']);
}

/** Petite page centrée (connexion, installation, invitation). */
function auth_open(string $title): void
{
    page_open($title, 'auth');
    echo '<main class="auth-box"><div class="auth-logo">📝 ' . e(config('site_title')) . '</div><h1>' . e($title) . '</h1>' . flashes_html();
}

function auth_close(): void
{
    echo '</main>';
    page_close();
}

/** Page simple avec retour vers l'espace de notes (administration, compte). */
function simple_open(string $title, array $user): void
{
    page_open($title, 'simple');
    ?>
    <header class="simple-header">
        <a href="index.php" class="back">← <?= e(config('site_title')) ?></a>
        <nav>
            <a href="compte.php">Mon compte</a>
            <?php if ((int) $user['is_admin']): ?><a href="admin.php">Administration</a><?php endif; ?>
        </nav>
    </header>
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
