<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

if (users_count() === 0) {
    redirect('setup.php');
}
$next = safe_next(is_string($_REQUEST['next'] ?? null) ? $_REQUEST['next'] : '');
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (post_string('action') === 'logout') {
        logout_user();
        flash('success', 'À bientôt !');
        redirect('login.php');
    } else {
        $email = clean_email(post_string('email'));
        $user = $email !== '' ? db_row('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user && password_verify(post_string('password'), $user['password_hash'])) {
            login_user($user);
            redirect($next);
        }
        sleep(1); // freine les essais en rafale
        flash('error', 'Email ou mot de passe incorrect.');
    }
}
if (current_user()) {
    redirect($next);
}

auth_open('Connexion');
?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Email <input type="email" name="email" required autofocus value="<?= e($email) ?>" autocomplete="email"></label>
    <label>Mot de passe <input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit" class="btn-primary">Se connecter</button>
</form>
<p class="muted small">Accès sur invitation uniquement. Mot de passe oublié : demande un lien de réinitialisation à un administrateur.</p>
<?php
auth_close();
