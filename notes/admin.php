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
                    flash('success', "Compte de {$target['name']} supprimé (ses pages privées aussi ; les pages d'équipe restent).");
                }
                break;
            case 'save_ai':
                if (!empty($_POST['remove_key'])) {
                    set_setting('openai_api_key', null);
                    flash('success', 'Clé OpenAI supprimée.');
                } elseif (($key = trim(post_string('openai_api_key'))) !== '') {
                    set_setting('openai_api_key', $key);
                    set_setting('openai_last_error', null);
                    flash('success', 'Clé OpenAI enregistrée. Clique sur « Tester » pour vérifier.');
                } else {
                    flash('success', 'Réglages enregistrés.');
                }
                $model = clean_line(post_string('openai_model'), 60);
                set_setting('openai_model', $model !== '' && $model !== (string) config('openai_model') ? $model : null);
                break;
            case 'test_ai':
                $test = openai_chat('Tu réponds en un mot.', 'Réponds uniquement : OK', 300);
                if ($test['text'] !== null) {
                    set_setting('openai_last_error', null);
                    flash('success', 'OpenAI répond (modèle ' . openai_model() . ') : « ' . mb_substr($test['text'], 0, 40) . ' ». L\'assistant IA est actif.');
                } else {
                    flash('error', 'OpenAI ne répond pas : ' . $test['error']);
                }
                break;
        }
    }
    redirect('admin.php');
}

$newLink = $_SESSION['new_link'] ?? null;
unset($_SESSION['new_link']);
$users = db_rows("SELECT u.*, (SELECT COUNT(*) FROM pages p WHERE p.created_by = u.id AND p.trashed_at IS NULL) AS pages
                  FROM users u ORDER BY u.name COLLATE NOCASE");
$pending = db_rows("SELECT i.*, u.name AS for_user FROM invitations i LEFT JOIN users u ON u.id = i.user_id
                    WHERE i.used_at IS NULL AND i.expires_at > datetime('now') ORDER BY i.created_at DESC");
$configKey = (string) config('openai_api_key') !== '';
$activeKey = openai_key();
$lastError = setting('openai_last_error');

/** Petit formulaire POST d'une action */
function action_button(string $action, string $label, int $id, string $class = 'btn-link', ?string $confirm = null): string
{
    return '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}

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
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernière connexion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td><?= (int) $u['is_admin'] ? 'Admin' : 'Membre' ?></td>
                <td class="muted small"><?= e(local_time($u['last_login_at'])) ?: '—' ?></td>
                <td class="actions">
                    <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                        <?= action_button('reset_link', 'Lien mot de passe', (int) $u['id']) ?>
                        <?= action_button('toggle_admin', (int) $u['is_admin'] ? 'Retirer admin' : 'Rendre admin', (int) $u['id']) ?>
                        <?= action_button('delete_user', 'Supprimer', (int) $u['id'], 'btn-link danger', "Supprimer le compte de {$u['name']} et ses pages privées ?") ?>
                    <?php else: ?>
                        <span class="muted small">c'est toi</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h2>✨ Assistant de rédaction IA</h2>
    <?php if ($activeKey === ''): ?>
        <p>Pas de clé : les boutons IA de l'éditeur affichent un message d'erreur. Ajoute une clé OpenAI pour les activer.</p>
    <?php else: ?>
        <p>✔ Clé active <code>…<?= e(substr($activeKey, -4)) ?></code><?= $configKey ? ' (définie dans le fichier de configuration)' : '' ?>,
            modèle <code><?= e(openai_model()) ?></code>.</p>
    <?php endif; ?>
    <?php if ($lastError !== ''): ?>
        <div class="alert alert-warn">Dernière erreur OpenAI : <?= e($lastError) ?></div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_ai">
        <?php if (!$configKey): ?>
            <label>Clé API OpenAI <small>(jamais réaffichée ; laisser vide pour garder la clé actuelle)</small>
                <input type="password" name="openai_api_key" autocomplete="off" placeholder="sk-…">
            </label>
        <?php endif; ?>
        <label>Modèle <small>(par défaut : <?= e((string) config('openai_model')) ?>)</small>
            <input type="text" name="openai_model" maxlength="60" value="<?= e(openai_model()) ?>">
        </label>
        <div class="form-actions">
            <button type="submit" class="btn-primary">Enregistrer</button>
            <?php if ($activeKey !== '' && !$configKey): ?>
                <label class="check"><input type="checkbox" name="remove_key" value="1"> Supprimer la clé</label>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($activeKey !== ''): ?>
        <p><?= action_button('test_ai', '🔌 Tester la connexion à OpenAI', 0, 'btn-secondary') ?></p>
    <?php endif; ?>
    <p class="muted small">Conseil : fixe une limite de dépenses mensuelle sur ton compte OpenAI.</p>
</section>
<?php
simple_close(['assets/simple.js']);
