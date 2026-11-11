<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * Planning en direct (JSON).
 * GET  ?lan=…                    : état du planning (et envoi des rappels push dus, en secours de la tâche planifiée)
 * POST action=rate|subscribe|unsubscribe|test|shift  (+ csrf)
 */

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lan = planning_lan((int) ($_GET['lan'] ?? 0));
    if (!$lan) {
        json_out(['error' => 'Aucune LAN.'], 404);
    }
    $state = planning_state($lan, $user);
    session_write_close();
    try {
        push_dispatch();
    } catch (Throwable $e) {
        error_log('push_dispatch : ' . $e->getMessage());
    }
    json_out($state);
}

if (!csrf_valid()) {
    json_out(['error' => 'Jeton de sécurité expiré : réessaie.', 'csrf' => csrf_token()], 403);
}
$action = post_string('action');

if ($action === 'shift') {
    if (!is_admin()) {
        json_out(['error' => 'Réservé à l\'organisateur (page Résultats).'], 403);
    }
    $slot = db_row('SELECT * FROM schedule_slots WHERE id = ?', [(int) post_string('slot')]);
    $minutes = max(-120, min(240, (int) post_string('minutes')));
    if (!$slot || $minutes === 0) {
        json_out(['error' => 'Créneau introuvable.'], 404);
    }
    $count = shift_slots($slot, $minutes);
    json_out(['ok' => true, 'message' => "Planning décalé de {$minutes} min ({$count} créneaux)."]);
}

if ($user === null) {
    json_out(['error' => 'Connecte-toi pour ça.'], 401);
}

switch ($action) {
    case 'rate':
        $slot = db_row('SELECT * FROM schedule_slots WHERE id = ?', [(int) post_string('slot')]);
        $stars = (int) post_string('stars');
        if (!$slot || $slot['kind'] === 'break') {
            json_out(['error' => 'Créneau introuvable.'], 404);
        }
        if (slot_ts($slot['starts_at']) > time() + 5 * 60) {
            json_out(['error' => 'Tu pourras noter ce jeu une fois la partie commencée.'], 422);
        }
        if ($stars === 0) {
            db_exec('DELETE FROM slot_ratings WHERE slot_id = ? AND user_id = ?', [$slot['id'], $user['id']]);
        } elseif ($stars >= 1 && $stars <= 5) {
            db_exec('INSERT INTO slot_ratings (slot_id, user_id, stars) VALUES (?, ?, ?)
                     ON CONFLICT (slot_id, user_id) DO UPDATE SET stars = excluded.stars, updated_at = CURRENT_TIMESTAMP',
                [$slot['id'], $user['id'], $stars]);
        }
        json_out(['ok' => true]);

    case 'subscribe':
        $sub = json_decode(post_string('subscription'), true);
        $endpoint = (string) ($sub['endpoint'] ?? '');
        $keys = $sub['keys'] ?? [];
        if (!preg_match('#^https://#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
            json_out(['error' => 'Abonnement invalide.'], 422);
        }
        db_exec('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
                 ON CONFLICT (endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth, failures = 0',
            [$user['id'], $endpoint, (string) $keys['p256dh'], (string) $keys['auth']]);
        json_out(['ok' => true]);

    case 'unsubscribe':
        db_exec('DELETE FROM push_subscriptions WHERE endpoint = ?', [post_string('endpoint')]);
        json_out(['ok' => true]);

    case 'test':
        $sent = push_to_all(['title' => '🔔 Notifications activées', 'body' => "Tu seras prévenu avant chaque changement de jeu. Bonne LAN {$user['name']} !",
            'tag' => 'test', 'url' => 'planning.php'], (int) $user['id']);
        json_out($sent ? ['ok' => true, 'sent' => $sent] : ['error' => "Aucun appareil n'a reçu la notification : réactive les rappels."], $sent ? 200 : 422);
}
json_out(['error' => 'Action inconnue.'], 400);
