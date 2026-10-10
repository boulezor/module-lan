<?php
declare(strict_types=1);

function config(string $key): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config.php';
        if (is_file(APP_ROOT . '/config.local.php')) {
            $config = array_replace($config, require APP_ROOT . '/config.local.php');
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
    return 'https://www.youtube.com/results?search_query=' . urlencode($title . ' gameplay trailer');
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

/** Date de LAN lisible : « samedi 14 novembre 2026 ». */
function format_date(string $isoDate): string
{
    $ts = strtotime($isoDate);
    if ($ts === false) {
        return $isoDate;
    }
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('fr_BE', IntlDateFormatter::FULL, IntlDateFormatter::NONE, config('timezone'));
        return (string) $formatter->format($ts);
    }
    return date('d/m/Y', $ts);
}

/** URL de retour après connexion : uniquement une page locale du module. */
function safe_next(string $next): string
{
    return preg_match('/^[a-z-]+\.php(\?[\w=&%-]*)?$/', $next) ? $next : 'ma-liste.php';
}
