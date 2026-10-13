<?php
declare(strict_types=1);

/*
 * Bases de données : une page de type « database » contient une configuration (propriétés + vues),
 * et ses lignes sont des pages de type « row » (enfants de la base), avec leurs valeurs dans `props`.
 */

const PROPERTY_TYPES = ['text', 'number', 'select', 'multi_select', 'date', 'checkbox', 'person', 'url'];
const OPTION_COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
const VIEW_TYPES = ['table', 'board', 'calendar'];
const FILTER_OPS = ['contains', 'not_contains', 'is', 'is_not', 'empty', 'not_empty', 'checked', 'unchecked', 'before', 'after', 'gt', 'lt'];

function short_id(string $prefix): string
{
    return $prefix . substr(random_token(4), 0, 6);
}

/** Configuration d'une nouvelle base : Statut + Date, et trois vues. */
function default_db_config(): array
{
    return [
        'properties' => [
            ['id' => 'status', 'name' => 'Statut', 'type' => 'select', 'options' => [
                ['id' => 'todo', 'name' => 'À faire', 'color' => 'gray'],
                ['id' => 'doing', 'name' => 'En cours', 'color' => 'blue'],
                ['id' => 'done', 'name' => 'Fait', 'color' => 'green'],
            ]],
            ['id' => 'date', 'name' => 'Date', 'type' => 'date', 'withTime' => false],
            ['id' => 'person', 'name' => 'Responsable', 'type' => 'person'],
        ],
        'views' => [
            ['id' => 'v_table', 'name' => 'Tableau', 'type' => 'table'],
            ['id' => 'v_board', 'name' => 'Kanban', 'type' => 'board', 'groupBy' => 'status'],
            ['id' => 'v_cal', 'name' => 'Calendrier', 'type' => 'calendar', 'dateProp' => 'date'],
        ],
    ];
}

function db_config(array $page): array
{
    $config = $page['db_config'] !== '' ? json_decode($page['db_config'], true) : null;
    return is_array($config) ? $config : default_db_config();
}

/** Nettoie la configuration envoyée par le navigateur (types, identifiants, longueurs, références). */
function sanitize_db_config(array $config): array
{
    $props = [];
    $seen = [];
    foreach (array_slice(is_array($config['properties'] ?? null) ? $config['properties'] : [], 0, 40) as $p) {
        $id = (string) ($p['id'] ?? '');
        $type = (string) ($p['type'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,24}$/', $id) || isset($seen[$id]) || !in_array($type, PROPERTY_TYPES, true)) {
            continue;
        }
        $seen[$id] = true;
        $prop = ['id' => $id, 'name' => clean_line(strip_tags((string) ($p['name'] ?? '')), 60) ?: 'Propriété', 'type' => $type];
        if (in_array($type, ['select', 'multi_select'], true)) {
            $prop['options'] = [];
            $optIds = [];
            foreach (array_slice(is_array($p['options'] ?? null) ? $p['options'] : [], 0, 60) as $o) {
                $oid = (string) ($o['id'] ?? '');
                if (!preg_match('/^[a-z0-9_]{1,24}$/', $oid) || isset($optIds[$oid])) {
                    continue;
                }
                $optIds[$oid] = true;
                $prop['options'][] = [
                    'id' => $oid,
                    'name' => clean_line(strip_tags((string) ($o['name'] ?? '')), 40) ?: 'Option',
                    'color' => in_array($o['color'] ?? '', OPTION_COLORS, true) ? $o['color'] : 'gray',
                ];
            }
        }
        if ($type === 'date') {
            $prop['withTime'] = !empty($p['withTime']);
        }
        $props[] = $prop;
    }
    $byId = array_column($props, null, 'id');

    $views = [];
    foreach (array_slice(is_array($config['views'] ?? null) ? $config['views'] : [], 0, 20) as $v) {
        $id = (string) ($v['id'] ?? '');
        $type = (string) ($v['type'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,24}$/', $id) || !in_array($type, VIEW_TYPES, true)) {
            continue;
        }
        $view = ['id' => $id, 'name' => clean_line(strip_tags((string) ($v['name'] ?? '')), 40) ?: 'Vue', 'type' => $type];
        $groupBy = (string) ($v['groupBy'] ?? '');
        if ($type === 'board' && isset($byId[$groupBy]) && $byId[$groupBy]['type'] === 'select') {
            $view['groupBy'] = $groupBy;
        }
        $dateProp = (string) ($v['dateProp'] ?? '');
        if ($type === 'calendar' && isset($byId[$dateProp]) && $byId[$dateProp]['type'] === 'date') {
            $view['dateProp'] = $dateProp;
        }
        $sort = $v['sort'] ?? null;
        if (is_array($sort) && ($sort['prop'] === 'title' || isset($byId[$sort['prop'] ?? ''])) && in_array($sort['dir'] ?? '', ['asc', 'desc'], true)) {
            $view['sort'] = ['prop' => $sort['prop'], 'dir' => $sort['dir']];
        }
        $view['filters'] = [];
        foreach (array_slice(is_array($v['filters'] ?? null) ? $v['filters'] : [], 0, 10) as $f) {
            $prop = (string) ($f['prop'] ?? '');
            if (($prop === 'title' || isset($byId[$prop])) && in_array($f['op'] ?? '', FILTER_OPS, true)) {
                $view['filters'][] = ['prop' => $prop, 'op' => $f['op'], 'value' => clean_line(strip_tags((string) ($f['value'] ?? '')), 100)];
            }
        }
        $view['hidden'] = array_values(array_filter(array_map('strval', is_array($v['hidden'] ?? null) ? $v['hidden'] : []), fn($h) => isset($byId[$h])));
        $views[] = $view;
    }
    if (!$views) {
        $views[] = ['id' => 'v_table', 'name' => 'Tableau', 'type' => 'table', 'filters' => [], 'hidden' => []];
    }
    return ['properties' => $props, 'views' => $views];
}

/** Valeur valide d'une propriété (ou null). */
function sanitize_prop_value(array $prop, mixed $value): mixed
{
    if ($value === null || $value === '') {
        return $prop['type'] === 'checkbox' ? false : null;
    }
    switch ($prop['type']) {
        case 'text':
            return mb_substr(trim(strip_tags((string) $value)), 0, 2000);
        case 'number':
            return is_numeric($value) ? $value + 0 : null;
        case 'select':
            return in_array($value, array_column($prop['options'] ?? [], 'id'), true) ? $value : null;
        case 'multi_select':
            return is_array($value) ? array_values(array_intersect(array_column($prop['options'] ?? [], 'id'), $value)) : [];
        case 'date':
            $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $d) && strtotime(str_replace('T', ' ', $d)) !== false;
            if (!is_array($value) || !$valid($value['start'] ?? null)) {
                return null;
            }
            $date = ['start' => $value['start']];
            if ($valid($value['end'] ?? null) && $value['end'] >= $value['start']) {
                $date['end'] = $value['end'];
            }
            return $date;
        case 'checkbox':
            return (bool) $value;
        case 'person':
            return find_user((int) $value) ? (int) $value : null;
        case 'url':
            return safe_url((string) $value, true) ?: null;
    }
    return null;
}

/** Fusionne des valeurs (partielles) dans les valeurs existantes d'une ligne. */
function merge_row_props(array $config, array $current, array $changes): array
{
    $byId = array_column($config['properties'], null, 'id');
    foreach ($changes as $propId => $value) {
        if (isset($byId[$propId])) {
            $current[$propId] = sanitize_prop_value($byId[$propId], $value);
        }
    }
    return array_intersect_key($current, $byId); // oublie les propriétés supprimées
}

/** Valeur lisible (CSV, recherche, iCal). */
function prop_text(array $prop, mixed $value, ?array $users = null): string
{
    if ($value === null || $value === [] || $value === '') {
        return '';
    }
    $options = array_column($prop['options'] ?? [], 'name', 'id');
    return match ($prop['type']) {
        'select' => $options[$value] ?? '',
        'multi_select' => implode(', ', array_filter(array_map(fn($id) => $options[$id] ?? '', (array) $value))),
        'date' => format_db_date($value['start']) . (isset($value['end']) ? ' → ' . format_db_date($value['end']) : ''),
        'checkbox' => $value ? 'Oui' : 'Non',
        'person' => ($users ?? users_by_id())[(int) $value] ?? '',
        default => (string) $value,
    };
}

function format_db_date(string $d): string
{
    $ts = strtotime(str_replace('T', ' ', $d));
    return strlen($d) > 10 ? date('d/m/Y H\hi', $ts) : date('d/m/Y', $ts);
}

/** @return array<int, string> */
function users_by_id(): array
{
    static $users = null;
    return $users ??= array_column(db_rows('SELECT id, name FROM users'), 'name', 'id');
}

/** Texte de recherche d'une ligne : valeurs + contenu de la page. */
function row_search_text(array $config, array $props, string $content): string
{
    $parts = [];
    foreach ($config['properties'] as $prop) {
        $parts[] = prop_text($prop, $props[$prop['id']] ?? null);
    }
    $decoded = $content !== '' ? json_decode($content, true) : null;
    return trim(implode("\n", array_filter($parts)) . "\n" . ($decoded ? blocks_to_text($decoded) : ''));
}

/** @return list<array<string, mixed>> Lignes d'une base (hors corbeille). */
function db_rows_of(int $dbId): array
{
    return array_map(fn($r) => [
        'id' => (int) $r['id'],
        'title' => $r['title'],
        'icon' => $r['icon'],
        'props' => $r['props'] !== '' ? (json_decode($r['props'], true) ?: (object) []) : (object) [],
        'position' => (float) $r['position'],
        'createdAt' => $r['created_at'],
        'updatedAt' => local_time($r['updated_at']),
    ], db_rows("SELECT id, title, icon, props, position, created_at, updated_at FROM pages
                WHERE parent_id = ? AND kind = 'row' AND trashed_at IS NULL ORDER BY position, id", [$dbId]));
}

function create_row(array $user, array $db, string $title, array $props): array
{
    $config = db_config($db);
    $values = merge_row_props($config, [], $props);
    db_exec("INSERT INTO pages (space, owner_id, parent_id, position, title, kind, props, search_text, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, 'row', ?, ?, ?, ?)", [
        $db['space'], $db['owner_id'], $db['id'], next_position((int) $db['id'], $db['space'], (int) $user['id']),
        clean_line($title, 200), json_encode($values, JSON_UNESCAPED_UNICODE), row_search_text($config, $values, ''), $user['id'], $user['id'],
    ]);
    return find_page($user, (int) db()->lastInsertId());
}

/** Copie les lignes d'une base vers une autre (duplication, modèles). */
function copy_rows(int $fromDbId, array $toDb, array $user, bool $fillTemplate = false): void
{
    foreach (db_rows("SELECT * FROM pages WHERE parent_id = ? AND kind = 'row' AND trashed_at IS NULL ORDER BY position, id", [$fromDbId]) as $row) {
        $title = $fillTemplate ? fill_template($row['title'], $user) : $row['title'];
        db_exec("INSERT INTO pages (space, owner_id, parent_id, position, title, icon, content, search_text, kind, props, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'row', ?, ?, ?)", [
            $toDb['space'], $toDb['owner_id'], $toDb['id'], $row['position'], $title, $row['icon'], $row['content'],
            $row['search_text'], $row['props'], $user['id'], $user['id'],
        ]);
    }
}

/**
 * Lignes avec une date → événements iCal (abonnement depuis Google Agenda, Outlook, iPhone…).
 */
function db_to_ical(array $db): string
{
    $config = db_config($db);
    $dateProps = array_values(array_filter($config['properties'], fn($p) => $p['type'] === 'date'));
    $calendarView = array_values(array_filter($config['views'], fn($v) => $v['type'] === 'calendar' && !empty($v['dateProp'])));
    $dateProp = $calendarView[0]['dateProp'] ?? ($dateProps[0]['id'] ?? null);
    $byId = array_column($config['properties'], null, 'id');
    $tz = new DateTimeZone((string) config('timezone'));
    $utc = new DateTimeZone('UTC');
    $esc = fn(string $s) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $s);
    $host = $_SERVER['HTTP_HOST'] ?? 'notes';

    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Notes//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
              'X-WR-CALNAME:' . $esc(trim($db['icon'] . ' ' . ($db['title'] ?: 'Sans titre'))), 'X-WR-TIMEZONE:' . config('timezone')];
    foreach ($dateProp ? db_rows_of((int) $db['id']) : [] as $row) {
        $props = (array) $row['props'];
        $date = $props[$dateProp] ?? null;
        if (!is_array($date) || empty($date['start'])) {
            continue;
        }
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = "UID:notes-row-{$row['id']}@{$host}";
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $start = $date['start'];
        $end = $date['end'] ?? null;
        if (strlen($start) === 10) { // journée entière (fin exclusive)
            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
            $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime(substr($end ?? $start, 0, 10) . ' +1 day'));
        } else {
            $s = new DateTimeImmutable(str_replace('T', ' ', $start), $tz);
            $e = $end && strlen($end) > 10 ? new DateTimeImmutable(str_replace('T', ' ', $end), $tz) : $s->modify('+1 hour');
            $lines[] = 'DTSTART:' . $s->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $e->setTimezone($utc)->format('Ymd\THis\Z');
        }
        $lines[] = 'SUMMARY:' . $esc(trim($row['icon'] . ' ' . ($row['title'] ?: 'Sans titre')));
        $details = [];
        foreach ($config['properties'] as $prop) {
            if ($prop['id'] !== $dateProp && ($text = prop_text($prop, $props[$prop['id']] ?? null)) !== '') {
                $details[] = "{$prop['name']} : {$text}";
            }
        }
        $details[] = app_url('index.php?p=' . $row['id']);
        $lines[] = 'DESCRIPTION:' . $esc(implode("\n", $details));
        $lines[] = 'URL:' . app_url('index.php?p=' . $row['id']);
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    // Lignes de 75 octets maximum (norme iCalendar), coupées sans casser les caractères UTF-8
    $out = '';
    foreach ($lines as $line) {
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n";
            $line = ' ' . substr($line, $cut);
        }
        $out .= $line . "\r\n";
    }
    return $out;
}
