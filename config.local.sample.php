<?php
// Copie ce fichier en config.local.php puis adapte les valeurs.
return [
    'admin_password' => 'change-moi',

    // Recommandé sur Plesk : base hors du dossier web (le dossier « private » n'est jamais servi).
    // Crée d'abord le dossier, ex. /var/www/vhosts/mon-domaine.be/private/lan
    // 'db_path' => '/var/www/vhosts/mon-domaine.be/private/lan/lan.sqlite',

    // 'openai_api_key' => 'sk-...',
    // 'youtube_api_key' => 'AIza...',
];
