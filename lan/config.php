<?php
/**
 * Configuration par défaut (versionnée, sans secret).
 *
 * Les secrets (mot de passe, clés API) se mettent dans un fichier qui n'est pas dans Git :
 *   - sur le serveur : <dossier privé>/config.php  (ex. /var/www/vhosts/<domaine>/private/lan/config.php)
 *   - en local       : config.local.php
 * Voir config.local.sample.php pour le contenu.
 */

// Dossier privé de l'appli, hors du dossier web : on cherche le dossier « private » de Plesk
// en remontant depuis l'appli. À défaut (développement local), on utilise data/.
$privateDir = __DIR__ . '/data';
for ($dir = dirname(__DIR__), $i = 0; $i < 4 && $dir !== dirname($dir); $dir = dirname($dir), $i++) {
    if (@is_dir($dir . '/private')) {
        $privateDir = $dir . '/private/lan';
        break;
    }
}

return [
    // Titre affiché dans l'en-tête des pages.
    'site_title' => 'Vote des jeux LAN',

    // Mot de passe de la page Résultats. Vide = page résultats inaccessible.
    'admin_password' => '',

    // Dossier privé (base de données + config.php avec les secrets), créé automatiquement.
    'private_dir' => $privateDir,

    // Base SQLite (créée automatiquement).
    'db_path' => $privateDir . '/lan.sqlite',

    'timezone' => 'Europe/Brussels',

    // Pays utilisé pour la boutique Steam (prix / disponibilité).
    'steam_country' => 'BE',

    // Optionnel : clé OpenAI pour rédiger des descriptions plus « LAN ».
    // Sans clé, la description vient de Steam puis de Wikipédia.
    'openai_api_key' => '',
    'openai_model' => 'gpt-4o-mini',

    // Optionnel : clé YouTube Data API v3 pour obtenir un lien direct vers une vidéo.
    // Sans clé, on donne un lien vers une recherche YouTube.
    'youtube_api_key' => '',

    // Délai max (secondes) de chaque appel vers Steam / Wikipédia / OpenAI / YouTube.
    'http_timeout' => 6,
];
