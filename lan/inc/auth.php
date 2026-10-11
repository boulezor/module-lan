<?php
declare(strict_types=1);

/*
 * Comptes volontairement simples (entre amis) : email + mot de passe,
 * sans vérification d'email. On reste connecté grâce à un cookie « se souvenir ».
 */

const REMEMBER_COOKIE = 'lan_auth';

function find_user(int $id): ?array
{
    return db_row('SELECT * FROM users WHERE id = ?', [$id]);
}

function current_user(): ?array
{
    if (!empty($_SESSION['user_id'])) {
        $user = find_user((int) $_SESSION['user_id']);
        if ($user) {
            return $user;
        }
        unset($_SESSION['user_id']);
    }

    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && preg_match('/^(\d+):([a-f0-9]{40})$/', $cookie, $m)) {
        $user = find_user((int) $m[1]);
        if ($user && $user['remember_token'] && hash_equals($user['remember_token'], hash('sha256', $m[2]))) {
            $_SESSION['user_id'] = (int) $user['id'];
            return $user;
        }
    }
    return null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    $token = bin2hex(random_bytes(20));
    db_exec('UPDATE users SET remember_token = ? WHERE id = ?', [hash('sha256', $token), $user['id']]);
    setcookie(REMEMBER_COOKIE, $user['id'] . ':' . $token, [
        'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true,
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

/** Redirige vers la page de connexion si personne n'est connecté. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $here = basename($_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        flash('info', 'Connecte-toi (ou crée un compte en 10 secondes) pour continuer.');
        redirect('connexion.php?next=' . urlencode($here));
    }
    return $user;
}

function clean_email(string $email): string
{
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/** Mot de passe lisible pour les réinitialisations faites par l'admin. */
function random_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}
