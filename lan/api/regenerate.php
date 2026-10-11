<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * Admin : régénère la fiche d'un jeu du pot commun (description, points forts / faibles).
 * Appelé jeu par jeu depuis la page Résultats, pour ne pas dépasser le délai max d'une requête.
 * Le commentaire perso n'est jamais modifié ; le nombre de joueurs, l'image, les liens et les
 * catégories ne sont complétés que s'ils sont vides (un lien de recherche YouTube est remplacé
 * par la bande-annonce trouvée).
 */

header('Content-Type: application/json; charset=utf-8');

if (!is_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès refusé (session admin expirée ?).']);
    exit;
}
session_write_close(); // ne bloque pas les autres pages pendant l'appel à l'IA
@set_time_limit(120);

$proposal = db_row('SELECT * FROM proposals WHERE id = ?', [(int) post_string('id')]);
if (!$proposal) {
    echo json_encode(['ok' => false, 'error' => 'Jeu introuvable.']);
    exit;
}

$info = lookup_game($proposal['title'], false);
if ($info['description'] === '' && is_youtube_search_url($info['video_url'])) {
    echo json_encode(['ok' => false, 'title' => $proposal['title'], 'error' => 'aucune information trouvée'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Nouvelles valeurs pour une fiche : texte remplacé, le reste seulement complété. */
$merge = function (array $row) use ($info): array {
    $values = [
        'description' => $info['description'] !== '' ? $info['description'] : $row['description'],
        'pros' => $info['pros'] !== '' ? $info['pros'] : $row['pros'],
        'cons' => $info['cons'] !== '' ? $info['cons'] : $row['cons'],
    ];
    foreach (['players', 'image_url', 'tags', 'store_url'] as $field) {
        $values[$field] = $row[$field] !== '' ? $row[$field] : $info[$field];
    }
    // Vidéo : un lien saisi à la main est gardé ; un lien de recherche est remplacé par la vidéo trouvée
    $values['video_url'] = $row['video_url'] !== '' && !is_youtube_search_url($row['video_url']) ? $row['video_url'] : $info['video_url'];
    return $values;
};
$sql = 'UPDATE %s SET description = ?, pros = ?, cons = ?, players = ?, image_url = ?, tags = ?, store_url = ?, video_url = ? WHERE id = ?';

db_exec(sprintf($sql, 'proposals'), [...array_values($merge($proposal)), $proposal['id']]);
if ($proposal['list_game_id'] && ($listGame = db_row('SELECT * FROM list_games WHERE id = ?', [$proposal['list_game_id']]))) {
    db_exec(sprintf($sql, 'list_games'), [...array_values($merge($listGame)), $listGame['id']]);
}

echo json_encode(['ok' => true, 'title' => $proposal['title'], 'source' => $info['source']], JSON_UNESCAPED_UNICODE);
