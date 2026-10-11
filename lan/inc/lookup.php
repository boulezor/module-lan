<?php
declare(strict_types=1);

/**
 * Recherche automatique d'informations sur un jeu, sans clé API obligatoire :
 *   1. Steam (description FR, image, genres, modes multijoueur/LAN)
 *   2. Wikipédia FR si Steam ne connaît pas le jeu (3-4 premières phrases, nettoyées)
 *   3. OpenAI (optionnel, clé dans l'admin ou la config) : description de 5 à 10 phrases
 *      avec points forts / points faibles, et nombre de joueurs
 *   4. YouTube : vidéo directe si clé API, sinon lien de recherche
 *
 * @return array{title: string, description: string, tags: string, image_url: string, players: string,
 *               video_url: string, store_url: string, steam_appid: ?int, source: string}
 */
function lookup_game(string $title): array
{
    $cacheKey = normalize_key($title);
    if (isset($_SESSION['lookup_cache'][$cacheKey])) {
        return $_SESSION['lookup_cache'][$cacheKey];
    }

    $result = [
        'title' => $title,
        'description' => '',
        'tags' => '',
        'image_url' => '',
        'players' => '',
        'video_url' => '',
        'store_url' => '',
        'steam_appid' => null,
        'source' => '',
    ];

    $steam = steam_lookup($title);
    if ($steam !== null) {
        $result = array_replace($result, $steam, ['source' => 'Steam']);
    } elseif (($wiki = wikipedia_lookup($title)) !== null) {
        $result = array_replace($result, $wiki, ['source' => 'Wikipédia']);
    }

    $context = $result['context'] ?? $result['description'];
    unset($result['context']);
    if (openai_key() !== '') {
        $ai = openai_describe($result['title'], $context, $result['tags']);
        if ($ai !== null) {
            $result['description'] = $ai['description'];
            $result['players'] = $ai['players'];
            $result['source'] = trim($result['source'] . ' + IA', ' +');
        }
    }

    $result['video_url'] = youtube_lookup($result['title']);

    // On ne met en cache que les recherches abouties (évite de figer une panne réseau).
    if ($result['source'] !== '') {
        $_SESSION['lookup_cache'][$cacheKey] = $result;
    }
    return $result;
}

function http_json(string $url, ?array $postJson = null, array $headers = []): ?array
{
    return http_request($url, $postJson, $headers)['data'];
}

/**
 * Requête HTTP JSON avec le détail de l'erreur (utile pour le bouton « Tester » de l'admin).
 *
 * @return array{data: ?array, error: string}
 */
function http_request(string $url, ?array $postJson = null, array $headers = [], ?int $timeout = null): array
{
    if (!function_exists('curl_init')) {
        return ['data' => null, 'error' => "L'extension PHP curl n'est pas installée."];
    }
    $ch = curl_init($url);
    $headers[] = 'Accept: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout ?? (int) config('http_timeout'),
        CURLOPT_USERAGENT => 'LanVote/1.0 (petit module de vote pour LAN party)',
    ]);
    if ($postJson !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postJson));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_string($body) || $status < 200 || $status >= 300) {
        $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? '') : '';
        return ['data' => null, 'error' => $curlError ?: trim("HTTP {$status} {$message}")];
    }
    return is_array($data) ? ['data' => $data, 'error' => ''] : ['data' => null, 'error' => 'Réponse illisible.'];
}

function plain_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** Coupe proprement à la fin d'une phrase. */
function shorten(string $text, int $max = 450): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $lastDot = mb_strrpos($cut, '. ');
    return $lastDot !== false && $lastDot > $max / 2 ? mb_substr($cut, 0, $lastDot + 1) : rtrim($cut) . '…';
}

function steam_lookup(string $title): ?array
{
    $country = (string) config('steam_country');
    $search = http_json('https://store.steampowered.com/api/storesearch/?' . http_build_query([
        'term' => $title, 'l' => 'french', 'cc' => $country,
    ]));
    $items = $search['items'] ?? [];
    if (!$items) {
        return null;
    }

    // Meilleure correspondance parmi les premiers résultats
    $key = normalize_key($title);
    $best = null;
    $bestScore = 0.0;
    foreach (array_slice($items, 0, 5) as $item) {
        $score = similarity($key, normalize_key((string) ($item['name'] ?? '')));
        if ($score > $bestScore) {
            [$best, $bestScore] = [$item, $score];
        }
    }
    if ($best === null || $bestScore < 0.6) {
        return null;
    }

    $appId = (int) $best['id'];
    $details = http_json('https://store.steampowered.com/api/appdetails?' . http_build_query([
        'appids' => $appId, 'l' => 'french', 'cc' => $country,
    ]));
    $data = ($details[$appId]['success'] ?? false) ? $details[$appId]['data'] : null;

    $result = [
        'title' => (string) ($best['name'] ?? $title),
        'steam_appid' => $appId,
        'store_url' => 'https://store.steampowered.com/app/' . $appId . '/',
        'image_url' => (string) ($best['tiny_image'] ?? ''),
        'description' => '',
        'tags' => '',
    ];
    if (!is_array($data)) {
        return $result;
    }

    // Catégories Steam utiles pour une LAN (multijoueur, coop, écran partagé, LAN…)
    $usefulCategories = [1, 9, 24, 27, 36, 37, 38, 39, 44, 47, 48, 49];
    $tags = [];
    if (!empty($data['is_free'])) {
        $tags[] = 'Gratuit';
    }
    foreach ($data['genres'] ?? [] as $genre) {
        $tags[] = (string) $genre['description'];
    }
    foreach ($data['categories'] ?? [] as $category) {
        if (in_array((int) $category['id'], $usefulCategories, true)) {
            $tags[] = (string) $category['description'];
        }
    }

    return array_replace($result, [
        'title' => (string) ($data['name'] ?? $result['title']),
        'description' => shorten(plain_text((string) ($data['short_description'] ?? ''))),
        'image_url' => (string) ($data['header_image'] ?? $result['image_url']),
        'tags' => implode(', ', array_unique(array_filter($tags))),
    ]);
}

function wikipedia_lookup(string $title): ?array
{
    $search = http_json('https://fr.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'list' => 'search', 'format' => 'json',
        'srsearch' => $title . ' jeu vidéo', 'srlimit' => 1,
    ]));
    $pageTitle = $search['query']['search'][0]['title'] ?? null;
    if (!is_string($pageTitle)) {
        return null;
    }

    $summary = http_json('https://fr.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $pageTitle)));
    $extract = clean_wiki_text((string) ($summary['extract'] ?? ''));
    if ($extract === '') {
        return null;
    }

    return [
        'title' => $title,
        'description' => first_sentences($extract, 4, 600),
        'context' => shorten($extract, 1500), // texte plus complet pour l'IA
        'image_url' => (string) ($summary['thumbnail']['source'] ?? ''),
        'store_url' => (string) ($summary['content_urls']['desktop']['page'] ?? ''),
    ];
}

/** Retire de Wikipédia ce qui alourdit la lecture : parenthèses, notes [1], espaces en trop. */
function clean_wiki_text(string $text): string
{
    do {
        $text = (string) preg_replace('/\s*\([^()]*\)/u', '', $text, -1, $count); // parenthèses (même imbriquées)
    } while ($count > 0);
    $text = (string) preg_replace('/\[[^\]]*\]/u', '', $text);
    $text = (string) preg_replace('/\s+([,.;:])/u', '$1', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** Les N premières phrases d'un texte (sans dépasser $maxLength caractères). */
function first_sentences(string $text, int $count, int $maxLength): string
{
    $sentences = preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-ÖØ-Þ«"])/u', $text) ?: [$text];
    $result = '';
    foreach (array_slice($sentences, 0, $count) as $sentence) {
        if ($result !== '' && mb_strlen($result . ' ' . $sentence) > $maxLength) {
            break;
        }
        $result = trim($result . ' ' . $sentence);
    }
    return shorten($result, $maxLength);
}

// --- OpenAI ------------------------------------------------------------

/** Clé OpenAI : fichier de config en priorité, sinon celle enregistrée dans l'admin. */
function openai_key(): string
{
    return (string) (config('openai_api_key') ?: setting('openai_api_key'));
}

function openai_model(): string
{
    return setting('openai_model') ?: (string) config('openai_model');
}

/**
 * Appel minimal à l'API Chat Completions d'OpenAI.
 *
 * @return array{text: ?string, error: string}
 */
function openai_chat(string $prompt, int $maxTokens, bool $json = false): array
{
    $payload = [
        'model' => openai_model(),
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_completion_tokens' => $maxTokens,
    ];
    if ($json) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    $response = http_request(rtrim((string) config('openai_base_url'), '/') . '/chat/completions', $payload,
        ['Authorization: Bearer ' . openai_key()], (int) config('openai_timeout'));

    $text = trim((string) ($response['data']['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        $error = $response['error'] ?: 'Réponse vide (augmenter la limite de jetons ou changer de modèle).';
        set_setting('openai_last_error', date('d/m H:i') . ' — ' . $error);
        return ['text' => null, 'error' => $error];
    }
    return ['text' => $text, 'error' => ''];
}

/**
 * Description « qui donne envie » (5 à 10 phrases, points forts / faibles) + nombre de joueurs.
 *
 * @return array{description: string, players: string}|null
 */
function openai_describe(string $title, string $context, string $tags): ?array
{
    $prompt = <<<TXT
        Tu aides un groupe d'amis à choisir les jeux de leur prochaine LAN party.
        Pour le jeu vidéo « {$title} », réponds en JSON avec exactement deux clés :
        - "description" : texte en français de 5 à 10 phrases au total, qui donne envie d'y jouer en LAN :
          un paragraphe qui présente le genre, le principe et l'intérêt à plusieurs ;
          puis une ligne qui commence par « 👍 Points forts : » ;
          puis une ligne qui commence par « 👎 Points faibles : » (honnête : prix, durée d'une partie, courbe d'apprentissage…).
          Sépare ces trois parties par un saut de ligne. Pas de markdown.
        - "players" : le nombre de joueurs en multijoueur, très court (ex. « 2 à 8 », « jusqu'à 16 », « 4 par équipe »),
          ou une chaîne vide si tu n'es pas sûr.
        Si tu ne connais pas ce jeu et que le contexte ne suffit pas, réponds {"description": "", "players": ""}.
        TXT;
    if ($context !== '' || $tags !== '') {
        $prompt .= "\n\nContexte : {$context}\nCatégories Steam : {$tags}";
    }

    $text = openai_chat($prompt, 1500, true)['text'];
    $data = $text !== null ? json_decode($text, true) : null;
    $description = trim((string) ($data['description'] ?? ''));
    if ($description === '') {
        return null;
    }
    return [
        'description' => mb_substr($description, 0, 2000),
        'players' => clean_line((string) ($data['players'] ?? ''), 30),
    ];
}

function youtube_lookup(string $title): string
{
    $key = (string) config('youtube_api_key');
    if ($key !== '') {
        $search = http_json('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'part' => 'snippet', 'type' => 'video', 'maxResults' => 1,
            'q' => $title . ' gameplay trailer', 'key' => $key,
        ]));
        $videoId = $search['items'][0]['id']['videoId'] ?? null;
        if (is_string($videoId) && $videoId !== '') {
            return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        }
    }
    return youtube_search_url($title);
}
