<?php
declare(strict_types=1);

/*
 * Pages : espace « team » (visible et modifiable par tous les comptes) ou « private » (son propriétaire seul).
 * Une sous-page hérite toujours de l'espace de sa racine.
 */

const PAGE_LIST_COLUMNS = 'id, space, owner_id, parent_id, position, title, icon, updated_at, share_token IS NOT NULL AS shared';

/** Condition SQL « page visible par cet utilisateur » (2 paramètres : rien, puis l'id). */
function visible_sql(string $alias = 'pages'): string
{
    return "({$alias}.space = 'team' OR ({$alias}.space = 'private' AND {$alias}.owner_id = ?))";
}

function find_page(array $user, int $id, bool $withTrashed = false): ?array
{
    return db_row('SELECT * FROM pages WHERE id = ? AND ' . visible_sql() . ($withTrashed ? '' : ' AND trashed_at IS NULL'),
        [$id, $user['id']]);
}

/** @return list<array<string, mixed>> Toutes les pages visibles (hors corbeille), pour la barre latérale. */
function page_tree(array $user): array
{
    return db_rows('SELECT ' . PAGE_LIST_COLUMNS . ' FROM pages WHERE trashed_at IS NULL AND ' . visible_sql()
        . ' ORDER BY position, id', [$user['id']]);
}

/** @return list<int> Identifiants de la page et de toutes ses descendantes. */
function page_subtree_ids(int $id): array
{
    $rows = db_rows('WITH RECURSIVE t(id) AS (SELECT ? UNION ALL SELECT p.id FROM pages p JOIN t ON p.parent_id = t.id)
                     SELECT id FROM t', [$id]);
    return array_map(fn($r) => (int) $r['id'], $rows);
}

function in_clause(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

function next_position(?int $parentId, string $space, int $ownerId): float
{
    $max = db_row('SELECT MAX(position) AS m FROM pages WHERE parent_id IS ? AND space = ? AND (space = \'team\' OR owner_id = ?)',
        [$parentId, $space, $ownerId]);
    return (float) ($max['m'] ?? 0) + 1;
}

function create_page(array $user, string $space, ?int $parentId, string $title = '', string $icon = ''): array
{
    if ($parentId !== null) {
        $parent = find_page($user, $parentId);
        if (!$parent) {
            throw new RuntimeException('Page parente introuvable.');
        }
        $space = $parent['space'];
    }
    $space = $space === 'private' ? 'private' : 'team';
    $ownerId = $space === 'private' ? (int) $user['id'] : null;
    db_exec('INSERT INTO pages (space, owner_id, parent_id, position, title, icon, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $space, $ownerId, $parentId, next_position($parentId, $space, (int) $user['id']), $title, $icon, $user['id'], $user['id'],
    ]);
    return find_page($user, (int) db()->lastInsertId());
}

/**
 * Déplace une page (et ses sous-pages) sous un autre parent ou à la racine d'un espace.
 * $beforeId : page devant laquelle se placer (null = à la fin).
 */
function move_page(array $user, array $page, ?int $parentId, string $space, ?int $beforeId): void
{
    if ($parentId !== null) {
        if (in_array($parentId, page_subtree_ids((int) $page['id']), true)) {
            throw new RuntimeException('Impossible de déplacer une page dans une de ses sous-pages.');
        }
        $parent = find_page($user, $parentId);
        if (!$parent) {
            throw new RuntimeException('Destination introuvable.');
        }
        $space = $parent['space'];
    }
    $space = $space === 'private' ? 'private' : 'team';

    $position = next_position($parentId, $space, (int) $user['id']);
    if ($beforeId && ($before = find_page($user, $beforeId))) {
        $previous = db_row('SELECT MAX(position) AS m FROM pages WHERE parent_id IS ? AND space = ? AND position < ? AND id != ?',
            [$parentId, $space, $before['position'], $page['id']]);
        $position = (((float) ($previous['m'] ?? ((float) $before['position'] - 1))) + (float) $before['position']) / 2;
    }

    db()->beginTransaction();
    db_exec('UPDATE pages SET parent_id = ?, position = ? WHERE id = ?', [$parentId, $position, $page['id']]);
    if ($space !== $page['space']) {
        $ids = page_subtree_ids((int) $page['id']);
        db_exec('UPDATE pages SET space = ?, owner_id = ? WHERE id IN (' . in_clause($ids) . ')',
            [$space, $space === 'private' ? $user['id'] : null, ...$ids]);
    }
    db()->commit();
}

/** Corbeille : la page et ses sous-pages, avec la même date (pour les restaurer ensemble). */
function trash_page(array $page): void
{
    $ids = page_subtree_ids((int) $page['id']);
    db_exec('UPDATE pages SET trashed_at = ? WHERE trashed_at IS NULL AND id IN (' . in_clause($ids) . ')',
        [gmdate('Y-m-d H:i:s'), ...$ids]);
}

function restore_page(array $page): void
{
    $ids = page_subtree_ids((int) $page['id']);
    db_exec('UPDATE pages SET trashed_at = NULL WHERE trashed_at = ? AND id IN (' . in_clause($ids) . ')', [$page['trashed_at'], ...$ids]);
    db_exec('UPDATE pages SET trashed_at = NULL WHERE id = ?', [$page['id']]);
    // Parent toujours à la corbeille (ou disparu) : la page revient à la racine
    if ($page['parent_id'] && !db_row('SELECT 1 FROM pages WHERE id = ? AND trashed_at IS NULL', [$page['parent_id']])) {
        db_exec('UPDATE pages SET parent_id = NULL WHERE id = ?', [$page['id']]);
    }
}

/** Suppression définitive (sous-pages comprises) + fichiers qui ne servent plus. */
function delete_page_forever(array $page): void
{
    $ids = page_subtree_ids((int) $page['id']);
    $files = db_rows('SELECT stored_name FROM files WHERE page_id IN (' . in_clause($ids) . ')', $ids);
    db_exec('DELETE FROM pages WHERE id = ?', [$page['id']]); // cascade : sous-pages, favoris, fichiers
    foreach ($files as $file) {
        if (!db_row('SELECT 1 FROM files WHERE stored_name = ?', [$file['stored_name']])) {
            @unlink(config('files_dir') . '/' . $file['stored_name']);
        }
    }
}

/** @return list<array<string, mixed>> Pages à la corbeille (racines seulement). */
function trashed_pages(array $user): array
{
    return db_rows('SELECT p.id, p.title, p.icon, p.space, p.trashed_at FROM pages p
                    LEFT JOIN pages parent ON parent.id = p.parent_id
                    WHERE p.trashed_at IS NOT NULL AND (parent.id IS NULL OR parent.trashed_at IS NULL OR parent.trashed_at != p.trashed_at)
                    AND ' . visible_sql('p') . ' ORDER BY p.trashed_at DESC', [$user['id']]);
}

function duplicate_page(array $user, array $page): array
{
    db_exec('INSERT INTO pages (space, owner_id, parent_id, position, title, icon, cover, content, search_text, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $page['space'], $page['owner_id'], $page['parent_id'], (float) $page['position'] + 0.5,
        trim($page['title'] . ' (copie)'), $page['icon'], $page['cover'], $page['content'], $page['search_text'], $user['id'], $user['id'],
    ]);
    $copyId = (int) db()->lastInsertId();
    // Les fichiers restent accessibles depuis la copie (même fichier sur le disque)
    db_exec('INSERT INTO files (uid, stored_name, page_id, user_id, name, mime, size)
             SELECT lower(hex(randomblob(16))), stored_name, ?, user_id, name, mime, size FROM files WHERE page_id = ?', [$copyId, $page['id']]);
    $copy = find_page($user, $copyId);
    // Les liens file.php?f=… pointent vers les fichiers de la copie
    $map = [];
    foreach (db_rows('SELECT a.uid AS old, b.uid AS new FROM files a JOIN files b ON a.stored_name = b.stored_name
                      WHERE a.page_id = ? AND b.page_id = ?', [$page['id'], $copyId]) as $row) {
        $map['file.php?f=' . $row['old']] = 'file.php?f=' . $row['new'];
    }
    if ($map) {
        db_exec('UPDATE pages SET content = ?, cover = ? WHERE id = ?', [strtr($copy['content'], $map), strtr($copy['cover'], $map), $copyId]);
        $copy = find_page($user, $copyId);
    }
    return $copy;
}

/** @return list<array<string, mixed>> */
function search_pages(array $user, string $query, int $limit = 20): array
{
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
    $rows = db_rows('SELECT id, title, icon, space, search_text, updated_at FROM pages
                     WHERE trashed_at IS NULL AND ' . visible_sql() . " AND (title LIKE ? ESCAPE '\\' OR search_text LIKE ? ESCAPE '\\')
                     ORDER BY (title LIKE ? ESCAPE '\\') DESC, updated_at DESC LIMIT {$limit}",
        [$user['id'], $like, $like, $like]);
    foreach ($rows as &$row) {
        $pos = mb_stripos($row['search_text'], $query);
        $row['snippet'] = $pos === false ? mb_substr($row['search_text'], 0, 120)
            : ($pos > 40 ? '…' : '') . mb_substr($row['search_text'], max(0, $pos - 40), 140);
        unset($row['search_text']);
    }
    return $rows;
}

/** Chemin (fil d'Ariane) d'une page : [racine, …, parent]. */
function page_breadcrumb(array $user, array $page): array
{
    $crumbs = [];
    $parentId = $page['parent_id'];
    while ($parentId && count($crumbs) < 20 && ($parent = find_page($user, (int) $parentId))) {
        array_unshift($crumbs, ['id' => (int) $parent['id'], 'title' => $parent['title'], 'icon' => $parent['icon']]);
        $parentId = $parent['parent_id'];
    }
    return $crumbs;
}

/** Données d'une page pour l'éditeur. */
function page_payload(array $user, array $page): array
{
    $author = $page['updated_by'] ? find_user((int) $page['updated_by']) : null;
    return [
        'id' => (int) $page['id'],
        'space' => $page['space'],
        'parentId' => $page['parent_id'] ? (int) $page['parent_id'] : null,
        'title' => $page['title'],
        'icon' => $page['icon'],
        'cover' => $page['cover'],
        'content' => $page['content'] !== '' ? json_decode($page['content'], true) : ['blocks' => []],
        'revision' => (int) $page['revision'],
        'updatedAt' => local_time($page['updated_at']),
        'updatedBy' => $author['name'] ?? '',
        'shareUrl' => $page['share_token'] ? app_url('share.php?t=' . $page['share_token']) : '',
        'favorite' => (bool) db_row('SELECT 1 FROM favorites WHERE user_id = ? AND page_id = ?', [$user['id'], $page['id']]),
        'breadcrumb' => page_breadcrumb($user, $page),
        'trashed' => $page['trashed_at'] !== null,
    ];
}
