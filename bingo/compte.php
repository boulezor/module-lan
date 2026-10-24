<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (post_string('action') === 'name') {
        $name = clean_line(post_string('name'), 60);
        if ($name !== '') {
            db_exec('UPDATE users SET name = ? WHERE id = ?', [$name, $user['id']]);
            flash('success', 'Nom modifié.');
        }
    } elseif (post_string('action') === 'password') {
        if (!password_verify(post_string('current'), $user['password_hash'])) {
            flash('error', 'Mot de passe actuel incorrect.');
        } elseif (mb_strlen(post_string('password')) < 8) {
            flash('error', 'Nouveau mot de passe trop court (8 caractères minimum).');
        } else {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash(post_string('password'), PASSWORD_DEFAULT), $user['id']]);
            flash('success', 'Mot de passe modifié.');
        }
    }
    redirect('compte.php');
}

simple_open('Mon compte', $user);
?>
<section class="card">
    <p class="muted"><?= e($user['email']) ?> · <?= (int) $user['is_admin'] ? 'Administrateur' : 'Organisateur' ?></p>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="name">
        <input type="text" name="name" maxlength="60" required value="<?= e($user['name']) ?>">
        <button type="submit" class="btn-secondary">Changer mon nom</button>
    </form>
</section>
<section class="card">
    <h2>Mot de passe</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label>Mot de passe actuel <input type="password" name="current" required autocomplete="current-password"></label>
        <label>Nouveau mot de passe <small>(8 caractères minimum)</small> <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <button type="submit" class="btn-primary">Changer le mot de passe</button>
    </form>
</section>
<section class="card">
    <form method="post" action="login.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn-secondary">Se déconnecter</button>
    </form>
</section>
<?php
simple_close(['assets/simple.js']);
