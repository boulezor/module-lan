<?php
declare(strict_types=1);

/*
 * Tâche planifiée (Plesk : chaque minute) : envoie les rappels push du planning à l'heure.
 *   Ligne de commande : php /var/www/vhosts/<domaine>/httpdocs/lan/cron.php
 *   ou par URL        : https://<domaine>/lan/cron.php?key=<cron_key>   (cron_key dans le config.php privé)
 * Sans tâche planifiée, les rappels partent quand même dès qu'une page Planning est ouverte quelque part.
 */
if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] ??= 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
require __DIR__ . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $key = (string) config('cron_key');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Interdit.');
    }
}
$sent = push_dispatch(true);
set_setting('push_cron_at', (string) time());
echo date('Y-m-d H:i:s') . " : {$sent} notification(s) envoyée(s)\n";
