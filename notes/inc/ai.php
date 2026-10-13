<?php
declare(strict_types=1);

/*
 * Assistant de rédaction IA (API OpenAI Chat Completions).
 * La clé vient du fichier de config privé, sinon de l'administration.
 */

function openai_key(): string
{
    return (string) (config('openai_api_key') ?: setting('openai_api_key'));
}

function openai_model(): string
{
    return setting('openai_model') ?: (string) config('openai_model');
}

/**
 * @return array{text: ?string, error: string}
 */
function openai_chat(string $system, string $prompt, int $maxTokens = 2000): array
{
    if (openai_key() === '') {
        return ['text' => null, 'error' => "L'assistant IA n'est pas configuré (clé OpenAI manquante dans l'administration)."];
    }
    if (!function_exists('curl_init')) {
        return ['text' => null, 'error' => "L'extension PHP curl n'est pas installée."];
    }
    $ch = curl_init(rtrim((string) config('openai_base_url'), '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => (int) config('openai_timeout'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . openai_key()],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => openai_model(),
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            'max_completion_tokens' => $maxTokens,
        ]),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body, true) : null;
    $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($status >= 200 && $status < 300 && $text !== '') {
        return ['text' => $text, 'error' => ''];
    }
    $error = $curlError ?: trim("HTTP {$status} " . (string) ($data['error']['message'] ?? ''));
    if ($text === '' && $error === "HTTP {$status}") {
        $error = 'Réponse vide (augmenter la limite ou changer de modèle).';
    }
    set_setting('openai_last_error', date('d/m H:i') . ' — ' . $error);
    return ['text' => null, 'error' => $error];
}

/** Actions proposées dans l'éditeur : [libellé, consigne]. Le texte est fourni à part. */
function ai_actions(): array
{
    return [
        'improve' => ['✨ Améliorer l\'écriture', 'Améliore ce texte : plus clair, plus fluide et agréable à lire, sans changer le sens ni ajouter d\'informations.'],
        'fix' => ['✅ Corriger orthographe et grammaire', 'Corrige uniquement l\'orthographe, la grammaire et la ponctuation. Ne change rien d\'autre.'],
        'shorter' => ['✂️ Raccourcir', 'Raccourcis ce texte d\'environ la moitié en gardant l\'essentiel.'],
        'longer' => ['➕ Développer', 'Développe ce texte avec plus de détails et d\'exemples, en restant cohérent.'],
        'simpler' => ['🧒 Simplifier', 'Réécris ce texte avec des mots simples et des phrases courtes.'],
        'pro' => ['👔 Ton professionnel', 'Réécris ce texte sur un ton professionnel et courtois.'],
        'casual' => ['😊 Ton décontracté', 'Réécris ce texte sur un ton décontracté et chaleureux.'],
        'bullets' => ['📋 Transformer en liste', 'Transforme ce texte en liste à puces claire et structurée (Markdown).'],
        'summarize' => ['📝 Résumer', 'Résume ce texte en quelques phrases (ou une courte liste à puces si c\'est plus clair).'],
        'actions' => ['☑️ Extraire les tâches', 'Extrais de ce texte la liste des actions à faire, sous forme de cases à cocher Markdown (- [ ] …). S\'il n\'y en a pas, dis-le en une phrase.'],
        'translate_en' => ['🇬🇧 Traduire en anglais', 'Traduis ce texte en anglais.'],
        'translate_nl' => ['🇳🇱 Traduire en néerlandais', 'Traduis ce texte en néerlandais.'],
        'translate_fr' => ['🇫🇷 Traduire en français', 'Traduis ce texte en français.'],
        'continue' => ['✍️ Continuer d\'écrire', 'Écris la suite logique de ce texte (un ou deux paragraphes), dans le même style. Ne répète pas le texte existant.'],
    ];
}

const AI_SYSTEM = "Tu es un assistant de rédaction intégré à un outil de notes (type Notion), pour des utilisateurs francophones. "
    . "Réponds uniquement avec le texte demandé, sans introduction ni commentaire (pas de « Voici… »). "
    . "Garde la langue du texte d'origine sauf demande de traduction. "
    . "Tu peux utiliser du Markdown simple : titres ##, listes -, listes numérotées, cases à cocher - [ ], **gras**, *italique*, citations >, blocs de code ```.";
