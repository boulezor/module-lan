<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) post_string('id');
    if (!csrf_valid()) {
        flash('error', 'Session expirée, réessaie.');
    } else {
        switch (post_string('action')) {
            case 'invite':
                $token = create_invitation((int) $admin['id'], clean_line(post_string('label'), 80));
                $_SESSION['new_link'] = ['label' => "Lien d'invitation", 'url' => app_url('invite.php?t=' . $token)];
                break;
            case 'reset_link':
                if ($target = find_user($id)) {
                    $token = create_invitation((int) $admin['id'], 'Réinitialisation', $id);
                    $_SESSION['new_link'] = ['label' => "Lien de réinitialisation pour {$target['name']}", 'url' => app_url('invite.php?t=' . $token)];
                }
                break;
            case 'revoke':
                db_exec('DELETE FROM invitations WHERE id = ? AND used_at IS NULL', [$id]);
                flash('success', 'Lien révoqué.');
                break;
            case 'toggle_admin':
                if ($id === (int) $admin['id']) {
                    flash('error', 'Tu ne peux pas retirer tes propres droits.');
                } else {
                    db_exec('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$id]);
                }
                break;
            case 'school_theme':
                $title = clean_line(post_string('school_title'), 60);
                set_setting('school_title', $title !== '' ? $title : null);
                foreach (['school_color1', 'school_color2'] as $key) {
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', post_string($key))) {
                        set_setting($key, strtolower(post_string($key)));
                    }
                }
                $upload = $_FILES['logo'] ?? null;
                if (!empty($_POST['remove_logo']) && ($old = school_logo_path())) {
                    unlink($old);
                }
                if (is_array($upload) && $upload['error'] === UPLOAD_ERR_OK) {
                    $info = @getimagesize($upload['tmp_name']);
                    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
                    if ($ext === null || $upload['size'] > 3 * 1024 * 1024) {
                        flash('error', 'Logo refusé : image PNG, JPG, WebP ou GIF de 3 Mo maximum.');
                        break;
                    }
                    $dir = config('private_dir') . '/theme';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    foreach (glob($dir . '/logo.*') as $old) {
                        unlink($old);
                    }
                    move_uploaded_file($upload['tmp_name'], "{$dir}/logo.{$ext}");
                } elseif (is_array($upload) && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                    flash('error', "Le logo n'a pas pu être envoyé (fichier trop lourd ?).");
                    break;
                }
                flash('success', 'Thème École Saint-Martin enregistré.');
                break;
            case 'delete_user':
                if ($id === (int) $admin['id']) {
                    flash('error', 'Tu ne peux pas supprimer ton propre compte.');
                } elseif ($target = find_user($id)) {
                    db_exec('DELETE FROM users WHERE id = ?', [$id]);
                    flash('success', "Compte de {$target['name']} supprimé (et ses sessions de bingo).");
                }
                break;
        }
    }
    redirect('admin.php');
}

$newLink = $_SESSION['new_link'] ?? null;
unset($_SESSION['new_link']);
$users = db_rows("SELECT u.*, (SELECT COUNT(*) FROM games g WHERE g.owner_id = u.id) AS games
                  FROM users u ORDER BY u.name COLLATE NOCASE");
$pending = db_rows("SELECT i.*, u.name AS for_user FROM invitations i LEFT JOIN users u ON u.id = i.user_id
                    WHERE i.used_at IS NULL AND i.expires_at > datetime('now') ORDER BY i.created_at DESC");

simple_open('Administration', $admin);
?>
<?php if ($newLink): ?>
    <div class="card highlight">
        <p><strong><?= e($newLink['label']) ?></strong> (valable <?= (int) config('invite_days') ?> jours, utilisable une seule fois) :</p>
        <div class="copy-row">
            <input type="text" readonly value="<?= e($newLink['url']) ?>" id="new-link">
            <button type="button" class="btn-primary" data-copy="new-link">Copier</button>
        </div>
        <p class="muted small">Envoie-le par message à la personne. Il ne sera plus affiché ensuite.</p>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Inviter quelqu'un</h2>
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite">
        <input type="text" name="label" maxlength="80" placeholder="Pour qui ? (facultatif, ex. Julie)">
        <button type="submit" class="btn-primary">Créer un lien d'invitation</button>
    </form>
    <?php if ($pending): ?>
        <h3>Liens en attente</h3>
        <ul class="plain-list">
            <?php foreach ($pending as $inv): ?>
                <li>
                    <?= $inv['user_id'] ? '🔑 Réinitialisation · ' . e($inv['for_user']) : '✉️ Invitation' . ($inv['label'] !== '' ? ' · ' . e($inv['label']) : '') ?>
                    <span class="muted small">expire le <?= e(local_time($inv['expires_at'])) ?></span>
                    <?= action_button('revoke', 'Révoquer', (int) $inv['id']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Comptes (<?= count($users) ?>)</h2>
    <table class="table">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Sessions</th><th>Dernière connexion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td><?= (int) $u['is_admin'] ? 'Admin' : 'Organisateur' ?></td>
                <td><?= (int) $u['games'] ?></td>
                <td class="muted small"><?= e(local_time($u['last_login_at'])) ?: '—' ?></td>
                <td class="actions">
                    <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                        <?= action_button('reset_link', 'Lien mot de passe', (int) $u['id']) ?>
                        <?= action_button('toggle_admin', (int) $u['is_admin'] ? 'Retirer admin' : 'Rendre admin', (int) $u['id']) ?>
                        <?= action_button('delete_user', 'Supprimer', (int) $u['id'], 'btn-link danger', "Supprimer le compte de {$u['name']} et ses sessions de bingo ?") ?>
                    <?php else: ?>
                        <span class="muted small">c'est toi</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php $school = school_theme(); ?>
<section class="card">
    <h2>🎨 Thème « École Saint-Martin » <small class="muted">(réservé aux administrateurs)</small></h2>
    <p class="muted small">Choisis-le dans les réglages d'une session (thème 🔒). Le logo et le titre apparaissent sur l'écran de projection,
        l'écran BINGO et les planches imprimées.</p>
    <form method="post" enctype="multipart/form-data" class="school-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="school_theme">
        <div class="school-preview" id="school-preview" style="--sm-1: <?= e($school['color1']) ?>; --sm-2: <?= e($school['color2']) ?>;">
            <?php if ($school['logo']): ?><img src="<?= e($school['logo']) ?>" alt="Logo" id="school-logo"><?php else: ?><span class="no-logo" id="school-logo-empty">Pas encore de logo</span><?php endif; ?>
            <strong id="school-title-preview"><?= e($school['title']) ?></strong>
        </div>
        <label>Logo de l'école <small>(PNG, JPG ou WebP, 3 Mo max ; idéalement sur fond blanc ou transparent)</small>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" id="school-logo-input">
        </label>
        <label>Titre affiché <input type="text" name="school_title" maxlength="60" value="<?= e($school['title']) ?>"></label>
        <div class="colors">
            <label>Couleur principale <input type="color" name="school_color1" value="<?= e($school['color1']) ?>"></label>
            <label>Couleur secondaire <input type="color" name="school_color2" value="<?= e($school['color2']) ?>"></label>
            <button type="button" class="btn btn-small" id="school-pick" <?= $school['logo'] ? '' : 'hidden' ?>>🎯 Reprendre les couleurs du logo</button>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn-primary btn">Enregistrer</button>
            <?php if ($school['logo']): ?><label class="check"><input type="checkbox" name="remove_logo" value="1"> Retirer le logo</label><?php endif; ?>
        </div>
    </form>
</section>
<?php
simple_close(['assets/simple.js', 'assets/admin.js']);
