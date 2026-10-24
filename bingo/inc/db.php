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
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT NOT NULL,
            email          TEXT NOT NULL UNIQUE,
            password_hash  TEXT NOT NULL,
            is_admin       INTEGER NOT NULL DEFAULT 0,
            remember_token TEXT,
            last_login_at  TEXT,
            created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        -- Liens d'invitation (user_id renseigné = lien de réinitialisation du mot de passe)
        CREATE TABLE IF NOT EXISTS invitations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            label      TEXT NOT NULL DEFAULT '',
            user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        -- Sessions de bingo (une par soirée / salle). revision augmente à chaque changement (écran mis à jour).
        CREATE TABLE IF NOT EXISTS games (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
            name          TEXT NOT NULL,
            type          INTEGER NOT NULL DEFAULT 90,
            draw_mode     TEXT NOT NULL DEFAULT 'manual',
            theme         TEXT NOT NULL DEFAULT 'nuit',
            voice         INTEGER NOT NULL DEFAULT 1,
            suspense      INTEGER NOT NULL DEFAULT 1,
            phases        TEXT NOT NULL DEFAULT '',
            phase_index   INTEGER NOT NULL DEFAULT 0,
            auto_running  INTEGER NOT NULL DEFAULT 0,
            auto_interval INTEGER NOT NULL DEFAULT 10,
            auto_next_at  REAL,
            overlay       TEXT NOT NULL DEFAULT '',
            display_token TEXT NOT NULL UNIQUE,
            card_seed     TEXT NOT NULL,
            round         INTEGER NOT NULL DEFAULT 1,
            revision      INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        -- Boules sorties, dans l'ordre (source : manual, random, auto)
        CREATE TABLE IF NOT EXISTS draws (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
            number     INTEGER NOT NULL,
            source     TEXT NOT NULL DEFAULT 'manual',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (game_id, number)
        );
        CREATE TABLE IF NOT EXISTS winners (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id     INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
            phase       TEXT NOT NULL,
            prize       TEXT NOT NULL DEFAULT '',
            name        TEXT NOT NULL DEFAULT '',
            card_no     INTEGER,
            draws_count INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS winners_game ON winners(game_id, id);
        -- Séries de planches imprimées : numéros de grilles first_card à last_card, per_sheet grilles par planche (A4)
        CREATE TABLE IF NOT EXISTS print_runs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
            per_sheet  INTEGER NOT NULL,
            sheets     INTEGER NOT NULL,
            first_card INTEGER NOT NULL,
            last_card  INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS print_runs_game ON print_runs(game_id, id);
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        SQL);

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

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function setting(string $key, string $default = ''): string
{
    $row = db_row('SELECT value FROM settings WHERE key = ?', [$key]);
    return $row === null ? $default : (string) $row['value'];
}

function set_setting(string $key, ?string $value): void
{
    if ($value === null) {
        db_exec('DELETE FROM settings WHERE key = ?', [$key]);
        return;
    }
    db_exec('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$key, $value]);
}
