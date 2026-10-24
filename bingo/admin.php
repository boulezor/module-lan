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

<?php
simple_close(['assets/simple.js']);
