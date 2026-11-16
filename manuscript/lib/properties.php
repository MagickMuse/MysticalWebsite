<?php
declare(strict_types=1);

/*
 * The Notion-style properties panel at the top of an entry: the entry's own properties
 * (Type, Planet, Also called…) first, then one row per relation with its linked pages.
 * Long relation rows show the first few links; the rest load only when "Show all" is tapped.
 */

const RELATION_PREVIEW = 12;

/** Soft tag colours in the spirit of Notion's, picked by the tag's name so a value always keeps its colour. */
function pill_class(string $value): string
{
    $colors = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
    return 'pill pill-' . $colors[crc32(mb_strtolower(trim($value))) % count($colors)];
}

function chip(array $p): string
{
    return '<a class="rel" href="' . e($p['url']) . '">' . icon_html($p['icon'], 'rel-icon') . '<span>' . e($p['title']) . '</span></a>';
}

/** One relation row: the first few links, and a "Show all" control when there are more. */
function relation_row(array $page, string $name, array $items, bool $showAll): string
{
    $n = count($items);
    $shown = $showAll ? $items : array_slice($items, 0, RELATION_PREVIEW);
    $html = '<div class="rels" data-rels>' . implode('', array_map('chip', $shown));
    if ($n > count($shown)) {
        $all = page_url($page) . '?all=' . rawurlencode($name) . '#' . rawurlencode('rel-' . slugify($name));
        $html .= '<a class="rel-more" href="' . e($all) . '" data-more="' . e(url('relations?page=' . $page['id'] . '&prop=' . rawurlencode($name))) . '">'
            . 'Show all ' . number_format($n) . '</a>';
    }
    return $html . '</div>';
}

function properties_panel(array $page, array $tags, array $groups): string
{
    $rows = '';
    // 1. The entry's own properties (Type, Planet…), as coloured tags or text.
    foreach ($tags as $name => $vals) {
        $isLink = count($vals) === 1 && preg_match('~^https?://~', (string) $vals[0]);
        $value = $isLink
            ? '<a href="' . e($vals[0]) . '" rel="noopener" target="_blank">' . e(preg_replace('~^https?://(www\.)?~', '', $vals[0])) . '</a>'
            : implode('', array_map(fn($v) => mb_strlen($v) > 40 ? '<span class="prop-text">' . e($v) . '</span>' : '<span class="' . pill_class($v) . '">' . e($v) . '</span>', $vals));
        $rows .= '<div class="prop-row"><div class="prop-name"><span class="prop-glyph" aria-hidden="true">◇</span>' . e($name) . '</div><div class="prop-val">' . $value . '</div></div>';
    }
    if ($page['aka']) {
        $rows .= '<div class="prop-row"><div class="prop-name"><span class="prop-glyph" aria-hidden="true">≡</span>Also called</div><div class="prop-val"><span class="prop-text">' . e($page['aka']) . '</span></div></div>';
    }
    // 2. Relations: links to the rest of the Manuscript.
    $open = (string) ($_GET['all'] ?? '');
    foreach ($groups as $name => $items) {
        $rows .= '<div class="prop-row rel-row" id="' . e('rel-' . slugify($name)) . '"><div class="prop-name"><span class="prop-glyph" aria-hidden="true">↗</span>' . e($name)
            . '<small>' . count($items) . '</small></div><div class="prop-val">' . relation_row($page, $name, array_values($items), $open === $name) . '</div></div>';
    }
    return $rows ? '<section class="props-panel" aria-label="Properties">' . $rows . '</section>' : '';
}

/** GET /relations?page=…&prop=…: every link in one relation row, for "Show all". Members only. */
function relations_json(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $page = page_by_id((string) ($_GET['page'] ?? ''));
    if (!$page || !(has_access() || is_public_page($page))) { http_response_code(403); echo '{}'; return; }
    $groups = connections($page);
    $items = $groups[(string) ($_GET['prop'] ?? '')] ?? [];
    echo json_encode(['html' => implode('', array_map('chip', array_values($items))), 'count' => count($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
