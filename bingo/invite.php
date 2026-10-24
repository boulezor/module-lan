<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

// Lien d'invitation (création de compte) ou de réinitialisation du mot de passe (si l'invitation vise un compte).
$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$invitation = find_valid_invitation($token);
$target = $invitation && $invitation['user_id'] ? find_user((int) $invitation['user_id']) : null;
$form = ['name' => '', 'email' => ''];

if ($invitation && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = post_string('password');
    $form = ['name' => clean_line(post_string('name'), 60), 'email' => clean_email(post_string('email'))];
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } elseif (mb_strlen($password) < 8) {
        flash('error', 'Mot de passe trop court (8 caractères minimum).');
    } elseif ($password !== post_string('password2')) {
        flash('error', 'Les deux mots de passe ne correspondent pas.');
    } elseif ($target) {
        db_exec('UPDATE users SET password_hash = ?, remember_token = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $target['id']]);
        db_exec('UPDATE invitations SET used_at = CURRENT_TIMESTAMP WHERE id = ?', [$invitation['id']]);
        login_user(find_user((int) $target['id']));
        flash('success', 'Mot de passe modifié.');
        redirect('index.php');
    } elseif ($form['name'] === '' || $form['email'] === '') {
        flash('error', 'Nom et email valides obligatoires.');
    } elseif (db_row('SELECT 1 FROM users WHERE email = ?', [$form['email']])) {
        flash('error', 'Un compte existe déjà avec cet email : connecte-toi.');
    } else {
        db()->beginTransaction();
        $used = db_exec('UPDATE invitations SET used_at = CURRENT_TIMESTAMP WHERE id = ? AND used_at IS NULL', [$invitation['id']]);
        if ($used === 1) {
            db_exec('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
                [$form['name'], $form['email'], password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) db()->lastInsertId();
            db()->commit();
            login_user(find_user($userId));
            flash('success', "Bienvenue {$form['name']} !");
            redirect('index.php');
        }
        db()->rollBack();
        flash('error', 'Ce lien vient déjà d\'être utilisé.');
    }
}

auth_open($target ? 'Nouveau mot de passe' : 'Créer ton compte');
if (!$invitation): ?>
    <p>Ce lien n'est pas valide : il a expiré ou a déjà été utilisé. Demande un nouveau lien à un administrateur.</p>
    <p><a href="login.php">Aller à la connexion</a></p>
<?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <?php if ($target): ?>
            <p class="muted">Compte : <strong><?= e($target['name']) ?></strong> (<?= e($target['email']) ?>)</p>
        <?php else: ?>
            <p class="muted">Tu as été invité<?= $invitation['label'] !== '' ? ' (' . e($invitation['label']) . ')' : '' ?> à organiser des bingos.</p>
            <label>Nom <input type="text" name="name" maxlength="60" required value="<?= e($form['name']) ?>" autocomplete="name"></label>
            <label>Email <input type="email" name="email" required value="<?= e($form['email']) ?>" autocomplete="email"></label>
        <?php endif; ?>
        <label>Mot de passe <small>(8 caractères minimum)</small> <input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <label>Confirmer le mot de passe <input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
        <button type="submit" class="btn-primary"><?= $target ? 'Enregistrer' : 'Créer mon compte' ?></button>
    </form>
<?php endif;
auth_close();
