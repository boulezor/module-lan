<?php
declare(strict_types=1);

/*
 * Planning de la LAN : créneaux, notes des jeux joués (étoiles), jeu préféré (« revanche »),
 * import d'un planning collé en texte, retard (décalage des créneaux) et rappels push.
 */

const SLOT_KINDS = ['game' => 'Jeu', 'break' => 'Pause / autre', 'favorite' => 'Jeu préféré (selon les notes)'];

/** 'AAAA-MM-JJ HH:MM' (heure locale) → horodatage */
function slot_ts(string $value): int
{
    return (int) strtotime($value);
}

/** Créneaux d'une LAN avec la moyenne des notes (et la note de $userId) */
function lan_slots(int $lanId, ?int $userId = null): array
{
    return db_rows('SELECT s.*, p.image_url, p.title AS game_title,
            (SELECT AVG(stars) FROM slot_ratings r WHERE r.slot_id = s.id) AS avg_stars,
            (SELECT COUNT(*) FROM slot_ratings r WHERE r.slot_id = s.id) AS ratings,
            (SELECT stars FROM slot_ratings r WHERE r.slot_id = s.id AND r.user_id = ?) AS my_stars
        FROM schedule_slots s LEFT JOIN proposals p ON p.id = s.proposal_id
        WHERE s.lan_id = ? ORDER BY s.starts_at, s.id', [$userId ?? 0, $lanId]);
}

/** Jeu le mieux noté de la LAN (pour le créneau « revanche / jeu préféré ») */
function favorite_game(array $slots): ?array
{
    $best = null;
    foreach ($slots as $slot) {
        if ($slot['kind'] !== 'game' || !(int) $slot['ratings']) {
            continue;
        }
        $score = [(float) $slot['avg_stars'], (int) $slot['ratings']];
        if ($best === null || $score > $best['score']) {
            $best = ['score' => $score, 'slot' => $slot];
        }
    }
    return $best ? $best['slot'] + ['avg' => round($best['score'][0], 1)] : null;
}

/** Titre affiché d'un créneau (le jeu préféré est remplacé par le jeu le mieux noté) */
function slot_title(array $slot, ?array $favorite): string
{
    if ($slot['kind'] === 'favorite' && $favorite) {
        return $favorite['title'];
    }
    return $slot['title'];
}

/** Jour de LAN d'un créneau : avant 6 h du matin, on est encore dans la soirée de la veille */
function slot_day(string $startsAt): string
{
    return date('Y-m-d', slot_ts($startsAt) - 6 * 3600);
}

/** « Vendredi 13 » */
function day_label(string $day): string
{
    $label = fr_date($day, false);
    return ucfirst(str_contains($label, ' ') ? (string) preg_replace('/ \S+$/u', '', $label) : $label);
}

function kind_from_title(string $title): string
{
    if (preg_match('/revanche|pr[ée]f[ée]r[ée]/iu', $title)) {
        return 'favorite';
    }
    return preg_match('/pause|repas|marge|rangement|prolongation|fin de soir|accueil|installation|petit[- ]d[ée]j/iu', $title) ? 'break' : 'game';
}

/** Jeu du pot commun qui correspond à un titre (pour l'image et le lien) */
function match_proposal_id(int $lanId, string $title): ?int
{
    $key = normalize_key($title);
    $best = null;
    $bestScore = 0.82;
    foreach (lan_proposals($lanId) as $game) {
        $score = similarity($key, (string) $game['normalized']);
        if ($score >= $bestScore) {
            [$best, $bestScore] = [(int) $game['id'], $score];
        }
    }
    return $best;
}

/**
 * Lit un planning collé en texte. Formats acceptés (une ligne par créneau, titres de jour) :
 *   Vendredi 13            ← jour (nom + numéro, ou 2026-11-13, ou 13/11)
 *   16:00 - 17:30 Far Far West
 *   17:30 - 19:00          ← l'heure et le titre peuvent aussi être sur deux lignes (copie d'un PDF)
 *   Machine Party
 * Après minuit, le créneau passe automatiquement au lendemain.
 *
 * @return array{0: list<array>, 1: list<string>} [créneaux, erreurs]
 */
function parse_schedule(array $lan, string $text): array
{
    $first = substr((string) ($lan['starts_at'] ?: $lan['lan_date']), 0, 10);
    $last = substr((string) ($lan['ends_at'] ?: $first), 0, 10);
    $candidates = [];
    for ($ts = strtotime($first . ' -1 day'); $ts <= strtotime($last . ' +1 day'); $ts += 86400) {
        $candidates[] = date('Y-m-d', $ts);
    }

    $slots = [];
    $errors = [];
    $date = $first;
    $previousStart = null;
    $pending = null;
    $time = '(\d{1,2})\s*[:hH]\s*(\d{2})?';
    foreach (preg_split('/\R/u', $text) as $number => $line) {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));
        if ($line === '') {
            continue;
        }
        // Créneau : « 16:00 - 17:30 Titre » (titre facultatif : il peut être sur la ligne suivante)
        if (preg_match("/^(?:(\d{4}-\d{2}-\d{2})\s+)?{$time}\s*(?:-|–|—|à|>)\s*{$time}\s*(?:[:|·–—-]\s*)?(.*)$/u", $line, $m)) {
            if ($m[1] !== '') {
                [$date, $previousStart] = [$m[1], null];
            }
            $start = sprintf('%02d:%02d', (int) $m[2], (int) ($m[3] ?: 0));
            $end = sprintf('%02d:%02d', (int) $m[4], (int) ($m[5] ?: 0));
            $startDate = $date;
            if ($previousStart !== null && $start < $previousStart) {
                if ($start >= '08:00') {
                    $errors[] = 'Ligne ' . ($number + 1) . " : {$start} est avant le créneau précédent ({$previousStart}). Mets un titre de jour (ex. « Samedi 14 ») avant.";
                    $pending = null;
                    continue;
                }
                $date = date('Y-m-d', strtotime($date . ' +1 day')); // passage de minuit
                $startDate = $date;
            }
            $previousStart = $start;
            $endDate = $end <= $start ? date('Y-m-d', strtotime($startDate . ' +1 day')) : $startDate;
            $pending = ['starts_at' => "{$startDate} {$start}", 'ends_at' => "{$endDate} {$end}", 'title' => trim($m[6])];
            if ($pending['title'] !== '') {
                $slots[] = $pending;
                $pending = null;
            }
            continue;
        }
        // Jour : « Vendredi 13 », « Samedi 14 novembre », « 2026-11-14 », « 14/11 »
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\b/', $line, $m)
            || preg_match('/^(?:lun|mar|mer|jeu|ven|sam|dim)[a-zé]*\.?\s+(\d{1,2})\b/iu', $line, $m)
            || preg_match('/^(\d{1,2})[\/.](\d{1,2})\b/', $line, $m)) {
            $found = strlen($m[1]) === 10 ? $m[1] : null;
            foreach ($found ? [] : $candidates as $candidate) {
                if ((int) substr($candidate, 8, 2) === (int) $m[1]) {
                    $found = $candidate;
                    break;
                }
            }
            if ($found) {
                [$date, $previousStart] = [$found, null];
            } else {
                $errors[] = 'Ligne ' . ($number + 1) . " : jour « {$line} » hors des dates de la LAN.";
            }
            continue;
        }
        if ($pending !== null) {
            $pending['title'] = $line;
            $slots[] = $pending;
            $pending = null;
        }
        // Les autres lignes (titre du document, remarques…) sont ignorées
    }
    foreach ($slots as &$slot) {
        $slot['title'] = mb_substr($slot['title'], 0, 80);
        $slot['kind'] = kind_from_title($slot['title']);
    }
    return [$slots, $errors];
}

/**
 * Retard : décale ce créneau et tous les suivants de $minutes (négatif pour avancer).
 * Les rappels pas encore « passés » seront renvoyés à la nouvelle heure.
 */
function shift_slots(array $slot, int $minutes): int
{
    $later = db_rows('SELECT * FROM schedule_slots WHERE lan_id = ? AND (starts_at > ? OR id = ?) ',
        [$slot['lan_id'], $slot['starts_at'], $slot['id']]);
    foreach ($later as $item) {
        $start = date('Y-m-d H:i', slot_ts($item['starts_at']) + $minutes * 60);
        $end = date('Y-m-d H:i', slot_ts($item['ends_at']) + $minutes * 60);
        // Le créneau en cours garde son début : seule sa fin bouge
        if ((int) $item['id'] === (int) $slot['id'] && slot_ts($item['starts_at']) <= time()) {
            $start = $item['starts_at'];
        }
        db_exec('UPDATE schedule_slots SET starts_at = ?, ends_at = ? WHERE id = ?', [$start, $end, $item['id']]);
        if (slot_ts($start) > time()) {
            db_exec('DELETE FROM push_log WHERE slot_id = ?', [$item['id']]);
        }
    }
    return count($later);
}

/**
 * Fiche d'un jeu du planning (description, joueurs, points forts / faibles, liens) :
 * celle du pot commun si le créneau y est lié, sinon une recherche Steam / Wikipédia faite une fois puis gardée.
 * Le créneau « jeu préféré » montre la fiche du jeu le mieux noté.
 */
function slot_game_info(array $slot): ?array
{
    if ($slot['kind'] === 'favorite') {
        $favorite = favorite_game(lan_slots((int) $slot['lan_id']));
        return $favorite ? slot_game_info(db_row('SELECT * FROM schedule_slots WHERE id = ?', [$favorite['id']])) : null;
    }
    if ($slot['kind'] !== 'game') {
        return null;
    }
    $game = $slot['proposal_id'] ? db_row('SELECT * FROM proposals WHERE id = ?', [$slot['proposal_id']]) : null;
    if (!$game) {
        $game = json_decode((string) $slot['info'], true);
        if (!is_array($game)) {
            $game = lookup_game($slot['title']);
            if ($game['source'] !== '') {
                db_exec('UPDATE schedule_slots SET info = ? WHERE id = ?', [json_encode($game, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $slot['id']]);
            }
        }
    }
    [$description, $pros, $cons] = game_text_parts($game + ['pros' => '', 'cons' => '']);
    $store = (string) ($game['store_url'] ?? '');
    $steamSearch = 'https://store.steampowered.com/search/?term=' . rawurlencode($slot['kind'] === 'game' ? $slot['title'] : $game['title']);
    return [
        'title' => $slot['title'],
        'image' => (string) ($game['image_url'] ?? ''),
        'description' => $description,
        'pros' => $pros,
        'cons' => $cons,
        'players' => ($game['players'] ?? '') !== '' ? players_label((string) $game['players']) : '',
        'tags' => (string) ($game['tags'] ?? ''),
        'video' => is_youtube_search_url((string) ($game['video_url'] ?? '')) ? '' : (string) ($game['video_url'] ?? ''),
        'store' => $store,
        'steam' => str_contains($store, 'steampowered.com') ? $store : $steamSearch,
        'steam_search' => !str_contains($store, 'steampowered.com'),
        'comment' => (string) ($game['comment'] ?? ''),
    ];
}

/** Créneau en cours ou, à défaut, le prochain (pour le bouton « retard ») */
function current_or_next_slot(int $lanId): ?array
{
    $now = date('Y-m-d H:i');
    return db_row('SELECT * FROM schedule_slots WHERE lan_id = ? AND ends_at > ? ORDER BY starts_at LIMIT 1', [$lanId, $now]);
}

/** LAN à afficher dans le planning : celle demandée, sinon celle dont le planning est en cours ou à venir */
function planning_lan(?int $lanId): ?array
{
    if ($lanId && ($lan = find_lan($lanId))) {
        return $lan;
    }
    $row = db_row("SELECT lan_id FROM schedule_slots WHERE ends_at > ? ORDER BY starts_at LIMIT 1", [date('Y-m-d H:i')])
        ?? db_row('SELECT lan_id FROM schedule_slots ORDER BY starts_at DESC LIMIT 1');
    return $row ? find_lan((int) $row['lan_id']) : (all_lans()[0] ?? null);
}

/** État complet du planning (JSON pour la page) */
function planning_state(array $lan, ?array $user): array
{
    $slots = lan_slots((int) $lan['id'], $user ? (int) $user['id'] : null);
    $favorite = favorite_game($slots);
    $days = [];
    $out = [];
    foreach ($slots as $slot) {
        $day = slot_day($slot['starts_at']);
        $days[$day] ??= ['key' => $day, 'label' => day_label($day), 'from' => $slot['starts_at'], 'to' => $slot['ends_at']];
        $days[$day]['to'] = max($days[$day]['to'], $slot['ends_at']);
        $image = $slot['image_url'];
        if ($slot['kind'] === 'favorite' && $favorite) {
            $image = $favorite['image_url'];
        }
        $out[] = [
            'id' => (int) $slot['id'],
            'day' => $day,
            'start' => slot_ts($slot['starts_at']) * 1000,
            'end' => slot_ts($slot['ends_at']) * 1000,
            'from' => substr($slot['starts_at'], 11, 5),
            'to' => substr($slot['ends_at'], 11, 5),
            'kind' => $slot['kind'],
            'title' => slot_title($slot, $favorite),
            'label' => $slot['title'],
            'note' => $slot['note'],
            'image' => (string) $image,
            'avg' => $slot['avg_stars'] === null ? null : round((float) $slot['avg_stars'], 1),
            'ratings' => (int) $slot['ratings'],
            'mine' => $slot['my_stars'] === null ? null : (int) $slot['my_stars'],
        ];
    }
    foreach ($days as &$day) {
        $day['hours'] = str_replace(':', 'h', substr($day['from'], 11, 5)) . ' – ' . str_replace(':', 'h', substr($day['to'], 11, 5));
        unset($day['from'], $day['to']);
    }
    return [
        'now' => (int) round(microtime(true) * 1000),
        'lan' => ['id' => (int) $lan['id'], 'title' => $lan['title'], 'dates' => format_lan_dates($lan)],
        'remind' => (int) $lan['remind_minutes'],
        'slots' => $out,
        'days' => array_values($days),
        'favorite' => $favorite ? ['title' => $favorite['title'], 'avg' => $favorite['avg'], 'ratings' => (int) $favorite['ratings']] : null,
        'user' => $user ? ['name' => $user['name']] : null,
        'admin' => is_admin(),
        'csrf' => csrf_token(),
        'vapid' => vapid_keys()['public'],
    ];
}

/* ---------- Rappels push ---------- */

/** Message d'un rappel (« bientôt » ou « c'est parti ») pour un créneau */
function slot_message(array $slot, string $kind, ?array $favorite, ?array $previous, int $remind): array
{
    $title = slot_title($slot, $favorite);
    $from = str_replace(':', 'h', substr($slot['starts_at'], 11, 5));
    $to = str_replace(':', 'h', substr($slot['ends_at'], 11, 5));
    $isBreak = $slot['kind'] === 'break';
    if ($kind === 'soon') {
        return [
            'title' => ($isBreak ? '⏸️ ' : '⏰ ') . "Dans {$remind} min : {$title}",
            'body' => $isBreak ? "À {$from}." : "Terminez la partie en cours : {$title} commence à {$from}." . ($slot['note'] !== '' ? ' ' . $slot['note'] : ''),
            'tag' => 'slot-' . $slot['id'],
            'url' => 'planning.php',
        ];
    }
    $rate = $previous && $previous['kind'] === 'game' ? "\n⭐ Pense à noter {$previous['title']} !" : '';
    $prefix = $slot['kind'] === 'favorite' ? '🏆 Revanche : ' : ($isBreak ? '⏸️ ' : '🎮 C\'est parti : ');
    return [
        'title' => $prefix . $title,
        'body' => ($isBreak ? "Jusqu'à {$to}." : "{$from} – {$to}" . ($slot['note'] !== '' ? ' · ' . $slot['note'] : '')) . $rate,
        'tag' => 'slot-' . $slot['id'],
        'url' => 'planning.php' . ($rate ? '#noter-' . $previous['id'] : ''),
    ];
}

/**
 * Envoie les rappels arrivés à échéance. Appelée par la tâche planifiée (cron.php, chaque minute)
 * et, en secours, par les pages Planning ouvertes (au plus toutes les 20 secondes).
 * Un rappel en retard de plus de 15 minutes n'est plus envoyé (pas d'avalanche à l'activation).
 */
function push_dispatch(bool $force = false): int
{
    $now = time();
    if (!$force && (int) setting('push_last_run') > $now - 20) {
        return 0;
    }
    set_setting('push_last_run', (string) $now);
    if (!db_row('SELECT 1 FROM push_subscriptions LIMIT 1')) {
        return 0;
    }
    $sent = 0;
    $window = date('Y-m-d H:i', $now - 16 * 60);
    $horizon = date('Y-m-d H:i', $now + 61 * 60);
    $claim = db()->prepare('INSERT OR IGNORE INTO push_log (slot_id, kind) VALUES (?, ?)');
    foreach (db_rows('SELECT DISTINCT s.lan_id, l.remind_minutes FROM schedule_slots s JOIN lans l ON l.id = s.lan_id
                      WHERE s.starts_at BETWEEN ? AND ?', [$window, $horizon]) as $lan) {
        $slots = lan_slots((int) $lan['lan_id']);
        $favorite = favorite_game($slots);
        $remind = (int) $lan['remind_minutes'];
        foreach ($slots as $i => $slot) {
            $start = slot_ts($slot['starts_at']);
            $dues = ['start' => $start];
            if ($remind > 0) {
                $dues['soon'] = $start - $remind * 60;
            }
            foreach ($dues as $kind => $due) {
                if ($due > $now || $due < $now - 15 * 60) {
                    continue;
                }
                $claim->execute([$slot['id'], $kind]);
                if ($claim->rowCount() === 0) {
                    continue; // déjà envoyé (ou en cours d'envoi par une autre requête)
                }
                // Pas de « bientôt » si le « c'est parti » est déjà dû
                if ($kind === 'soon' && $start <= $now) {
                    continue;
                }
                $previous = $slots[$i - 1] ?? null;
                if ($previous && abs(slot_ts($previous['ends_at']) - $start) > 30 * 60) {
                    $previous = null; // pas de « pense à noter » pour un jeu fini depuis longtemps
                }
                $sent += push_to_all(slot_message($slot, $kind, $favorite, $previous, $remind));
            }
        }
    }
    return $sent;
}
