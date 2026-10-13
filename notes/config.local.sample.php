<?php
// Secrets de l'appli Notes, à ne jamais mettre dans Git.
// Sur le serveur : copier dans /var/www/vhosts/<domaine>/private/notes/config.php
// En local       : copier dans notes/config.local.php
return [
    // Code demandé une seule fois pour créer le premier compte administrateur
    'setup_code' => 'choisis-un-code',

    // Clé OpenAI : se règle aussi depuis l'administration (celle-ci est alors prioritaire)
    // 'openai_api_key' => 'sk-...',
    // 'openai_model' => 'gpt-4o-mini',
];
