<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * Assistant de rédaction : {action, text} (action prédéfinie) ou {action: "custom", prompt, text?}.
 * Réponse : le texte en Markdown + sa conversion en blocs Editor.js.
 */

$user = require_api_user();
session_write_close(); // ne bloque pas les autres requêtes pendant l'appel (plusieurs secondes)
@set_time_limit(120);

$in = json_input();
$action = (string) ($in['action'] ?? '');
$text = mb_substr(trim((string) ($in['text'] ?? '')), 0, 30000);
$prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 4000);
$actions = ai_actions();

if ($action === 'custom') {
    if ($prompt === '') {
        json_response(['error' => 'Écris une consigne.'], 400);
    }
    $request = $text !== ''
        ? "Consigne : {$prompt}\n\nTexte de référence (page ou sélection) :\n\"\"\"\n{$text}\n\"\"\""
        : "Consigne : {$prompt}";
} elseif (isset($actions[$action])) {
    if ($text === '') {
        json_response(['error' => 'Aucun texte à traiter.'], 400);
    }
    $request = $actions[$action][1] . "\n\nTexte :\n\"\"\"\n{$text}\n\"\"\"";
} else {
    json_response(['error' => 'Action IA inconnue.'], 400);
}

$result = openai_chat(AI_SYSTEM, $request, 3000);
if ($result['text'] === null) {
    json_response(['error' => $result['error']], 502);
}
$markdown = preg_replace('/^```(?:markdown|md)?\s*\n(.*)\n```\s*$/s', '$1', $result['text']); // réponse parfois encadrée
json_response(['markdown' => $markdown, 'blocks' => markdown_to_blocks((string) $markdown)]);
