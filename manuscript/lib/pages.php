<?php
declare(strict_types=1);

/*
 * Reading the Manuscript: the home page, the members-only gate, entry pages, the calendar, the library,
 * picture credits, paid pictures (media) and links back to Notion.
 * Routed from index.php.
 */

function view_home(): void
{
    // Visitors see the same home, as a taste: any book they pick opens the join pop-up (assets/wiki.js).
    $member = has_access();
    if (!$member && !isset($_GET['peek'])) record_view('route:/manuscript/'); // the homepage's shelf (?peek) isn't a visit
    $grimoires = db()->query("SELECT * FROM pages WHERE kind = 'grimoire'")->fetchAll();
    // Alphabetical by name; Begin Here is the way in, so it gets its own button instead of a card.
    $begin = null;
    $grimoires = array_values(array_filter($grimoires, function ($g) use (&$begin) {
        if ($g['grimoire'] === 'begin-here') { $begin = $g; return false; }
        return true;
    }));
    usort($grimoires, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
    // The grimoires as books on one long shelf.
    $counts = db()->query("SELECT grimoire, COUNT(*) FROM pages WHERE kind = 'entry' GROUP BY grimoire")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cards = bookshelf_html($grimoires, $counts);
    if (!$grimoires) {
        $cards = '<p class="lede">Nothing has been moved over yet. Run the importer (see README) to bring grimoires in from Notion.</p>';
    }
    $join = '';
    // Under the shelf, a quiet way in for anyone unsure where to start.
    $guide = $begin ? '<p class="shelf-guide">Need some <a href="' . e(page_url($begin)) . '">guidance</a>?</p>' : '';
    // A member's own reading (beginner path, continue reading, a random page) sits under the shelf, not above it:
    // above the shelf there is only the title, one sentence and the way in.
    $yours = $member ? '<section class="mm-yours" aria-label="Your reading">' . path_home_line() . '</section>' : '';
    if (!$member) {
        $join = '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a Member</a>'
            . (sneak_peek_page() ? '<a class="btn ghost" href="' . e(url('sneak-peek')) . '">Take a Sneak Peek</a>' : '') . '</div>'
            . (current_email() ? '<p class="small">Signed in as ' . e(current_email()) . ', but this email has no active membership. <a href="' . e(url('account')) . '">See your account</a></p>'
                : '<p class="small">Already a member? <a href="' . e(url('login')) . '">Sign In</a></p>');
    }
    layout('The Magick Manuscript', <<<HTML
<section class="mm-hero">
  <h1>The Magick Manuscript</h1>
  <p class="lede">Choose a book from the shelf, and let your journey begin.</p>
  $join
</section>
$cards
$guide
$yours
HTML, $member ? ['private' => true, 'protect' => true]
    : ['index' => true, 'protect' => true, 'description' => 'The Magick Manuscript is a members-only, cross-linked digital grimoire by The Magick Muse.']);
}

/** What non-members see in place of the Manuscript: no titles, lists or previews. */
function view_gate(?array $locked = null): void
{
    $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
    $signin = current_email()
        ? '<p class="small">Signed in as ' . e(current_email()) . ', but this email has no active membership. <a href="' . e(url('account')) . '">See your account</a></p>'
        : '<p class="small">Already a member? <a href="' . e(url('login') . '?next=' . rawurlencode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) . '">Sign In</a></p>';
    $join = e(url('join'));
    $count = grimoire_count_word();
    $heading = $locked ? 'This page lives inside <em>the Manuscript</em>' : 'The Magick <em>Manuscript</em>';
    if ($locked) http_response_code(403);
    record_view($locked ? 'route:members-only' : 'route:/manuscript/');
    layout($locked ? 'Members only' : 'The Magick Manuscript', <<<HTML
<section class="mm-hero narrow gate">
  <p class="eyebrow">Members Only</p>
  <h1>$heading</h1>
  <p class="lede">{$count} cross-linked grimoires and {$total} pages of researched magick, open to members. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three.</p>
  <div class="actions"><a class="btn" href="{$join}">Become a Member</a></div>
  $signin
</section>
HTML, ['index' => !$locked, 'description' => 'The Magick Manuscript is a members-only, cross-linked digital grimoire by The Magick Muse.']);
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
    // Recipes and rituals: Description, Quantities and Directions go in the middle of the page, not with the properties.
    $textParts = content_properties($p['grimoire'], $tags);

    // Title, then (for pages inside a grimoire) the properties panel: own properties, then relation links.
    // On a grimoire, "The Magick Manuscript" above the title leads back to the Manuscript's home.
    $eyebrowHtml = $p['kind'] === 'grimoire' ? '<a href="' . e(url()) . '">' . e($eyebrow) . '</a>' : e($eyebrow);
    $head = '<header class="pg-head">' . $crumbs . '<p class="eyebrow">' . $eyebrowHtml . '</p>'
        . '<div class="pg-title"><h1>' . entry_icon($p, 'pg-icon big') . e($p['title']) . '</h1>' . save_button($p) . '</div></header>';
    $panel = in_array($p['kind'], ['entry', 'guide'], true) ? properties_panel($p, $tags, connections($p)) : '';
    // Visitors reading the sneak peek get a note above it; every link inside opens the join pop-up.
    $peek = !has_access() && ($sp = sneak_peek_page()) && $sp['id'] === $p['id'];
    if ($peek) {
        $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
        $head = '<aside class="peek-note"><p class="eyebrow">A Sneak Peek</p>'
            . '<p>You\'re reading one page of the Manuscript, just as members see it. Every connection on it leads to another page, and there are ' . $total . ' in all, across ' . mb_strtolower(grimoire_count_word()) . ' grimoires.</p>'
            . '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a Member</a></div></aside>' . $head;
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
    // Runes and astrological signs: the site's own gold drawing (sign_picture()), in place of a cover.
    $signPic = sign_picture($p);
    if ($signPic) {
        $cover = '<div class="pg-cover cutout rune-pic"><img src="' . e($signPic) . '" alt="' . e($p['title']) . '" width="512" height="512"></div>';
    }
    if (!$photo && !$signPic && $p['cover']) {
        // pictures kept with the site (the mineral photos from Wikimedia Commons) are specimen shots: shown whole
        $whole = str_starts_with((string) $p['cover'], 'asset:') || is_cutout($p['cover']) || is_portrait($p['cover']);
        $img = '<img src="' . e(media_url($p['cover'])) . '" alt="' . ($whole ? e($p['title']) : '') . '">';
        $cover = '<div class="pg-cover' . ($whole ? ' cutout' : '') . '">'
            . ($whole ? '<a class="zoom" href="' . e(media_url($p['cover'])) . '" data-zoom>' . $img . '</a>' : $img) . '</div>';
    }

    $gallery = '';
    if ($images) {
        // Each picture opens full size when clicked (assets/wiki.js); without JavaScript the link opens the picture itself.
        $gallery = '<div class="gallery">' . implode('', array_map(fn($f) => '<figure><a class="zoom" href="' . e(media_url($f)) . '" data-zoom>'
            . '<img src="' . e(media_url($f)) . '" alt="' . e($p['title']) . '" loading="lazy"></a></figure>', $images)) . '</div>';
    }
    // A spread's layout picture goes right above its Card Details, where the positions are explained.
    $details = '<div class="prop-row"><div class="prop-name">' . prop_glyph('Card Details') . 'Card Details</div>';
    if ($gallery && str_contains($panel, $details)) {
        $panel = str_replace($details, str_replace('class="gallery"', 'class="gallery in-panel"', $gallery) . $details, $panel);
        $gallery = '';
    }
    $prose = resolve_links((string) $p['body_html']);
    // Lines that only repeat the sidebar ("Day: Friday" when Timing shows Friday) are left out of the text.
    if ($panel !== '') $prose = drop_sidebar_repeats($prose, $panel);
    // The blog's hierarchy: burgundy highlights, ink labels, warnings boxed.
    $bodyHtml = prose_hierarchy($textParts . $prose);
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
    [$days, $events] = calendar_days();
    echo_calendar_page(calendar_html($days, fn(array $p) => page_url($p), fn(array $p) => array_map(
        fn($t) => [festival_icon($t), $t['title']], $events[$p['id']] ?? [])));
}

/**
 * The Timing Grimoire's day pages ($days[month][day] = page) and each day's festivals ($events[day page id] = pages):
 * its "Related Timing" connections (Samhain on October 31, Yule on December 21…).
 */
function calendar_days(): array
{
    $st = db()->prepare("SELECT id, slug, grimoire, kind, title FROM pages WHERE grimoire = 'timing' AND kind = 'entry'");
    $st->execute();
    $days = [];
    $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
    foreach ($st->fetchAll() as $p) {
        if (preg_match('/^(' . implode('|', $months) . ')-(\d{1,2})$/', $p['slug'], $m)) $days[array_search($m[1], $months, true) + 1][(int) $m[2]] = $p;
    }
    $ids = array_merge(...array_map(fn($m) => array_column($m, 'id'), $days ?: [[]]));
    $events = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $q = db()->prepare("SELECT from_id, to_id FROM relations WHERE prop = 'Related Timing' AND from_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY sort');
        $q->execute($chunk);
        $rows = $q->fetchAll();
        $idx = page_index(array_column($rows, 'to_id'));
        foreach ($rows as $r) if (isset($idx[$r['to_id']])) $events[$r['from_id']][] = $idx[$r['to_id']];
    }
    return [$days, $events];
}

/** A festival's emoji, if its icon is one (not a picture). */
function festival_icon(array $p): string
{
    return $p['icon'] && !preg_match('/^(file|asset):/', (string) $p['icon']) ? (string) $p['icon'] : '';
}

/**
 * /calendar.ics: the festivals and moon phases as a calendar feed (lib/ical.php), open to everyone so calendar apps can
 * fetch it without signing in. Like Today for visitors, it gives names only: each event links to its page, which
 * visitors find locked.
 */
function view_calendar_feed(): void
{
    [$days, $events] = calendar_days();
    $festivals = [];
    foreach ($days as $m => $list) foreach ($list as $d => $p) foreach ($events[$p['id']] ?? [] as $t) {
        $festivals[] = ['month' => $m, 'day' => $d, 'title' => $t['title'], 'icon' => festival_icon($t), 'url' => page_url($t)];
    }
    $moons = array_map(fn($slug) => ($p = find_page('astrology', $slug)) ? page_url($p) : '',
        ['new-moon', 'first-quarter-moon', 'full-moon', 'third-quarter-moon']);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="magick-manuscript.ics"');
    header('Cache-Control: public, max-age=3600');
    echo calendar_ics($festivals, $moons, time());
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
        . '<p class="lede small">Every day of the year has its own page, with its correspondences, the spirits and festivals tied to it, and the rituals for it.</p></header>'
        . '<nav class="cal-nav" hidden><button class="cal-btn" type="button" data-cal="-1" aria-label="Previous month">‹</button>'
        . '<p class="cal-title" aria-live="polite"></p><button class="cal-btn" type="button" data-cal="1" aria-label="Next month">›</button>'
        . '<button class="link as-button cal-today" type="button" data-cal="0">Today</button></nav>'
        . '<div class="cal-months">' . $html . '</div></section>';
}

function echo_calendar_page(string $html): void
{
    layout('Calendar', '<article class="pg pg-calendar">' . $html . '</article>', ['private' => true, 'protect' => true]);
}

/**
 * /library: The Library, the books the Manuscript draws on (data_dir/library.json, written by the import), A to Z,
 * each with its author and the grimoires it informs. Buttons above the list show only the books on one topic (assets/wiki.js).
 */
function view_the_library(): void
{
    if (!has_access()) { view_gate(['locked' => true]); return; }
    record_view('route:/manuscript/library');
    $books = json_decode((string) @file_get_contents(config('data_dir') . '/library.json'), true) ?: [];
    layout('The Library', '<article class="pg pg-library">' . the_library_html($books) . '</article>', ['private' => true, 'protect' => true]);
}

function the_library_html(array $books): string
{
    $topics = [];
    foreach ($books as $b) foreach ($b['topics'] as $t) $topics[$t] = ($topics[$t] ?? 0) + 1;
    ksort($topics, SORT_NATURAL | SORT_FLAG_CASE);
    $filters = '';
    if ($topics) {
        $filters = '<nav class="lib-filter" aria-label="Show books by topic" hidden><button type="button" class="lib-t on" data-lib="" aria-pressed="true">All</button>';
        foreach ($topics as $t => $n) $filters .= '<button type="button" class="lib-t" data-lib="' . e($t) . '" aria-pressed="false">' . e($t) . ' <small>' . $n . '</small></button>';
        $filters .= '</nav>';
    }
    $list = '';
    foreach ($books as $b) {
        $tags = $b['topics'] ? '<span class="lib-topics">' . implode('', array_map(fn($t) => '<span>' . e($t) . '</span>', $b['topics'])) . '</span>' : '';
        $list .= '<li data-topics="' . e(implode('|', $b['topics'])) . '"><span class="lib-title">' . e($b['title']) . '</span>'
            . ($b['author'] !== '' ? '<span class="lib-author">' . e($b['author']) . '</span>' : '') . $tags . '</li>';
    }
    return '<header class="pg-head"><p class="eyebrow">Sources</p><h1>The Library</h1>'
        . '<p class="lede small">The books behind the Manuscript: ' . count($books) . ' titles on witchcraft, folklore, herbs, crystals, divination and the spirits, gathered as the grimoires grew.</p></header>'
        . $filters . '<ol class="library">' . $list . '</ol>'
        . '<p class="lib-foot"><a href="' . e(url('picture-credits')) . '">Picture credits</a> for the photos used across the Manuscript.</p>';
}

/** /picture-credits: who took the photos the site uses from Wikimedia Commons, and their licences (assets/minerals/credits.json). */
function view_picture_credits(): void
{
    $credits = [];
    foreach (['minerals', 'plants'] as $kind) {
        $credits = array_merge($credits, json_decode((string) @file_get_contents(MM_ROOT . "/assets/$kind/credits.json"), true) ?: []);
    }
    layout('Picture Credits', '<article class="pg pg-library">' . picture_credits_html($credits, fn($path) => url($path)) . '</article>');
}

function picture_credits_html(array $credits, callable $href): string
{
    $rows = '';
    foreach ($credits as $c) {
        $lic = $c['license_url'] !== '' ? '<a href="' . e($c['license_url']) . '" rel="noopener license">' . e($c['license']) . '</a>' : e($c['license']);
        $rows .= '<li><a class="lib-title" href="' . e($href($c['page'])) . '">' . e($c['title']) . '</a>'
            . '<span class="lib-author">' . e($c['artist']) . '</span>'
            . '<span class="credit-meta">' . $lic . ' · <a href="' . e($c['source']) . '" rel="noopener">Wikimedia Commons</a></span></li>';
    }
    return '<header class="pg-head"><p class="eyebrow">Sources</p><h1>Picture Credits</h1>'
        . '<p class="lede small">Many of the mineral and plant photos in the Manuscript come from Wikimedia Commons, shared under free licences by the photographers below. Thank you to each of them.</p></header>'
        . '<ol class="library">' . $rows . '</ol>';
}

/** True for a picture taller than it is wide, read from the file itself. */
function is_portrait(string $file): bool
{
    $size = @getimagesize(config('data_dir') . '/media/' . basename($file));
    return $size !== false && $size[1] > $size[0] * 1.05;
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

/**
 * The site's own drawing for a rune, an astrological sign or a symbol, if it has one: assets/runes/<slug>.png in the
 * Rune Grimoire, assets/signs/ in Astrology, assets/symbols/ in Symbols (colours, numbers). Shown at the top of the
 * page and on its card in lists.
 */
function sign_picture(array $p): ?string
{
    $dir = ['rune' => 'runes', 'astrology' => 'signs', 'symbol' => 'symbols'][$p['grimoire'] ?? ''] ?? null;
    if (!$dir || !is_file(MM_ROOT . "/assets/$dir/" . $p['slug'] . '.png')) return null;
    return url("assets/$dir/" . $p['slug'] . '.png');
}

/**
 * The small icon beside an entry's name (in lists and its page title): its own picture icon if it has one, else the
 * site's gold drawing (sign_picture()), else its emoji.
 */
function entry_icon(array $e, string $class = 'pg-icon'): string
{
    $icon = (string) ($e['icon'] ?? '');
    if (!preg_match('/^(asset|file):./', $icon) && ($pic = sign_picture($e))) {
        return '<img class="' . $class . '" src="' . e($pic) . '" alt="" width="24" height="24" loading="lazy">';
    }
    return icon_html($icon, $class);
}
