<?php
declare(strict_types=1);

/*
 * Pages : espace « team » (visible et modifiable par tous les comptes) ou « private » (son propriétaire seul).
 * Une sous-page hérite toujours de l'espace de sa racine.
 */

const PAGE_LIST_COLUMNS = 'id, space, owner_id, parent_id, position, title, icon, updated_at, share_token IS NOT NULL AS shared, is_template, kind';

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
    // Les lignes de bases de données ne figurent pas dans la barre latérale
    return db_rows('SELECT ' . PAGE_LIST_COLUMNS . " FROM pages WHERE trashed_at IS NULL AND kind != 'row' AND " . visible_sql()
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

/** Remplace {{date}}, {{heure}} et {{auteur}} dans un modèle. */
function fill_template(string $text, array $user): string
{
    return strtr($text, ['{{date}}' => date('d/m/Y'), '{{auteur}}' => $user['name'], '{{heure}}' => date('H\hi')]);
}

/** Nouvelle page à partir d'un modèle (contenu, icône et couverture copiés). */
function create_page_from_template(array $user, array $template, string $space, ?int $parentId): array
{
    $page = create_page($user, $space, $parentId, fill_template($template['title'], $user), $template['icon'], $template['kind']);
    $content = $template['content'] !== '' ? fill_template($template['content'], $user) : '';
    $decoded = $content !== '' ? json_decode($content, true) : null;
    db_exec('UPDATE pages SET cover = ?, content = ?, search_text = ?, db_config = ? WHERE id = ?',
        [$template['cover'], $decoded ? $content : '', $decoded ? blocks_to_text($decoded) : '', $template['db_config'], $page['id']]);
    $page = find_page($user, (int) $page['id']);
    if ($template['kind'] === 'database') {
        copy_rows((int) $template['id'], $page, $user, true);
    }
    return $page;
}

/** Modèles fournis, créés une seule fois (modifiables ou supprimables ensuite). */
function seed_templates(): void
{
    if (setting('templates_seeded') === '1') {
        return;
    }
    set_setting('templates_seeded', '1');
    $templates = [
        ['📋', 'Réunion du {{date}}', <<<MD
            **Date :** {{date}} · **Rédigé par :** {{auteur}}

            ## Participants
            - …

            ## Ordre du jour
            1. …

            ## Discussion

            ## Décisions
            - …

            ## Actions
            - [ ] Action — responsable — échéance
            MD],
        ['🎪', 'Nouvel événement', <<<MD
            **Client :** … · **Date :** … · **Lieu :** …

            ## Informations
            - Contact sur place :
            - Horaires montage / démontage :
            - Accès / stationnement :

            ## Matériel
            - [ ] Chapiteaux
            - [ ] Tables et chaises
            - [ ] Éclairage
            - [ ] Plancher

            ## Équipe
            - …

            ## Checklist
            - [ ] Devis signé
            - [ ] Acompte reçu
            - [ ] Repérage fait
            - [ ] Montage
            - [ ] Démontage
            - [ ] Facture envoyée

            ## Notes
            MD],
        ['🚀', 'Nouveau projet', <<<MD
            ## Objectif
            Qu'est-ce qu'on veut obtenir, et pour quand ?

            ## Contexte

            ## Étapes
            - [ ] Étape 1
            - [ ] Étape 2
            - [ ] Étape 3

            ## Ressources
            - …

            ## Risques et questions
            - …
            MD],
        ['📔', 'Journal du {{date}}', <<<MD
            ## Aujourd'hui
            - …

            ## Idées
            - …

            ## À faire demain
            - [ ] …
            MD],
        ['📘', 'Procédure', <<<MD
            ## Objectif
            À quoi sert cette procédure ?

            ## Étapes
            1. Première étape
            2. Deuxième étape
            3. Troisième étape

            ## Points d'attention
            > À ne pas oublier…
            MD],
    ];
    foreach ($templates as $i => [$icon, $title, $markdown]) {
        $blocks = markdown_to_blocks($markdown);
        db_exec("INSERT INTO pages (space, title, icon, content, search_text, position, is_template) VALUES ('team', ?, ?, ?, ?, ?, 1)",
            [$title, $icon, json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE), blocks_to_text(['blocks' => $blocks]), $i + 1]);
    }
}

/** Modèles de bases de données fournis (créés une seule fois). */
function seed_db_templates(): void
{
    if (setting('db_templates_seeded') === '1') {
        return;
    }
    set_setting('db_templates_seeded', '1');
    $opt = fn(string $id, string $name, string $color) => ['id' => $id, 'name' => $name, 'color' => $color];
    $templates = [
        ['✅', 'Tâches', [
            'properties' => [
                ['id' => 'status', 'name' => 'Statut', 'type' => 'select', 'options' => [$opt('todo', 'À faire', 'gray'), $opt('doing', 'En cours', 'blue'), $opt('done', 'Fait', 'green')]],
                ['id' => 'priority', 'name' => 'Priorité', 'type' => 'select', 'options' => [$opt('high', 'Haute', 'red'), $opt('medium', 'Moyenne', 'orange'), $opt('low', 'Basse', 'gray')]],
                ['id' => 'due', 'name' => 'Échéance', 'type' => 'date', 'withTime' => false],
                ['id' => 'owner', 'name' => 'Responsable', 'type' => 'person'],
            ],
            'views' => [
                ['id' => 'v_board', 'name' => 'Kanban', 'type' => 'board', 'groupBy' => 'status'],
                ['id' => 'v_table', 'name' => 'Tableau', 'type' => 'table', 'sort' => ['prop' => 'due', 'dir' => 'asc']],
                ['id' => 'v_cal', 'name' => 'Calendrier', 'type' => 'calendar', 'dateProp' => 'due'],
            ],
        ], [
            ['Exemple : préparer la réunion', ['status' => 'todo', 'priority' => 'high']],
            ['Exemple : commander le matériel', ['status' => 'doing', 'priority' => 'medium']],
        ]],
        ['🎪', 'Événements', [
            'properties' => [
                ['id' => 'status', 'name' => 'Statut', 'type' => 'select', 'options' => [
                    $opt('quote', 'Devis', 'gray'), $opt('confirmed', 'Confirmé', 'blue'), $opt('setup', 'Monté', 'orange'),
                    $opt('dismantled', 'Démonté', 'purple'), $opt('invoiced', 'Facturé', 'green')]],
                ['id' => 'dates', 'name' => 'Dates', 'type' => 'date', 'withTime' => false],
                ['id' => 'client', 'name' => 'Client', 'type' => 'text'],
                ['id' => 'place', 'name' => 'Lieu', 'type' => 'text'],
                ['id' => 'amount', 'name' => 'Montant (€)', 'type' => 'number'],
                ['id' => 'owner', 'name' => 'Responsable', 'type' => 'person'],
            ],
            'views' => [
                ['id' => 'v_cal', 'name' => 'Calendrier', 'type' => 'calendar', 'dateProp' => 'dates'],
                ['id' => 'v_board', 'name' => 'Suivi', 'type' => 'board', 'groupBy' => 'status'],
                ['id' => 'v_table', 'name' => 'Tableau', 'type' => 'table', 'sort' => ['prop' => 'dates', 'dir' => 'asc']],
            ],
        ], [
            ['Exemple : mariage', ['status' => 'quote', 'client' => 'Famille Exemple', 'place' => 'Namur', 'amount' => 1500]],
        ]],
        ['📦', 'Inventaire', [
            'properties' => [
                ['id' => 'category', 'name' => 'Catégorie', 'type' => 'select', 'options' => [
                    $opt('tents', 'Chapiteaux', 'blue'), $opt('furniture', 'Mobilier', 'brown'), $opt('light', 'Éclairage', 'yellow'), $opt('other', 'Autre', 'gray')]],
                ['id' => 'qty', 'name' => 'Quantité', 'type' => 'number'],
                ['id' => 'state', 'name' => 'État', 'type' => 'select', 'options' => [$opt('ok', 'Bon', 'green'), $opt('repair', 'À réparer', 'orange'), $opt('out', 'Hors service', 'red')]],
                ['id' => 'where', 'name' => 'Emplacement', 'type' => 'text'],
            ],
            'views' => [
                ['id' => 'v_table', 'name' => 'Tableau', 'type' => 'table', 'sort' => ['prop' => 'title', 'dir' => 'asc']],
                ['id' => 'v_board', 'name' => 'Par état', 'type' => 'board', 'groupBy' => 'state'],
            ],
        ], [
            ['Exemple : tente 6×12 m', ['category' => 'tents', 'qty' => 2, 'state' => 'ok', 'where' => 'Dépôt']],
        ]],
    ];
    $position = 10;
    foreach ($templates as [$icon, $title, $config, $rows]) {
        db_exec("INSERT INTO pages (space, title, icon, kind, db_config, position, is_template) VALUES ('team', ?, ?, 'database', ?, ?, 1)",
            [$title, $icon, json_encode($config, JSON_UNESCAPED_UNICODE), $position++]);
        $dbId = (int) db()->lastInsertId();
        foreach ($rows as $i => [$rowTitle, $props]) {
            db_exec("INSERT INTO pages (space, parent_id, title, kind, props, search_text, position) VALUES ('team', ?, ?, 'row', ?, ?, ?)",
                [$dbId, $rowTitle, json_encode($props, JSON_UNESCAPED_UNICODE), $rowTitle, $i + 1]);
        }
    }
}

function create_page(array $user, string $space, ?int $parentId, string $title = '', string $icon = '', string $kind = 'page'): array
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
    $kind = $kind === 'database' ? 'database' : 'page';
    db_exec('INSERT INTO pages (space, owner_id, parent_id, position, title, icon, kind, db_config, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $space, $ownerId, $parentId, next_position($parentId, $space, (int) $user['id']), $title, $icon, $kind,
        $kind === 'database' ? json_encode(default_db_config(), JSON_UNESCAPED_UNICODE) : '', $user['id'], $user['id'],
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
    db_exec('INSERT INTO pages (space, owner_id, parent_id, position, title, icon, cover, content, search_text, kind, props, db_config, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $page['space'], $page['owner_id'], $page['parent_id'], (float) $page['position'] + 0.5,
        trim($page['title'] . ' (copie)'), $page['icon'], $page['cover'], $page['content'], $page['search_text'],
        $page['kind'], $page['props'], $page['db_config'], $user['id'], $user['id'],
    ]);
    $copyId = (int) db()->lastInsertId();
    if ($page['kind'] === 'database') {
        copy_rows((int) $page['id'], find_page($user, $copyId), $user);
    }
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
        'isTemplate' => (bool) $page['is_template'],
        'kind' => $page['kind'],
    ] + match ($page['kind']) {
        'database' => ['config' => db_config($page), 'icalUrl' => $page['ical_token'] ? app_url('ical.php?t=' . $page['ical_token']) : ''],
        'row' => row_payload($user, $page),
        default => [],
    };
}

/** Une ligne de base de données : ses valeurs + la configuration de sa base. */
function row_payload(array $user, array $page): array
{
    $db = $page['parent_id'] ? find_page($user, (int) $page['parent_id'], true) : null;
    return [
        'props' => $page['props'] !== '' ? (json_decode($page['props'], true) ?: (object) []) : (object) [],
        'db' => $db ? ['id' => (int) $db['id'], 'title' => $db['title'], 'icon' => $db['icon'], 'config' => db_config($db)] : null,
        'users' => array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name']], db_rows('SELECT id, name FROM users ORDER BY name COLLATE NOCASE')),
    ];
}

// --- Historique des versions -------------------------------------------

const VERSION_EVERY_SECONDS = 600; // une version au plus toutes les 10 minutes par auteur
const VERSIONS_KEPT = 100;

/**
 * Avant d'enregistrer une modification : garde l'état actuel de la page comme version,
 * sauf si la dernière version est récente et du même auteur (évite une version par frappe).
 */
function snapshot_before_save(array $page, array $user): void
{
    if ($page['content'] === '' && $page['title'] === '') {
        return; // page neuve, rien à garder
    }
    $last = db_row('SELECT author_id, created_at FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT 1', [$page['id']]);
    $lastEditor = (int) ($page['updated_by'] ?? 0);
    $recent = $last && strtotime($last['created_at'] . ' UTC') > time() - VERSION_EVERY_SECONDS;
    if ($recent && $lastEditor === (int) $user['id']) {
        return;
    }
    db_exec('INSERT INTO page_versions (page_id, title, icon, cover, content, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$page['id'], $page['title'], $page['icon'], $page['cover'], $page['content'], $page['updated_by'], $page['updated_at']]);
    // Ne garde que les N dernières versions
    db_exec('DELETE FROM page_versions WHERE page_id = ? AND id NOT IN (SELECT id FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT ' . VERSIONS_KEPT . ')',
        [$page['id'], $page['id']]);
}

/** @return list<array<string, mixed>> */
function page_versions(int $pageId): array
{
    return array_map(fn($v) => [
        'id' => (int) $v['id'], 'title' => $v['title'], 'icon' => $v['icon'],
        'author' => $v['author'] ?? '', 'date' => local_time($v['created_at']),
    ], db_rows('SELECT v.id, v.title, v.icon, v.created_at, u.name AS author FROM page_versions v
                LEFT JOIN users u ON u.id = v.author_id WHERE v.page_id = ? ORDER BY v.id DESC', [$pageId]));
}
