<?php
// Secrets de l'appli LAN, à ne jamais mettre dans Git.
// Sur le serveur : copier dans /var/www/vhosts/<domaine>/private/lan/config.php
// En local       : copier dans lan/config.local.php
return [
    'admin_password' => 'change-moi',

    // 'openai_api_key' => 'sk-...',
    // 'youtube_api_key' => 'AIza...',
];
