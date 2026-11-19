<?php
declare(strict_types=1);

/*
 * A grimoire's list of entries, shown right on the grimoire page, with Notion-style views:
 * filter by any shared property (Type, Element…), group by it, sort (A–Z, most connected, most
 * read, recently updated), jump by letter, search within the grimoire, and "only my saved".
 * The controls sit behind one "Sort & filter" button. The view lives in the address, so it can
 * be bookmarked and works without JavaScript. 120 entries show at a time, with "Show more".
 * When a grimoire has several databases (e.g. Tarot, Lenormand, Playing cards), each list has
 * its own view, told apart by ?list=<database>.
 */

const COLLECTION_PAGE = 120;
const GROUP_PREVIEW = 24;

/** All entries of a collection with what the views need. */
function collection_entries(string $collectionId): array
{
    $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, aka, tags_json, notion_edited FROM pages WHERE parent_id = ? AND kind = 'entry'");
    $st->execute([$collectionId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['icon'] = shown_icon($r);
        $t = json_decode($r['tags_json'] ?? '[]', true) ?: [];
        unset($t['_images']);
        $r['tags'] = $t;
    }
    return $rows;
}

/** Properties never offered in Sort & filter, though entries still show them (config "hidden_filters" can replace the list). */
function hidden_filters(): array
{
    return array_map('mb_strtolower', (array) config('hidden_filters', ['Precautions', 'Notes/Warnings', 'Notes', 'Cleavage', 'Luster', 'Description', 'Directions', 'Quantities']));
}

/** Properties that work as filters: a handful of short values shared by many entries. */
function collection_facets(array $entries): array
{
    $values = [];
    $hidden = hidden_filters();
    foreach ($entries as $e) foreach ($e['tags'] as $prop => $vals) foreach ($vals as $v) {
        if (in_array(mb_strtolower((string) $prop), $hidden, true)) continue 2;
        if (mb_strlen($v) > 32) continue 2;
        $values[$prop][$v] = ($values[$prop][$v] ?? 0) + 1;
    }
    $facets = [];
    $min = max(2, (int) ceil(count($entries) * 0.05));
    foreach ($values as $prop => $counts) {
        $withValue = 0;
        foreach ($entries as $e) if (!empty($e['tags'][$prop])) $withValue++;
        if (count($counts) < 2 || count($counts) > 40 || $withValue < $min) continue;
        uksort($counts, 'strnatcasecmp');
        $facets[$prop] = $counts;
    }
    ksort($facets, SORT_NATURAL | SORT_FLAG_CASE);
    return $facets;
}

function facet_param(string $prop): string { return 'f_' . slugify($prop); }

/**
 * What a list is grouped by until the reader picks something else: its "Type" property (or "Spread Type",
 * or "Category"), if it has one that works as a filter. A long A–Z list is a lot to take in; grouped by type it reads in sections.
 */
function default_group(array $facets): string
{
    foreach (['/^type$/i', '/\btype\b/i', '/^categor/i'] as $pattern) {
        foreach (array_keys($facets) as $prop) if (preg_match($pattern, (string) $prop)) return (string) $prop;
    }
    return '';
}

function default_state(string $group = ''): array
{
    return ['filters' => [], 'sort' => 'az', 'group' => $group, 'defaultGroup' => $group, 'letter' => '', 'q' => '', 'saved' => false, 'page' => 1];
}

/** The view from the address, if it is meant for this list. */
function collection_state(array $facets, bool $mine, array $all = []): array
{
    $default = default_group($facets);
    if (!$mine) return default_state($default);
    // Any property the entries have can filter the list (a value clicked on an entry's page links here),
    // not only the handful offered as filter chips.
    $filters = [];
    foreach ($all as $e) foreach ($e['tags'] as $prop => $vals) {
        $v = (string) ($_GET[facet_param((string) $prop)] ?? '');
        if ($v !== '' && !isset($filters[$prop]) && in_array($v, array_map('strval', (array) $vals), true)) $filters[$prop] = $v;
    }
    $sort = (string) ($_GET['sort'] ?? 'az');
    if (!in_array($sort, ['az', 'za', 'connected', 'popular', 'updated'], true)) $sort = 'az';
    // ?group=none: the reader turned grouping off. No ?group at all: the default.
    $group = (string) ($_GET['group'] ?? $default);
    if ($group === 'none') $group = '';
    elseif (!isset($facets[$group])) $group = $default;
    // Filtered to one type, grouping by type would only make one group: show the plain list.
    $autoFlat = !isset($_GET['group']) && $group !== '' && isset($filters[$group]);
    if ($autoFlat) $group = '';
    $letter = strtoupper(substr((string) ($_GET['letter'] ?? ''), 0, 1));
    if (!preg_match('/^[A-Z#]$/', $letter)) $letter = '';
    return [
        'filters' => $filters, 'sort' => $sort, 'group' => $group, 'defaultGroup' => $default, 'autoFlat' => $autoFlat, 'letter' => $letter,
        'q' => trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80)),
        'saved' => !empty($_GET['saved']) && current_email(),
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

function is_default_view(array $s): bool
{
    return !$s['filters'] && $s['sort'] === 'az' && $s['group'] === ($s['defaultGroup'] ?? '') && $s['letter'] === '' && $s['q'] === '' && !$s['saved'];
}

function first_letter(string $title): string
{
    $l = strtoupper(substr(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', ltrim($title, " \t\"'“‘(")) ?: '#', 0, 1));
    return preg_match('/[A-Z]/', $l) ? $l : '#';
}

/**
 * One list, ready to show. $base is the page it sits on (the grimoire page);
 * $listKey tells this list's view apart when the page has more than one ('' when it has one).
 */
function collection_view(array $c, string $base, string $listKey): array
{
    $all = collection_entries($c['id']);
    $facets = collection_facets($all);
    $mine = $listKey === '' || ($_GET['list'] ?? '') === $listKey;
    $state = collection_state($facets, $mine, $all);

    $saved = [];
    if ($state['saved']) {
        $st = db()->prepare('SELECT page_id FROM bookmarks WHERE email = ?');
        $st->execute([current_email()]);
        $saved = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    }
    $needle = mb_strtolower($state['q']);
    $rows = array_values(array_filter($all, function ($e) use ($state, $saved, $needle) {
        foreach ($state['filters'] as $prop => $v) if (!in_array($v, $e['tags'][$prop] ?? [], true)) return false;
        if ($state['saved'] && !isset($saved[$e['id']])) return false;
        if ($needle !== '' && !str_contains(mb_strtolower($e['title'] . ' ' . ($e['aka'] ?? '')), $needle)) return false;
        return true;
    }));
    $lettersPresent = array_count_values(array_map(fn($e) => first_letter($e['title']), $rows));
    if ($state['letter'] !== '') $rows = array_values(array_filter($rows, fn($e) => first_letter($e['title']) === $state['letter']));

    $metric = [];
    if (in_array($state['sort'], ['connected', 'popular'], true) && $rows) {
        foreach (array_chunk(array_column($rows, 'id'), 500) as $chunk) {
            if ($state['sort'] === 'connected') {
                $st = db()->prepare("SELECT value AS id, (SELECT COUNT(*) FROM relations WHERE from_id = value) + (SELECT COUNT(*) FROM relations WHERE to_id = value) AS n FROM json_each(?)");
                $st->execute([json_encode($chunk)]);
            } else {
                $st = db()->prepare('SELECT page_id AS id, SUM(views) AS n FROM page_views WHERE page_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY page_id');
                $st->execute($chunk);
            }
            foreach ($st->fetchAll() as $m) $metric[$m['id']] = (int) $m['n'];
        }
    }
    usort($rows, match ($state['sort']) {
        'za' => fn($a, $b) => strnatcasecmp($b['title'], $a['title']),
        'updated' => fn($a, $b) => strcmp((string) $b['notion_edited'], (string) $a['notion_edited']),
        'connected', 'popular' => fn($a, $b) => (($metric[$b['id']] ?? 0) <=> ($metric[$a['id']] ?? 0)) ?: strnatcasecmp($a['title'], $b['title']),
        default => fn($a, $b) => strnatcasecmp($a['title'], $b['title']),
    });
    return compact('c', 'base', 'listKey', 'all', 'facets', 'state', 'rows', 'lettersPresent', 'metric');
}

/** Address of this list with some of the view changed. */
function view_link(array $v, array $change): string
{
    $s = array_merge($v['state'], $change);
    // A list made flat only because it's filtered to one type goes back to its usual grouping when that filter goes.
    if (!empty($v['state']['autoFlat']) && !array_key_exists('group', $change)) $s['group'] = $s['defaultGroup'];
    $q = [];
    if ($v['listKey'] !== '') $q['list'] = $v['listKey'];
    foreach ($s['filters'] as $prop => $val) $q[facet_param($prop)] = $val;
    if ($s['sort'] !== 'az') $q['sort'] = $s['sort'];
    if ($s['group'] !== ($s['defaultGroup'] ?? '')) $q['group'] = $s['group'] === '' ? 'none' : $s['group'];
    if ($s['letter'] !== '') $q['letter'] = $s['letter'];
    if ($s['q'] !== '') $q['q'] = $s['q'];
    if ($s['saved']) $q['saved'] = 1;
    if (($s['page'] ?? 1) > 1) $q['page'] = $s['page'];
    $anchor = '#list-' . $v['c']['slug'];
    return $v['base'] . ($q ? '?' . http_build_query($q) : '') . (array_diff_key($q, ['list' => 1]) ? $anchor : '');
}

/** "Show more": just the next entries, for the page's script to append. Returns true if it answered. */
function collection_part(array $v): bool
{
    if (empty($_GET['part']) || ($v['listKey'] !== '' && ($_GET['list'] ?? '') !== $v['listKey'])) return false;
    header('Content-Type: text/html; charset=utf-8');
    $offset = ($v['state']['page'] - 1) * COLLECTION_PAGE;
    echo collection_items(array_slice($v['rows'], $offset, COLLECTION_PAGE), $v['state']['sort'], $v['metric']);
    // The next batch's address, without the #anchor, so "&part=1" can be added to it.
    if ($offset + COLLECTION_PAGE < count($v['rows'])) echo '<li class="more-row" data-next="' . e(preg_replace('/#.*$/', '', view_link($v, ['page' => $v['state']['page'] + 1]))) . '"></li>';
    return true;
}

/** The whole list block: heading, one "Sort & filter" button with the controls folded inside, the entries. */
function collection_html(array $v, bool $withHeading): string
{
    ['c' => $c, 'state' => $state, 'facets' => $facets, 'rows' => $rows, 'all' => $all, 'metric' => $metric] = $v;
    $link = fn(array $change) => view_link($v, $change + ['page' => 1]);
    $total = count($rows);

    // Folded controls: search, sort, group, saved; then filters; then letters.
    $hidden = $v['listKey'] !== '' ? '<input type="hidden" name="list" value="' . e($v['listKey']) . '">' : '';
    foreach ($state['filters'] as $prop => $val) $hidden .= '<input type="hidden" name="' . e(facet_param($prop)) . '" value="' . e($val) . '">';
    if ($state['letter'] !== '') $hidden .= '<input type="hidden" name="letter" value="' . e($state['letter']) . '">';
    $sel = function (string $name, array $opts, string $cur) {
        $h = '<select name="' . $name . '" data-autosubmit>';
        foreach ($opts as $k => $label) $h .= '<option value="' . e((string) $k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . e($label) . '</option>';
        return $h . '</select>';
    };
    $tools = '<form class="col-tools" method="get" action="' . e($v['base']) . '#list-' . e($c['slug']) . '">' . $hidden
        . '<label class="tool search"><span class="sr-only">Search ' . e($c['title']) . '</span><input type="search" name="q" value="' . e($state['q']) . '" placeholder="Search ' . e(mb_strtolower($c['title'])) . '…"></label>'
        . '<label class="tool"><span>Sort</span>' . $sel('sort', ['az' => 'A–Z', 'za' => 'Z–A', 'connected' => 'Most connected', 'popular' => 'Most read', 'updated' => 'Recently updated'], $state['sort']) . '</label>'
        . ($facets ? '<label class="tool"><span>Group</span>' . $sel('group', ['none' => 'None'] + array_combine(array_keys($facets), array_map(fn($p) => 'By ' . $p, array_keys($facets))), $state['group'] === '' ? 'none' : $state['group']) . '</label>' : '')
        . (current_email() ? '<label class="tool check"><input type="checkbox" name="saved" value="1" data-autosubmit' . ($state['saved'] ? ' checked' : '') . '> Only my saved</label>' : '')
        . '<noscript><button class="btn ghost" type="submit">Apply</button></noscript></form>';
    $chips = '';
    foreach ($facets as $prop => $counts) {
        $cur = $state['filters'][$prop] ?? '';
        $chips .= '<div class="facet"><span class="facet-name">' . e($prop) . '</span><div class="facet-vals">'
            . '<a class="fchip' . ($cur === '' ? ' on' : '') . '" href="' . e($link(['filters' => array_diff_key($state['filters'], [$prop => 1])])) . '">All</a>';
        foreach ($counts as $val => $n) {
            $val = (string) $val; // PHP turns numeric keys like "1" into numbers
            $f = $state['filters']; $f[$prop] = $val;
            $chips .= '<a class="fchip ' . pill_class($val) . ($cur === $val ? ' on' : '') . '" href="' . e($link(['filters' => $f])) . '">' . e($val) . ' <small>' . $n . '</small></a>';
        }
        $chips .= '</div></div>';
    }
    $letters = '<nav class="letters" aria-label="Jump to letter"><a href="' . e($link(['letter' => ''])) . '"' . ($state['letter'] === '' ? ' aria-current="true"' : '') . '>All</a>';
    foreach (array_merge(range('A', 'Z'), ['#']) as $L) {
        $letters .= isset($v['lettersPresent'][$L])
            ? '<a href="' . e($link(['letter' => $L])) . '"' . ($state['letter'] === $L ? ' aria-current="true"' : '') . '>' . $L . '</a>'
            : '<span>' . $L . '</span>';
    }
    $letters .= '</nav>';

    // What's applied, as small removable tags beside the button.
    $active = '';
    foreach ($state['filters'] as $prop => $val) $active .= '<a class="atag ' . pill_class($val) . '" href="' . e($link(['filters' => array_diff_key($state['filters'], [$prop => 1])])) . '" title="Remove">' . e($val) . ' <span aria-hidden="true">×</span></a>';
    $labels = ['za' => 'Z–A', 'connected' => 'Most connected', 'popular' => 'Most read', 'updated' => 'Recently updated'];
    if ($state['sort'] !== 'az') $active .= '<a class="atag" href="' . e($link(['sort' => 'az'])) . '">' . $labels[$state['sort']] . ' <span aria-hidden="true">×</span></a>';
    // Grouping is only shown as a removable tag when it isn't the list's usual one.
    $autoFlat = !empty($state['autoFlat']); // filtered to one type: flat on purpose
    if ($state['group'] !== $state['defaultGroup'] && !$autoFlat) {
        $active .= $state['group'] !== ''
            ? '<a class="atag" href="' . e($link(['group' => $state['defaultGroup']])) . '">By ' . e($state['group']) . ' <span aria-hidden="true">×</span></a>'
            : '<a class="atag" href="' . e($link(['group' => $state['defaultGroup']])) . '">Not grouped <span aria-hidden="true">×</span></a>';
    }
    if ($state['letter'] !== '') $active .= '<a class="atag" href="' . e($link(['letter' => ''])) . '">' . e($state['letter']) . ' <span aria-hidden="true">×</span></a>';
    if ($state['q'] !== '') $active .= '<a class="atag" href="' . e($link(['q' => ''])) . '">“' . e($state['q']) . '” <span aria-hidden="true">×</span></a>';
    if ($state['saved']) $active .= '<a class="atag" href="' . e($link(['saved' => false])) . '">My saved <span aria-hidden="true">×</span></a>';

    $count = number_format($total) . ($total === count($all) ? '' : ' of ' . number_format(count($all))) . ' ' . (count($all) === 1 ? 'entry' : 'entries');
    $bar = '<div class="col-bar"><details class="col-filter"' . (is_default_view($state) ? '' : '') . '><summary><span class="sf-icon" aria-hidden="true">⇅</span> Sort &amp; filter</summary>'
        . '<div class="col-views">' . $tools . ($chips ? '<div class="facets">' . $chips . '</div>' : '') . $letters . '</div></details>'
        . ($active ? '<div class="atags">' . $active . '</div>' : '') . '<p class="col-count">' . $count . '</p></div>';

    // Entries: grouped, or one list with "Show more".
    if ($state['group'] !== '') {
        $groups = [];
        foreach ($rows as $r) foreach (($r['tags'][$state['group']] ?? []) ?: ['No ' . $state['group']] as $val) $groups[$val][] = $r;
        uksort($groups, fn($a, $b) => (str_starts_with((string) $a, 'No ') <=> str_starts_with((string) $b, 'No ')) ?: strnatcasecmp((string) $a, (string) $b));
        $list = '';
        foreach ($groups as $val => $items) {
            $val = (string) $val;
            $f = $state['filters']; $f[$state['group']] = $val;
            $more = count($items) > GROUP_PREVIEW && !str_starts_with($val, 'No ')
                ? '<a class="link" href="' . e($link(['filters' => $f, 'group' => ''])) . '">Show all ' . count($items) . '</a>' : '';
            // The group's name leads to just that group, as one list (not for the "No …" group).
            $name = str_starts_with($val, 'No ') ? '<span class="' . pill_class($val) . '">' . e($val) . '</span>'
                : '<a class="' . pill_class($val) . '" href="' . e($link(['filters' => $f, 'group' => ''])) . '" title="Only ' . e($val) . '">' . e($val) . '</a>';
            $list .= '<section class="col-group"><h3>' . $name . ' <small>' . count($items) . '</small></h3>'
                . '<ul class="entry-grid">' . collection_items(array_slice($items, 0, GROUP_PREVIEW), $state['sort'], $metric) . '</ul>' . $more . '</section>';
        }
    } else {
        $offset = 0;
        $list = '<ul class="entry-grid" data-col-list>' . collection_items(array_slice($rows, 0, COLLECTION_PAGE * $state['page']), $state['sort'], $metric) . '</ul>';
        if (COLLECTION_PAGE * $state['page'] < $total) {
            $next = view_link($v, ['page' => $state['page'] + 1]);
            $part = preg_replace('/#.*$/', '', $next);
            $list .= '<p class="col-more"><a class="btn ghost" href="' . e($next) . '" data-more-list="' . e($part . (str_contains($part, '?') ? '&' : '?') . 'part=1') . '">Show more</a></p>';
        }
    }
    if (!$rows) $list = '<p class="lede">No entries match. <a href="' . e($link(['filters' => [], 'letter' => '', 'q' => '', 'saved' => false])) . '">Clear the filters</a>.</p>';

    $heading = $withHeading
        ? '<div class="collection-head"><h2>' . icon_html($c['icon']) . e($c['title']) . '</h2></div>' : '';
    return '<section class="collection" id="list-' . e($c['slug']) . '">' . $heading . $bar . $list . '</section>';
}

/** The database's own address (from old links or bookmarks): show the same list on its own. */
function view_collection(array $c): void
{
    $v = collection_view($c, page_url($c), '');
    if (collection_part($v)) return;
    $html = '<article class="pg pg-collection">' . breadcrumbs($c) . '<header class="pg-head"><p class="eyebrow">' . e(grimoire_root($c['grimoire'])['title'] ?? '') . '</p>'
        . '<h1>' . icon_html($c['icon'], 'pg-icon big') . e($c['title']) . '</h1></header>' . collection_html($v, false) . '</article>';
    layout($c['title'], $html, ['private' => true, 'protect' => true]);
}

function collection_items(array $rows, string $sort, array $metric): string
{
    $out = '';
    foreach ($rows as $e) {
        $badge = match ($sort) {
            'connected' => '<small class="metric">' . ($metric[$e['id']] ?? 0) . ' links</small>',
            'popular' => isset($metric[$e['id']]) ? '<small class="metric">' . $metric[$e['id']] . ' views</small>' : '',
            default => '',
        };
        $out .= '<li><a href="' . e(page_url($e)) . '">' . icon_html($e['icon']) . '<span>' . e($e['title']) . '</span>' . $badge . '</a></li>';
    }
    return $out;
}
