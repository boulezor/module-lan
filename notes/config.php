<?php
/**
 * Configuration par défaut (versionnée, sans secret).
 *
 * Les secrets se mettent dans un fichier hors de Git :
 *   - sur le serveur : <dossier privé>/config.php  (ex. /var/www/vhosts/<domaine>/private/notes/config.php)
 *   - en local       : config.local.php
 * Voir config.local.sample.php.
 */

// Dossier privé de l'appli (base + fichiers envoyés + secrets), hors du dossier web :
// on cherche le dossier « private » de Plesk en remontant ; à défaut (local), data/.
$privateDir = __DIR__ . '/data';
for ($dir = dirname(__DIR__), $i = 0; $i < 4 && $dir !== dirname($dir); $dir = dirname($dir), $i++) {
    if (@is_dir($dir . '/private')) {
        $privateDir = $dir . '/private/notes';
        break;
    }
}

return [
    'site_title' => 'Notes',
    'team_name' => 'Équipe',
    'timezone' => 'Europe/Brussels',

    'private_dir' => $privateDir,
    'db_path' => $privateDir . '/notes.sqlite',
    'files_dir' => $privateDir . '/files',

    // Code demandé pour créer le tout premier compte administrateur (à définir dans le fichier privé).
    'setup_code' => '',

    // Taille max d'un fichier envoyé (Mo). Attention aussi à upload_max_filesize / post_max_size de PHP.
    'max_upload_mb' => 20,

    // Durée de validité d'un lien d'invitation (jours)
    'invite_days' => 7,

    // Assistant IA (OpenAI) : la clé peut aussi être enregistrée dans l'administration.
    'openai_api_key' => '',
    'openai_model' => 'gpt-4o-mini',
    'openai_timeout' => 60,
    'openai_base_url' => 'https://api.openai.com/v1',
];
