<?php
declare(strict_types=1);

/*
 * A grimoire's list of entries, with Notion-style views:
 * filter by any property (Type, Element…), group by a property, sort (A–Z, most connected,
 * most read, recently updated), jump by letter, search within the grimoire, and "only my saved".
 * Everything is in the address (?type=Concept&sort=connected…), so views can be bookmarked and
 * work without JavaScript. The list shows 120 entries at a time.
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
        $t = json_decode($r['tags_json'] ?? '[]', true) ?: [];
        unset($t['_images']);
        $r['tags'] = $t;
    }
    return $rows;
}

/** Properties that work as filters: a handful of short values shared by many entries. */
function collection_facets(array $entries): array
{
    $values = [];
    foreach ($entries as $e) foreach ($e['tags'] as $prop => $vals) foreach ($vals as $v) {
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

/** The current view, read from the address. */
function collection_state(array $facets): array
{
    $filters = [];
    foreach ($facets as $prop => $counts) {
        $v = (string) ($_GET[facet_param($prop)] ?? '');
        if ($v !== '' && isset($counts[$v])) $filters[$prop] = $v;
    }
    $sort = (string) ($_GET['sort'] ?? 'az');
    if (!in_array($sort, ['az', 'za', 'connected', 'popular', 'updated'], true)) $sort = 'az';
    $group = (string) ($_GET['group'] ?? '');
    if (!isset($facets[$group])) $group = '';
    $letter = strtoupper(substr((string) ($_GET['letter'] ?? ''), 0, 1));
    if (!preg_match('/^[A-Z#]$/', $letter)) $letter = '';
    return [
        'filters' => $filters, 'sort' => $sort, 'group' => $group, 'letter' => $letter,
        'q' => trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80)),
        'saved' => !empty($_GET['saved']) && current_email(),
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

/** Builds a link to this collection with some of the view changed. */
function collection_link(array $c, array $facets, array $state, array $change): string
{
    $s = array_merge($state, $change);
    $q = [];
    foreach ($s['filters'] as $prop => $v) $q[facet_param($prop)] = $v;
    if ($s['sort'] !== 'az') $q['sort'] = $s['sort'];
    if ($s['group'] !== '') $q['group'] = $s['group'];
    if ($s['letter'] !== '') $q['letter'] = $s['letter'];
    if ($s['q'] !== '') $q['q'] = $s['q'];
    if ($s['saved']) $q['saved'] = 1;
    if (($s['page'] ?? 1) > 1) $q['page'] = $s['page'];
    return page_url($c) . ($q ? '?' . http_build_query($q) : '');
}

function first_letter(string $title): string
{
    $l = strtoupper(substr(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', ltrim($title, " \t\"'“‘(")) ?: '#', 0, 1));
    return preg_match('/[A-Z]/', $l) ? $l : '#';
}

function view_collection(array $c): void
{
    $all = collection_entries($c['id']);
    $facets = collection_facets($all);
    $state = collection_state($facets);

    // Filter
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

    // Sort
    $metric = [];
    if (in_array($state['sort'], ['connected', 'popular'], true) && $rows) {
        $ids = array_column($rows, 'id');
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = $state['sort'] === 'connected'
                ? "SELECT id, (SELECT COUNT(*) FROM relations WHERE from_id = id) + (SELECT COUNT(*) FROM relations WHERE to_id = id) n FROM (SELECT value AS id FROM json_each(?))"
                : "SELECT page_id AS id, SUM(views) n FROM page_views WHERE page_id IN ($in) GROUP BY page_id";
            $st = db()->prepare($sql);
            $st->execute($state['sort'] === 'connected' ? [json_encode($chunk)] : $chunk);
            foreach ($st->fetchAll() as $m) $metric[$m['id']] = (int) $m['n'];
        }
    }
    usort($rows, match ($state['sort']) {
        'za' => fn($a, $b) => strnatcasecmp($b['title'], $a['title']),
        'updated' => fn($a, $b) => strcmp((string) $b['notion_edited'], (string) $a['notion_edited']),
        'connected', 'popular' => fn($a, $b) => (($metric[$b['id']] ?? 0) <=> ($metric[$a['id']] ?? 0)) ?: strnatcasecmp($a['title'], $b['title']),
        default => fn($a, $b) => strnatcasecmp($a['title'], $b['title']),
    });

    $link = fn(array $change) => collection_link($c, $facets, $state, $change + ['page' => 1]);

    // "Show more": the next 120 only (for the script to append), or a normal page without it.
    $total = count($rows);
    $offset = ($state['page'] - 1) * COLLECTION_PAGE;
    if (!empty($_GET['part'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo collection_items(array_slice($rows, $offset, COLLECTION_PAGE), $state['sort'], $metric);
        $next = $offset + COLLECTION_PAGE < $total ? collection_link($c, $facets, $state, ['page' => $state['page'] + 1]) : '';
        echo $next ? '<li class="more-row" data-next="' . e($next) . '"></li>' : '';
        return;
    }

    // Toolbar: search, sort, group, saved
    $hidden = '';
    foreach ($state['filters'] as $prop => $v) $hidden .= '<input type="hidden" name="' . e(facet_param($prop)) . '" value="' . e($v) . '">';
    if ($state['letter'] !== '') $hidden .= '<input type="hidden" name="letter" value="' . e($state['letter']) . '">';
    $sortOpts = ['az' => 'A–Z', 'za' => 'Z–A', 'connected' => 'Most connected', 'popular' => 'Most read', 'updated' => 'Recently updated'];
    $sortSel = '<label class="tool"><span>Sort</span><select name="sort" data-autosubmit>';
    foreach ($sortOpts as $k => $label) $sortSel .= '<option value="' . $k . '"' . ($state['sort'] === $k ? ' selected' : '') . '>' . $label . '</option>';
    $sortSel .= '</select></label>';
    $groupSel = '';
    if ($facets) {
        $groupSel = '<label class="tool"><span>Group</span><select name="group" data-autosubmit><option value="">None</option>';
        foreach (array_keys($facets) as $prop) $groupSel .= '<option value="' . e($prop) . '"' . ($state['group'] === $prop ? ' selected' : '') . '>By ' . e($prop) . '</option>';
        $groupSel .= '</select></label>';
    }
    $savedBox = current_email() ? '<label class="tool check"><input type="checkbox" name="saved" value="1" data-autosubmit' . ($state['saved'] ? ' checked' : '') . '> Only my saved</label>' : '';
    $toolbar = '<form class="col-tools" method="get" action="' . e(page_url($c)) . '">' . $hidden
        . '<label class="tool search"><span class="sr-only">Search ' . e($c['title']) . '</span><input type="search" name="q" value="' . e($state['q']) . '" placeholder="Search ' . e(mb_strtolower($c['title'])) . '…"></label>'
        . $sortSel . $groupSel . $savedBox . '<noscript><button class="btn ghost" type="submit">Apply</button></noscript></form>';

    // Filter chips, one row per property
    $chips = '';
    foreach ($facets as $prop => $counts) {
        $cur = $state['filters'][$prop] ?? '';
        $chips .= '<div class="facet"><span class="facet-name">' . e($prop) . '</span><div class="facet-vals">'
            . '<a class="fchip' . ($cur === '' ? ' on' : '') . '" href="' . e($link(['filters' => array_diff_key($state['filters'], [$prop => 1])])) . '">All</a>';
        foreach ($counts as $v => $n) {
            $f = $state['filters']; $f[$prop] = $v;
            $chips .= '<a class="fchip ' . pill_class($v) . ($cur === $v ? ' on' : '') . '" href="' . e($link(['filters' => $f])) . '">' . e($v) . ' <small>' . $n . '</small></a>';
        }
        $chips .= '</div></div>';
    }

    // Letter bar
    $letters = '<nav class="letters" aria-label="Jump to letter"><a href="' . e($link(['letter' => ''])) . '"' . ($state['letter'] === '' ? ' aria-current="true"' : '') . '>All</a>';
    foreach (array_merge(range('A', 'Z'), ['#']) as $L) {
        $letters .= isset($lettersPresent[$L])
            ? '<a href="' . e($link(['letter' => $L])) . '"' . ($state['letter'] === $L ? ' aria-current="true"' : '') . '>' . $L . '</a>'
            : '<span>' . $L . '</span>';
    }
    $letters .= '</nav>';

    $summary = '<p class="col-count">' . number_format($total) . ' of ' . number_format(count($all)) . ' ' . (count($all) === 1 ? 'entry' : 'entries')
        . (($state['filters'] || $state['q'] !== '' || $state['letter'] !== '' || $state['saved']) ? ' · <a href="' . e(page_url($c)) . '">Clear</a>' : '') . '</p>';

    // The list: grouped, or one list with Show more
    if ($state['group'] !== '') {
        $groups = [];
        foreach ($rows as $r) {
            $vals = $r['tags'][$state['group']] ?? [];
            foreach ($vals ?: ['No ' . $state['group']] as $v) $groups[$v][] = $r;
        }
        uksort($groups, fn($a, $b) => (str_starts_with($a, 'No ') <=> str_starts_with($b, 'No ')) ?: strnatcasecmp($a, $b));
        $list = '';
        foreach ($groups as $v => $items) {
            $f = $state['filters']; $f[$state['group']] = $v;
            $more = count($items) > GROUP_PREVIEW && !str_starts_with($v, 'No ')
                ? '<a class="link" href="' . e($link(['filters' => $f, 'group' => ''])) . '">Show all ' . count($items) . '</a>' : '';
            $list .= '<section class="col-group"><h2><span class="' . pill_class($v) . '">' . e($v) . '</span> <small>' . count($items) . '</small></h2>'
                . '<ul class="entry-grid">' . collection_items(array_slice($items, 0, GROUP_PREVIEW), $state['sort'], $metric) . '</ul>' . $more . '</section>';
        }
    } else {
        $next = $offset + COLLECTION_PAGE < $total ? collection_link($c, $facets, $state, ['page' => $state['page'] + 1]) : '';
        $list = '<ul class="entry-grid" data-col-list>' . collection_items(array_slice($rows, $offset, COLLECTION_PAGE), $state['sort'], $metric) . '</ul>'
            . ($next ? '<p class="col-more"><a class="btn ghost" href="' . e($next) . '" data-more-list="' . e($next . (str_contains($next, '?') ? '&' : '?') . 'part=1') . '">Show more</a></p>' : '');
    }
    if (!$rows) $list = '<p class="lede">No entries match. <a href="' . e(page_url($c)) . '">Clear the filters</a>.</p>';

    $intro = $c['plain'] ? '<p class="lede">' . e($c['plain']) . '</p>' : '';
    $html = '<article class="pg pg-collection">' . breadcrumbs($c) . '<header class="pg-head"><p class="eyebrow">' . e(grimoire_root($c['grimoire'])['title'] ?? '') . '</p>'
        . '<h1>' . icon_html($c['icon'], 'pg-icon big') . e($c['title']) . '</h1>' . $intro . '</header>'
        . '<div class="col-views">' . $toolbar . ($chips ? '<div class="facets">' . $chips . '</div>' : '') . $letters . $summary . '</div>'
        . $list . '</article>';
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
