<?php
// Secrets de l'appli LAN, à ne jamais mettre dans Git.
// Sur le serveur : copier dans /var/www/vhosts/<domaine>/private/lan/config.php
// En local       : copier dans lan/config.local.php
return [
    'admin_password' => 'change-moi',

    // Clé OpenAI : se règle aussi depuis la page Résultats (celle-ci est alors prioritaire)
    // 'openai_api_key' => 'sk-...',
    // 'openai_model' => 'gpt-4o-mini',
    // Clé YouTube Data API v3 (bandes-annonces) : se règle aussi depuis la page Résultats
    // 'youtube_api_key' => 'AIza...',
];
