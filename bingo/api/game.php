<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * API JSON du bingo.
 * GET  ?action=state&t=<jeton écran>     : état public (écran de projection), fait avancer le tirage automatique
 * GET  ?action=state&id=<session>        : état pour l'organisateur
 * GET  ?action=csrf                      : nouveau jeton de sécurité (page restée ouverte longtemps)
 * POST {action, id, …} + jeton CSRF      : actions de l'organisateur
 */

$action = (string) ($_GET['action'] ?? json_input()['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'csrf') {
        require_api_user(false);
        json_response(['csrf' => csrf_token()]);
    }
    $token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
    if ($token !== '') {
        $game = preg_match('/^[a-f0-9]{24}$/', $token) ? db_row('SELECT * FROM games WHERE display_token = ?', [$token]) : null;
        if (!$game) {
            json_response(['error' => "Cet écran n'existe plus (lien changé ou session supprimée)."], 404);
        }
        $manager = can_manage($game, current_user());
    } else {
        $user = require_api_user(false);
        $game = find_game((int) ($_GET['id'] ?? 0));
        if (!$game || !can_manage($game, $user)) {
            json_response(['error' => 'Session introuvable.'], 404);
        }
        $manager = true;
    }
    session_write_close(); // pas de blocage entre les requêtes de l'écran
    $game = auto_tick($game);
    if ((int) ($_GET['rev'] ?? 0) === (int) $game['revision']) {
        json_response(['same' => true, 'rev' => (int) $game['revision']]);
    }
    json_response(game_state($game, $manager));
}

$user = require_api_user();
$input = json_input();
$game = find_game((int) ($input['id'] ?? 0));
if (!$game || !can_manage($game, $user)) {
    json_response(['error' => 'Session introuvable.'], 404);
}
$id = (int) $game['id'];
$error = null;
$extra = [];

switch ($action) {
    case 'draw':
        $error = draw_number($game, null, 'random');
        break;

    case 'add':
        $error = draw_number($game, (int) ($input['number'] ?? 0), 'manual');
        break;

    case 'remove':
        if (db_exec('DELETE FROM draws WHERE game_id = ? AND number = ?', [$id, (int) ($input['number'] ?? 0)])) {
            touch_game($id);
        }
        break;

    case 'undo':
        $last = db_row('SELECT id, number FROM draws WHERE game_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        if ($last) {
            db_exec('DELETE FROM draws WHERE id = ?', [$last['id']]);
            touch_game($id, ['auto_running' => 0]);
            $extra['undone'] = (int) $last['number'];
        } else {
            $error = 'Aucune boule à annuler.';
        }
        break;

    case 'reset':
        db_exec('DELETE FROM draws WHERE game_id = ?', [$id]);
        db_exec('DELETE FROM winners WHERE game_id = ?', [$id]);
        touch_game($id, ['phase_index' => 0, 'auto_running' => 0, 'overlay' => '', 'round' => (int) $game['round'] + 1]);
        break;

    case 'auto':
        if (!empty($input['running'])) {
            if (count(game_draws($id)) >= game_max($game)) {
                $error = 'Toutes les boules sont sorties.';
                break;
            }
            touch_game($id, ['auto_running' => 1, 'draw_mode' => 'auto', 'auto_next_at' => microtime(true) + 2]);
        } else {
            touch_game($id, ['auto_running' => 0]);
        }
        break;

    case 'interval':
        $interval = max(AUTO_MIN_INTERVAL, min(AUTO_MAX_INTERVAL, (int) ($input['interval'] ?? 10)));
        $set = ['auto_interval' => $interval];
        if ((int) $game['auto_running']) {
            $set['auto_next_at'] = min((float) $game['auto_next_at'], microtime(true) + $interval);
        }
        touch_game($id, $set);
        break;

    case 'mode':
        touch_game($id, ['draw_mode' => ($input['mode'] ?? '') === 'auto' ? 'auto' : 'manual', 'auto_running' => 0]);
        break;

    case 'settings':
        $name = clean_line((string) ($input['name'] ?? ''), 80);
        $type = (int) ($input['type'] ?? $game['type']) === 75 ? 75 : 90;
        $set = [
            'theme' => array_key_exists((string) ($input['theme'] ?? ''), GAME_THEMES) ? (string) $input['theme'] : $game['theme'],
            'voice' => empty($input['voice']) ? 0 : 1,
            'suspense' => empty($input['suspense']) ? 0 : 1,
        ];
        if ($name !== '') {
            $set['name'] = $name;
        }
        if ($type !== (int) $game['type']) {
            if (game_draws($id)) {
                $error = 'Réinitialise la partie avant de changer le type de bingo.';
                break;
            }
            $set['type'] = $type;
            $set['phase_index'] = 0;
            if (json_decode((string) $game['phases'], true) == default_phases((int) $game['type'])) {
                $set['phases'] = json_encode(default_phases($type), JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($input['phases']) && is_string($input['phases'])) {
            $phases = parse_phases($input['phases']) ?: default_phases($type);
            $set['phases'] = json_encode($phases, JSON_UNESCAPED_UNICODE);
            $set['phase_index'] = min((int) $game['phase_index'], count($phases) - 1);
        }
        touch_game($id, $set);
        break;

    case 'phase':
        $count = count(game_phases($game));
        touch_game($id, ['phase_index' => max(0, min($count - 1, (int) ($input['index'] ?? 0))), 'overlay' => '']);
        break;

    case 'winner':
        $phases = game_phases($game);
        $phase = $phases[min((int) $game['phase_index'], count($phases) - 1)];
        $card = (int) ($input['card'] ?? 0);
        $card = $card >= 1 && $card <= (int) config('max_cards') ? $card : null;
        $name = clean_line((string) ($input['name'] ?? ''), 60);
        db_exec('INSERT INTO winners (game_id, phase, prize, name, card_no, draws_count) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $phase['name'], $phase['prize'], $name, $card, count(game_draws($id))]);
        touch_game($id, ['auto_running' => 0, 'overlay' => json_encode([
            'kind' => 'bingo', 'phase' => $phase['name'], 'prize' => $phase['prize'], 'name' => $name, 'card' => $card,
        ], JSON_UNESCAPED_UNICODE)]);
        break;

    case 'winner_delete':
        db_exec('DELETE FROM winners WHERE id = ? AND game_id = ?', [(int) ($input['winner'] ?? 0), $id]);
        touch_game($id);
        break;

    case 'check':
        $card = (int) ($input['card'] ?? 0);
        if ($card < 1 || $card > (int) config('max_cards')) {
            $error = 'Numéro de carton invalide (1 à ' . (int) config('max_cards') . ').';
            break;
        }
        $extra['check'] = check_card($game, $card, array_column(game_draws($id), 'n'));
        if (!empty($input['show'])) {
            touch_game($id, ['overlay' => json_encode(['kind' => 'card', 'card' => $card])]);
        }
        break;

    case 'message':
        $text = trim(mb_substr((string) ($input['text'] ?? ''), 0, 200));
        touch_game($id, ['overlay' => $text === '' ? '' : json_encode(['kind' => 'message', 'text' => $text], JSON_UNESCAPED_UNICODE)]);
        break;

    case 'overlay_clear':
        touch_game($id, ['overlay' => '']);
        break;

    case 'new_link':
        touch_game($id, ['display_token' => random_token(12)]);
        $extra['display_url'] = app_url('ecran.php?t=' . find_game($id)['display_token']);
        break;

    default:
        json_response(['error' => 'Action inconnue.'], 400);
}

$state = game_state(find_game($id), true) + $extra;
if ($error !== null) {
    $state['error'] = $error;
}
json_response($state, $error !== null ? 422 : 200);
