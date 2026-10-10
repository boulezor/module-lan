<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Connecte-toi pour utiliser la recherche.']);
    exit;
}

$title = clean_line(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 100);
$excludeId = (int) ($_GET['exclude'] ?? 0); // jeu en cours de modification
if (strlen(normalize_key($title)) < 2) {
    echo json_encode(['error' => 'Nom trop court']);
    exit;
}

$userId = (int) $user['id'];
$suggestion = lookup_game($title);
session_write_close();

$inList = find_list_duplicate($userId, $title, null, $excludeId)
    ?? find_list_duplicate($userId, $suggestion['title'], $suggestion['steam_appid'], $excludeId);

// Déjà dans le pot commun d'une LAN ouverte ? (information, pas un blocage : la liste reste perso)
$proposed = [];
foreach (lans_with_open('proposals') as $lan) {
    $dup = find_lan_duplicate((int) $lan['id'], $title, null)
        ?? find_lan_duplicate((int) $lan['id'], $suggestion['title'], $suggestion['steam_appid']);
    if ($dup) {
        $proposed[] = ['lan' => $lan['title'], 'proposed_by' => (int) $dup['user_id'] === $userId ? 'toi' : $dup['proposed_by']];
    }
}

$others = array_filter(user_games($userId), fn($g) => (int) $g['id'] !== $excludeId);

echo json_encode([
    'in_list' => $inList ? ['title' => $inList['title']] : null,
    'similar' => array_map(fn($g) => ['title' => $g['title']], find_similar($others, $suggestion['title'])),
    'proposed' => $proposed,
    'suggestion' => $suggestion,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
