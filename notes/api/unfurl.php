<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';

/*
 * Aperçu de lien : récupère titre, description et image (balises Open Graph) d'une page web publique.
 * Protégé contre les requêtes vers le réseau interne (adresses privées refusées, à chaque redirection).
 */

require_api_user(false);
session_write_close();

$url = trim(is_string($_GET['url'] ?? null) ? $_GET['url'] : '');
if (safe_url($url) === '' || !preg_match('#^https?://#i', $url)) {
    json_response(['error' => 'Adresse invalide (http ou https uniquement).'], 400);
}

/** Adresse IP publique pour cet hôte, ou null (réseau interne, introuvable). */
function public_ip(string $host): ?string
{
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    foreach ($ips as $ip) {
        if (!config('unfurl_allow_private') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // une seule adresse interne suffit à refuser
        }
    }
    return $ips[0] ?? null;
}

/** Télécharge le début d'une page HTML en suivant au plus 3 redirections (vérifiées une à une). */
function fetch_html(string $url): array
{
    for ($hop = 0; $hop < 4; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $portOk = in_array($port, [80, 443], true) || config('unfurl_allow_private');
        if (!in_array($scheme, ['http', 'https'], true) || !$portOk || $host === '') {
            return [null, 'Adresse non autorisée.', $url];
        }
        $ip = public_ip($host);
        if ($ip === null) {
            return [null, 'Adresse non autorisée (réseau interne ou introuvable).', $url];
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], // évite un changement d'adresse entre vérification et requête
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NotesLinkPreview/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: fr,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > 1500000 ? 0 : strlen($chunk); // 1,5 Mo max
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($status >= 300 && $status < 400 && $location !== '') {
            $url = $location;
            continue;
        }
        if ($status < 200 || $status >= 300 || $body === '') {
            return [null, "La page ne répond pas (HTTP {$status}).", $url];
        }
        if ($type !== '' && !str_contains($type, 'html')) {
            return ['', '', $url]; // pas une page HTML (PDF, image…) : aperçu minimal
        }
        return [$body, '', $url];
    }
    return [null, 'Trop de redirections.', $url];
}

[$html, $error, $finalUrl] = fetch_html($url);
if ($html === null) {
    json_response(['error' => $error], 422);
}

$meta = [];
$title = '';
$icon = '';
if ($html !== '') {
    if (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4000), $m) && strtolower($m[1]) !== 'utf-8') {
        $html = (string) @mb_convert_encoding($html, 'UTF-8', $m[1]);
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    foreach ($doc->getElementsByTagName('meta') as $tag) {
        $key = strtolower($tag->getAttribute('property') ?: $tag->getAttribute('name'));
        if ($key !== '' && !isset($meta[$key])) {
            $meta[$key] = trim($tag->getAttribute('content'));
        }
    }
    $title = trim((string) ($doc->getElementsByTagName('title')->item(0)?->textContent ?? ''));
    foreach ($doc->getElementsByTagName('link') as $link) {
        if (preg_match('/\bicon\b/i', $link->getAttribute('rel'))) {
            $icon = $link->getAttribute('href');
            break;
        }
    }
}

/** URL relative → absolue (par rapport à la page) */
$absolute = function (string $ref) use ($finalUrl): string {
    if ($ref === '' || preg_match('#^https?://#i', $ref)) {
        return $ref;
    }
    $p = parse_url($finalUrl);
    $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($ref, '//')) {
        return $p['scheme'] . ':' . $ref;
    }
    if (str_starts_with($ref, '/')) {
        return $base . $ref;
    }
    return $base . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $ref;
};

$host = (string) parse_url($finalUrl, PHP_URL_HOST);
json_response([
    'url' => $url,
    'title' => clean_line(html_entity_decode($meta['og:title'] ?? $meta['twitter:title'] ?? $title, ENT_QUOTES, 'UTF-8') ?: $host, 200),
    'description' => clean_line(html_entity_decode($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? '', ENT_QUOTES, 'UTF-8'), 300),
    'image' => safe_url($absolute($meta['og:image'] ?? $meta['twitter:image'] ?? '')),
    'site' => clean_line($meta['og:site_name'] ?? preg_replace('/^www\./', '', $host), 80),
    'favicon' => safe_url($absolute($icon ?: '/favicon.ico')),
]);
