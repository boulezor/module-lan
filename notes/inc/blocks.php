<?php
declare(strict_types=1);

/*
 * Contenu des pages : format JSON d'Editor.js ({"blocks": [{"type": "...", "data": {...}}]}).
 * Ici : nettoyage de sécurité (liste blanche), texte brut pour la recherche, export / import Markdown.
 */

const BLOCK_TYPES = ['paragraph', 'header', 'list', 'quote', 'code', 'delimiter', 'table', 'image',
                     'attaches', 'embed', 'callout', 'toggle', 'pageLink', 'toc'];

/** Une URL acceptable dans un lien ou un média : http(s), mailto, ou fichier interne. */
function safe_url(string $url, bool $allowMailto = false): string
{
    $url = trim($url);
    if (preg_match('#^file\.php\?f=[a-f0-9]{32}$#', $url)) {
        return $url;
    }
    if (preg_match('#^index\.php\?p=\d+$#', $url)) {
        return $url;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (in_array($scheme, ['http', 'https'], true) || ($allowMailto && $scheme === 'mailto')) {
        return filter_var($url, FILTER_VALIDATE_URL) || $scheme === 'mailto' ? $url : '';
    }
    return '';
}

/** HTML « inline » d'Editor.js : on ne garde que gras, italique, souligné, barré, surligné, code, liens, retours à la ligne. */
function sanitize_inline_html(string $html): string
{
    if ($html === '' || !str_contains($html, '<') && !str_contains($html, '&')) {
        return $html;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root') ?? $doc->documentElement;
    if (!$root) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }
    sanitize_node($root);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

function sanitize_node(DOMNode $node): void
{
    $allowed = ['b', 'strong', 'i', 'em', 'u', 's', 'mark', 'code', 'a', 'br'];
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) {
            continue;
        }
        if (!$child instanceof DOMElement) {
            $node->removeChild($child); // commentaires, etc.
            continue;
        }
        $tag = strtolower($child->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template'], true)) {
            $node->removeChild($child);
            continue;
        }
        sanitize_node($child);
        if (!in_array($tag, $allowed, true)) {
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child); // on garde le texte
            }
            $node->removeChild($child);
            continue;
        }
        $keep = [];
        if ($tag === 'a') {
            $href = safe_url($child->getAttribute('href'), true);
            if ($href !== '') {
                $keep['href'] = $href;
            }
        } elseif (in_array($tag, ['mark', 'code'], true) && preg_match('/^[a-z0-9 -]+$/i', $child->getAttribute('class'))) {
            $keep['class'] = $child->getAttribute('class');
        }
        foreach (iterator_to_array($child->attributes) as $attr) {
            $child->removeAttribute($attr->name);
        }
        foreach ($keep as $name => $value) {
            $child->setAttribute($name, $value);
        }
    }
}

/** Nettoie récursivement les textes d'un bloc (sauf les champs gérés à part). */
function sanitize_value(mixed $value): mixed
{
    if (is_string($value)) {
        return sanitize_inline_html($value);
    }
    if (is_array($value)) {
        return array_map('sanitize_value', $value);
    }
    return is_bool($value) || is_int($value) || is_float($value) || $value === null ? $value : null;
}

/**
 * Contenu JSON envoyé par l'éditeur → contenu nettoyé (blocs inconnus ou dangereux retirés).
 */
function sanitize_content(array $content): array
{
    $blocks = [];
    foreach (is_array($content['blocks'] ?? null) ? $content['blocks'] : [] as $block) {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        if (!in_array($type, BLOCK_TYPES, true)) {
            continue;
        }
        switch ($type) {
            case 'code':
                $data = ['code' => mb_substr((string) ($data['code'] ?? ''), 0, 100000)];
                break;
            case 'image':
                $url = safe_url((string) ($data['file']['url'] ?? ''));
                if ($url === '') {
                    continue 2;
                }
                $data = [
                    'file' => ['url' => $url],
                    'caption' => sanitize_inline_html((string) ($data['caption'] ?? '')),
                    'withBorder' => !empty($data['withBorder']),
                    'stretched' => !empty($data['stretched']),
                    'withBackground' => !empty($data['withBackground']),
                ];
                break;
            case 'attaches':
                $url = safe_url((string) ($data['file']['url'] ?? ''));
                if ($url === '') {
                    continue 2;
                }
                $data = [
                    'file' => [
                        'url' => $url,
                        'name' => clean_line((string) ($data['file']['name'] ?? ''), 200),
                        'size' => (int) ($data['file']['size'] ?? 0),
                        'extension' => preg_replace('/[^a-z0-9]/i', '', (string) ($data['file']['extension'] ?? '')),
                    ],
                    'title' => clean_line(strip_tags((string) ($data['title'] ?? '')), 200),
                ];
                break;
            case 'embed':
                $embed = (string) ($data['embed'] ?? '');
                $source = safe_url((string) ($data['source'] ?? ''));
                if (!str_starts_with($embed, 'https://') || $source === '') {
                    continue 2;
                }
                $data = [
                    'service' => preg_replace('/[^a-z0-9-]/i', '', (string) ($data['service'] ?? '')),
                    'source' => $source,
                    'embed' => $embed,
                    'width' => (int) ($data['width'] ?? 580),
                    'height' => (int) ($data['height'] ?? 320),
                    'caption' => sanitize_inline_html((string) ($data['caption'] ?? '')),
                ];
                break;
            case 'pageLink':
                $data = ['pageId' => (int) ($data['pageId'] ?? 0)];
                break;
            case 'callout':
                $data = [
                    'emoji' => mb_substr(strip_tags((string) ($data['emoji'] ?? '💡')), 0, 8),
                    'color' => in_array($data['color'] ?? '', ['gray', 'blue', 'green', 'yellow', 'red', 'purple'], true) ? $data['color'] : 'gray',
                    'text' => sanitize_inline_html((string) ($data['text'] ?? '')),
                ];
                break;
            case 'header':
                $data = ['text' => sanitize_inline_html((string) ($data['text'] ?? '')), 'level' => max(1, min(4, (int) ($data['level'] ?? 2)))];
                break;
            default:
                $data = sanitize_value($data);
        }
        $blocks[] = array_filter(['id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($block['id'] ?? '')) ?: null,
                                  'type' => $type, 'data' => $data], fn($v) => $v !== null);
    }
    return ['time' => (int) ($content['time'] ?? 0), 'blocks' => $blocks, 'version' => (string) ($content['version'] ?? '')];
}

/** Texte inline HTML → texte brut */
function inline_text(string $html): string
{
    return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Éléments de liste (Editor.js List 2.x, imbriqués) → lignes de texte */
function list_lines(array $items, string $style, int $depth = 0): array
{
    $lines = [];
    foreach ($items as $i => $item) {
        $content = is_array($item) ? (string) ($item['content'] ?? '') : (string) $item;
        $prefix = match ($style) {
            'ordered' => ($i + 1) . '. ',
            'checklist' => !empty($item['meta']['checked']) ? '- [x] ' : '- [ ] ',
            default => '- ',
        };
        $lines[] = str_repeat('  ', $depth) . $prefix . inline_markdown($content);
        if (is_array($item) && !empty($item['items'])) {
            array_push($lines, ...list_lines($item['items'], $style, $depth + 1));
        }
    }
    return $lines;
}

/** Texte brut de toute la page (recherche, IA). */
function blocks_to_text(array $content): string
{
    $parts = [];
    $walk = function ($value) use (&$walk, &$parts) {
        if (is_string($value)) {
            $text = inline_text($value);
            if ($text !== '' && !preg_match('#^(https?://|file\.php)#', $text)) {
                $parts[] = $text;
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $v) {
                if (!in_array($key, ['url', 'embed', 'source', 'service', 'extension', 'color', 'level', 'style'], true)) {
                    $walk($v);
                }
            }
        }
    };
    foreach ($content['blocks'] ?? [] as $block) {
        $walk($block['data'] ?? []);
    }
    return implode("\n", $parts);
}

/** HTML inline → Markdown inline */
function inline_markdown(string $html): string
{
    $md = preg_replace_callback('#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', fn($m) => '[' . strip_tags($m[2]) . '](' . html_entity_decode($m[1]) . ')', $html);
    $md = preg_replace('#</?(b|strong)>#i', '**', (string) $md);
    $md = preg_replace('#</?(i|em)>#i', '*', (string) $md);
    $md = preg_replace('#</?s>#i', '~~', (string) $md);
    $md = preg_replace('#<code[^>]*>(.*?)</code>#is', '`$1`', (string) $md);
    $md = preg_replace('#<br\s*/?>#i', "  \n", (string) $md);
    return html_entity_decode(strip_tags((string) $md), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Export Markdown d'une page.
 *
 * @param callable(int): ?array $pageTitle  titre d'une page liée (null si inaccessible)
 */
function blocks_to_markdown(array $content, callable $pageTitle): string
{
    $out = [];
    foreach ($content['blocks'] ?? [] as $block) {
        $d = $block['data'] ?? [];
        $out[] = match ($block['type']) {
            'header' => str_repeat('#', (int) ($d['level'] ?? 2)) . ' ' . inline_markdown((string) ($d['text'] ?? '')),
            'paragraph' => inline_markdown((string) ($d['text'] ?? '')),
            'list' => implode("\n", list_lines($d['items'] ?? [], (string) ($d['style'] ?? 'unordered'))),
            'quote' => '> ' . str_replace("\n", "\n> ", inline_markdown((string) ($d['text'] ?? ''))),
            'code' => "```\n" . ($d['code'] ?? '') . "\n```",
            'delimiter' => '---',
            'table' => table_markdown($d),
            'image' => '![' . inline_text((string) ($d['caption'] ?? '')) . '](' . ($d['file']['url'] ?? '') . ')',
            'attaches' => '📎 [' . ($d['title'] ?: ($d['file']['name'] ?? 'fichier')) . '](' . ($d['file']['url'] ?? '') . ')',
            'embed' => '[' . ($d['service'] ?? 'vidéo') . '](' . ($d['source'] ?? '') . ')',
            'callout' => '> ' . ($d['emoji'] ?? '💡') . ' ' . inline_markdown((string) ($d['text'] ?? '')),
            'toggle' => '**▸ ' . inline_markdown((string) ($d['title'] ?? '')) . "**\n\n" . inline_markdown((string) ($d['text'] ?? '')),
            'pageLink' => ($t = $pageTitle((int) ($d['pageId'] ?? 0))) !== null ? '📄 ' . $t : '',
            'toc' => '',
            default => '',
        };
    }
    return trim(implode("\n\n", array_filter($out, fn($s) => $s !== ''))) . "\n";
}

function table_markdown(array $d): string
{
    $rows = $d['content'] ?? [];
    if (!$rows) {
        return '';
    }
    $cell = fn($c) => str_replace('|', '\\|', inline_markdown((string) $c));
    $lines = [];
    foreach ($rows as $i => $row) {
        $lines[] = '| ' . implode(' | ', array_map($cell, $row)) . ' |';
        if ($i === 0) {
            $lines[] = '|' . str_repeat(' --- |', count($row));
        }
    }
    return implode("\n", $lines);
}

/** Markdown inline → HTML inline (texte échappé d'abord). */
function markdown_inline_html(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace('/`([^`]+)`/', '<code class="inline-code">$1</code>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', (string) $html);
    $html = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<i>$1</i>', (string) $html);
    $html = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', (string) $html);
    return (string) $html;
}

/**
 * Markdown (réponse de l'IA) → blocs Editor.js.
 *
 * @return list<array{type: string, data: array}>
 */
function markdown_to_blocks(string $markdown): array
{
    $blocks = [];
    $lines = preg_split('/\r?\n/', trim($markdown)) ?: [];
    $paragraph = [];
    $list = null;
    $flushParagraph = function () use (&$paragraph, &$blocks) {
        if ($paragraph) {
            $blocks[] = ['type' => 'paragraph', 'data' => ['text' => implode('<br>', array_map('markdown_inline_html', $paragraph))]];
            $paragraph = [];
        }
    };
    $flushList = function () use (&$list, &$blocks) {
        if ($list) {
            $blocks[] = ['type' => 'list', 'data' => ['style' => $list['style'], 'meta' => (object) [], 'items' => $list['items']]];
            $list = null;
        }
    };
    for ($i = 0; $i < count($lines); $i++) {
        $line = rtrim($lines[$i]);
        if (preg_match('/^```/', $line)) {
            $flushParagraph();
            $flushList();
            $code = [];
            while (++$i < count($lines) && !preg_match('/^```/', $lines[$i])) {
                $code[] = $lines[$i];
            }
            $blocks[] = ['type' => 'code', 'data' => ['code' => implode("\n", $code)]];
            continue;
        }
        if (trim($line) === '') {
            $flushParagraph();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
            $flushParagraph();
            $flushList();
            $blocks[] = ['type' => 'header', 'data' => ['text' => markdown_inline_html($m[2]), 'level' => max(2, strlen($m[1]))]];
        } elseif (preg_match('/^\s*[-*]\s+\[( |x|X)\]\s+(.+)$/', $line, $m)) {
            $flushParagraph();
            if (($list['style'] ?? '') !== 'checklist') {
                $flushList();
                $list = ['style' => 'checklist', 'items' => []];
            }
            $list['items'][] = ['content' => markdown_inline_html($m[2]), 'meta' => ['checked' => strtolower($m[1]) === 'x'], 'items' => []];
        } elseif (preg_match('/^\s*([-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) {
            $flushParagraph();
            $style = ctype_digit($m[1][0]) ? 'ordered' : 'unordered';
            if (($list['style'] ?? '') !== $style) {
                $flushList();
                $list = ['style' => $style, 'items' => []];
            }
            $list['items'][] = ['content' => markdown_inline_html($m[2]), 'meta' => (object) [], 'items' => []];
        } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushParagraph();
            $flushList();
            $blocks[] = ['type' => 'quote', 'data' => ['text' => markdown_inline_html($m[1]), 'caption' => '', 'alignment' => 'left']];
        } elseif (preg_match('/^(-{3,}|\*{3,})$/', trim($line))) {
            $flushParagraph();
            $flushList();
            $blocks[] = ['type' => 'delimiter', 'data' => (object) []];
        } else {
            $flushList();
            $paragraph[] = $line;
        }
    }
    $flushParagraph();
    $flushList();
    return $blocks;
}
