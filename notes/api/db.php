<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * API des bases de données. Lecture : GET ?action=get&id=…  — Modifications : POST JSON + X-CSRF-Token.
 */

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$user = require_api_user($isPost);
$in = $isPost ? json_input() : $_GET;
$action = (string) ($in['action'] ?? '');

function database_or_404(array $user, int $id): array
{
    $db = find_page($user, $id);
    if (!$db || $db['kind'] !== 'database') {
        json_response(['error' => 'Base de données introuvable.'], 404);
    }
    return $db;
}

function row_or_404(array $user, int $id): array
{
    $row = find_page($user, $id);
    if (!$row || $row['kind'] !== 'row') {
        json_response(['error' => 'Ligne introuvable.'], 404);
    }
    return $row;
}

function row_json(array $row): array
{
    return [
        'id' => (int) $row['id'], 'title' => $row['title'], 'icon' => $row['icon'],
        'props' => $row['props'] !== '' ? (json_decode($row['props'], true) ?: (object) []) : (object) [],
        'position' => (float) $row['position'], 'createdAt' => $row['created_at'], 'updatedAt' => local_time($row['updated_at']),
    ];
}

switch ($action) {
    case 'get':
        $db = database_or_404($user, (int) ($in['id'] ?? 0));
        json_response([
            'config' => db_config($db),
            'rows' => db_rows_of((int) $db['id']),
            'users' => array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name']], db_rows('SELECT id, name FROM users ORDER BY name COLLATE NOCASE')),
            'icalUrl' => $db['ical_token'] ? app_url('ical.php?t=' . $db['ical_token']) : '',
        ]);

    case 'config':
        $db = database_or_404($user, (int) ($in['id'] ?? 0));
        $config = sanitize_db_config(is_array($in['config'] ?? null) ? $in['config'] : []);
        db_exec('UPDATE pages SET db_config = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [json_encode($config, JSON_UNESCAPED_UNICODE), $user['id'], $db['id']]);
        json_response(['config' => $config]);

    case 'row_create':
        $db = database_or_404($user, (int) ($in['dbId'] ?? 0));
        $row = create_row($user, $db, (string) ($in['title'] ?? ''), is_array($in['props'] ?? null) ? $in['props'] : []);
        json_response(['row' => row_json($row)]);

    case 'row_update':
        $row = row_or_404($user, (int) ($in['id'] ?? 0));
        $db = find_page($user, (int) $row['parent_id']);
        if (!$db) {
            json_response(['error' => 'Base de données introuvable.'], 404);
        }
        $config = db_config($db);
        $current = $row['props'] !== '' ? (json_decode($row['props'], true) ?: []) : [];
        // Fusion champ par champ : deux personnes peuvent modifier deux colonnes différentes en même temps
        $props = merge_row_props($config, $current, is_array($in['props'] ?? null) ? $in['props'] : []);
        $title = array_key_exists('title', $in) ? clean_line((string) $in['title'], 200) : $row['title'];
        db_exec('UPDATE pages SET title = ?, props = ?, search_text = ?, revision = revision + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$title, json_encode($props, JSON_UNESCAPED_UNICODE), row_search_text($config, $props, $row['content']), $user['id'], $row['id']]);
        json_response(['row' => row_json(find_page($user, (int) $row['id']))]);

    case 'row_move':
        // Réordonne une ligne : se place avant la ligne $beforeId (ou à la fin)
        $row = row_or_404($user, (int) ($in['id'] ?? 0));
        $siblings = db_rows("SELECT id, position FROM pages WHERE parent_id = ? AND kind = 'row' AND trashed_at IS NULL AND id != ? ORDER BY position, id",
            [$row['parent_id'], $row['id']]);
        $beforeId = (int) ($in['beforeId'] ?? 0);
        $index = array_search($beforeId, array_map(fn($s) => (int) $s['id'], $siblings), true);
        if ($index === false) {
            $position = ($siblings ? (float) end($siblings)['position'] : 0) + 1;
        } else {
            $next = (float) $siblings[$index]['position'];
            $prev = $index > 0 ? (float) $siblings[$index - 1]['position'] : $next - 1;
            $position = ($prev + $next) / 2;
        }
        db_exec('UPDATE pages SET position = ? WHERE id = ?', [$position, $row['id']]);
        json_response(['ok' => true, 'position' => $position]);

    case 'row_trash':
        trash_page(row_or_404($user, (int) ($in['id'] ?? 0)));
        json_response(['ok' => true]);

    case 'ical':
        $db = database_or_404($user, (int) ($in['id'] ?? 0));
        $token = !empty($in['on']) ? ($db['ical_token'] ?: random_token(16)) : null;
        db_exec('UPDATE pages SET ical_token = ? WHERE id = ?', [$token, $db['id']]);
        json_response(['icalUrl' => $token ? app_url('ical.php?t=' . $token) : '']);

    default:
        json_response(['error' => 'Action inconnue.'], 400);
}
