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
        -- Pages : espace « team » (tout le monde) ou « private » (owner_id seulement)
        CREATE TABLE IF NOT EXISTS pages (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            space       TEXT NOT NULL DEFAULT 'team',
            owner_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
            parent_id   INTEGER REFERENCES pages(id) ON DELETE CASCADE,
            position    REAL NOT NULL DEFAULT 0,
            title       TEXT NOT NULL DEFAULT '',
            icon        TEXT NOT NULL DEFAULT '',
            cover       TEXT NOT NULL DEFAULT '',
            content     TEXT NOT NULL DEFAULT '',
            search_text TEXT NOT NULL DEFAULT '',
            revision    INTEGER NOT NULL DEFAULT 0,
            share_token TEXT UNIQUE,
            created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trashed_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS pages_parent ON pages(parent_id);
        CREATE TABLE IF NOT EXISTS favorites (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, page_id)
        );
        -- Fichiers envoyés (images, pièces jointes), stockés hors du web
        CREATE TABLE IF NOT EXISTS files (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            uid         TEXT NOT NULL UNIQUE,
            stored_name TEXT NOT NULL,
            page_id     INTEGER REFERENCES pages(id) ON DELETE CASCADE,
            user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            name        TEXT NOT NULL,
            mime        TEXT NOT NULL,
            size        INTEGER NOT NULL,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        -- Historique : état d'une page avant modification
        CREATE TABLE IF NOT EXISTS page_versions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id    INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
            title      TEXT NOT NULL,
            icon       TEXT NOT NULL,
            cover      TEXT NOT NULL,
            content    TEXT NOT NULL,
            author_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS versions_page ON page_versions(page_id, id);
        SQL);

    // Colonnes ajoutées après coup : mise à jour des bases existantes
    add_missing_column($pdo, 'pages', 'is_template', 'INTEGER NOT NULL DEFAULT 0');
    // Bases de données : kind = page | database | row
    add_missing_column($pdo, 'pages', 'kind', "TEXT NOT NULL DEFAULT 'page'");
    add_missing_column($pdo, 'pages', 'props', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'pages', 'db_config', "TEXT NOT NULL DEFAULT ''");
    add_missing_column($pdo, 'pages', 'ical_token', 'TEXT');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS pages_ical ON pages(ical_token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS pages_kind ON pages(parent_id, kind)');
    seed_templates();
    seed_db_templates();

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
