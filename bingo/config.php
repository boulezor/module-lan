<?php
/**
 * Configuration par défaut (versionnée, sans secret).
 * Secrets : <dossier privé>/config.php sur le serveur (ex. /var/www/vhosts/<domaine>/private/bingo/config.php),
 * config.local.php en local. Voir config.local.sample.php.
 */

// Dossier privé (base, sessions, secrets) hors du dossier web : dossier « private » de Plesk, sinon data/ (local).
$privateDir = __DIR__ . '/data';
for ($dir = dirname(__DIR__), $i = 0; $i < 4 && $dir !== dirname($dir); $dir = dirname($dir), $i++) {
    if (@is_dir($dir . '/private')) {
        $privateDir = $dir . '/private/bingo';
        break;
    }
}

return [
    'site_title' => 'Bingo',
    'timezone' => 'Europe/Brussels',
    'private_dir' => $privateDir,
    'db_path' => $privateDir . '/bingo.sqlite',

    // Code demandé pour créer le tout premier compte (administrateur)
    'setup_code' => '',

    // Durée de validité d'un lien d'invitation (jours)
    'invite_days' => 7,

    // Numéro de grille le plus élevé (impression et vérification)
    'max_cards' => 5000,
];
