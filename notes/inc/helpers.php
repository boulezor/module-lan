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

function clean_line(string $value, int $maxLength): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, $maxLength);
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function random_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(16);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Jeton CSRF : champ de formulaire, en-tête X-CSRF-Token ou champ « csrf » du JSON (sendBeacon). */
function csrf_valid(): bool
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (json_input()['csrf'] ?? ''));
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
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

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corps JSON d'une requête POST (appels JavaScript). */
function json_input(): array
{
    static $data = null;
    if ($data === null) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($decoded) ? $decoded : [];
    }
    return $data;
}

/** Horodatage SQLite (UTC) → « 13/10 à 18h05 » à l'heure locale. */
function local_time(?string $utc): string
{
    return $utc ? date('d/m/Y \à H\hi', (int) strtotime($utc . ' UTC')) : '';
}

/** URL d'un fichier statique avec sa version (date de modification) pour éviter les vieux fichiers en cache. */
function asset(string $path): string
{
    return e($path . '?v=' . (@filemtime(APP_ROOT . '/' . $path) ?: '1'));
}
