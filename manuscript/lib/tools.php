<?php
declare(strict_types=1);

/*
 * Members' tools, built from the connections between grimoires:
 *   /today          Today's page: the moon, the day's planet, the Sun's sign, the season and the day's festivals,
 *                   with what they favour across the grimoires.
 *   /spell-builder  The Spell Builder: pick up to three intentions (Correspondence Grimoire entries) and get the
 *                   timing, colours, herbs, stones, oils, deities and rituals tied to them, ranked; save the result
 *                   as a working (/workings/<n>).
 *   /wheel          The Correspondence Wheel: the planets or the zodiac signs around a circle; choose one to see
 *                   everything in the Manuscript tied to it.
 *   margin notes    A member's own private notes on any page (POST /notes), listed under Saved.
 * All of it is members-only. Relations are stored in both directions, but either side may be missing, so links
 * are always read both ways.
 */

const TOOL_LIMIT_IDS = 400; // SQLite's limit on "IN (…)" values is far higher; this keeps queries quick

// ------------------------------------------------------------------ shared helpers

/** Pages linked to any of $ids, either way round: id => how many of $ids it is linked to. */
function linked_counts(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    $pairs = [];
    foreach (array_chunk($ids, TOOL_LIMIT_IDS) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT from_id AS src, to_id AS other FROM relations WHERE from_id IN ($in)
            UNION SELECT to_id AS src, from_id AS other FROM relations WHERE to_id IN ($in)");
        $st->execute(array_merge($chunk, $chunk));
        foreach ($st->fetchAll() as $r) $pairs[$r['other']][$r['src']] = true;
    }
    $out = [];
    foreach ($pairs as $other => $srcs) if (!in_array($other, $ids, true)) $out[$other] = count($srcs);
    return $out;
}

/** How many connections each page has: a well-documented entry is a better suggestion than a stub. */
function link_degree(array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values($ids), TOOL_LIMIT_IDS) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT to_id, COUNT(*) FROM relations WHERE to_id IN ($in) GROUP BY to_id");
        $st->execute($chunk);
        $out += $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $out;
}

/** Pages by id with their properties: id => page row (+ url, types). */
function tool_pages(array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), TOOL_LIMIT_IDS) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, tags_json FROM pages WHERE id IN ($in) AND kind IN ('entry', 'guide')");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $p) {
            $tags = json_decode((string) $p['tags_json'], true) ?: [];
            $p['types'] = array_map('strval', (array) ($tags['Type'] ?? $tags['Category'] ?? []));
            $p['tags'] = $tags;
            $p['icon'] = shown_icon($p);
            $p['url'] = page_url($p);
            $out[$p['id']] = $p;
        }
    }
    return $out;
}

/** The entries of one kind in a grimoire, by their Type (e.g. astrology "Planets"): slug => page. */
function pages_of_type(string $grimoire, string $type): array
{
    $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, tags_json FROM pages WHERE grimoire = ? AND kind = 'entry' AND tags_json LIKE ?");
    $st->execute([$grimoire, '%' . json_encode($type) . '%']);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $tags = json_decode((string) $p['tags_json'], true) ?: [];
        if (!in_array($type, array_map('strval', (array) ($tags['Type'] ?? [])), true)) continue;
        $p['tags'] = $tags;
        $p['icon'] = shown_icon($p);
        $p['url'] = page_url($p);
        $out[$p['slug']] = $p;
    }
    return $out;
}

/** Which part of a working a page belongs to (see SPELL_PARTS), or null if it isn't one. */
function spell_part(array $p): ?string
{
    $t = $p['types'] ?? [];
    return match ($p['grimoire']) {
        'astrology' => array_intersect($t, ['Planets', 'Moon Phases']) ? 'when' : null,
        'timing' => array_intersect($t, ['Days of the Week', 'Holidays', 'Seasons', 'Time of Day']) ? 'when' : null,
        'symbol' => in_array('Color', $t, true) ? 'colours' : null,
        'plant' => 'plants',
        'mineral' => 'stones',
        'oil' => 'oils',
        'entity' => 'deities',
        'rune' => 'runes',
        'card' => isset($p['tags']['Suit']) || isset($p['tags']['Upright Correspondences']) ? 'tarot' : null,
        'recipe' => 'recipes',
        'ritual' => 'rituals',
        default => null,
    };
}

/** The parts of a working, in order: key => [heading, how many to show, how many ticked to start with]. */
const SPELL_PARTS = [
    'when' => ['When to work', 8, 3],
    'colours' => ['Colours', 6, 2],
    'plants' => ['Herbs & plants', 10, 3],
    'stones' => ['Crystals & stones', 8, 2],
    'oils' => ['Oils', 6, 2],
    'deities' => ['Deities & spirits', 8, 0],
    'runes' => ['Runes', 4, 0],
    'tarot' => ['Tarot', 4, 0],
    'recipes' => ['Recipes', 6, 0],
    'rituals' => ['Rituals to adapt', 8, 1],
];

/**
 * Everything tied to $ids, sorted into the parts of a working and ranked: first by how many of $ids an entry is
 * tied to, then by how well connected it is in the Manuscript. part => list of [page, hits].
 */
function ranked_parts(array $ids, ?array $only = null): array
{
    $counts = linked_counts($ids);
    if (!$counts) return [];
    $pages = tool_pages(array_keys($counts));
    $degree = link_degree(array_keys($pages));
    $parts = [];
    foreach ($pages as $id => $p) {
        $part = spell_part($p);
        if (!$part || ($only && !in_array($part, $only, true))) continue;
        $parts[$part][] = [$p, $counts[$id], (int) ($degree[$id] ?? 0)];
    }
    foreach ($parts as &$list) usort($list, fn($a, $b) => [$b[1], $b[2], $a[0]['title']] <=> [$a[1], $a[2], $b[0]['title']]);
    unset($list);
    $out = [];
    foreach (array_keys(SPELL_PARTS) as $k) if (!empty($parts[$k])) $out[$k] = $parts[$k];
    return $out;
}

/** A page as a small link with its icon (the Manuscript's chip). */
function tool_chip(array $p, string $extra = ''): string
{
    return '<a class="rel" href="' . e($p['url']) . '">' . icon_html($p['icon'] ?? null, 'rel-icon') . '<span>' . e($p['title']) . '</span>' . $extra . '</a>';
}

/**
 * Each grimoire's tools, listed in its intro box beside "Guide Pages" (members only): grimoire => [[address, name]…].
 * The heading is TOOLS_HEADING.
 */
const TOOLS_HEADING = 'Enchanted Tools';
const GRIMOIRE_TOOLS = [
    'ritual' => [['spell-builder', 'Spell Builder']],
    'astrology' => [['wheel?of=planets', 'Planet Wheel'], ['wheel?of=signs', 'Zodiac Wheel'], ['wheel?of=moon', 'Moon Phase Wheel']],
    'timing' => [['today', 'Today'], ['calendar', 'Calendar']],
];

/** A grimoire's intro box with its tools added as a fold-out, right after "Guide Pages" (or at the end of the box). */
function grimoire_tools_html(string $grimoire, string $body): string
{
    $tools = GRIMOIRE_TOOLS[$grimoire] ?? [];
    if (!$tools || !has_access()) return $body;
    $links = implode('', array_map(fn($t) => '<p class="page-link tool-page"><a href="' . e(url($t[0])) . '">' . e($t[1]) . '</a></p>', $tools));
    $box = '<details class="toggle tools-toggle"><summary>' . e(TOOLS_HEADING) . '</summary><div class="link-flow">' . $links . '</div></details>';
    $guide = preg_replace('~(<details class="toggle"><summary>Guide Pages</summary>.*?</details>)~s', '$1' . str_replace(['\\', '$'], ['\\\\', '\$'], $box), $body, 1, $done);
    if ($done) return $guide;
    $inBox = preg_replace('~(<aside class="mm-callout[^"]*"><div>.*?)(</div></aside>)~s', '$1' . str_replace(['\\', '$'], ['\\\\', '\$'], $box) . '$2', $body, 1, $done);
    return $done ? $inBox : '<aside class="mm-callout tools-only"><div>' . $box . '</div></aside>' . $body;
}

/** Breadcrumbs from a tool page back to its grimoire (and on, for a working, to the spell builder). */
function tool_crumbs(string $grimoire, array $more = []): string
{
    $g = grimoire_root($grimoire);
    $html = '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' . e(url()) . '">Manuscript</a></li>'
        . ($g ? '<li><a href="' . e(page_url($g)) . '">' . e($g['title']) . '</a></li>' : '');
    foreach ($more as [$label, $href]) $html .= '<li><a href="' . e($href) . '">' . e($label) . '</a></li>';
    return $html . '</ol></nav>';
}

function members_only_tool(): bool
{
    if (has_access()) return true;
    view_gate(['locked' => true]);
    return false;
}

// ------------------------------------------------------------------ the moon

/** Days into the moon's cycle (0 = new moon) at noon UTC on a date. */
function moon_age(DateTimeImmutable $day): float
{
    $synodic = 29.530588853;
    $ref = 947182440; // a new moon: 2000-01-06 18:14 UTC
    $t = (int) $day->setTime(12, 0)->format('U');
    $age = fmod(($t - $ref) / 86400, $synodic);
    return $age < 0 ? $age + $synodic : $age;
}

/** The phase for an age in days: [astrology page slug, name, waxing?]. */
function moon_phase(float $age): array
{
    $phases = [
        [1.84566, 'new-moon', 'New Moon'], [5.53699, 'waxing-crescent-moon', 'Waxing Crescent Moon'],
        [9.22831, 'first-quarter-moon', 'First Quarter Moon'], [12.91963, 'waxing-gibbous-moon', 'Waxing Gibbous Moon'],
        [16.61096, 'full-moon', 'Full Moon'], [20.30228, 'waning-gibbous-moon', 'Waning Gibbous Moon'],
        [23.99361, 'third-quarter-moon', 'Third Quarter Moon'], [27.68493, 'waning-crescent-moon', 'Waning Crescent Moon'],
    ];
    foreach ($phases as [$limit, $slug, $name]) if ($age < $limit) return [$slug, $name, $age < 14.765];
    return ['new-moon', 'New Moon', true];
}

/** The moon as it looks on a given day: a small SVG, the lit part drawn with two arcs. */
function moon_svg(float $age, int $size = 96): string
{
    $r = $size / 2 - 2;
    $c = $size / 2;
    $k = cos(2 * M_PI * $age / 29.530588853); // 1 at new moon, -1 at full
    $rx = round(abs($k) * $r, 2);
    $waxing = $age < 14.765;
    // Outer edge: the right half while waxing, the left half while waning; then back along the terminator.
    $limb = $waxing ? 1 : 0;
    $term = $waxing ? ($k > 0 ? 0 : 1) : ($k > 0 ? 1 : 0);
    $top = $c - $r;
    $bottom = $c + $r;
    $lit = $age < 0.5 || $age > 29.03 ? ''
        : '<path class="moon-lit" d="M' . $c . ' ' . $top . ' A' . $r . ' ' . $r . ' 0 0 ' . $limb . ' ' . $c . ' ' . $bottom
          . ' A' . $rx . ' ' . $r . ' 0 0 ' . $term . ' ' . $c . ' ' . $top . 'Z"/>';
    return '<svg class="moon-pic" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
        . '<circle class="moon-dark" cx="' . $c . '" cy="' . $c . '" r="' . $r . '"/>' . $lit . '</svg>';
}

// ------------------------------------------------------------------ /today

/** The day shown: ?d=YYYY-MM-DD (wiki.js sends the reader's own date), else today on the server. */
function today_date(): DateTimeImmutable
{
    $d = (string) ($_GET['d'] ?? '');
    $tz = new DateTimeZone((string) config('timezone', 'UTC'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($day = DateTimeImmutable::createFromFormat('!Y-m-d', $d, $tz))) return $day;
    return new DateTimeImmutable('today', $tz);
}

/** The Sun's sign on a date, from the signs' "Date Range" (March 21 - April 19). */
function sun_sign(DateTimeImmutable $day, array $signs): ?array
{
    $md = (int) $day->format('n') * 100 + (int) $day->format('j');
    foreach ($signs as $s) {
        $range = (string) (is_array($s['tags']['Date Range'] ?? null) ? ($s['tags']['Date Range'][0] ?? '') : ($s['tags']['Date Range'] ?? ''));
        if (!preg_match('/^([A-Za-z]+)\s+(\d+)\s*[-–]\s*([A-Za-z]+)\s+(\d+)/', $range, $m)) continue;
        $from = (int) date('n', strtotime($m[1] . ' 1 2001')) * 100 + (int) $m[2];
        $to = (int) date('n', strtotime($m[3] . ' 1 2001')) * 100 + (int) $m[4];
        if ($from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to)) return $s;
    }
    return null;
}

/** Season by the solstices and equinoxes (about the 21st), for the northern or southern hemisphere. */
function season_slug(DateTimeImmutable $day, bool $south): string
{
    $md = (int) $day->format('n') * 100 + (int) $day->format('j');
    $north = match (true) { $md >= 1221 || $md < 320 => 'winter', $md < 621 => 'spring', $md < 922 => 'summer', default => 'autumn' };
    if (!$south) return $north;
    return ['winter' => 'summer', 'spring' => 'autumn', 'summer' => 'winter', 'autumn' => 'spring'][$north];
}

/** The named full moon for a month ("November (NH) / May (SH)"), shown when the moon is full. */
function full_moon_name(DateTimeImmutable $day, array $phases, bool $south): ?array
{
    $month = $day->format('F');
    foreach ($phases as $p) {
        if (!str_starts_with($p['slug'], 'full-') || $p['slug'] === 'full-moon') continue;
        $range = implode(' ', (array) ($p['tags']['Date Range'] ?? []));
        if (preg_match('/' . $month . ' \(' . ($south ? 'SH' : 'NH') . '\)/', $range)) return $p;
    }
    return null;
}

/** Festivals on the days ahead, from the Timing grimoire's day pages: [date, festival page]… */
function coming_festivals(DateTimeImmutable $from, int $days): array
{
    $slugs = [];
    for ($i = 1; $i <= $days; $i++) {
        $d = $from->modify("+$i day");
        $slugs[strtolower($d->format('F')) . '-' . $d->format('j')] = $d;
    }
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $st = db()->prepare("SELECT id, slug FROM pages WHERE grimoire = 'timing' AND slug IN ($in)");
    $st->execute(array_keys($slugs));
    $dayIds = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$dayIds) return [];
    $in = implode(',', array_fill(0, count($dayIds), '?'));
    $st = db()->prepare("SELECT from_id, to_id FROM relations WHERE prop = 'Related Timing' AND from_id IN ($in) ORDER BY sort");
    $st->execute(array_keys($dayIds));
    $rows = $st->fetchAll();
    $idx = page_index(array_column($rows, 'to_id'));
    $out = [];
    foreach ($rows as $r) if (isset($idx[$r['to_id']])) $out[] = [$slugs[$dayIds[$r['from_id']]], $idx[$r['to_id']]];
    usort($out, fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

function view_today(): void
{
    if (!members_only_tool()) return;
    record_view('route:/manuscript/today');
    $day = today_date();
    $south = ($_GET['h'] ?? '') === 's';
    $age = moon_age($day);
    [$phaseSlug, $phaseName, $waxing] = moon_phase($age);
    $phases = pages_of_type('astrology', 'Moon Phases');
    $planets = pages_of_type('astrology', 'Planets');
    $signs = pages_of_type('astrology', 'Western Zodiac');
    $weekday = find_page('timing', strtolower($day->format('l')));
    $dayPage = find_page('timing', strtolower($day->format('F')) . '-' . $day->format('j'));
    $season = find_page('timing', season_slug($day, $south));
    $moon = $phases[$phaseSlug] ?? null;
    $fullName = str_starts_with($phaseSlug, 'full') ? full_moon_name($day, $phases, $south) : null;
    $sign = sun_sign($day, $signs);
    // The planet that rules the weekday: the weekday's own Astrology link (Thursday → Jupiter).
    $ruler = null;
    if ($weekday) {
        $linked = linked_counts([$weekday['id']]);
        foreach ($planets as $p) if (isset($linked[$p['id']])) { $ruler = $p; break; }
    }

    // The day's influences, each with what it is known for (its Correspondence Grimoire links).
    $influences = array_values(array_filter([
        $moon ? ['The moon', $moon, $fullName ? 'Tonight’s full moon is the ' . $fullName['title'] . '.' : ($waxing ? 'Waxing: a time to draw things in and build.' : 'Waning: a time to release, banish and clear.'), $fullName] : null,
        $weekday ? ['The day', $weekday, $ruler ? $weekday['title'] . ' belongs to ' . $ruler['title'] . '.' : '', $ruler] : null,
        $sign ? ['The sun', $sign, 'The Sun is in ' . $sign['title'] . '.', null] : null,
        $season ? ['The season', $season, $south ? 'Southern hemisphere.' : 'Northern hemisphere.', null] : null,
    ]));
    $sourceIds = [];
    foreach ($influences as [, $p, , $extra]) { $sourceIds[] = $p['id']; if ($extra) $sourceIds[] = $extra['id']; }
    if ($dayPage) $sourceIds[] = $dayPage['id'];

    $cards = '';
    foreach ($influences as [$label, $p, $note, $extra]) {
        $own = tool_pages(array_keys(linked_counts([$p['id']])));
        $corr = array_slice(array_filter($own, fn($x) => $x['grimoire'] === 'correspondence'), 0, 6);
        $pic = $label === 'The moon' ? moon_svg($age) : '<span class="today-icon">' . icon_html(shown_icon($p), 'pg-icon big') . '</span>';
        $cards .= '<section class="today-card"><p class="eyebrow">' . e($label) . '</p>' . $pic
            . '<h2><a href="' . e(page_url($p)) . '">' . e($p['title']) . '</a></h2>'
            . ($note ? '<p class="small">' . e($note) . ($extra ? ' <a class="link" href="' . e($extra['url'] ?? page_url($extra)) . '">Read about ' . e($extra['title']) . '</a>' : '') . '</p>' : '')
            . ($corr ? '<div class="rels">' . implode('', array_map(fn($c) => tool_chip($c), $corr)) . '</div>' : '')
            . '</section>';
    }

    // What the day favours: intentions tied to more than one of its influences.
    $counts = linked_counts($sourceIds);
    $intentions = array_filter(tool_pages(array_keys(array_filter($counts, fn($n) => $n >= 2))), fn($p) => $p['grimoire'] === 'correspondence');
    uasort($intentions, fn($a, $b) => [$counts[$b['id']], $a['title']] <=> [$counts[$a['id']], $b['title']]);
    $favours = '';
    foreach (array_slice($intentions, 0, 12) as $p) {
        $favours .= '<a class="fav" href="' . e(url('spell-builder') . '?i=' . rawurlencode($p['slug'])) . '">' . icon_html($p['icon'], 'rel-icon') . e($p['title'])
            . '<small>' . $counts[$p['id']] . ' of today’s influences</small></a>';
    }

    // What to work with: herbs, stones, oils, colours and rituals tied to several of today's influences.
    $parts = ranked_parts($sourceIds, ['colours', 'plants', 'stones', 'oils', 'rituals']);
    $work = '';
    foreach ($parts as $k => $list) {
        $top = array_slice(array_filter($list, fn($x) => $x[1] >= 2), 0, 6) ?: array_slice($list, 0, 3);
        $work .= '<div class="work-part"><h3>' . e(SPELL_PARTS[$k][0]) . '</h3><div class="rels">'
            . implode('', array_map(fn($x) => tool_chip($x[0]), $top)) . '</div></div>';
    }

    // On this day, and what's coming.
    $onDay = '';
    if ($dayPage) {
        $festivals = array_filter(coming_festivals($day->modify('-1 day'), 1), fn($f) => $f[0] == $day);
        $tied = array_filter(tool_pages(array_keys(linked_counts([$dayPage['id']]))), fn($x) => !in_array($x['id'], array_column(array_column($festivals, 1), 'id'), true));
        uasort($tied, fn($a, $b) => [$a['grimoire'], $a['title']] <=> [$b['grimoire'], $b['title']]);
        $onDay = ($festivals ? '<div class="rels">' . implode('', array_map(fn($f) => tool_chip($f[1]), $festivals)) . '</div>' : '')
            . ($tied ? '<p class="small">Tied to this day in the Manuscript:</p><div class="rels">' . implode('', array_map(fn($x) => tool_chip($x), array_slice($tied, 0, 16))) . '</div>' : '')
            . '<p><a class="link" href="' . e(page_url($dayPage)) . '">' . e($dayPage['title']) . ' in the Timing Grimoire</a></p>';
    }
    $soon = '';
    foreach (array_slice(coming_festivals($day, 21), 0, 8) as [$d, $p]) {
        $soon .= '<li><time datetime="' . $d->format('Y-m-d') . '">' . $d->format('M j') . '</time>' . tool_chip($p) . '</li>';
    }

    $date = $day->format('Y-m-d');
    $hemi = '<a class="link" href="' . e(url('today') . '?d=' . $date . ($south ? '' : '&h=s')) . '">' . ($south ? 'Use the northern hemisphere' : 'Use the southern hemisphere') . '</a>';
    $lede = implode(', ', array_filter([
        $moon ? 'a ' . $phaseName : null,
        $ruler ? 'a ' . $ruler['title'] . ' day' : null,
        $sign ? 'the Sun in ' . $sign['title'] : null,
        $season ? mb_strtolower($season['title']) : null,
    ]));
    $prev = $day->modify('-1 day')->format('Y-m-d');
    $next = $day->modify('+1 day')->format('Y-m-d');
    $h = $south ? '&h=s' : '';
    layout('Today', '<article class="pg pg-tool pg-today" data-today="' . e($date) . '">'
        . '<header class="pg-head tool-head">' . tool_crumbs('timing') . '<p class="eyebrow labelled">Today in the Manuscript</p>'
        . '<h1>' . e($day->format('l')) . ', <em>' . e($day->format('F j')) . '</em></h1>'
        . ($lede ? '<p class="lede">' . e(ucfirst($lede)) . '.</p>' : '')
        . '<p class="small day-nav"><a class="link" href="' . e(url('today') . '?d=' . $prev . $h) . '" rel="nofollow">‹ ' . e($day->modify('-1 day')->format('M j')) . '</a>'
        . '<span aria-hidden="true">·</span>' . $hemi . '<span aria-hidden="true">·</span>'
        . '<a class="link" href="' . e(url('today') . '?d=' . $next . $h) . '" rel="nofollow">' . e($day->modify('+1 day')->format('M j')) . ' ›</a></p></header>'
        . '<div class="today-cards">' . $cards . '</div>'
        . ($favours ? '<section class="tool-section"><h2>Today <em>favours</em></h2><p class="small">Intentions tied to more than one of today’s influences. Choose one to build a spell for it.</p><div class="favs">' . $favours . '</div></section>' : '')
        . ($work ? '<section class="tool-section"><h2>Work <em>with today</em></h2><p class="small">Tied to the most of today’s moon, day, sign and season.</p><div class="work-parts">' . $work . '</div></section>' : '')
        . '<section class="tool-section two-col">'
        . ($onDay ? '<div><h2>On <em>this day</em></h2>' . $onDay . '</div>' : '')
        . ($soon ? '<div><h2>Coming <em>up</em></h2><ul class="soon">' . $soon . '</ul><p class="small"><a class="link" href="' . e(url('calendar')) . '">The full calendar</a></p></div>' : '')
        . '</section></article>', ['private' => true, 'protect' => true]);
}

// ------------------------------------------------------------------ /spell-builder

/** The intentions chosen (?i=protection,money): Correspondence Grimoire pages, at most three. */
function chosen_intentions(): array
{
    $slugs = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_GET['i'] ?? '')))))), 0, 3);
    $out = [];
    foreach ($slugs as $s) if ($p = find_page('correspondence', $s)) $out[$p['slug']] = $p;
    return $out;
}

/** An intention typed in the box: its exact title or another name first, then the best search match. */
function find_intention(string $q): ?array
{
    $q = trim($q);
    if ($q === '') return null;
    $st = db()->prepare("SELECT * FROM pages WHERE grimoire = 'correspondence' AND kind = 'entry' AND (title = ? COLLATE NOCASE OR slug = ?) LIMIT 1");
    $st->execute([$q, slugify($q)]);
    if ($p = $st->fetch()) return $p;
    try {
        $st = db()->prepare("SELECT p.* FROM search s JOIN pages p ON p.id = s.id WHERE search MATCH ? AND p.grimoire = 'correspondence' AND p.kind = 'entry' ORDER BY rank LIMIT 1");
        $st->execute([fts_query($q)]);
        return $st->fetch() ?: null;
    } catch (Throwable $ex) {
        return null;
    }
}

/** The intentions offered to start with: common ones, if they're in the Correspondence Grimoire. */
function starter_intentions(): array
{
    $want = ['protection', 'love', 'money', 'healing', 'banishing', 'luck', 'cleansing', 'divination', 'courage', 'peace', 'success', 'wisdom', 'psychic-abilities', 'beauty', 'fertility', 'purification'];
    $in = implode(',', array_fill(0, count($want), '?'));
    $st = db()->prepare("SELECT slug, title, icon, grimoire, kind FROM pages WHERE grimoire = 'correspondence' AND kind = 'entry' AND slug IN ($in)");
    $st->execute($want);
    $rows = $st->fetchAll();
    usort($rows, fn($a, $b) => array_search($a['slug'], $want, true) <=> array_search($b['slug'], $want, true));
    return array_slice($rows, 0, 12);
}

function route_spell_builder(string $method): void
{
    if (!members_only_tool()) return;
    if ($method === 'POST') { save_working(); return; }
    // "Add" from the box: find the intention, then come back with it in the address.
    if (isset($_GET['add'])) {
        $chosen = array_keys(chosen_intentions());
        $found = find_intention((string) $_GET['add']);
        if ($found && !in_array($found['slug'], $chosen, true)) $chosen[] = $found['slug'];
        $to = url('spell-builder') . ($chosen ? '?i=' . rawurlencode(implode(',', array_slice($chosen, 0, 3))) : '');
        if (!$found) $to .= ($chosen ? '&' : '?') . 'missing=' . rawurlencode((string) $_GET['add']);
        redirect($to);
    }
    view_spell_builder();
}

function view_spell_builder(): void
{
    record_view('route:/manuscript/spell-builder');
    $chosen = chosen_intentions();
    $slugs = array_keys($chosen);
    $with = fn(array $s) => url('spell-builder') . ($s ? '?i=' . rawurlencode(implode(',', $s)) : '');

    $chips = '';
    foreach ($chosen as $slug => $p) {
        $chips .= '<span class="intent">' . icon_html(shown_icon($p), 'rel-icon') . '<a href="' . e(page_url($p)) . '">' . e($p['title']) . '</a>'
            . '<a class="intent-x" href="' . e($with(array_values(array_diff($slugs, [$slug])))) . '" aria-label="Remove ' . e($p['title']) . '">×</a></span>';
    }
    $missing = trim((string) ($_GET['missing'] ?? ''));
    $box = count($chosen) < 3
        ? '<form class="mm-form spell-add" method="get" action="' . e(url('spell-builder')) . '" role="search">'
          . ($slugs ? '<input type="hidden" name="i" value="' . e(implode(',', $slugs)) . '">' : '')
          . '<label for="spell-q">' . ($chosen ? 'Add another intention <small>(up to three)</small>' : 'What is the spell for?') . '</label>'
          . '<div class="spell-add-row"><input id="spell-q" type="search" name="add" list="intentions" autocomplete="off" placeholder="Protection, love, money, a new home…" required>'
          . '<button class="btn" type="submit">' . ($chosen ? 'Add' : 'Begin') . '</button></div>'
          . '<datalist id="intentions">' . intention_options() . '</datalist></form>'
        : '<p class="small">Three intentions is the most a working can hold. Remove one to change it.</p>';
    if ($missing !== '') $box = '<p class="notice">Nothing called “' . e($missing) . '” in the Correspondence Grimoire. Try another word for it.</p>' . $box;

    $starters = '';
    if (!$chosen) {
        foreach (starter_intentions() as $p) $starters .= '<a class="fav" href="' . e($with([$p['slug']])) . '">' . icon_html(shown_icon($p), 'rel-icon') . e($p['title']) . '</a>';
        $starters = $starters ? '<section class="tool-section"><h2>Or start <em>with one of these</em></h2><div class="favs">' . $starters . '</div></section>' : '';
    }

    $result = '';
    if ($chosen) {
        $ids = array_column($chosen, 'id');
        $parts = ranked_parts($ids);
        $n = count($ids);
        $rows = '';
        foreach ($parts as $k => $list) {
            [$heading, $show, $tick] = SPELL_PARTS[$k];
            $items = '';
            foreach (array_slice($list, 0, $show) as $i => [$p, $hits]) {
                $items .= '<label class="pick"><input type="checkbox" name="pick[]" value="' . e($p['id']) . '"' . ($i < $tick ? ' checked' : '') . '>'
                    . '<span class="pick-box" aria-hidden="true"></span>' . icon_html($p['icon'], 'rel-icon')
                    . '<a href="' . e($p['url']) . '">' . e($p['title']) . '</a>'
                    . ($n > 1 ? '<small class="hits' . ($hits === $n ? ' all' : '') . '">' . ($hits === $n ? 'all ' . $n : $hits . ' of ' . $n) . '</small>' : '') . '</label>';
            }
            $more = count($list) > $show ? '<p class="small more-count">' . (count($list) - $show) . ' more in the Manuscript</p>' : '';
            $rows .= '<fieldset class="spell-part part-' . e($k) . '"><legend>' . e($heading) . '</legend><div class="picks">' . $items . '</div>' . $more . '</fieldset>';
        }
        $title = 'A working for ' . implode(' & ', array_column($chosen, 'title'));
        $result = $rows
            ? '<form class="spell-form" method="post" action="' . e(url('spell-builder')) . '">'
              . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="i" value="' . e(implode(',', $slugs)) . '">'
              . '<p class="small">The best-connected matches come first' . ($n > 1 ? ', and those tied to all ' . $n . ' intentions before the rest' : '') . '. Tick what you’ll use, then save it as a working.</p>'
              . '<div class="spell-parts">' . $rows . '</div>'
              . '<div class="spell-save"><h2>Keep <em>this working</em></h2>'
              . '<label for="w-title">Name</label><input id="w-title" name="title" maxlength="120" value="' . e($title) . '">'
              . '<label for="w-notes">Your notes <small>(optional)</small></label><textarea id="w-notes" name="notes" rows="4" maxlength="5000" placeholder="What you’ll do, when, and anything you want to remember."></textarea>'
              . '<button class="btn" type="submit">Save the working</button><p class="small">Saved workings are under ★ Saved, at the top of every page.</p></div></form>'
            : '<p class="lede">Nothing in the Manuscript is tied to this yet. Try a broader intention.</p>';
    }

    layout('Spell Builder', '<article class="pg pg-tool pg-spell">'
        . '<header class="pg-head tool-head">' . tool_crumbs('ritual') . '<p class="eyebrow labelled">Spell Builder</p><h1>Build <em>a working</em></h1>'
        . '<p class="lede">Choose what the spell is for. The Manuscript gathers the days, colours, herbs, stones, oils, deities and rituals tied to it.</p></header>'
        . ($chips ? '<div class="intents">' . $chips . '</div>' : '') . $box . $starters . $result . '</article>', ['private' => true, 'protect' => true]);
}

/** Every intention's title, for the box's suggestions. */
function intention_options(): string
{
    $rows = db()->query("SELECT title FROM pages WHERE grimoire = 'correspondence' AND kind = 'entry' ORDER BY title COLLATE NOCASE")->fetchAll(PDO::FETCH_COLUMN);
    return implode('', array_map(fn($t) => '<option value="' . e($t) . '">', $rows));
}

/** POST /spell-builder: save the ticked entries as a working. */
function save_working(): void
{
    check_csrf();
    $email = current_email();
    if (!$email) redirect(url('login') . '?next=' . rawurlencode((string) parse_url(url('spell-builder'), PHP_URL_PATH)));
    $picks = array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['pick'] ?? [])), fn($id) => preg_match('/^[0-9a-z]{32}$/', $id)))), 0, 80);
    $_GET['i'] = (string) ($_POST['i'] ?? '');
    $intents = array_column(chosen_intentions(), 'id');
    $title = trim(mb_substr(preg_replace('/\s+/', ' ', (string) ($_POST['title'] ?? '')), 0, 120)) ?: 'My working';
    $notes = trim(mb_substr((string) ($_POST['notes'] ?? ''), 0, 5000));
    $st = db()->prepare('SELECT COUNT(*) FROM workings WHERE email = ?');
    $st->execute([$email]);
    if ((int) $st->fetchColumn() >= 300) { http_response_code(400); exit('You have 300 saved workings, the most there can be. Delete some under Saved first.'); }
    db()->prepare('INSERT INTO workings (email, title, intentions_json, items_json, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$email, $title, json_encode($intents), json_encode($picks), $notes, now()]);
    redirect(url('workings/' . db()->lastInsertId()));
}

function route_workings(array $parts, string $method): void
{
    if (!members_only_tool()) return;
    $email = (string) current_email();
    $st = db()->prepare('SELECT * FROM workings WHERE id = ? AND email = ?');
    $st->execute([(int) ($parts[0] ?? 0), $email]);
    $w = $st->fetch();
    if (!$w) { not_found(); return; }
    if ($method === 'POST') {
        check_csrf();
        if (($_POST['do'] ?? '') === 'delete') {
            db()->prepare('DELETE FROM workings WHERE id = ? AND email = ?')->execute([$w['id'], $email]);
            redirect(url('saved'));
        }
        $notes = trim(mb_substr((string) ($_POST['notes'] ?? ''), 0, 5000));
        db()->prepare('UPDATE workings SET notes = ? WHERE id = ? AND email = ?')->execute([$notes, $w['id'], $email]);
        redirect(url('workings/' . $w['id']));
    }
    $intents = tool_pages(json_decode((string) $w['intentions_json'], true) ?: []);
    $items = tool_pages(json_decode((string) $w['items_json'], true) ?: []);
    $groups = [];
    foreach ($items as $p) $groups[spell_part($p) ?? 'other'][] = $p;
    $html = '';
    foreach (array_keys(SPELL_PARTS + ['other' => ['Also', 0, 0]]) as $k) {
        if (empty($groups[$k])) continue;
        $html .= '<section class="work-part"><h3>' . e((SPELL_PARTS[$k][0] ?? 'Also')) . '</h3><div class="rels">' . implode('', array_map(fn($p) => tool_chip($p), $groups[$k])) . '</div></section>';
    }
    $csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $again = $intents ? '<a class="link" href="' . e(url('spell-builder') . '?i=' . rawurlencode(implode(',', array_column($intents, 'slug')))) . '">Open in the spell builder</a>' : '';
    layout($w['title'], '<article class="pg pg-tool pg-working">'
        . '<header class="pg-head tool-head">' . tool_crumbs('ritual', [['Spell Builder', url('spell-builder')]]) . '<p class="eyebrow labelled">Your working</p><h1>' . e($w['title']) . '</h1>'
        . '<p class="small">Saved ' . e(date('F j, Y', strtotime($w['created_at']))) . ($again ? ' · ' . $again : '') . '</p></header>'
        . ($intents ? '<div class="intents">' . implode('', array_map(fn($p) => '<span class="intent">' . icon_html($p['icon'], 'rel-icon') . '<a href="' . e($p['url']) . '">' . e($p['title']) . '</a></span>', $intents)) . '</div>' : '')
        . '<div class="work-parts">' . ($html ?: '<p class="lede">Nothing was ticked in this working.</p>') . '</div>'
        . '<form class="mm-form margin-notes" method="post" action="' . e(url('workings/' . $w['id'])) . '">' . $csrf
        . '<label for="w-notes">Your notes</label><textarea id="w-notes" name="notes" rows="6" maxlength="5000">' . e($w['notes']) . '</textarea>'
        . '<button class="btn ghost" type="submit">Save notes</button></form>'
        . '<form method="post" action="' . e(url('workings/' . $w['id'])) . '" class="delete-working">' . $csrf . '<input type="hidden" name="do" value="delete">'
        . '<button class="link as-button" type="submit" data-confirm="Delete this working? This can’t be undone.">Delete this working</button></form>'
        . '</article>', ['private' => true]);
}

// ------------------------------------------------------------------ /wheel

/** The wheels: key => [title, grimoire, Type, order of slugs around the circle, glyphs]. */
function wheels(): array
{
    return [
        'planets' => ['Planet Wheel', 'Planets', ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto'],
            ['sun' => '☉', 'moon' => '☽', 'mercury' => '☿', 'venus' => '♀', 'mars' => '♂', 'jupiter' => '♃', 'saturn' => '♄', 'uranus' => '♅', 'neptune' => '♆', 'pluto' => '♇']],
        'signs' => ['Zodiac Wheel', 'Western Zodiac', ['aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo', 'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces'],
            ['aries' => '♈', 'taurus' => '♉', 'gemini' => '♊', 'cancer' => '♋', 'leo' => '♌', 'virgo' => '♍', 'libra' => '♎', 'scorpio' => '♏', 'sagittarius' => '♐', 'capricorn' => '♑', 'aquarius' => '♒', 'pisces' => '♓']],
        'moon' => ['Moon Phase Wheel', 'Moon Phases', ['new-moon', 'waxing-crescent-moon', 'first-quarter-moon', 'waxing-gibbous-moon', 'full-moon', 'waning-gibbous-moon', 'third-quarter-moon', 'waning-crescent-moon'],
            ['new-moon' => '●', 'waxing-crescent-moon' => '☽', 'first-quarter-moon' => '◑', 'waxing-gibbous-moon' => '◖', 'full-moon' => '○', 'waning-gibbous-moon' => '◗', 'third-quarter-moon' => '◐', 'waning-crescent-moon' => '☾']],
    ];
}

/** Which grimoires to show for a point on the wheel, and in what order. */
const WHEEL_GROUPS = [
    'correspondence' => 'Intentions', 'plant' => 'Herbs & plants', 'mineral' => 'Crystals & stones', 'oil' => 'Oils',
    'entity' => 'Deities & spirits', 'timing' => 'Timing', 'astrology' => 'Astrology', 'animal' => 'Animals', 'symbol' => 'Symbols',
    'rune' => 'Runes', 'card' => 'Cards', 'material' => 'Materials', 'recipe' => 'Recipes', 'ritual' => 'Rituals', 'dream' => 'Dreams',
];

function view_wheel(): void
{
    if (!members_only_tool()) return;
    record_view('route:/manuscript/wheel');
    $wheels = wheels();
    $of = isset($wheels[$_GET['of'] ?? '']) ? $_GET['of'] : 'planets';
    [$name, $type, $order, $glyphs] = $wheels[$of];
    $all = pages_of_type('astrology', $type);
    $points = array_values(array_filter(array_map(fn($s) => $all[$s] ?? null, $order)));
    $on = $_GET['on'] ?? '';
    $sel = null;
    foreach ($points as $p) if ($p['slug'] === $on) $sel = $p;
    $sel ??= $points[0] ?? null;

    // How much each point is tied to, for the ring's dots.
    $sizes = [];
    foreach ($points as $p) $sizes[$p['slug']] = count(linked_counts([$p['id']]));
    $wheel = wheel_svg($of, $points, $glyphs, $sel['slug'] ?? '', $sizes);

    $groups = '';
    $total = 0;
    if ($sel) {
        $linked = tool_pages(array_keys(linked_counts([$sel['id']])));
        $total = count($linked);
        $by = [];
        // Single calendar days (November 3, November 4…) would drown the festivals and seasons: they're on the Calendar instead.
        foreach ($linked as $p) if (isset(WHEEL_GROUPS[$p['grimoire']]) && !in_array('Days of the Year', $p['types'], true)) $by[$p['grimoire']][] = $p;
        foreach (WHEEL_GROUPS as $g => $label) {
            if (empty($by[$g])) continue;
            usort($by[$g], fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
            $list = $by[$g];
            $shown = array_slice($list, 0, 24);
            $rest = array_slice($list, 24);
            $groups .= '<section class="wheel-group"><h3>' . e($label) . ' <small>' . count($list) . '</small></h3><div class="rels">'
                . implode('', array_map(fn($p) => tool_chip($p), $shown))
                . ($rest ? '<details class="rels-more"><summary>Show all ' . count($list) . '</summary><div class="rels">' . implode('', array_map(fn($p) => tool_chip($p), $rest)) . '</div></details>' : '')
                . '</div></section>';
        }
    }
    $tabs = '';
    foreach ($wheels as $k => [$label]) {
        $tabs .= '<a class="fchip' . ($k === $of ? ' on' : '') . '" href="' . e(url('wheel') . '?of=' . $k) . '"' . ($k === $of ? ' aria-current="true"' : '') . '>' . e($label) . '</a>';
    }
    $detail = $sel
        ? '<header class="wheel-head"><p class="eyebrow">' . e(isset($glyphs[$sel['slug']]) ? $glyphs[$sel['slug']] . "\u{FE0E}" : '') . ' ' . e(['planets' => 'Planet', 'signs' => 'Zodiac sign', 'moon' => 'Moon phase'][$of]) . '</p>'
          . '<h2><a href="' . e($sel['url']) . '">' . e($sel['title']) . '</a></h2>'
          . '<p class="small">Tied to ' . number_format($total) . ' pages across the grimoires.</p></header>' . $groups
        : '<p class="lede">These pages haven’t been moved over from Notion yet.</p>';

    $body = '<div class="wheel-layout"><div class="wheel-wrap">' . $wheel . '</div><div class="wheel-detail" id="wheel-detail" aria-live="polite">' . $detail . '</div></div>';
    if (!empty($_GET['part'])) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store'); echo $body; return; }
    $wheelName = ['planets' => 'Planet Wheel', 'signs' => 'Zodiac Wheel', 'moon' => 'Moon Phase Wheel'][$of];
    $one = ['planets' => 'planet', 'signs' => 'zodiac sign', 'moon' => 'moon phase'][$of];
    layout($wheelName, '<article class="pg pg-tool pg-wheel">'
        . '<header class="pg-head tool-head">' . tool_crumbs('astrology') . '<p class="eyebrow labelled">Correspondence wheel</p><h1>The ' . e(str_replace(' Wheel', '', $wheelName)) . ' <em>Wheel</em></h1>'
        . '<p class="lede">Choose a ' . e($one) . ' to light up everything tied to it: the intentions it rules, its herbs, stones, oils, deities and days.</p>'
        . '<nav class="fchips wheel-tabs" aria-label="Wheels">' . $tabs . '</nav></header>'
        . '<div data-wheel>' . $body . '</div></article>', ['private' => true, 'protect' => true]);
}

/** The wheel itself: one segment per point (a link), its glyph and name, and a dot sized by how much it's tied to. */
function wheel_svg(string $of, array $points, array $glyphs, string $sel, array $sizes): string
{
    $n = count($points);
    if (!$n) return '';
    $W = 520; $c = $W / 2; $R = 248; $r = 112; // outer and inner radius
    $max = max(1, ...array_values($sizes));
    $pt = fn(float $a, float $rad) => round($c + $rad * cos($a), 2) . ' ' . round($c + $rad * sin($a), 2);
    $segs = '';
    foreach ($points as $i => $p) {
        $a0 = -M_PI / 2 + ($i - .5) * 2 * M_PI / $n;
        $a1 = $a0 + 2 * M_PI / $n;
        $mid = ($a0 + $a1) / 2;
        $d = 'M' . $pt($a0, $r) . ' L' . $pt($a0, $R) . ' A' . $R . ' ' . $R . ' 0 0 1 ' . $pt($a1, $R) . ' L' . $pt($a1, $r) . ' A' . $r . ' ' . $r . ' 0 0 0 ' . $pt($a0, $r) . 'Z';
        [$gx, $gy] = explode(' ', $pt($mid, ($R + $r) / 2 + 8));
        [$tx, $ty] = [$gx, (string) ((float) $gy + 26)];
        $gy = (string) ((float) $gy - 6);
        [$dx, $dy] = explode(' ', $pt($mid, $r + 16));
        $dot = round(2 + 5 * sqrt(($sizes[$p['slug']] ?? 0) / $max), 1);
        $on = $p['slug'] === $sel;
        $segs .= '<a class="seg' . ($on ? ' on' : '') . '" href="' . e(url('wheel') . '?of=' . $of . '&on=' . $p['slug']) . '" data-on="' . e($p['slug']) . '"'
            . ($on ? ' aria-current="true"' : '') . ' aria-label="' . e($p['title']) . '">'
            . '<path class="seg-bg" d="' . $d . '"/>'
            . '<text class="seg-glyph" x="' . $gx . '" y="' . $gy . '" text-anchor="middle" dominant-baseline="central">' . e(($glyphs[$p['slug']] ?? '✦') . "\u{FE0E}") . '</text>'
            . '<text class="seg-name" x="' . $tx . '" y="' . $ty . '" text-anchor="middle" dominant-baseline="central">' . e(preg_replace('/ Moon$/', '', $p['title'])) . '</text>'
            . '<circle class="seg-dot" cx="' . $dx . '" cy="' . $dy . '" r="' . $dot . '"/></a>';
    }
    return '<svg class="wheel" viewBox="0 0 ' . $W . ' ' . $W . '" role="group" aria-label="Correspondence wheel">'
        . '<circle class="wheel-rim" cx="' . $c . '" cy="' . $c . '" r="' . ($R + 6) . '"/>' . $segs
        . '<circle class="wheel-hub" cx="' . $c . '" cy="' . $c . '" r="' . ($r - 10) . '"/>'
        . '<text class="wheel-hub-star" x="' . $c . '" y="' . $c . '" text-anchor="middle" dominant-baseline="central">✦</text></svg>';
}

// ------------------------------------------------------------------ notes

/*
 * A member's notes: each has a title (the page it was written on, to start with) and text, and remembers that page.
 * "✎ Notes" at the top of every Manuscript page opens the note pop-up (layout() puts it in the page; wiki.js runs it):
 * the note already written on this page if there is one, else a new one. /notes lists them all; each opens in the pop-up.
 */

const NOTE_MAX = 20000;

function note_row(int $id, string $email): ?array
{
    $st = db()->prepare('SELECT * FROM member_notes WHERE id = ? AND email = ?');
    $st->execute([$id, $email]);
    return $st->fetch() ?: null;
}

/** The note a member last wrote on a page (by page id, or by address for pages that aren't entries). */
function note_for_page(string $email, ?string $pageId, string $path): ?array
{
    $st = $pageId
        ? db()->prepare('SELECT * FROM member_notes WHERE email = ? AND page_id = ? ORDER BY updated_at DESC LIMIT 1')
        : db()->prepare('SELECT * FROM member_notes WHERE email = ? AND page_id IS NULL AND page_path = ? ORDER BY updated_at DESC LIMIT 1');
    $st->execute([$email, $pageId ?: $path]);
    return $st->fetch() ?: null;
}

/** The pop-up itself, added to every page a member reads ($ctx: the page's id, if any, and its title). */
function note_dialog(array $ctx): string
{
    $email = current_email();
    if (!$email || !has_access()) return '';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $pageId = $ctx['page'] ?? null;
    $title = trim((string) ($ctx['title'] ?? '')) ?: 'A note';
    $n = note_for_page($email, $pageId, $path);
    $count = (int) db()->query('SELECT COUNT(*) FROM member_notes WHERE email = ' . db()->quote($email))->fetchColumn();
    return '<dialog class="note-pop" id="note-pop" aria-labelledby="note-pop-h" data-default-title="' . e($title) . '">'
        . '<form class="note-form" method="post" action="' . e(url('notes')) . '" data-note-form>'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="id" value="' . e((string) ($n['id'] ?? '')) . '">'
        . '<input type="hidden" name="page" value="' . e((string) $pageId) . '"><input type="hidden" name="path" value="' . e($path) . '">'
        . '<div class="note-top"><p class="eyebrow" id="note-pop-h"><span aria-hidden="true">✎</span> Your note</p>'
        . '<button class="note-x" type="button" data-note-close aria-label="Close">&times;</button></div>'
        . '<label class="sr-only" for="note-title">Note title</label>'
        . '<input class="note-title" id="note-title" name="title" maxlength="160" value="' . e($n['title'] ?? $title) . '" placeholder="Name this note">'
        . '<label class="sr-only" for="note-body">Note</label>'
        . '<textarea class="note-body" id="note-body" name="body" rows="9" maxlength="' . NOTE_MAX . '" placeholder="Write in the margin… only you can see this.">' . e($n['body'] ?? '') . '</textarea>'
        . '<div class="note-foot"><button class="btn" type="submit">Save</button><span class="notes-status" role="status"></span>'
        . '<span class="note-links"><button class="link as-button" type="button" data-note-new>New note</button>'
        . '<a class="link" href="' . e(url('notes')) . '">All notes' . ($count ? ' (' . $count . ')' : '') . '</a></span></div>'
        . '</form></dialog>';
}

/** Saves a note from the pop-up or the note's own page: [id, deleted?]. An empty new note isn't kept. */
function store_note(string $email, array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    $title = trim(mb_substr(preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')), 0, 160)) ?: 'Untitled note';
    $body = rtrim(mb_substr(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')), 0, NOTE_MAX));
    if ($id && note_row($id, $email)) {
        db()->prepare('UPDATE member_notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND email = ?')->execute([$title, $body, now(), $id, $email]);
        return [$id, false];
    }
    if (trim($body) === '') return [0, false];
    $page = page_by_id((string) ($in['page'] ?? ''));
    $path = (string) ($in['path'] ?? '');
    if (!preg_match('~^/[^\s]*$~', $path) || strlen($path) > 300) $path = '';
    $st = db()->prepare('SELECT COUNT(*) FROM member_notes WHERE email = ?');
    $st->execute([$email]);
    if ((int) $st->fetchColumn() >= 2000) return [0, false];
    db()->prepare('INSERT INTO member_notes (email, page_id, page_path, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $page['id'] ?? null, $path, $title, $body, now(), now()]);
    return [(int) db()->lastInsertId(), false];
}

/** Where a note was written, as [title, address], for "from …" under it. */
function note_source(array $n): ?array
{
    if ($n['page_id'] && ($p = page_index([$n['page_id']])[$n['page_id']] ?? null)) return [$p['title'], $p['url']];
    if ($n['page_path']) return [null, $n['page_path']];
    return null;
}

/** /notes (list; POST saves), /notes/<n> (one note; ?json for the pop-up; POST saves or deletes). */
function route_notes(array $parts, string $method): void
{
    $email = current_email();
    if (!$email) redirect(url('login') . '?next=' . rawurlencode((string) parse_url(url('notes'), PHP_URL_PATH)));
    if (!has_access()) { view_gate(['locked' => true]); return; }
    $fetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    $id = (int) ($parts[0] ?? 0);
    if ($method === 'POST') {
        check_csrf();
        if (($_POST['do'] ?? '') === 'delete') {
            db()->prepare('DELETE FROM member_notes WHERE id = ? AND email = ?')->execute([$id ?: (int) ($_POST['id'] ?? 0), $email]);
            if ($fetch) { header('Content-Type: application/json'); echo json_encode(['deleted' => true]); return; }
            redirect(url('notes'));
        }
        [$saved] = store_note($email, $id ? ['id' => $id] + $_POST : $_POST);
        if ($fetch) { header('Content-Type: application/json'); echo json_encode(['id' => $saved]); return; }
        redirect($saved ? url('notes/' . $saved) : url('notes'));
    }
    if ($id) {
        $n = note_row($id, $email);
        if (!$n) { not_found(); return; }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json');
            header('Cache-Control: private, no-store');
            echo json_encode(['id' => (int) $n['id'], 'title' => $n['title'], 'body' => $n['body']]);
            return;
        }
        view_note($n);
        return;
    }
    view_notes($email);
}

function view_notes(string $email): void
{
    $st = db()->prepare('SELECT * FROM member_notes WHERE email = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$email]);
    $notes = $st->fetchAll();
    $items = '';
    foreach ($notes as $n) {
        $src = note_source($n);
        $snip = trim(preg_replace('/\s+/', ' ', $n['body']));
        $items .= '<li class="note-card"><a class="note-open" href="' . e(url('notes/' . $n['id'])) . '" data-note-id="' . (int) $n['id'] . '">'
            . '<span class="note-card-title">' . e($n['title']) . '</span>'
            . '<span class="note-card-snip">' . e(mb_strimwidth($snip, 0, 190, '…')) . '</span></a>'
            . '<p class="note-card-meta">' . e(date('M j, Y', strtotime($n['updated_at'])))
            . ($src && $src[0] ? ' · from <a href="' . e($src[1]) . '">' . e($src[0]) . '</a>' : '') . '</p></li>';
    }
    $body = $items ? '<ul class="note-cards">' . $items . '</ul>'
        : '<p class="lede">No notes yet. Tap <strong>✎ Notes</strong> at the top of any page to write one.</p>';
    layout('Your notes', '<section class="saved notes-page"><p class="eyebrow labelled">Your Manuscript</p><h1>Your <em>notes</em></h1>'
        . '<p class="small">Only you can see these. Each one opens to read, rename or change it.</p>' . $body . '</section>', ['private' => true, 'note' => ['title' => 'A note']]);
}

/** One note on its own page (without JavaScript, or opened from a link): edit, rename, delete. */
function view_note(array $n): void
{
    $csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $src = note_source($n);
    layout($n['title'], '<section class="saved notes-page one-note"><p class="eyebrow labelled"><a href="' . e(url('notes')) . '">Your notes</a></p>'
        . '<form class="note-form on-page" method="post" action="' . e(url('notes/' . $n['id'])) . '">' . $csrf
        . '<label class="sr-only" for="np-title">Note title</label><input class="note-title" id="np-title" name="title" maxlength="160" value="' . e($n['title']) . '">'
        . ($src ? '<p class="small">Written on ' . ($src[0] ? '<a class="link" href="' . e($src[1]) . '">' . e($src[0]) . '</a>' : '<a class="link" href="' . e($src[1]) . '">this page</a>') . ', ' . e(date('F j, Y', strtotime($n['created_at']))) . '</p>' : '')
        . '<label class="sr-only" for="np-body">Note</label><textarea class="note-body" id="np-body" name="body" rows="14" maxlength="' . NOTE_MAX . '">' . e($n['body']) . '</textarea>'
        . '<div class="note-foot"><button class="btn" type="submit">Save</button></div></form>'
        . '<form method="post" action="' . e(url('notes/' . $n['id'])) . '">' . $csrf . '<input type="hidden" name="do" value="delete">'
        . '<button class="link as-button" type="submit" data-confirm="Delete this note? This can’t be undone.">Delete this note</button></form></section>', ['private' => true]);
}

/** For the Saved page: the member's saved workings. */
function saved_extras(string $email): string
{
    $st = db()->prepare('SELECT id, title, created_at FROM workings WHERE email = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$email]);
    $workings = $st->fetchAll();
    if (!$workings) return '';
    $html = '<section class="saved-group"><h2>Your workings</h2><ul class="saved-list">';
    foreach ($workings as $w) $html .= '<li><a href="' . e(url('workings/' . $w['id'])) . '"><span aria-hidden="true">✦</span><span>' . e($w['title']) . '</span></a><small>' . e(date('M j, Y', strtotime($w['created_at']))) . '</small></li>';
    return $html . '</ul></section>';
}

/** Under a page's title: "Build a spell for Protection" on intentions, "See Venus on the wheel" on planets, signs and moon phases. */
function tool_links(array $p): string
{
    if (!has_access() || $p['kind'] !== 'entry') return '';
    $links = [];
    if ($p['grimoire'] === 'correspondence') $links[] = '<a class="link" href="' . e(url('spell-builder') . '?i=' . rawurlencode($p['slug'])) . '">✦ Build a spell for ' . e($p['title']) . '</a>';
    if ($p['grimoire'] === 'astrology') {
        foreach (wheels() as $k => [, , $order]) if (in_array($p['slug'], $order, true)) $links[] = '<a class="link" href="' . e(url('wheel') . '?of=' . $k . '&on=' . $p['slug']) . '">✺ See ' . e($p['title']) . ' on the wheel</a>';
    }
    return $links ? '<p class="tool-links small">' . implode('', $links) . '</p>' : '';
}
