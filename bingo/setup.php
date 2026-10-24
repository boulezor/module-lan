<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Installation : création du tout premier compte (administrateur), protégée par un code défini côté serveur.
if (users_count() > 0) {
    redirect('login.php');
}
$code = (string) config('setup_code');
$form = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== '') {
    $form = ['name' => clean_line(post_string('name'), 60), 'email' => clean_email(post_string('email'))];
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (!hash_equals($code, post_string('setup_code'))) {
        sleep(1);
        flash('error', "Code d'installation incorrect.");
    } elseif ($form['name'] === '' || $form['email'] === '') {
        flash('error', 'Nom et email valides obligatoires.');
    } elseif (mb_strlen(post_string('password')) < 8) {
        flash('error', 'Mot de passe trop court (8 caractères minimum).');
    } else {
        db_exec('INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, 1)',
            [$form['name'], $form['email'], password_hash(post_string('password'), PASSWORD_DEFAULT)]);
        $user = find_user((int) db()->lastInsertId());
        login_user($user);
        flash('success', 'Bienvenue ! Crée ta première session de bingo.');
        redirect('index.php');
    }
}

auth_open('Installation');
if ($code === ''): ?>
    <p>Pour créer le premier compte administrateur, définis d'abord un <strong>code d'installation</strong> dans le fichier :</p>
    <p><code><?= e(config('private_dir')) ?>/config.php</code></p>
    <pre>&lt;?php
return [
    'setup_code' => 'choisis-un-code',
];</pre>
    <p class="muted">Puis recharge cette page.</p>
<?php else: ?>
    <p class="muted">Crée le premier compte (administrateur). Les autres comptes seront créés par invitation.</p>
    <form method="post">
        <?= csrf_field() ?>
        <label>Code d'installation <input type="password" name="setup_code" required autocomplete="off"></label>
        <label>Nom <input type="text" name="name" maxlength="60" required value="<?= e($form['name']) ?>"></label>
        <label>Email <input type="email" name="email" required value="<?= e($form['email']) ?>" autocomplete="email"></label>
        <label>Mot de passe <small>(8 caractères minimum)</small> <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <button type="submit" class="btn-primary">Créer le compte administrateur</button>
    </form>
<?php endif;
auth_close();
