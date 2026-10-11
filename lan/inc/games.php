<?php
declare(strict_types=1);

/**
 * Champs d'un jeu envoyés par le formulaire (ajout ou modification).
 * Si la description est vide (pas de JavaScript, ou champ vidé), on complète automatiquement.
 *
 * @return array{title: string, description: string, video_url: string, store_url: string,
 *               image_url: string, tags: string, steam_appid: ?int, comment: string}
 */
function game_from_post(): array
{
    $game = [
        'title' => clean_line(post_string('title'), 100),
        'description' => trim(mb_substr(post_string('description'), 0, 1000)),
        'video_url' => clean_url(post_string('video_url')),
        'store_url' => clean_url(post_string('store_url')),
        'image_url' => clean_url(post_string('image_url')),
        'tags' => clean_line(post_string('tags'), 300),
        'steam_appid' => ctype_digit(post_string('steam_appid')) ? (int) post_string('steam_appid') : null,
        'comment' => trim(mb_substr(post_string('comment'), 0, 500)),
    ];

    if ($game['description'] === '' && normalize_key($game['title']) !== '') {
        $info = lookup_game($game['title']);
        $game['description'] = $info['description'];
        foreach (['video_url', 'store_url', 'image_url', 'tags', 'steam_appid'] as $field) {
            $game[$field] = $game[$field] ?: $info[$field];
        }
    }
    $game['video_url'] = $game['video_url'] ?: youtube_search_url($game['title']);
    return $game;
}

/**
 * Formulaire d'ajout / modification d'un jeu de la liste personnelle.
 *
 * @param list<array<string, mixed>> $openLans LAN ouvertes aux propositions (ajout seulement)
 */
function render_game_form(?array $game, array $openLans = []): void
{
    $editing = $game !== null;
    $game ??= ['id' => 0, 'title' => '', 'description' => '', 'video_url' => '', 'store_url' => '',
               'image_url' => '', 'tags' => '', 'steam_appid' => null, 'comment' => ''];
    ?>
    <form method="post" class="card" id="game-form" autocomplete="off" data-mode="<?= $editing ? 'edit' : 'add' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="game-id" value="<?= (int) $game['id'] ?>">
        <h2 class="form-title"><?= $editing ? 'Modifier « ' . e($game['title']) . ' »' : 'Ajouter un jeu à ma liste' ?></h2>

        <label>Nom du jeu
            <span class="input-row">
                <input type="text" name="title" id="title" maxlength="100" required value="<?= e($game['title']) ?>" placeholder="ex. Age of Empires II">
                <button type="button" id="lookup-btn" class="btn-secondary"><?= $editing ? 'Rechercher à nouveau' : 'Chercher' ?></button>
            </span>
        </label>

        <div id="lookup-status" class="lookup-status" aria-live="polite"></div>

        <div id="suggestion" class="suggestion"<?= $game['image_url'] === '' && $game['tags'] === '' ? ' hidden' : '' ?>>
            <img id="preview-img" alt="" src="<?= e($game['image_url']) ?>"<?= $game['image_url'] === '' ? ' hidden' : '' ?>>
            <p id="preview-tags" class="tags"><?php foreach (array_filter(explode(', ', $game['tags'])) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?></p>
        </div>
        <input type="hidden" name="image_url" id="image_url" value="<?= e($game['image_url']) ?>">
        <input type="hidden" name="tags" id="tags" value="<?= e($game['tags']) ?>">
        <input type="hidden" name="steam_appid" id="steam_appid" value="<?= e((string) $game['steam_appid']) ?>">

        <label>Description <small class="muted">(remplie automatiquement, modifiable)</small>
            <textarea name="description" id="description" rows="4" maxlength="1000"><?= e($game['description']) ?></textarea>
        </label>

        <label>Lien vidéo (YouTube…)
            <input type="url" name="video_url" id="video_url" value="<?= e($game['video_url']) ?>">
        </label>

        <label>Lien fiche du jeu (Steam, Wikipédia, site officiel…)
            <input type="url" name="store_url" id="store_url" value="<?= e($game['store_url']) ?>">
        </label>

        <label>Mon commentaire <small class="muted">(facultatif — affiché avec ton nom si tu proposes le jeu à une LAN)</small>
            <textarea name="comment" rows="2" maxlength="500" placeholder="ex. Parfait à 8, parties de 20 minutes, gratuit sur Epic…"><?= e($game['comment']) ?></textarea>
        </label>

        <?php if (!$editing && $openLans): ?>
            <label>Proposer aussi pour
                <select name="propose_lan">
                    <option value="">— garder seulement dans ma liste —</option>
                    <?php foreach ($openLans as $lan): ?>
                        <option value="<?= (int) $lan['id'] ?>"><?= e($lan['title']) ?> (<?= e(format_date($lan['lan_date'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" id="submit-btn"><?= $editing ? 'Enregistrer' : 'Ajouter à ma liste' ?></button>
            <?php if ($editing): ?><a href="ma-liste.php">Annuler</a><?php endif; ?>
        </div>
    </form>
    <?php
}
