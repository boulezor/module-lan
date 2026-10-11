<?php
declare(strict_types=1);

/**
 * Recherche automatique d'informations sur un jeu, sans clé API obligatoire :
 *   1. Steam (description FR, image, genres, modes multijoueur/LAN)
 *   2. Wikipédia FR si Steam ne connaît pas le jeu
 *   3. OpenAI (optionnel) pour réécrire une description orientée LAN
 *   4. YouTube : vidéo directe si clé API, sinon lien de recherche
 *
 * @return array{title: string, description: string, tags: string, image_url: string,
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

    if (config('openai_api_key')) {
        $ai = openai_describe($result['title'], $result['description'], $result['tags']);
        if ($ai !== null) {
            $result['description'] = $ai;
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
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    $headers[] = 'Accept: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => (int) config('http_timeout'),
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
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
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
    $extract = (string) ($summary['extract'] ?? '');
    if ($extract === '') {
        return null;
    }

    return [
        'title' => $title,
        'description' => shorten($extract),
        'image_url' => (string) ($summary['thumbnail']['source'] ?? ''),
        'store_url' => (string) ($summary['content_urls']['desktop']['page'] ?? ''),
    ];
}

function openai_describe(string $title, string $context, string $tags): ?string
{
    $prompt = "Rédige en français une description courte (2 à 3 phrases, 350 caractères maximum) du jeu vidéo « {$title} » "
        . "pour des amis qui votent pour leur prochaine LAN party : genre, principe du jeu et intérêt en multijoueur "
        . "(nombre de joueurs si tu le connais). Texte brut, sans markdown. "
        . "Si tu ne connais pas ce jeu et que le contexte ne suffit pas, réponds exactement : INCONNU.";
    if ($context !== '' || $tags !== '') {
        $prompt .= "\n\nContexte : {$context}\nCatégories : {$tags}";
    }

    $response = http_json('https://api.openai.com/v1/chat/completions', [
        'model' => config('openai_model'),
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 250,
    ], ['Authorization: Bearer ' . config('openai_api_key')]);

    $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    return $text === '' || str_contains($text, 'INCONNU') ? null : $text;
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
