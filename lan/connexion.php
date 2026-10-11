<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$next = safe_next(is_string($_REQUEST['next'] ?? null) ? $_REQUEST['next'] : '');
$form = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');
    $email = clean_email(post_string('email'));
    $password = post_string('password');

    if (!csrf_valid()) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif ($action === 'logout') {
        logout_user();
        flash('success', 'À bientôt !');
        redirect('index.php');
    } elseif ($action === 'login') {
        $user = $email !== '' ? db_row('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            redirect($next);
        }
        sleep(1); // freine les essais en rafale
        flash('error', 'Email ou mot de passe incorrect.');
        $form['email'] = $email;
    } elseif ($action === 'register') {
        $form = ['name' => clean_line(post_string('name'), 40), 'email' => $email];
        if ($form['name'] === '') {
            flash('error', 'Choisis un pseudo.');
        } elseif ($email === '') {
            flash('error', 'Adresse email invalide.');
        } elseif (mb_strlen($password) < 4) {
            flash('error', 'Mot de passe trop court (4 caractères minimum).');
        } elseif (db_row('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', 'Un compte existe déjà avec cet email. Connecte-toi !');
        } else {
            db_exec('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
                [$form['name'], $email, password_hash($password, PASSWORD_DEFAULT)]);
            login_user(find_user((int) db()->lastInsertId()));
            flash('success', "Bienvenue {$form['name']} ! Ajoute tes jeux à ta liste.");
            redirect($next);
        }
    }
}

if (current_user()) {
    redirect($next);
}

page_header('Connexion', 'connexion');
?>
<h1>Connexion</h1>
<div class="two-cols">
    <form method="post" class="card">
        <h2 class="form-title">J'ai déjà un compte</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <label>Email
            <input type="email" name="email" required value="<?= e($form['email']) ?>" autocomplete="email">
        </label>
        <label>Mot de passe
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Se connecter</button>
    </form>

    <form method="post" class="card">
        <h2 class="form-title">Créer un compte</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <label>Pseudo
            <input type="text" name="name" maxlength="40" required value="<?= e($form['name']) ?>" autocomplete="nickname">
        </label>
        <label>Email
            <input type="email" name="email" required value="<?= e($form['email']) ?>" autocomplete="email">
        </label>
        <label>Mot de passe <small class="muted">(4 caractères minimum)</small>
            <input type="password" name="password" required minlength="4" autocomplete="new-password">
        </label>
        <button type="submit">Créer mon compte</button>
    </form>
</div>
<p class="muted small">Mot de passe oublié ? Demande à l'organisateur de le réinitialiser.</p>
<?php page_footer();
