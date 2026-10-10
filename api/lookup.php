<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$title = clean_line(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 100);
if (strlen(normalize_key($title)) < 2) {
    echo json_encode(['error' => 'Nom trop court']);
    exit;
}

// Doublon exact sur le nom tapé : inutile d'interroger Steam
$duplicate = find_duplicate($title, null);
$suggestion = null;
if ($duplicate === null) {
    $suggestion = lookup_game($title);
    // Le nom tapé est nouveau mais correspond peut-être à une fiche Steam déjà enregistrée
    $duplicate = find_duplicate($suggestion['title'], $suggestion['steam_appid']);
}
session_write_close();

$publicGame = fn(array $g) => ['title' => $g['title'], 'proposed_by' => $g['proposed_by']];

echo json_encode([
    'duplicate' => $duplicate ? $publicGame($duplicate) : null,
    'similar' => array_map($publicGame, find_similar($suggestion['title'] ?? $title)),
    'suggestion' => $duplicate ? null : $suggestion,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
