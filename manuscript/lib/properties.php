<?php
declare(strict_types=1);

/*
 * The Notion-style properties panel at the top of an entry: the entry's own properties
 * (Type, Planet, Also called…) first, then one row per relation with its linked pages.
 * Long relation rows show the first few links; the rest load only when "Show all" is tapped.
 */

const RELATION_PREVIEW = 12;

/**
 * Properties that are really the page's text: shown in the middle of the page, not with the others.
 * A recipe or ritual reads as Description (an introduction), Quantities (a list) and Directions (numbered steps).
 */
const CONTENT_PROPERTIES = [
    'ritual' => ['Description', 'Quantities', 'Directions'],
    'recipe' => ['Description', 'Quantities', 'Directions'],
];

/** Takes the page's text properties out of $tags and returns them as HTML for the middle of the page. */
function content_properties(string $grimoire, array &$tags): string
{
    $html = '';
    foreach (CONTENT_PROPERTIES[$grimoire] ?? [] as $name) {
        if (!isset($tags[$name])) continue;
        $text = trim(implode("\n", array_map('strval', (array) $tags[$name])));
        unset($tags[$name]);
        if ($text === '') continue;
        $html .= match ($name) {
            'Quantities' => '<h2 id="quantities">Quantities</h2>' . text_as_list($text, false),
            'Directions' => '<h2 id="directions">Directions</h2>' . text_as_list($text, true),
            default => implode('', array_map(fn($p) => '<p class="lead-in">' . nl2br(e(trim($p)), false) . '</p>', preg_split('/\n\s*\n/', $text))),
        };
    }
    return $html;
}

/**
 * Lines typed as a list in Notion ("• Bay leaves", "1. Light the candle") as an HTML list. Lines without a bullet
 * or number belong to the item above them (an incantation inside a step). Text that isn't a list stays a paragraph.
 */
function text_as_list(string $text, bool $numbered): string
{
    $mark = '/^\s*(?:[•●▪◦*\-–]|\d+[.)])\s+/u';
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
    $items = [];
    foreach ($lines as $line) {
        if (preg_match($mark, $line) || !$items) $items[] = [preg_replace($mark, '', $line)];
        else $items[count($items) - 1][] = $line;
    }
    if (count($items) < 2 && !preg_match($mark, $lines[0] ?? '')) return '<p>' . nl2br(e($text), false) . '</p>';
    $tag = $numbered ? 'ol class="steps"' : 'ul class="ingredients"';
    return '<' . $tag . '>' . implode('', array_map(fn($i) => '<li>' . implode('<br>', array_map('e', $i)) . '</li>', $items)) . '</' . strtok($tag, ' ') . '>';
}

/** A property value: plain words in the site's type, separated by small gold stars (no coloured tags). */
function pill_class(string $value): string
{
    return 'pill';
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

/** Where a property value leads: its grimoire's list, showing only the entries with that value. */
function value_link_base(array $page): ?callable
{
    if ($page['kind'] !== 'entry' || !$page['parent_id']) return null;
    $list = page_by_id($page['parent_id']);
    if (!$list || $list['kind'] !== 'collection') return null;
    [$g, $several] = grimoire_lists($page['grimoire']);
    if (!$g) return null;
    return fn(string $prop, string $value) => page_url($g) . '?' . http_build_query(($several ? ['list' => $list['slug']] : []) + [facet_param($prop) => $value])
        . '#list-' . $list['slug'];
}

function properties_panel(array $page, array $tags, array $groups): string
{
    $rows = '';
    $to = value_link_base($page);
    // 1. The entry's own properties (Type, Planet…), as words separated by gold stars.
    foreach ($tags as $name => $vals) {
        $isLink = count($vals) === 1 && preg_match('~^https?://~', (string) $vals[0]);
        $value = $isLink
            ? '<a href="' . e($vals[0]) . '" rel="noopener" target="_blank">' . e(preg_replace('~^https?://(www\.)?~', '', $vals[0])) . '</a>'
            : implode('', array_map(fn($v) => mb_strlen($v) > 40 ? '<span class="prop-text">' . e($v) . '</span>'
                : ($to ? '<a class="' . pill_class($v) . '" href="' . e($to((string) $name, (string) $v)) . '" title="All ' . e((string) $name) . ': ' . e((string) $v) . '">' . e($v) . '</a>'
                    : '<span class="' . pill_class($v) . '">' . e($v) . '</span>'), $vals));
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

/**
 * Lines in a page's text that only repeat its sidebar, like "Day: Friday" on Venus when the Timing row
 * already shows Friday, are left out. Only "Label: a, b, c" lines whose label names a correspondence
 * (Day, Planet, Crystal, Deity…) and whose every value is in the sidebar; anything else stays as written.
 * A heading left with nothing under it goes too. (The GitHub Pages copy was cleaned the same way.)
 */
function drop_sidebar_repeats(string $body, string $panel): string
{
    static $labels = ['day', 'days', 'planet', 'planets', 'ruling planet', 'planetary ruler', 'ruler', 'element', 'elements',
        'sign', 'signs', 'astrological sign', 'astrological signs', 'zodiac', 'zodiac sign', 'zodiac signs',
        'crystal', 'crystals', 'suggested crystal', 'suggested crystals', 'stone', 'stones', 'gemstone', 'gemstones', 'mineral', 'minerals',
        'herb', 'herbs', 'plant', 'plants', 'oil', 'oils', 'incense', 'metal', 'metals',
        'deity', 'deities', 'deity/spirit', 'deities/spirits', 'god', 'gods', 'goddess', 'goddesses', 'entity', 'entities', 'spirit', 'spirits',
        'color', 'colors', 'colour', 'colours', 'number', 'numbers', 'animal', 'animals', 'tarot', 'tarot card', 'tarot cards', 'rune', 'runes',
        'season', 'seasons', 'month', 'months', 'moon phase', 'direction', 'directions', 'chakra', 'chakras', 'gender', 'polarity',
        'folk name', 'folk names', 'other names', 'also known as', 'aka'];
    $norm = function (string $t): string {
        $t = mb_strtolower(html_entity_decode(preg_replace('~<[^>]+>~', ' ', $t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = preg_replace('~[^\w\s\'&-]+~u', ' ', $t);
        return trim(preg_replace('~\s+~u', ' ', $t));
    };
    $forms = function (string $t) use ($norm): array {
        $t = $norm($t);
        $out = [$t, $t . 's'];
        if (str_ends_with($t, 'ies')) $out[] = substr($t, 0, -3) . 'y';
        if (str_ends_with($t, 'es')) $out[] = substr($t, 0, -2);
        if (str_ends_with($t, 's')) $out[] = substr($t, 0, -1);
        return $out;
    };
    // everything the sidebar shows, one value at a time
    $vals = [];
    preg_match_all('~<div class="prop-val">(.*?)</div>~s', $panel, $rows);
    foreach ($rows[1] as $v) {
        preg_match_all('~<(?:a|span)[^>]*>(?:<[^>]+>)*([^<]+)~', $v, $chips);
        foreach (array_merge($chips[1], [preg_replace('~<[^>]+>~', '|', $v)]) as $piece) {
            foreach (preg_split('~[|,]~', $piece) as $x) {
                if ($norm($x) !== '') foreach ($forms($x) as $f) $vals[$f] = true;
            }
        }
    }
    if (!$vals) return $body;
    $emptyHeadings = function (string $t): array {
        preg_match_all('~<h([2-5]) id="[^"]*"[^>]*>.*?</h\1>~s', $t, $h, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $out = [];
        foreach ($h as $i => $m) {
            $end = $m[0][1] + strlen($m[0][0]);
            $next = $h[$i + 1] ?? null;
            $between = substr($t, $end, ($next ? $next[0][1] : strlen($t)) - $end);
            if (trim($between) === '' && (!$next || (int) $next[1][0] <= (int) $m[1][0])) $out[] = $m[0][0];
        }
        return $out;
    };
    $new = preg_replace_callback('~<(p|li)>\s*<(strong|b)>([^<]{1,40}?):\s*</\2>\s*(.*?)</\1>~s', function ($m) use ($labels, $vals, $forms) {
        [$all, , , $label, $value] = $m;
        if (!in_array(preg_replace('~\s+~', ' ', mb_strtolower(trim($label))), $labels, true)) return $all;
        if (str_contains($value, '<') && !preg_match('~^(?:\s*<a [^>]*>[^<]*</a>\s*[,;]?\s*(?:and\s*)?)+$~', $value)) return $all;
        $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('~,?\s+and\s+(?=[^,]*$)~', ', ', rtrim(trim($plain), '.'));
        $items = array_values(array_filter(array_map('trim', preg_split('~\s*[,;·]\s*~u', $plain)), 'strlen'));
        if (!$items) return $all;
        foreach ($items as $x) {
            if (mb_strlen($x) > 40 || count(preg_split('~\s+~', $x)) > 5) return $all;   // a sentence, not a list
            if (!array_intersect_key(array_flip($forms($x)), $vals)) return $all;         // not in the sidebar: keep the line
        }
        return '';
    }, $body);
    $new = preg_replace('~<ul>\s*</ul>~', '', $new);
    if ($new === $body) return $body;
    foreach (array_diff($emptyHeadings($new), $emptyHeadings($body)) as $h) {
        $pos = strpos($new, $h);
        if ($pos !== false) $new = substr_replace($new, '', $pos, strlen($h));
    }
    return $new;
}
