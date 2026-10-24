<?php
declare(strict_types=1);

/*
 * Moteur du bingo : sessions, tirages (manuel, au hasard, automatique), phases, gagnants,
 * cartons numérotés (générés à partir d'une graine : le carton n°12 est toujours le même) et vérification.
 */

const GAME_THEMES = ['nuit' => 'Nuit étoilée', 'fete' => 'Fête', 'casino' => 'Casino', 'clair' => 'Clair'];
// Thèmes réservés aux administrateurs (logo et couleurs réglés dans l'administration)
const ADMIN_THEMES = ['saintmartin' => 'École Saint-Martin'];
const CARDS_PER_SHEET_MAX = 6;
const AUTO_MIN_INTERVAL = 4;
const AUTO_MAX_INTERVAL = 120;

function game_max(array $game): int
{
    return (int) $game['type'] === 75 ? 75 : 90;
}

/** Lettre B-I-N-G-O d'un numéro (bingo 75) */
function bingo_letter(int $n): string
{
    return 'BINGO'[intdiv(max(1, min(75, $n)) - 1, 15)];
}

/** Phases par défaut : quine, double quine, carton plein (ligne / deux lignes / carton plein en 75). */
function default_phases(int $type): array
{
    return $type === 75
        ? [['name' => 'Une ligne', 'prize' => ''], ['name' => 'Deux lignes', 'prize' => ''], ['name' => 'Carton plein', 'prize' => '']]
        : [['name' => 'Quine', 'prize' => ''], ['name' => 'Double quine', 'prize' => ''], ['name' => 'Carton plein', 'prize' => '']];
}

/** @return list<array{name: string, prize: string}> */
function game_phases(array $game): array
{
    $phases = json_decode((string) $game['phases'], true);
    return is_array($phases) && $phases ? $phases : default_phases((int) $game['type']);
}

/** Texte « Quine | Panier garni » (une phase par ligne) → liste de phases */
function parse_phases(string $text): array
{
    $phases = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        [$name, $prize] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        if ($name !== '') {
            $phases[] = ['name' => mb_substr(clean_line($name, 40), 0, 40), 'prize' => clean_line($prize, 80)];
        }
    }
    return array_slice($phases, 0, 12);
}

function phases_to_text(array $phases): string
{
    return implode("\n", array_map(fn($p) => $p['name'] . ($p['prize'] !== '' ? ' | ' . $p['prize'] : ''), $phases));
}

/** Thèmes proposés : les thèmes réservés seulement aux admins (ou s'ils sont déjà appliqués à la session). */
function available_themes(?array $user, ?array $game = null): array
{
    $themes = GAME_THEMES;
    foreach (ADMIN_THEMES as $key => $label) {
        if (($user && (int) $user['is_admin']) || ($game && $game['theme'] === $key)) {
            $themes[$key] = $label;
        }
    }
    return $themes;
}

/** Thème « École Saint-Martin » : titre, couleurs et logo (envoyé par un admin, rangé dans le dossier privé). */
function school_theme(): array
{
    $logo = school_logo_path();
    return [
        'title' => setting('school_title', 'Bingo École Saint-Martin'),
        'color1' => setting('school_color1', '#1d4f91'),
        'color2' => setting('school_color2', '#e2232e'),
        'logo' => $logo ? 'logo.php?v=' . filemtime($logo) : null,
    ];
}

function school_logo_path(): ?string
{
    foreach (['png', 'jpg', 'webp', 'gif'] as $ext) {
        $path = config('private_dir') . '/theme/logo.' . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/** Variables CSS et attributs du thème d'une session (pour la balise body) */
function theme_style(string $theme): string
{
    if ($theme !== 'saintmartin') {
        return '';
    }
    $school = school_theme();
    return '--sm-1: ' . $school['color1'] . '; --sm-2: ' . $school['color2'] . ';';
}

function find_game(int $id): ?array
{
    return db_row('SELECT * FROM games WHERE id = ?', [$id]);
}

function can_manage(array $game, ?array $user): bool
{
    return $user !== null && ((int) $game['owner_id'] === (int) $user['id'] || (int) $user['is_admin']);
}

function create_game(array $user, string $name, int $type): int
{
    $type = $type === 75 ? 75 : 90;
    db_exec('INSERT INTO games (owner_id, name, type, phases, display_token, card_seed) VALUES (?, ?, ?, ?, ?, ?)', [
        $user['id'], $name, $type, json_encode(default_phases($type), JSON_UNESCAPED_UNICODE), random_token(12), random_token(8),
    ]);
    return (int) db()->lastInsertId();
}

/** @return list<array{n: int, src: string}> */
function game_draws(int $gameId): array
{
    return array_map(fn($r) => ['n' => (int) $r['number'], 'src' => $r['source']],
        db_rows('SELECT number, source FROM draws WHERE game_id = ? ORDER BY id', [$gameId]));
}

/** Enregistre une modification : la révision change, les écrans se mettent à jour. */
function touch_game(int $gameId, array $set = []): void
{
    $sql = 'UPDATE games SET revision = revision + 1, updated_at = CURRENT_TIMESTAMP';
    foreach (array_keys($set) as $column) {
        $sql .= ", {$column} = ?";
    }
    db_exec($sql . ' WHERE id = ?', [...array_values($set), $gameId]);
}

/**
 * Sort un numéro (manuel) ou une boule au hasard ($number = null). Renvoie un message d'erreur ou null.
 * L'incrustation « carton » / « bingo » disparaît de l'écran dès la boule suivante.
 */
function draw_number(array $game, ?int $number, string $source): ?string
{
    $max = game_max($game);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $drawn = array_map('intval', array_column(db_rows('SELECT number FROM draws WHERE game_id = ?', [$game['id']]), 'number'));
        if ($number === null) {
            $left = array_values(array_diff(range(1, $max), $drawn));
            if (!$left) {
                $pdo->exec('ROLLBACK');
                return 'Toutes les boules sont sorties.';
            }
            $number = $left[random_int(0, count($left) - 1)];
        } elseif ($number < 1 || $number > $max) {
            $pdo->exec('ROLLBACK');
            return "Numéro hors limites (1 à {$max}).";
        } elseif (in_array($number, $drawn, true)) {
            $pdo->exec('ROLLBACK');
            return "Le {$number} est déjà sorti.";
        }
        db_exec('INSERT INTO draws (game_id, number, source) VALUES (?, ?, ?)', [$game['id'], $number, $source]);
        $overlay = json_decode((string) $game['overlay'], true);
        $set = (is_array($overlay) && ($overlay['kind'] ?? '') !== 'message') ? ['overlay' => ''] : [];
        if (count($drawn) + 1 >= $max) {
            $set['auto_running'] = 0;
        }
        touch_game((int) $game['id'], $set);
        $pdo->exec('COMMIT');
        return null;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Tirage automatique piloté par le serveur : à chaque consultation de l'état (écran ou panneau, ~1 fois par seconde),
 * si l'échéance est passée, une boule sort. Plusieurs écrans ouverts ne provoquent jamais de double tirage.
 */
function auto_tick(array $game): array
{
    if (!(int) $game['auto_running'] || microtime(true) < (float) $game['auto_next_at']) {
        return $game;
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $fresh = find_game((int) $game['id']);
    $due = $fresh && (int) $fresh['auto_running'] && microtime(true) >= (float) $fresh['auto_next_at'];
    if ($due) {
        db_exec('UPDATE games SET auto_next_at = ? WHERE id = ?', [microtime(true) + (int) $fresh['auto_interval'], $fresh['id']]);
    }
    $pdo->exec('COMMIT');
    if ($due) {
        draw_number(find_game((int) $game['id']), null, 'auto');
    }
    return find_game((int) $game['id']);
}

/** État complet envoyé à l'écran de projection et au panneau (JSON). */
function game_state(array $game, bool $manager): array
{
    $draws = game_draws((int) $game['id']);
    $phases = game_phases($game);
    $index = min((int) $game['phase_index'], count($phases) - 1);
    $overlay = json_decode((string) $game['overlay'], true) ?: null;
    if ($overlay && $overlay['kind'] === 'card') {
        $overlay['check'] = check_card($game, (int) $overlay['card'], array_column($draws, 'n'));
    }
    $state = [
        'rev' => (int) $game['revision'],
        'name' => $game['name'],
        'type' => (int) $game['type'],
        'max' => game_max($game),
        'mode' => $game['draw_mode'],
        'theme' => $game['theme'],
        'voice' => (bool) $game['voice'],
        'suspense' => (bool) $game['suspense'],
        'round' => (int) $game['round'],
        'draws' => array_column($draws, 'n'),
        'last_src' => $draws ? end($draws)['src'] : null,
        'phase' => ['index' => $index, 'count' => count($phases)] + $phases[$index],
        'phases' => $phases,
        'auto' => [
            'running' => (bool) $game['auto_running'],
            'interval' => (int) $game['auto_interval'],
            'next_in' => $game['auto_running'] ? max(0, (int) round(((float) $game['auto_next_at'] - microtime(true)) * 1000)) : null,
        ],
        'overlay' => $overlay,
        'winners' => array_map(fn($w) => [
            'id' => (int) $w['id'], 'phase' => $w['phase'], 'prize' => $w['prize'], 'name' => $w['name'],
            'card' => $w['card_no'] === null ? null : (int) $w['card_no'], 'draws' => (int) $w['draws_count'],
            'time' => date('H\hi', (int) strtotime($w['created_at'] . ' UTC')),
        ], db_rows('SELECT * FROM winners WHERE game_id = ? ORDER BY id', [$game['id']])),
    ];
    if ($game['theme'] === 'saintmartin') {
        $state['school'] = school_theme();
    }
    if ($manager) {
        $state['id'] = (int) $game['id'];
        $state['interval'] = (int) $game['auto_interval'];
        $state['next_card'] = next_card_number((int) $game['id']);
        $state['runs'] = array_map(fn($r) => [
            'id' => (int) $r['id'], 'per' => (int) $r['per_sheet'], 'sheets' => (int) $r['sheets'],
            'from' => (int) $r['first_card'], 'to' => (int) $r['last_card'], 'date' => local_time($r['created_at']),
        ], db_rows('SELECT * FROM print_runs WHERE game_id = ? ORDER BY id', [$game['id']]));
    }
    return $state;
}

/* ---------- Cartons et planches ---------- */

/** Premier numéro de grille libre : les séries imprimées se suivent sans jamais réutiliser un numéro. */
function next_card_number(int $gameId): int
{
    return (int) (db_row('SELECT MAX(last_card) AS m FROM print_runs WHERE game_id = ?', [$gameId])['m'] ?? 0) + 1;
}

/** Nouvelle série : $sheets planches de $perSheet grilles. Renvoie la série ou un message d'erreur. */
function create_print_run(array $game, int $sheets, int $perSheet): array|string
{
    $perSheet = max(1, min(CARDS_PER_SHEET_MAX, $perSheet));
    $first = next_card_number((int) $game['id']);
    $last = $first + $sheets * $perSheet - 1;
    if ($sheets < 1 || $sheets > 1000) {
        return 'Nombre de planches invalide (1 à 1000).';
    }
    if ($last > (int) config('max_cards')) {
        return 'Trop de grilles : le numéro maximum est ' . (int) config('max_cards') . '.';
    }
    db_exec('INSERT INTO print_runs (game_id, per_sheet, sheets, first_card, last_card) VALUES (?, ?, ?, ?, ?)',
        [$game['id'], $perSheet, $sheets, $first, $last]);
    return ['from' => $first, 'to' => $last, 'per' => $perSheet];
}

/**
 * Mise en page d'une planche A4 (zone utile 190 × 264 mm sous l'en-tête) : nombre de colonnes et taille d'une case (mm),
 * en choisissant la disposition qui donne les plus grandes cases.
 */
function sheet_layout(int $type, int $perSheet): array
{
    $best = ['cols' => 1, 'cell' => 0.0];
    foreach ($type === 75 ? [1, 2] : [1] as $cols) {
        $rows = (int) ceil($perSheet / $cols);
        $ticketHeight = (264 - ($rows - 1) * 5) / $rows;
        $ticketWidth = (190 - ($cols - 1) * 6) / $cols;
        $cell = $type === 75
            ? min(24, ($ticketHeight - 15) / 6, ($ticketWidth - 8) / 5)
            : min(20, ($ticketHeight - 15) / 3);
        if ($cell > $best['cell']) {
            $best = ['cols' => $cols, 'cell' => round($cell, 1)];
        }
    }
    return $best;
}

/** Générateur pseudo-aléatoire déterministe (graine de la session + numéro du carton). */
function seeded_rng(string $seed): Closure
{
    $state = hash('sha256', $seed, true);
    $offset = 0;
    return function (int $max) use (&$state, &$offset): int {
        if ($offset + 4 > strlen($state)) {
            $state = hash('sha256', $state, true);
            $offset = 0;
        }
        $value = unpack('N', substr($state, $offset, 4))[1];
        $offset += 4;
        return $value % $max;
    };
}

function seeded_shuffle(array $items, Closure $rng): array
{
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = $rng($i + 1);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

/**
 * Grille d'un carton : liste de lignes, chaque case = numéro, null (case vide) ou 0 (case libre au centre en 75).
 * 90 : 3 lignes × 9 colonnes, 15 numéros, 5 par ligne, colonne 1 = 1-9, colonne 2 = 10-19… colonne 9 = 80-90.
 * 75 : 5 × 5, colonne B = 1-15, I = 16-30, N = 31-45, G = 46-60, O = 61-75, centre libre.
 */
function card_grid(array $game, int $cardNo): array
{
    $rng = seeded_rng($game['card_seed'] . ':' . (int) $game['type'] . ':' . $cardNo);
    if ((int) $game['type'] === 75) {
        $grid = array_fill(0, 5, array_fill(0, 5, null));
        for ($c = 0; $c < 5; $c++) {
            $numbers = array_slice(seeded_shuffle(range($c * 15 + 1, $c * 15 + 15), $rng), 0, 5);
            for ($r = 0; $r < 5; $r++) {
                $grid[$r][$c] = $numbers[$r];
            }
        }
        $grid[2][2] = 0;
        return $grid;
    }

    // Nombre de numéros par colonne : 1 partout, puis 6 de plus (3 maximum par colonne) → 15
    $counts = array_fill(0, 9, 1);
    for ($added = 0; $added < 6;) {
        $c = $rng(9);
        if ($counts[$c] < 3) {
            $counts[$c]++;
            $added++;
        }
    }
    // Placement : 5 numéros par ligne (les colonnes les plus remplies d'abord, dans les lignes les moins pleines)
    for ($attempt = 0; ; $attempt++) {
        $left = [5, 5, 5];
        $layout = array_fill(0, 3, array_fill(0, 9, false));
        $order = seeded_shuffle(range(0, 8), $rng);
        usort($order, fn($a, $b) => $counts[$b] <=> $counts[$a]);
        $ok = true;
        foreach ($order as $c) {
            $rows = seeded_shuffle([0, 1, 2], $rng);
            usort($rows, fn($a, $b) => $left[$b] <=> $left[$a]);
            foreach (array_slice($rows, 0, $counts[$c]) as $r) {
                if ($left[$r] === 0) {
                    $ok = false;
                }
                $left[$r]--;
                $layout[$r][$c] = true;
            }
        }
        if ($ok || $attempt > 20) {
            break;
        }
    }
    $grid = array_fill(0, 3, array_fill(0, 9, null));
    for ($c = 0; $c < 9; $c++) {
        $range = $c === 0 ? range(1, 9) : ($c === 8 ? range(80, 90) : range($c * 10, $c * 10 + 9));
        $numbers = array_slice(seeded_shuffle($range, $rng), 0, $counts[$c]);
        sort($numbers);
        foreach ([0, 1, 2] as $r) {
            if ($layout[$r][$c]) {
                $grid[$r][$c] = array_shift($numbers);
            }
        }
    }
    return $grid;
}

/**
 * Vérification d'un carton : lignes complètes (90 : lignes horizontales ; 75 : lignes, colonnes, diagonales),
 * carton plein, numéros manquants.
 */
function check_card(array $game, int $cardNo, array $drawn): array
{
    $grid = card_grid($game, $cardNo);
    $set = array_flip(array_filter($drawn, 'is_int'));
    $isMarked = fn($n) => $n === 0 || ($n !== null && isset($set[$n]));
    $lines = [];
    foreach ($grid as $r => $row) {
        $cells = array_filter($row, fn($n) => $n !== null);
        $lines[] = ['label' => 'Ligne ' . ($r + 1), 'done' => count(array_filter($cells, $isMarked)) === count($cells)];
    }
    if ((int) $game['type'] === 75) {
        for ($c = 0; $c < 5; $c++) {
            $lines[] = ['label' => 'Colonne ' . 'BINGO'[$c], 'done' => count(array_filter(array_column($grid, $c), $isMarked)) === 5];
        }
        $lines[] = ['label' => 'Diagonale ↘', 'done' => count(array_filter([0, 1, 2, 3, 4], fn($i) => $isMarked($grid[$i][$i]))) === 5];
        $lines[] = ['label' => 'Diagonale ↙', 'done' => count(array_filter([0, 1, 2, 3, 4], fn($i) => $isMarked($grid[$i][4 - $i]))) === 5];
    }
    $numbers = array_values(array_filter(array_merge(...$grid), fn($n) => $n !== null && $n !== 0));
    $missing = array_values(array_filter($numbers, fn($n) => !isset($set[$n])));
    sort($missing);
    $done = array_values(array_filter($lines, fn($l) => $l['done']));
    return [
        'card' => $cardNo,
        'grid' => $grid,
        'lines' => count($done),
        'line_labels' => array_column($done, 'label'),
        'full' => !$missing,
        'missing' => $missing,
        'marked' => count($numbers) - count($missing),
        'total' => count($numbers),
    ];
}
