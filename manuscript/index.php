<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/render.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/stripe.php';
require __DIR__ . '/lib/shop.php';
require __DIR__ . '/lib/dashboard.php';
require __DIR__ . '/lib/engage.php';
require __DIR__ . '/lib/properties.php';
require __DIR__ . '/lib/collection.php';
require __DIR__ . '/lib/glyphs.php';
require __DIR__ . '/lib/shelf.php';

// Path relative to the wiki's base, e.g. "correspondence/protection".
$base = parse_url(config('base_url'), PHP_URL_PATH) ?: '';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = trim($path, '/');
$parts = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    route($parts, $method);
} catch (Throwable $ex) {
    error_log('[manuscript] ' . $ex);
    http_response_code(500);
    layout('Something went wrong', '<section class="mm-empty"><h1>Something went <em>astray</em></h1><p class="lede">Please try again in a moment.</p></section>');
}

function route(array $parts, string $method): void
{
    $first = $parts[0] ?? '';
    switch ($first) {
        case '':              view_home(); return;
        case 'search':        view_search(trim((string) ($_GET['q'] ?? ''))); return;
        case 'media':         serve_media($parts[1] ?? ''); return;
        case 'go':            go_notion($parts[1] ?? ''); return;
        case 'join':          view_join(); return;
        case 'checkout':      if ($method === 'POST') do_checkout(); else redirect(url('join')); return;
        case 'welcome':       view_welcome(); return;
        case 'login':         $method === 'POST' ? do_login() : view_login(); return;
        case 'auth':          do_auth((string) ($_GET['token'] ?? '')); return;
        case 'logout':        if ($method === 'POST') { check_csrf(); sign_out(); } redirect(url()); return;
        case 'account':       $method === 'POST' ? do_billing() : view_account(); return;
        case 'stripe-webhook': stripe_webhook(); return;
        case 'admin':         redirect(url('dashboard')); return;
        case 'relations':     relations_json(); return;
        case 'saved':         $method === 'POST' ? toggle_saved() : view_saved(); return;
        case 'dashboard':     route_dashboard(array_slice($parts, 1), $method); return;
        case 'shop':          route_shop(array_slice($parts, 1), $method); return;
        case 'suggest':       search_suggest(trim((string) ($_GET['q'] ?? ''))); return;
        case 'calendar':      view_calendar(); return;
        case 'sneak-peek':    ($p = sneak_peek_page()) ? view_page($p) : redirect(url()); return;
    }
    $p = match (count($parts)) {
        1 => grimoire_root($first),
        2 => find_page($parts[0], $parts[1]),
        default => null,
    };
    if ($p) { view_page($p); return; }
    // Visitors get the same members-only page whether or not something exists at this address,
    // so the Manuscript's contents can't be mapped out by guessing URLs.
    has_access() ? not_found() : view_gate(['locked' => true]);
}

// ------------------------------------------------------------------ reading

function view_home(): void
{
    // Visitors see the same home, as a taste: any book they pick opens the join pop-up (assets/wiki.js).
    $member = has_access();
    if (!$member) record_view('route:/manuscript/');
    $grimoires = db()->query("SELECT * FROM pages WHERE kind = 'grimoire'")->fetchAll();
    // Alphabetical by name; Begin Here is the way in, so it gets its own button instead of a card.
    $begin = null;
    $grimoires = array_values(array_filter($grimoires, function ($g) use (&$begin) {
        if ($g['grimoire'] === 'begin-here') { $begin = $g; return false; }
        return true;
    }));
    usort($grimoires, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
    $count = grimoire_count_word();
    $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());

    // The grimoires as books on one row (a carousel).
    $counts = db()->query("SELECT grimoire, COUNT(*) FROM pages WHERE kind = 'entry' GROUP BY grimoire")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cards = bookshelf_html($grimoires, $counts);
    if (!$grimoires) {
        $cards = '<p class="lede">Nothing has been moved over yet. Run the importer (see README) to bring grimoires in from Notion.</p>';
    }
    $join = $begin ? '<div class="actions"><a class="btn" href="' . e(page_url($begin)) . '">Begin here</a></div>' : '';
    if (!$member) {
        $join = '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a member</a>'
            . (sneak_peek_page() ? '<a class="btn ghost" href="' . e(url('sneak-peek')) . '">Take a sneak peek</a>' : '') . '</div>'
            . (current_email() ? '<p class="small">Signed in as ' . e(current_email()) . ', but this email has no active membership. <a href="' . e(url('account')) . '">See your account</a></p>'
                : '<p class="small">Already a member? <a href="' . e(url('login')) . '">Sign in</a></p>');
    }
    layout('The Magick Manuscript', <<<HTML
<section class="mm-hero">
  <p class="eyebrow labelled">A Living Digital Grimoire</p>
  <h1>The Magick <em>Manuscript</em></h1>
  <p class="lede">{$count} cross-linked grimoires. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three. <span class="count-line">{$total} pages inscribed so far.</span></p>
  $join
</section>
$cards
HTML, $member ? ['private' => true, 'protect' => true]
    : ['index' => true, 'protect' => true, 'description' => 'The Magick Manuscript: a members-only, cross-linked digital grimoire by The Magick Muse.']);
}

/** What non-members see in place of the Manuscript: no titles, lists or previews. */
function view_gate(?array $locked = null): void
{
    $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
    $signin = current_email()
        ? '<p class="small">Signed in as ' . e(current_email()) . ', but this email has no active membership. <a href="' . e(url('account')) . '">See your account</a></p>'
        : '<p class="small">Already a member? <a href="' . e(url('login') . '?next=' . rawurlencode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) . '">Sign in</a></p>';
    $join = e(url('join'));
    $count = grimoire_count_word();
    $heading = $locked ? 'This page lives inside <em>the Manuscript</em>' : 'The Magick <em>Manuscript</em>';
    if ($locked) http_response_code(403);
    record_view($locked ? 'route:members-only' : 'route:/manuscript/');
    layout($locked ? 'Members only' : 'The Magick Manuscript', <<<HTML
<section class="mm-hero narrow gate">
  <p class="eyebrow labelled">Members only</p>
  <h1>$heading</h1>
  <p class="lede">{$count} cross-linked grimoires and {$total} pages of researched magick, open to members. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three.</p>
  <div class="actions"><a class="btn" href="{$join}">Become a member</a></div>
  $signin
</section>
HTML, ['index' => !$locked, 'description' => 'The Magick Manuscript: a members-only, cross-linked digital grimoire by The Magick Muse.']);
}

/** Pages listed in config "public_pages", and the sneak peek page, can be read by anyone. */
function is_public_page(array $p): bool
{
    return in_array($p['id'], array_column(open_pages(), 'id'), true);
}

function view_page(array $p): void
{
    $access = has_access() || is_public_page($p);
    if (!$access) { view_gate($p); return; }
    // "Show more" on a grimoire page's list: answer with just the next entries.
    if (!empty($_GET['part']) && $p['kind'] === 'grimoire') {
        [$g, $several] = grimoire_lists($p['grimoire']);
        $st = db()->prepare("SELECT * FROM pages WHERE grimoire = ? AND kind = 'collection'");
        $st->execute([$p['grimoire']]);
        foreach ($st->fetchAll() as $c) if (collection_part(collection_view($c, page_url($g), $several ? $c['slug'] : ''))) return;
        http_response_code(404); return;
    }
    record_view($p['id']);
    if ($p['kind'] === 'collection') { view_collection($p); return; }
    $crumbs = $p['kind'] === 'grimoire' ? '' : breadcrumbs($p);
    $g = $p['kind'] === 'grimoire' ? $p : grimoire_root($p['grimoire']);
    $eyebrow = $p['kind'] === 'grimoire' ? 'The Magick Manuscript' : ($g['title'] ?? '');

    $noPictures = in_array($p['grimoire'], (array) config('no_pictures', ['entity']), true);
    if ($noPictures && str_starts_with((string) $p['icon'], 'file:')) $p['icon'] = null;
    $tags = json_decode($p['tags_json'] ?? '[]', true) ?: [];
    $images = $tags['_images'] ?? [];
    unset($tags['_images']);

    // Title, then (for pages inside a grimoire) the properties panel: own properties, then relation links.
    // On a grimoire, "The Magick Manuscript" above the title leads back to the Manuscript's home.
    $eyebrowHtml = $p['kind'] === 'grimoire' ? '<a href="' . e(url()) . '">' . e($eyebrow) . '</a>' : e($eyebrow);
    $head = '<header class="pg-head">' . $crumbs . '<p class="eyebrow">' . $eyebrowHtml . '</p>'
        . '<div class="pg-title"><h1>' . icon_html($p['icon'], 'pg-icon big') . e($p['title']) . '</h1>' . save_button($p) . '</div></header>';
    $panel = in_array($p['kind'], ['entry', 'guide'], true) ? properties_panel($p, $tags, connections($p)) : '';
    // Visitors reading the sneak peek get a note above it; every link inside opens the join pop-up.
    $peek = !has_access() && ($sp = sneak_peek_page()) && $sp['id'] === $p['id'];
    if ($peek) {
        $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
        $head = '<aside class="peek-note"><p class="eyebrow labelled">A sneak peek</p>'
            . '<p>You\'re reading one page of the Manuscript, just as members see it. Every connection on it leads to another page: there are ' . $total . ' in all, across ' . mb_strtolower(grimoire_count_word()) . ' grimoires.</p>'
            . '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a member</a></div></aside>' . $head;
    }

    // Grimoires without pictures (the Entity Grimoire): nothing to show, even before the next import takes them off.
    if ($noPictures) {
        $p['cover'] = null;
        $images = [];
        $p['body_html'] = preg_replace('~<figure>.*?</figure>~s', '', (string) $p['body_html']);
    }
    // Grimoires use their photo from the website; other pages use their own cover, if any.
    $photo = $p['kind'] === 'grimoire' ? grimoire_photo($p['grimoire'], 1600, 640) : null;
    $cover = $photo ? '<div class="pg-cover arch-banner"><img src="' . e($photo) . '" alt="" width="1600" height="640"></div>' : '';
    // Cut-out pictures (transparent PNGs, like the Animal Grimoire's) are shown whole, standing on the page, not cropped into the arch.
    // Tall pictures (a tarot or Lenormand card) are shown whole too, and open full size when clicked: a wide banner would crop them to a strip.
    if (!$photo && $p['cover']) {
        $whole = is_cutout($p['cover']) || is_portrait($p['cover']);
        $img = '<img src="' . e(media_url($p['cover'])) . '" alt="' . ($whole ? e($p['title']) : '') . '">';
        $cover = '<div class="pg-cover' . ($whole ? ' cutout' : '') . '">'
            . ($whole ? '<a class="zoom" href="' . e(media_url($p['cover'])) . '" data-zoom>' . $img . '</a>' : $img) . '</div>';
    } elseif (!$photo) {
        $cover = '';
    }

    {
        $gallery = '';
        if ($images) {
            // Each picture opens full size when clicked (assets/wiki.js); without JavaScript the link opens the picture itself.
            $gallery = '<div class="gallery">' . implode('', array_map(fn($f) => '<figure><a class="zoom" href="' . e(media_url($f)) . '" data-zoom>'
                . '<img src="' . e(media_url($f)) . '" alt="' . e($p['title']) . '" loading="lazy"></a></figure>', $images)) . '</div>';
        }
        // A spread's layout picture goes right above its Card Details, where the positions are explained.
        $details = '<div class="prop-row"><div class="prop-name"><span class="prop-glyph" aria-hidden="true">◇</span>Card Details</div>';
        if ($gallery && str_contains($panel, $details)) {
            $panel = str_replace($details, str_replace('class="gallery"', 'class="gallery in-panel"', $gallery) . $details, $panel);
            $gallery = '';
        }
        // Landing pages: intro text is public, the guide pages it links to are for members.
        $bodyHtml = resolve_links((string) $p['body_html']);
        // A grimoire's intro box repeats its name as a heading ("The Animal Grimoire"); the page title already says it.
        if ($p['kind'] === 'grimoire') {
            $name = mb_strtolower(preg_replace('/^the\s+/i', '', $p['title']));
            $bodyHtml = preg_replace_callback('~<h([2-4])\b[^>]*>(.*?)</h\1>~s', function ($m) use ($name) {
                return mb_strtolower(preg_replace('/^the\s+/i', '', trim(html_entity_decode(strip_tags($m[2]))))) === $name ? '' : $m[0];
            }, $bodyHtml, 1);
        }
        // In intro boxes, "Label:" written in bold becomes a label line, with any text after it as its own paragraph.
        $bodyHtml = preg_replace_callback('~<aside class="mm-callout[^"]*">.*?</aside>~s', fn($box) => preg_replace_callback(
            '~<p><strong>([^<]{2,80}:)</strong>\s*(.*?)</p>~s',
            fn($m) => '<p class="label">' . $m[1] . '</p>' . (trim(strip_tags($m[2])) !== '' ? '<p>' . $m[2] . '</p>' : ''),
            $box[0]), $bodyHtml);
        $body = $panel . $gallery . '<div class="mm-prose">' . $bodyHtml . '</div>';
    }
    // With written content, the properties become a sidebar beside it (and wiki.js adds a table of contents).
    // A page that is only properties keeps them across the page: sidebars beside nothing would only show the gap.
    $aside = $panel !== '' && trim(html_entity_decode(strip_tags($bodyHtml))) !== '';
    layout($p['title'], '<article class="pg pg-' . e($p['kind']) . ($aside ? ' with-aside' : '') . '">' . $cover . $head . $body . '</article>', ['private' => true, 'protect' => true, 'peek' => $peek]);
}

/** True for a PNG with transparency (colour type with alpha), read from the file's header. */
function is_cutout(string $file): bool
{
    if (!str_ends_with(strtolower($file), '.png')) return false;
    $h = @file_get_contents(config('data_dir') . '/media/' . basename($file), false, null, 0, 26);
    return $h !== false && strlen($h) === 26 && in_array(ord($h[25]), [4, 6], true);
}

/**
 * /calendar: the year, month by month, every day linking to its page in the Timing grimoire (january-1 … december-31).
 * All twelve months are in the page; wiki.js shows one at a time, lines the days up under their weekdays for the
 * current year, marks today, and moves between months. Without JavaScript the months are simple lists.
 */
function view_calendar(): void
{
    if (!has_access()) { view_gate(['locked' => true]); return; }
    record_view('route:/manuscript/calendar');
    $st = db()->prepare("SELECT id, slug, grimoire, kind, title FROM pages WHERE grimoire = 'timing' AND kind = 'entry'");
    $st->execute();
    $days = [];
    $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
    foreach ($st->fetchAll() as $p) {
        if (preg_match('/^(' . implode('|', $months) . ')-(\d{1,2})$/', $p['slug'], $m)) $days[array_search($m[1], $months, true) + 1][(int) $m[2]] = $p;
    }
    // The festivals on each day: its "Related Timing" connections (Samhain on October 31, Yule on December 21…).
    $ids = array_merge(...array_map(fn($m) => array_column($m, 'id'), $days ?: [[]]));
    $events = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $q = db()->prepare("SELECT from_id, to_id FROM relations WHERE prop = 'Related Timing' AND from_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY sort');
        $q->execute($chunk);
        $rows = $q->fetchAll();
        $idx = page_index(array_column($rows, 'to_id'));
        foreach ($rows as $r) if (isset($idx[$r['to_id']])) $events[$r['from_id']][] = $idx[$r['to_id']];
    }
    echo_calendar_page(calendar_html($days, fn(array $p) => page_url($p), fn(array $p) => array_map(
        fn($t) => [$t['icon'] && !preg_match('/^(file|asset):/', (string) $t['icon']) ? $t['icon'] : '', $t['title']], $events[$p['id']] ?? [])));
}

/** The twelve months as lists of days ($days[month][day] = page); $href gives a day's address, $events its festivals ([emoji, name]…). */
function calendar_html(array $days, callable $href, ?callable $events = null): string
{
    $html = '';
    for ($m = 1; $m <= 12; $m++) {
        $name = date('F', mktime(0, 0, 0, $m, 1, 2024));
        $html .= '<section class="cal-m" data-month="' . $m . '" aria-label="' . $name . '"><h2 class="cal-m-name">' . $name . '</h2><ol class="cal-days">';
        for ($d = 1; $d <= (int) date('t', mktime(0, 0, 0, $m, 1, 2024)); $d++) {
            $p = $days[$m][$d] ?? null;
            $ev = $p && $events ? $events($p) : [];
            $list = '';
            foreach (array_slice($ev, 0, 2) as [$icon, $name]) $list .= '<span class="cal-ev">' . ($icon ? '<i aria-hidden="true">' . e($icon) . '</i>' : '') . e($name) . '</span>';
            if (count($ev) > 2) $list .= '<span class="cal-more">+' . (count($ev) - 2) . ' more</span>';
            $label = $p ? $p['title'] . ($ev ? ': ' . implode(', ', array_column($ev, 1)) : '') : '';
            $html .= '<li data-day="' . $d . '">' . ($p ? '<a href="' . e($href($p)) . '" aria-label="' . e($label) . '"><span class="cal-n">' . $d . '</span>' . $list . '</a>' : '<span><span class="cal-n">' . $d . '</span></span>') . '</li>';
        }
        $html .= '</ol></section>';
    }
    return '<section class="calendar" data-calendar><header class="pg-head"><p class="eyebrow">Timing Grimoire</p><h1>Calendar</h1>'
        . '<p class="lede small">Every day of the year has its own page: its correspondences, the spirits and festivals tied to it, and the rituals for it.</p></header>'
        . '<nav class="cal-nav" hidden><button class="cal-btn" type="button" data-cal="-1" aria-label="Previous month">‹</button>'
        . '<p class="cal-title" aria-live="polite"></p><button class="cal-btn" type="button" data-cal="1" aria-label="Next month">›</button>'
        . '<button class="link as-button cal-today" type="button" data-cal="0">Today</button></nav>'
        . '<div class="cal-months">' . $html . '</div></section>';
}

function echo_calendar_page(string $html): void
{
    layout('Calendar', '<article class="pg pg-calendar">' . $html . '</article>', ['private' => true, 'protect' => true]);
}

/** True for a picture taller than it is wide, read from the file itself. */
function is_portrait(string $file): bool
{
    $size = @getimagesize(config('data_dir') . '/media/' . basename($file));
    return $size !== false && $size[1] > $size[0] * 1.05;
}

/** Turns what someone typed into an FTS5 query where every word is a prefix match ("rose qua" → Rose Quartz). */
function fts_query(string $q): string
{
    $terms = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"*', array_slice($terms, 0, 8)));
}

function view_search(string $q): void
{
    if ($q === '') redirect(url());
    if (!has_access()) { view_gate(['search']); return; }
    $g = (string) ($_GET['g'] ?? '');
    $match = fts_query($q);
    $rows = [];
    if ($match !== '') {
        $sql = "SELECT p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.aka, p.parent_id,
                snippet(search, 3, '<mark>', '</mark>', '…', 24) AS snip
            FROM search JOIN pages p ON p.id = search.id
            WHERE search MATCH ?" . ($g !== '' ? ' AND p.grimoire = ?' : '') . "
            ORDER BY bm25(search, 0, 12.0, 6.0, 1.0) LIMIT 100";
        $st = db()->prepare($sql);
        $st->execute($g !== '' ? ["{title aka plain} : $match", $g] : ["{title aka plain} : $match"]);
        $rows = $st->fetchAll();
    }
    // How many hits in each grimoire, for the filter.
    $counts = [];
    if ($match !== '') {
        $st = db()->prepare("SELECT p.grimoire, COUNT(*) n FROM search JOIN pages p ON p.id = search.id WHERE search MATCH ? GROUP BY p.grimoire");
        $st->execute(["{title aka plain} : $match"]);
        $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $parents = page_index(array_column($rows, 'parent_id'));
    $list = '';
    foreach ($rows as $r) {
        $where = $parents[$r['parent_id']]['title'] ?? '';
        // Snippet text is escaped, then our own <mark> tags are restored.
        $snip = str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], e($r['snip']));
        $list .= '<li><a href="' . e(page_url($r)) . '">' . icon_html($r['icon']) . '<strong>' . e($r['title']) . '</strong></a>'
            . ($where ? '<small>' . e($where) . '</small>' : '') . ($r['aka'] ? '<small class="aka-s">Also: ' . e($r['aka']) . '</small>' : '') . ($snip ? '<p>' . $snip . '</p>' : '') . '</li>';
    }
    $chips = '';
    if (count($counts) > 1 || $g !== '') {
        $roots = page_index(array_map(fn($k) => grimoire_root($k)['id'] ?? '', array_keys($counts)));
        $titles = [];
        foreach ($roots as $r) $titles[$r['grimoire']] = $r['title'];
        $base = url('search?q=' . rawurlencode($q));
        $chips = '<nav class="filters" aria-label="Filter by grimoire"><a class="chip" href="' . e($base) . '"' . ($g === '' ? ' aria-current="true"' : '') . '>All <small>' . array_sum($counts) . '</small></a>';
        arsort($counts);
        foreach ($counts as $slug => $n) {
            $chips .= '<a class="chip" href="' . e($base . '&g=' . rawurlencode($slug)) . '"' . ($g === $slug ? ' aria-current="true"' : '') . '>' . e($titles[$slug] ?? $slug) . ' <small>' . $n . '</small></a>';
        }
        $chips .= '</nav>';
    }
    $count = count($rows);
    $shown = $count >= 100 ? '100+' : (string) $count;
    $body = '<section class="mm-results"><p class="eyebrow">Search</p><h1>' . ($count ? $shown . ($count === 1 ? ' result' : ' results') . ' for <em>' . e($q) . '</em>' : 'Nothing found for <em>' . e($q) . '</em>') . '</h1>'
        . $chips
        . ($count ? '<ol class="results">' . $list . '</ol>' : '<p class="lede">Try another spelling, a shorter word, or a related term.</p>')
        . '</section>';
    layout('Search: ' . $q, $body, ['private' => true, 'protect' => true]);
}

/** Live suggestions for the search box: page titles and alternate names only. */
function search_suggest(string $q): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
    $match = fts_query($q);
    if (!has_access() || mb_strlen($q) < 2 || $match === '') { echo '[]'; return; }
    $st = db()->prepare("SELECT p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.parent_id FROM search JOIN pages p ON p.id = search.id
        WHERE search MATCH ? ORDER BY bm25(search, 0, 10.0, 4.0, 0.0) LIMIT 8");
    $st->execute(["{title aka} : $match"]);
    $rows = $st->fetchAll();
    $parents = page_index(array_column($rows, 'parent_id'));
    echo json_encode(array_map(fn($r) => [
        'title' => $r['title'],
        'url' => page_url($r),
        'where' => $parents[$r['parent_id']]['title'] ?? '',
        'icon' => ($r['icon'] && !preg_match('/^(file|asset):/', $r['icon'])) ? $r['icon'] : '', // emoji only
    ], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function serve_media(string $file): void
{
    $file = basename($file);
    $path = config('data_dir') . '/media/' . $file;
    if (!preg_match('/^[a-z0-9-]+\.(jpe?g|png|gif|webp|svg|avif)$/', $file) || !is_file($path)) { http_response_code(404); exit; }
    // Members only, except images that appear on a page listed in public_pages.
    if (!has_access() && !media_is_public($file)) { http_response_code(403); exit; }
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'avif' => 'image/avif'];
    header('Content-Type: ' . $types[pathinfo($file, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=604800');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

function media_is_public(string $file): bool
{
    // Page icons are small decorations shown beside links (e.g. the sneak peek's connections): anyone may see them.
    if (preg_match('/-icon-[0-9a-f]+\.[a-z]+$/', $file)) return true;
    foreach (open_pages() as $p) {
        if (str_contains((string) $p['body_html'], $file) || $p['cover'] === $file || $p['icon'] === 'file:' . $file
            || str_contains((string) $p['tags_json'], $file)) return true;
    }
    return false;
}

/** /go/<notion-id>: old Notion links (Discord posts, bookmarks) land on the right wiki page. */
function go_notion(string $id): void
{
    $id = NotionImporterLinks::idFrom($id);
    $p = $id ? page_by_id($id) : null;
    if ($p && (has_access() || is_public_page($p))) redirect(page_url($p), 301);
    $p ? view_gate($p) : (has_access() ? not_found() : view_gate(['locked' => true]));
}

final class NotionImporterLinks
{
    public static function idFrom(string $s): ?string
    {
        $s = strtolower(str_replace('-', '', $s));
        return preg_match('/([0-9a-f]{32})$/', $s, $m) ? $m[1] : null;
    }
}

// ------------------------------------------------------------------ membership

function view_join(): void
{
    record_view('route:/manuscript/join');
    $copy = require MM_ROOT . '/manuscript-copy.php';
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['code'] ?? ''));
    $csrf = e(csrf_token());
    $plans = '';
    foreach (config('plans', []) as $key => $plan) {
        if (empty($plan['price'])) continue;
        $plans .= '<form class="card plan" method="post" action="' . e(url('checkout')) . '">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="plan" value="' . e($key) . '"><input type="hidden" name="code" value="' . e($code) . '">'
            . '<h3>' . e($plan['label']) . '</h3>'
            . (!empty($plan['amount_label']) ? '<p class="price big">' . e($plan['amount_label']) . '</p>' : '')
            . '<p>' . e($plan['blurb'] ?? '') . '</p>'
            . '<label class="agree"><input type="checkbox" name="agree" value="1" required> I agree to the <a href="#terms">Terms of Access</a></label>'
            . '<button class="btn" type="submit">' . e($plan['button'] ?? 'Get access') . '</button></form>';
    }
    $planClass = substr_count($plans, '<form') > 1 ? 'pairs' : 'single';
    $highlights = '';
    foreach ($copy['highlights'] as [$title, $text]) {
        $highlights .= '<li><strong>' . e($title) . '</strong> ' . e($text) . '</li>';
    }
    $login = e(url('login'));
    $note = has_access() ? '<p class="notice">You already have full access. <a href="' . e(url()) . '">Open the Manuscript</a>.</p>' : '';
    // A code from a link (e.g. the SEEKER10 pop-up) is applied quietly at checkout; no note on the page.
    $desc = safe_html($copy['description_html']);
    $terms = safe_html($copy['terms_html']);
    $community = community_links();
    layout('Join the Manuscript', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Membership</p>
  <h1>The Magick <em>Manuscript</em></h1>
  <p class="lede">20,000+ entries · 15 cross-linked grimoires · an interactive digital grimoire that never stops growing.</p>
  $note
</section>
<section class="cards plans $planClass">$plans</section>
<p class="center small">Secure checkout by Stripe. After paying you're signed in straight away. Already a member? <a href="$login">Sign in</a></p>
<section class="join-copy">
  <div class="mm-prose">$desc</div>
  <ul class="highlights">$highlights</ul>
  $community
  <details class="terms" id="terms"><summary>Terms of Access</summary><div class="mm-prose">$terms</div></details>
</section>
HTML, ['index' => true, 'description' => 'Join The Magick Manuscript: 20,000+ pages of cross-linked, sourced witchcraft reference across 15 grimoires.']);
}

function do_checkout(): void
{
    check_csrf();
    if (empty($_POST['agree'])) redirect(url('join') . '#terms');
    $plan = (string) ($_POST['plan'] ?? '');
    if (empty(config('plans', [])[$plan]['price'])) { not_found(); return; }
    show_checkout(create_checkout($plan, current_email(), (string) ($_POST['code'] ?? '')), config('plans')[$plan]['label'] ?? 'The Magick Manuscript', url('join'));
}

function view_welcome(): void
{
    $email = null;
    $sid = (string) ($_GET['session_id'] ?? '');
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
        try {
            $s = stripe('GET', 'checkout/sessions/' . $sid);
            $buyer = $s['customer_details']['email'] ?? null;
            if (($s['status'] ?? '') === 'complete' && $buyer) {
                // The webhook normally records the membership; doing it here too means no waiting.
                if (!member_row($buyer)) {
                    handle_stripe_event(['id' => 'welcome_' . $sid, 'type' => 'checkout.session.completed', 'data' => ['object' => $s]]);
                }
                // Only say "you're in" when access really was granted (a bank transfer may still be pending).
                if (checkout_link_fresh($s) && member_row($buyer)) {
                    sign_in($buyer);
                    $email = $buyer;
                }
            }
        } catch (Throwable $ex) {
            error_log('[manuscript] welcome: ' . $ex->getMessage());
        }
    }
    $msg = $email
        ? '<p class="lede">Your membership is active and you are signed in as ' . e($email) . '. On another device, sign in with this email.</p><div class="actions"><a class="btn" href="' . e(url('begin-here')) . '">Begin here</a><a class="link" href="' . e(url()) . '">All grimoires</a></div>'
        : '<p class="lede">Thank you. If your access is not ready yet, <a href="' . e(url('login')) . '">sign in with the email you paid with</a>.</p>';
    layout('Welcome', '<section class="mm-hero narrow"><p class="eyebrow labelled">Welcome, seeker</p><h1>The Manuscript <em>is open to you</em></h1>' . $msg . '</section>', ['private' => true]);
}

/** Only same-site paths may be used as a post-login destination. */
function safe_next(?string $next): string
{
    $next = (string) $next;
    return preg_match('~^/(?![/\\\\])[^\s]*$~', $next) ? $next : (parse_url(config('base_url'), PHP_URL_PATH) ?: '/');
}

function view_login(string $notice = '', ?string $next = null): void
{
    $next = safe_next($next ?? ($_GET['next'] ?? $_POST['next'] ?? null));
    $csrf = e(csrf_token());
    $action = e(url('login'));
    $nextAttr = e($next);
    layout('Sign in', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Welcome back</p>
  <h1>Sign <em>in</em></h1>
  $notice
  <div class="signin">
  <form class="mm-form" method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <input type="hidden" name="next" value="$nextAttr">
    <label for="email">Your email</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
    <button class="btn" type="submit">Email me a sign-in link</button>
    <p class="small">No password needed. Use the email you bought with. The link works once and expires in 30 minutes.</p>
  </form>
  </div>
</section>
HTML, ['private' => true]);
}

function do_login(): void
{
    check_csrf();
    $next = safe_next($_POST['next'] ?? null);
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $ok = '<p class="notice">If that email has bought anything from The Magick Muse, a sign-in link is on its way. Check your inbox (and spam).</p>';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { view_login('<p class="notice error">That email doesn’t look right.</p>', $next); return; }
    if (!throttle('login', 5, 900)) { view_login('<p class="notice error">Too many tries. Please wait a few minutes.</p>', $next); return; }
    // Only customers and admins get a link; everyone sees the same message so emails can't be probed.
    if ((is_customer($email) || is_admin($email)) && !too_many_links($email)) {
        $link = url('auth?token=' . issue_login_token($email) . '&next=' . rawurlencode($next));
        send_mail($email, 'Your sign-in link for The Magick Muse',
            "Open this link to sign in:\n\n$link\n\nIt works once and expires in 30 minutes. If you didn't ask for it, ignore this email.",
            '<p>Open this link to sign in to The Magick Muse:</p><p><a href="' . e($link) . '">Sign in</a></p><p>It works once and expires in 30 minutes. If you didn’t ask for it, ignore this email.</p>');
    }
    view_login($ok, $next);
}

function do_auth(string $token): void
{
    $next = safe_next($_GET['next'] ?? null);
    $email = preg_match('/^[0-9a-f]{48}$/', $token) ? redeem_login_token($token) : null;
    if (!$email) { view_login('<p class="notice error">That link has expired or was already used. Request a new one below.</p>', $next); return; }
    sign_in($email);
    redirect($next);
}

function view_account(): void
{
    $email = current_email();
    if (!$email) redirect(url('login'));
    $m = member_row($email);
    $csrf = e(csrf_token());
    $status = match (true) {
        is_admin($email) && !$m => 'Curator (admin) access',
        !$m => 'No membership on this email',
        // A refunded or revoked lifetime/comp member keeps the plan name but not the access.
        $m['plan'] === 'lifetime' && $m['status'] === 'active' => 'Member: full Manuscript access',
        $m['status'] === 'disputed' => 'Access paused: your bank is disputing the payment. Access comes back if the dispute is withdrawn or settled in our favour.',
        $m['plan'] === 'comp' && $m['status'] === 'active' => 'Member (complimentary)',
        in_array($m['plan'], ['lifetime', 'comp'], true) => 'Membership ended',
        $m['status'] === 'active' => 'Monthly member, renews ' . e(substr((string) $m['paid_through'], 0, 10)),
        default => 'Membership ' . e($m['status']) . ($m['paid_through'] ? ', access until ' . e(substr($m['paid_through'], 0, 10)) : ''),
    };
    // Billing management is only for subscriptions; lifetime buyers get their Stripe receipt by email.
    $billing = $m && $m['stripe_customer'] && $m['plan'] === 'monthly' && $m['status'] !== 'canceled'
        ? '<form method="post" action="' . e(url('account')) . '"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="btn ghost" type="submit">Manage billing</button></form>' : '';
    $join = has_access() ? '' : '<a class="btn" href="' . e(url('join')) . '">Become a member</a>';
    $logout = e(url('logout'));
    $email = e($email);
    $admin = is_admin(current_email()) ? '<a class="link" href="' . e(url('dashboard')) . '">Dashboard</a>' : '';
    $library = '<a class="link" href="' . e(site_url('shop/library')) . '">Your library</a>';
    layout('Account', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Account</p>
  <h1><em>{$email}</em></h1>
  <p class="lede">$status</p>
  <div class="actions">$join $billing $library $admin
    <form method="post" action="$logout"><input type="hidden" name="csrf" value="$csrf"><button class="link as-button" type="submit">Sign out</button></form>
  </div>
</section>
HTML, ['private' => true]);
}

function do_billing(): void
{
    check_csrf();
    $m = member_row(current_email());
    if (!$m || !$m['stripe_customer']) redirect(url('account'));
    redirect(billing_portal_url($m['stripe_customer']));
}

function stripe_webhook(): void
{
    $payload = (string) file_get_contents('php://input');
    $event = verify_webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    if (!$event) { http_response_code(400); exit('bad signature'); }
    try {
        handle_stripe_event($event);
    } catch (Throwable $ex) {
        error_log('[manuscript] stripe webhook ' . ($event['type'] ?? '') . ': ' . $ex->getMessage());
        http_response_code(500); // Stripe will retry
        exit('retry');
    }
    http_response_code(200);
    echo 'ok';
}
