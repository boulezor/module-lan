<?php
/**
 * Configuration par défaut.
 *
 * Ne modifie pas ce fichier : copie config.local.sample.php en config.local.php
 * et surcharge uniquement les valeurs dont tu as besoin (mot de passe, clés API…).
 */
return [
    // Titre affiché dans l'en-tête des pages.
    'site_title' => 'Vote des jeux LAN',

    // Mot de passe de la page Résultats. Vide = page résultats inaccessible.
    'admin_password' => '',

    // Base SQLite (créée automatiquement). Le dossier doit être accessible en écriture.
    'db_path' => __DIR__ . '/data/lan.sqlite',

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
