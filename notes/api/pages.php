<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * API des pages (JSON). Lecture : GET ?action=…  — Modification : POST JSON {action, …} + en-tête X-CSRF-Token.
 */

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$user = require_api_user($isPost);
$in = $isPost ? json_input() : $_GET;
$action = (string) ($in['action'] ?? '');
$id = (int) ($in['id'] ?? 0);

/** Arbre + favoris (après chaque changement de structure). */
function sidebar_data(array $user): array
{
    return [
        'tree' => array_map(fn($p) => [
            'id' => (int) $p['id'], 'parentId' => $p['parent_id'] ? (int) $p['parent_id'] : null, 'space' => $p['space'],
            'title' => $p['title'], 'icon' => $p['icon'], 'shared' => (bool) $p['shared'], 'isTemplate' => (bool) $p['is_template'],
        ], page_tree($user)),
        'favorites' => array_map(fn($r) => (int) $r['page_id'],
            db_rows('SELECT f.page_id FROM favorites f JOIN pages p ON p.id = f.page_id WHERE f.user_id = ? AND p.trashed_at IS NULL ORDER BY f.created_at', [$user['id']])),
    ];
}

function page_or_404(array $user, int $id, bool $withTrashed = false): array
{
    $page = find_page($user, $id, $withTrashed);
    if (!$page) {
        json_response(['error' => 'Page introuvable (supprimée, ou privée à quelqu\'un d\'autre).'], 404);
    }
    return $page;
}

try {
    switch ($action) {
        case 'sidebar':
            json_response(sidebar_data($user));

        case 'get':
            json_response(['page' => page_payload($user, page_or_404($user, $id, true))]);

        case 'recent':
            json_response(['pages' => array_map(fn($p) => $p + ['updated' => local_time($p['updated_at'])],
                db_rows('SELECT p.id, p.title, p.icon, p.space, p.updated_at, u.name AS updated_by FROM pages p
                         LEFT JOIN users u ON u.id = p.updated_by
                         WHERE p.trashed_at IS NULL AND ' . visible_sql('p') . ' ORDER BY p.updated_at DESC LIMIT 12', [$user['id']]))]);

        case 'search':
            $q = clean_line((string) ($in['q'] ?? ''), 100);
            json_response(['results' => $q === '' ? [] : search_pages($user, $q)]);

        case 'trash_list':
            json_response(['pages' => array_map(fn($p) => $p + ['trashed' => local_time($p['trashed_at'])], trashed_pages($user))]);

        case 'create':
            $parentId = isset($in['parentId']) ? (int) $in['parentId'] : null;
            if (!empty($in['templateId'])) {
                $page = create_page_from_template($user, page_or_404($user, (int) $in['templateId']), (string) ($in['space'] ?? 'team'), $parentId);
            } else {
                $page = create_page($user, (string) ($in['space'] ?? 'team'), $parentId, clean_line((string) ($in['title'] ?? ''), 200));
            }
            json_response(['page' => page_payload($user, $page)] + sidebar_data($user));

        case 'save':
            $page = page_or_404($user, $id);
            // Conflit : quelqu'un d'autre a enregistré entre-temps
            if (empty($in['force']) && (int) ($in['revision'] ?? -1) !== (int) $page['revision']) {
                $other = $page['updated_by'] ? find_user((int) $page['updated_by']) : null;
                json_response(['error' => 'conflict', 'updatedBy' => $other['name'] ?? '?', 'updatedAt' => local_time($page['updated_at'])], 409);
            }
            $fields = [];
            if (array_key_exists('title', $in)) {
                $fields['title'] = clean_line((string) $in['title'], 200);
            }
            if (array_key_exists('icon', $in)) {
                $fields['icon'] = mb_substr(strip_tags((string) $in['icon']), 0, 8);
            }
            if (array_key_exists('cover', $in)) {
                $cover = (string) $in['cover'];
                $fields['cover'] = preg_match('/^gradient-[1-8]$/', $cover) ? $cover : safe_url($cover);
            }
            if (isset($in['content']) && is_array($in['content'])) {
                $content = sanitize_content($in['content']);
                $fields['content'] = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $fields['search_text'] = mb_substr(blocks_to_text($content), 0, 200000);
            }
            if ($fields) {
                snapshot_before_save($page, $user);
                $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($fields)));
                db_exec("UPDATE pages SET {$sets}, revision = revision + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                    [...array_values($fields), $user['id'], $page['id']]);
            }
            $page = find_page($user, $id);
            json_response(['revision' => (int) $page['revision'], 'updatedAt' => local_time($page['updated_at'])]
                + (isset($fields['title']) || isset($fields['icon']) ? sidebar_data($user) : []));

        case 'move':
            $page = page_or_404($user, $id);
            move_page($user, $page, isset($in['parentId']) ? (int) $in['parentId'] : null,
                (string) ($in['space'] ?? $page['space']), isset($in['beforeId']) ? (int) $in['beforeId'] : null);
            json_response(['ok' => true] + sidebar_data($user));

        case 'favorite':
            $page = page_or_404($user, $id);
            if (!empty($in['on'])) {
                db_exec('INSERT OR IGNORE INTO favorites (user_id, page_id) VALUES (?, ?)', [$user['id'], $page['id']]);
            } else {
                db_exec('DELETE FROM favorites WHERE user_id = ? AND page_id = ?', [$user['id'], $page['id']]);
            }
            json_response(['ok' => true] + sidebar_data($user));

        case 'duplicate':
            $copy = duplicate_page($user, page_or_404($user, $id));
            json_response(['page' => page_payload($user, $copy)] + sidebar_data($user));

        case 'trash':
            trash_page(page_or_404($user, $id));
            json_response(['ok' => true] + sidebar_data($user));

        case 'restore':
            restore_page(page_or_404($user, $id, true));
            json_response(['ok' => true] + sidebar_data($user));

        case 'delete':
            $page = page_or_404($user, $id, true);
            if ($page['trashed_at'] === null) {
                json_response(['error' => 'Mets d\'abord la page à la corbeille.'], 400);
            }
            delete_page_forever($page);
            json_response(['ok' => true]);

        case 'template':
            $page = page_or_404($user, $id);
            db_exec('UPDATE pages SET is_template = ? WHERE id = ?', [empty($in['on']) ? 0 : 1, $page['id']]);
            json_response(['ok' => true] + sidebar_data($user));

        case 'versions':
            page_or_404($user, $id, true);
            json_response(['versions' => page_versions($id)]);

        case 'version':
            page_or_404($user, $id, true);
            $version = db_row('SELECT * FROM page_versions WHERE id = ? AND page_id = ?', [(int) ($in['versionId'] ?? 0), $id]);
            if (!$version) {
                json_response(['error' => 'Version introuvable.'], 404);
            }
            json_response(['version' => [
                'title' => $version['title'], 'icon' => $version['icon'], 'cover' => $version['cover'],
                'content' => $version['content'] !== '' ? json_decode($version['content'], true) : ['blocks' => []],
            ]]);

        case 'restore_version':
            $page = page_or_404($user, $id);
            $version = db_row('SELECT * FROM page_versions WHERE id = ? AND page_id = ?', [(int) ($in['versionId'] ?? 0), $id]);
            if (!$version) {
                json_response(['error' => 'Version introuvable.'], 404);
            }
            // L'état actuel devient lui aussi une version : la restauration est réversible
            db_exec('INSERT INTO page_versions (page_id, title, icon, cover, content, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$page['id'], $page['title'], $page['icon'], $page['cover'], $page['content'], $page['updated_by'], $page['updated_at']]);
            $content = $version['content'] !== '' ? json_decode($version['content'], true) : ['blocks' => []];
            db_exec('UPDATE pages SET title = ?, icon = ?, cover = ?, content = ?, search_text = ?, revision = revision + 1,
                     updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$version['title'], $version['icon'], $version['cover'], $version['content'], blocks_to_text($content ?: []), $user['id'], $page['id']]);
            json_response(['page' => page_payload($user, find_page($user, $id))] + sidebar_data($user));

        case 'share':
            $page = page_or_404($user, $id);
            $token = !empty($in['on']) ? ($page['share_token'] ?: random_token(16)) : null;
            db_exec('UPDATE pages SET share_token = ? WHERE id = ?', [$token, $page['id']]);
            json_response(['shareUrl' => $token ? app_url('share.php?t=' . $token) : ''] + sidebar_data($user));

        default:
            json_response(['error' => 'Action inconnue.'], 400);
    }
} catch (RuntimeException $ex) {
    json_response(['error' => $ex->getMessage()], 400);
}
