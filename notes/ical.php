<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Abonnement calendrier (iCal) d'une base de données, par lien secret (Google Agenda, Outlook, iPhone…).
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$db = preg_match('/^[a-f0-9]{32}$/', $token)
    ? db_row("SELECT * FROM pages WHERE ical_token = ? AND kind = 'database' AND trashed_at IS NULL", [$token]) : null;
if (!$db) {
    http_response_code(404);
    exit('Calendrier introuvable.');
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendrier.ics"');
header('Cache-Control: no-cache');
echo db_to_ical($db);
