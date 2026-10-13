<?php
declare(strict_types=1);

/*
 * Accès par compte obligatoire, sans inscription publique :
 * premier compte admin créé avec un code d'installation, puis comptes créés par lien d'invitation.
 */

const REMEMBER_COOKIE = 'notes_auth';

function find_user(int $id): ?array
{
    return db_row('SELECT * FROM users WHERE id = ?', [$id]);
}

function users_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (!empty($_SESSION['user_id']) && ($user = find_user((int) $_SESSION['user_id']))) {
        return $cache = $user;
    }
    unset($_SESSION['user_id']);

    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && preg_match('/^(\d+):([a-f0-9]{40})$/', $cookie, $m)) {
        $user = find_user((int) $m[1]);
        if ($user && $user['remember_token'] && hash_equals($user['remember_token'], hash('sha256', $m[2]))) {
            $_SESSION['user_id'] = (int) $user['id'];
            return $cache = $user;
        }
    }
    return null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $token = random_token(20);
    db_exec('UPDATE users SET remember_token = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [hash('sha256', $token), $user['id']]);
    setcookie(REMEMBER_COOKIE, $user['id'] . ':' . $token, [
        'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function logout_user(): void
{
    if ($user = current_user()) {
        db_exec('UPDATE users SET remember_token = NULL WHERE id = ?', [$user['id']]);
    }
    unset($_SESSION['user_id']);
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/** Pages : connexion obligatoire (ou installation si aucun compte n'existe). */
function require_login(): array
{
    if (users_count() === 0) {
        redirect('setup.php');
    }
    $user = current_user();
    if ($user === null) {
        $here = basename($_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        redirect('login.php?next=' . urlencode($here));
    }
    return $user;
}

/** API JSON : connexion + jeton CSRF obligatoires pour toute modification. */
function require_api_user(bool $write = true): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Session expirée : reconnecte-toi.'], 401);
    }
    if ($write && !csrf_valid()) {
        json_response(['error' => 'Jeton de sécurité invalide : recharge la page.'], 403);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!(int) $user['is_admin']) {
        http_response_code(403);
        exit('Réservé aux administrateurs.');
    }
    return $user;
}

/** URL de retour après connexion : uniquement une page locale de l'appli. */
function safe_next(string $next): string
{
    return preg_match('/^[a-z-]+\.php(\?[\w=&%-]*)?$/', $next) ? $next : 'index.php';
}

function clean_email(string $email): string
{
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * Crée un lien d'invitation (ou de réinitialisation si $userId) et renvoie le jeton en clair (affiché une seule fois).
 */
function create_invitation(int $createdBy, string $label, ?int $userId = null): string
{
    $token = random_token(24);
    db_exec('INSERT INTO invitations (token_hash, label, user_id, created_by, expires_at) VALUES (?, ?, ?, ?, ?)', [
        hash('sha256', $token), $label, $userId, $createdBy,
        gmdate('Y-m-d H:i:s', time() + 86400 * (int) config('invite_days')),
    ]);
    return $token;
}

function find_valid_invitation(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    return db_row("SELECT * FROM invitations WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now')",
        [hash('sha256', $token)]);
}

/** Adresse absolue de l'appli (pour les liens d'invitation / de partage à copier). */
function app_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $dir = preg_replace('#/api$#', '', $dir);
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/' . $path;
}
