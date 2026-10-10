<?php
declare(strict_types=1);

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
        CREATE TABLE IF NOT EXISTS games (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            normalized  TEXT NOT NULL UNIQUE,
            steam_appid INTEGER UNIQUE,
            description TEXT NOT NULL DEFAULT '',
            tags        TEXT NOT NULL DEFAULT '',
            image_url   TEXT NOT NULL DEFAULT '',
            video_url   TEXT NOT NULL DEFAULT '',
            store_url   TEXT NOT NULL DEFAULT '',
            proposed_by TEXT NOT NULL,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS votes (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id          INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
            voter_name       TEXT NOT NULL,
            voter_normalized TEXT NOT NULL UNIQUE,
            token            TEXT NOT NULL,
            created_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        SQL);

    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function proposals_open(): bool
{
    return setting('proposals_open', '1') === '1';
}

function votes_open(): bool
{
    return setting('votes_open', '1') === '1';
}

/** @return list<array<string, mixed>> */
function all_games(): array
{
    return db()->query('SELECT * FROM games ORDER BY title COLLATE NOCASE')->fetchAll();
}

/**
 * Jeu déjà enregistré sous le même nom (normalisé) ou la même fiche Steam.
 */
function find_duplicate(string $title, ?int $steamAppId): ?array
{
    $stmt = db()->prepare('SELECT * FROM games WHERE normalized = ? OR (steam_appid IS NOT NULL AND steam_appid = ?) LIMIT 1');
    $stmt->execute([normalize_key($title), $steamAppId]);
    return $stmt->fetch() ?: null;
}

/**
 * Jeux dont le nom ressemble fortement (fautes de frappe, sous-titres…).
 *
 * @return list<array<string, mixed>>
 */
function find_similar(string $title, int $max = 3): array
{
    $key = normalize_key($title);
    if (strlen($key) < 3) {
        return [];
    }
    $scored = [];
    foreach (all_games() as $game) {
        $score = similarity($key, $game['normalized']);
        if ($score >= 0.75 && $game['normalized'] !== $key) {
            $scored[] = [$score, $game];
        }
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($s) => $s[1], array_slice($scored, 0, $max));
}
