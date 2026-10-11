<?php
declare(strict_types=1);

function config(string $key): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config.php';
        foreach ([$config['private_dir'] . '/config.php', APP_ROOT . '/config.local.php'] as $override) {
            if (is_file($override)) {
                $config = array_replace($config, require $override);
            }
        }
    }
    return $config[$key] ?? null;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Clé de comparaison : minuscules, sans accents ni ponctuation.
 * « Counter-Strike 2 » et « counter strike 2 » donnent la même clé.
 */
function normalize_key(string $value): string
{
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $value = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
    } else {
        $value = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value));
    }
    return (string) preg_replace('/[^a-z0-9]+/', '', $value);
}

/** Score de ressemblance entre deux clés normalisées, de 0 à 1. */
function similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 1.0;
    }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = $short === $a ? $b : $a;
    // « rocketleague » contenu dans « rocketleaguecollectorsedition »
    if (strlen($short) >= 4 && str_contains($long, $short)) {
        return 0.9;
    }
    $max = max(strlen($a), strlen($b));
    if ($max > 255) {
        return 0.0;
    }
    return 1 - levenshtein($a, $b) / $max;
}

/** Liste de points (un par ligne) : lignes nettoyées, puces retirées, 6 points de 150 caractères maximum. */
function clean_points(string $text): string
{
    $lines = array_map(fn($l) => clean_line((string) preg_replace('/^\s*[-•*👍👎]+\s*/u', '', $l), 150), explode("\n", $text));
    return implode("\n", array_slice(array_values(array_filter($lines, fn($l) => $l !== '')), 0, 6));
}

/** Nettoie un texte libre sur une ligne (nom, titre). */
function clean_line(string $value, int $maxLength): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, $maxLength);
}

/** N'accepte que les URL http(s). */
function clean_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? mb_substr($url, 0, 500) : '';
}

function youtube_search_url(string $title): string
{
    return 'https://www.youtube.com/results?search_query=' . urlencode($title . ' official trailer');
}

/** Lien de recherche YouTube (et non une vidéo précise) ? */
function is_youtube_search_url(string $url): bool
{
    return str_contains($url, 'youtube.com/results?');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

/** @return list<array{0: string, 1: string}> */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Jour en toutes lettres : « vendredi 14 novembre (2026) ». Accepte 'AAAA-MM-JJ' ou 'AAAA-MM-JJ HH:MM'. */
function fr_date(string $value, bool $withYear = true): string
{
    $ts = strtotime(substr($value, 0, 10));
    if ($ts === false) {
        return $value;
    }
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('fr_BE', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
            config('timezone'), null, $withYear ? 'EEEE d MMMM y' : 'EEEE d MMMM');
        return (string) $formatter->format($ts);
    }
    return date($withYear ? 'd/m/Y' : 'd/m', $ts);
}

/** Heure « 18h00 » d'une valeur 'AAAA-MM-JJ HH:MM' (vide si pas d'heure). */
function fr_time(string $value): string
{
    return strlen($value) >= 16 ? str_replace(':', 'h', substr($value, 11, 5)) : '';
}

/** Période d'une LAN : « du vendredi 14 novembre à 18h00 au dimanche 16 novembre 2026 à 18h00 ». */
function format_lan_dates(array $lan): string
{
    $start = (string) ($lan['starts_at'] ?: $lan['lan_date']);
    $end = (string) ($lan['ends_at'] ?: $start);
    $at = fn(string $v) => fr_time($v) !== '' ? ' à ' . fr_time($v) : '';

    if (substr($start, 0, 10) === substr($end, 0, 10)) {
        $hours = fr_time($start) !== '' && fr_time($end) !== '' && $end !== $start
            ? ', de ' . fr_time($start) . ' à ' . fr_time($end)
            : $at($start);
        return fr_date($start) . $hours;
    }
    $sameYear = substr($start, 0, 4) === substr($end, 0, 4);
    return 'du ' . fr_date($start, !$sameYear) . $at($start) . ' au ' . fr_date($end) . $at($end);
}

/** Valeur d'un champ datetime-local (« 2026-11-14T18:00 ») → 'AAAA-MM-JJ HH:MM', ou '' si invalide. */
function parse_datetime_local(string $value): string
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $value, $m) || strtotime("{$m[1]} {$m[2]}") === false) {
        return '';
    }
    return "{$m[1]} {$m[2]}";
}

/** URL de retour après connexion : uniquement une page locale du module. */
function safe_next(string $next): string
{
    return preg_match('/^[a-z-]+\.php(\?[\w=&%-]*)?$/', $next) ? $next : 'ma-liste.php';
}
