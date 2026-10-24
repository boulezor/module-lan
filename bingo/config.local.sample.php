<?php
// Secrets de l'appli Bingo, à ne jamais mettre dans Git.
// Sur le serveur : copier dans /var/www/vhosts/<domaine>/private/bingo/config.php
// En local       : copier dans bingo/config.local.php
return [
    // Code demandé une seule fois pour créer le premier compte administrateur
    'setup_code' => 'choisis-un-code',
];
