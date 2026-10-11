<?php
declare(strict_types=1);

// Page d'accueil de l'espace : liste les applis (chaque dossier contenant un app.json).
$apps = [];
foreach (glob(__DIR__ . '/*/app.json') ?: [] as $manifest) {
    $info = json_decode((string) file_get_contents($manifest), true);
    if (is_array($info) && empty($info['hidden'])) {
        $apps[] = $info + ['folder' => basename(dirname($manifest)), 'icon' => '📦', 'description' => ''];
    }
}
usort($apps, fn($a, $b) => strcasecmp($a['name'] ?? $a['folder'], $b['name'] ?? $b['folder']));
$e = fn(?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Mes applis</title>
    <style>
        :root { --bg: #0f1220; --panel: #181c2f; --border: #2d3352; --text: #e8eaf6; --muted: #9aa0c3; --accent: #7c5cff; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 900px; margin: 0 auto; padding: 32px 16px; }
        h1 { margin: 0 0 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        a.app { display: block; padding: 20px; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; color: inherit; text-decoration: none; }
        a.app:hover { border-color: var(--accent); }
        .icon { font-size: 2rem; }
        .name { font-weight: 700; margin: 8px 0 4px; }
        .desc, .empty { color: var(--muted); font-size: .9rem; margin: 0; }
    </style>
</head>
<body>
<main>
    <h1>Mes applis</h1>
    <?php if (!$apps): ?>
        <p class="empty">Aucune appli pour l'instant.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($apps as $app): ?>
                <a class="app" href="<?= $e(rawurlencode($app['folder'])) ?>/">
                    <div class="icon"><?= $e($app['icon']) ?></div>
                    <div class="name"><?= $e($app['name'] ?? $app['folder']) ?></div>
                    <p class="desc"><?= $e($app['description']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
