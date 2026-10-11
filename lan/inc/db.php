<?php
declare(strict_types=1);

const DEFAULT_VOTE_OPTIONS = [
    ['Je veux', 5],
    ["J'adore", 3],
    ["J'aime bien", 1],
    ['À voir', 0],
    ["J'aime pas", -3],
    ['Je déteste', -5],
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path = config('db_path');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT NOT NULL,
            email          TEXT NOT NULL UNIQUE,
            password_hash  TEXT NOT NULL,
            remember_token TEXT,
            created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        -- Liste personnelle de chaque joueur
        CREATE TABLE IF NOT EXISTS list_games (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title       TEXT NOT NULL,
            normalized  TEXT NOT NULL,
            steam_appid INTEGER,
            description TEXT NOT NULL DEFAULT '',
            tags        TEXT NOT NULL DEFAULT '',
            image_url   TEXT NOT NULL DEFAULT '',
            video_url   TEXT NOT NULL DEFAULT '',
            store_url   TEXT NOT NULL DEFAULT '',
            comment     TEXT NOT NULL DEFAULT '',
            players     TEXT NOT NULL DEFAULT '',
            pros        TEXT NOT NULL DEFAULT '',   -- points forts, un par ligne
            cons        TEXT NOT NULL DEFAULT '',   -- points faibles, un par ligne
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, normalized)
        );
        CREATE TABLE IF NOT EXISTS lans (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            title          TEXT NOT NULL,
            lan_date       TEXT NOT NULL,              -- jour de début (tri, compatibilité)
            starts_at      TEXT NOT NULL DEFAULT '',   -- 'AAAA-MM-JJ HH:MM'
            ends_at        TEXT NOT NULL DEFAULT '',
            proposals_open INTEGER NOT NULL DEFAULT 1,
            votes_open     INTEGER NOT NULL DEFAULT 0,
            created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        -- Pot commun d'une LAN : copie du jeu au moment où il est proposé
        CREATE TABLE IF NOT EXISTS proposals (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            lan_id       INTEGER NOT NULL REFERENCES lans(id) ON DELETE CASCADE,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            list_game_id INTEGER REFERENCES list_games(id) ON DELETE SET NULL,
            title        TEXT NOT NULL,
            normalized   TEXT NOT NULL,
            steam_appid  INTEGER,
            description  TEXT NOT NULL DEFAULT '',
            tags         TEXT NOT NULL DEFAULT '',
            image_url    TEXT NOT NULL DEFAULT '',
            video_url    TEXT NOT NULL DEFAULT '',
            store_url    TEXT NOT NULL DEFAULT '',
            comment      TEXT NOT NULL DEFAULT '',
            players      TEXT NOT NULL DEFAULT '',
            pros         TEXT NOT NULL DEFAULT '',
            cons         TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (lan_id, normalized),
            UNIQUE (lan_id, steam_appid)
        );
        -- Réglages modifiables depuis l'admin (clé OpenAI, modèle…)
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        -- Options de vote paramétrables (libellé + points)
        CREATE TABLE IF NOT EXISTS vote_options (
            id     INTEGER PRIMARY KEY AUTOINCREMENT,
            label  TEXT NOT NULL,
            points INTEGER NOT NULL
        );
        -- Note donnée par une personne à un jeu du pot commun (une par jeu et par personne)
        CREATE TABLE IF NOT EXISTS lan_ratings (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            lan_id      INTEGER NOT NULL REFERENCES lans(id) ON DELETE CASCADE,
            proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            option_id   INTEGER NOT NULL REFERENCES vote_options(id) ON DELETE CASCADE,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (proposal_id, user_id)
        );
        SQL);

    // Colonnes ajoutées après coup : mise à jour des bases déjà créées
    add_missing_column($pdo, 'list_games', 'comment', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'proposals', 'comment', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'list_games', 'players', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'proposals', 'players', "TEXT NOT NULL DEFAULT ''");
    foreach (['list_games', 'proposals'] as $table) {
        add_missing_column($pdo, $table, 'pros', "TEXT NOT NULL DEFAULT ''");
        add_missing_column($pdo, $table, 'cons', "TEXT NOT NULL DEFAULT ''");
    }
    add_missing_column($pdo, 'lans', 'starts_at', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'lans', 'ends_at', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("UPDATE lans SET starts_at = lan_date WHERE starts_at = ''");
    $pdo->exec("UPDATE lans SET ends_at = starts_at WHERE ends_at = ''");

    // Options de vote par défaut (modifiables ensuite dans la page Résultats)
    if ((int) $pdo->query('SELECT COUNT(*) FROM vote_options')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO vote_options (label, points) VALUES (?, ?)');
        foreach (DEFAULT_VOTE_OPTIONS as [$label, $points]) {
            $insert->execute([$label, $points]);
        }
    }

    return $pdo;
}

function add_missing_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/** @return list<array<string, mixed>> */
function db_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec(string $sql, array $params = []): void
{
    db()->prepare($sql)->execute($params);
}

// --- Réglages -----------------------------------------------------------

function setting(string $key, string $default = ''): string
{
    $value = db_row('SELECT value FROM settings WHERE key = ?', [$key]);
    return $value === null ? $default : (string) $value['value'];
}

function set_setting(string $key, ?string $value): void
{
    if ($value === null) {
        db_exec('DELETE FROM settings WHERE key = ?', [$key]);
        return;
    }
    db_exec('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$key, $value]);
}

// --- LAN ---------------------------------------------------------------

function find_lan(int $id): ?array
{
    return db_row('SELECT * FROM lans WHERE id = ?', [$id]);
}

/** @return list<array<string, mixed>> LAN en cours / à venir d'abord, puis les passées (plus récentes d'abord). */
function all_lans(): array
{
    return db_rows("SELECT * FROM lans
                    ORDER BY substr(ends_at, 1, 10) < date('now', 'localtime'),
                             CASE WHEN substr(ends_at, 1, 10) >= date('now', 'localtime') THEN starts_at END ASC,
                             starts_at DESC");
}

/** @return list<array<string, mixed>> Options de vote, de la plus positive à la plus négative. */
function vote_options(): array
{
    return db_rows('SELECT * FROM vote_options ORDER BY points DESC, id');
}

/** @return list<array<string, mixed>> */
function lans_with_open(string $phase): array
{
    $column = $phase === 'votes' ? 'votes_open' : 'proposals_open';
    return array_values(array_filter(all_lans(), fn($lan) => (int) $lan[$column] === 1));
}

/** LAN demandée dans l'URL, sinon la prochaine à venir (ou la plus récente). */
function selected_lan(array $candidates): ?array
{
    $id = (int) ($_GET['lan'] ?? 0);
    foreach ($candidates as $lan) {
        if ((int) $lan['id'] === $id) {
            return $lan;
        }
    }
    return $candidates[0] ?? null;
}

/** @return list<array<string, mixed>> Pot commun d'une LAN, avec le nom de la personne qui a proposé. */
function lan_proposals(int $lanId): array
{
    return db_rows('SELECT p.*, u.name AS proposed_by FROM proposals p JOIN users u ON u.id = p.user_id
                    WHERE p.lan_id = ? ORDER BY p.title COLLATE NOCASE', [$lanId]);
}

/** Jeu déjà dans le pot commun d'une LAN (même nom normalisé ou même fiche Steam). */
function find_lan_duplicate(int $lanId, string $title, ?int $steamAppId): ?array
{
    return db_row('SELECT p.*, u.name AS proposed_by FROM proposals p JOIN users u ON u.id = p.user_id
                   WHERE p.lan_id = ? AND (p.normalized = ? OR (p.steam_appid IS NOT NULL AND p.steam_appid = ?))
                   LIMIT 1', [$lanId, normalize_key($title), $steamAppId]);
}

// --- Liste personnelle -------------------------------------------------

/** @return list<array<string, mixed>> */
function user_games(int $userId): array
{
    return db_rows('SELECT * FROM list_games WHERE user_id = ? ORDER BY title COLLATE NOCASE', [$userId]);
}

function find_user_game(int $userId, int $id): ?array
{
    return db_row('SELECT * FROM list_games WHERE user_id = ? AND id = ?', [$userId, $id]);
}

function find_list_duplicate(int $userId, string $title, ?int $steamAppId, int $excludeId = 0): ?array
{
    return db_row('SELECT * FROM list_games
                   WHERE user_id = ? AND id != ? AND (normalized = ? OR (steam_appid IS NOT NULL AND steam_appid = ?))
                   LIMIT 1', [$userId, $excludeId, normalize_key($title), $steamAppId]);
}

/**
 * Jeux dont le nom ressemble fortement (fautes de frappe, sous-titres…).
 *
 * @param list<array<string, mixed>> $games
 * @return list<array<string, mixed>>
 */
function find_similar(array $games, string $title, int $max = 3): array
{
    $key = normalize_key($title);
    if (strlen($key) < 3) {
        return [];
    }
    $scored = [];
    foreach ($games as $game) {
        $score = similarity($key, $game['normalized']);
        if ($score >= 0.75 && $game['normalized'] !== $key) {
            $scored[] = [$score, $game];
        }
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($s) => $s[1], array_slice($scored, 0, $max));
}

/** Proposition du pot commun qui correspond à ce jeu (même nom ou même fiche Steam). */
function matching_proposal(array $pot, array $game): ?array
{
    foreach ($pot as $proposal) {
        if ($proposal['normalized'] === $game['normalized']
            || ($game['steam_appid'] !== null && (int) $proposal['steam_appid'] === (int) $game['steam_appid'])) {
            return $proposal;
        }
    }
    return null;
}

/**
 * Tous les jeux de toutes les listes, regroupés quand plusieurs personnes ont le même jeu.
 * Chaque groupe garde la fiche la plus ancienne et la liste des propriétaires.
 *
 * @return list<array<string, mixed>>
 */
function all_list_games_grouped(): array
{
    $groups = [];
    foreach (db_rows('SELECT g.*, u.name AS owner FROM list_games g JOIN users u ON u.id = g.user_id
                      ORDER BY g.created_at, g.id') as $game) {
        foreach ($groups as &$group) {
            if (matching_proposal([$group], $game)) {
                $group['owners'][] = $game['owner'];
                $group['steam_appid'] ??= $game['steam_appid'];
                continue 2;
            }
        }
        unset($group);
        $groups[] = $game + ['owners' => [$game['owner']]];
    }
    unset($group);
    usort($groups, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    return $groups;
}

/**
 * Envoie un jeu de la liste personnelle dans le pot commun d'une LAN.
 * $force : ajout par l'organisateur, même si les propositions sont fermées.
 *
 * @return array{0: bool, 1: string} [succès, message]
 */
function propose_to_lan(array $user, array $game, array $lan, bool $force = false): array
{
    if (!$force && !(int) $lan['proposals_open']) {
        return [false, "Les propositions sont fermées pour « {$lan['title']} »."];
    }
    $steamAppId = $game['steam_appid'] !== null ? (int) $game['steam_appid'] : null;
    if ($dup = find_lan_duplicate((int) $lan['id'], $game['title'], $steamAppId)) {
        $who = !$force && (int) $dup['user_id'] === (int) $user['id'] ? 'toi' : $dup['proposed_by'];
        return [false, "« {$dup['title']} » est déjà dans le pot commun de « {$lan['title']} » (proposé par {$who})."];
    }
    try {
        db_exec('INSERT INTO proposals (lan_id, user_id, list_game_id, title, normalized, steam_appid,
                                        description, tags, image_url, video_url, store_url, comment, players, pros, cons)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $lan['id'], $user['id'], $game['id'], $game['title'], $game['normalized'], $steamAppId,
            $game['description'], $game['tags'], $game['image_url'], $game['video_url'], $game['store_url'], $game['comment'],
            $game['players'], $game['pros'], $game['cons'],
        ]);
    } catch (PDOException) {
        return [false, "« {$game['title']} » vient d'être proposé par quelqu'un d'autre."];
    }
    return [true, "« {$game['title']} » ajouté au pot commun de « {$lan['title']} »."];
}
