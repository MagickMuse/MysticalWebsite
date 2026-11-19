<?php
declare(strict_types=1);

function site_url(string $path = ''): string
{
    $site = rtrim((string) config('site_url', dirname(config('base_url'))), '/');
    return $site . '/' . ltrim($path, '/');
}

/** "What's inside" for a grimoire card: its "Within These Pages:" line from Notion, or its first sentence. */
function grimoire_summary(array $g): string
{
    $plain = (string) $g['plain'];
    if (preg_match('/Within These Pages:\s*(.+?)\s*(?:How to Walk It:|Why it.s called|Guide Pages|$)/su', $plain, $m)) return trim($m[1]);
    $plain = preg_replace('/^The ' . preg_quote($g['title'], '/') . '\s*/u', '', $plain);
    return preg_match('/^(.{20,200}?[.!?])\s/u', $plain, $m) ? $m[1] : mb_substr($plain, 0, 160);
}

/** The grimoire's photo from grimoire-photos.php at a given size, or null. */
function grimoire_photo(string $slug, int $w, int $h): ?string
{
    static $photos = null;
    $photos ??= is_file(MM_ROOT . '/grimoire-photos.php') ? require MM_ROOT . '/grimoire-photos.php' : [];
    if (empty($photos[$slug])) return null;
    $u = $photos[$slug];
    return $u . (str_contains($u, '?') ? '&' : '?') . "w=$w&h=$h";
}

/** A page named in the config: a Notion page id, "grimoire/page-slug", or a grimoire's slug. */
function page_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') return null;
    if (preg_match('/^[0-9a-f-]{32,36}$/i', $ref)) return page_by_id($ref);
    return str_contains($ref, '/') ? find_page(...explode('/', $ref, 2)) : grimoire_root($ref);
}

/** The one page visitors can read in full as a taste of the Manuscript (config "sneak_peek"). */
function sneak_peek_page(): ?array
{
    static $p = false;
    if ($p === false) $p = page_by_ref((string) config('sneak_peek', 'correspondence/protection'));
    return $p;
}

/** Every page anyone can read: config "public_pages" plus the sneak peek. */
function open_pages(): array
{
    static $pages = null;
    if ($pages === null) {
        $pages = array_values(array_filter(array_map('page_by_ref', array_map('strval', (array) config('public_pages', [])))));
        if ($sp = sneak_peek_page()) $pages[] = $sp;
    }
    return $pages;
}

/** How many grimoires the Manuscript holds, as a word ("Fifteen"), not counting Begin Here. */
function grimoire_count_word(): string
{
    static $word = null;
    if ($word !== null) return $word;
    $n = (int) db()->query("SELECT COUNT(*) FROM pages WHERE kind = 'grimoire' AND grimoire != 'begin-here'")->fetchColumn();
    $words = [1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', 'Twenty'];
    return $word = $words[$n] ?? (string) $n;
}

/** For visitors: the pop-up shown when they pick anything inside the Manuscript. */
function join_popup(bool $onPeek): string
{
    $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
    $next = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $peek = !$onPeek && sneak_peek_page() ? '<a class="btn ghost" href="' . e(url('sneak-peek')) . '">Take a sneak peek</a>' : '';
    return '<dialog class="join-pop" id="join-pop" aria-labelledby="join-pop-h">'
        . '<button class="join-x" type="button" data-close aria-label="Close">&times;</button>'
        . '<p class="eyebrow labelled">Members only</p>'
        . '<h2 id="join-pop-h">This lives inside <em>the Manuscript</em></h2>'
        . '<p>' . grimoire_count_word() . ' cross-linked grimoires and ' . $total . ' pages of researched magick, open to members. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three.</p>'
        . '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a member</a>' . $peek . '</div>'
        . (current_email() ? '' : '<p class="small">Already a member? <a href="' . e(url('login') . '?next=' . rawurlencode($next)) . '">Sign in</a></p>')
        . '</dialog>';
}

function find_page(string $grimoire, string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM pages WHERE grimoire = ? AND slug = ?');
    $st->execute([$grimoire, $slug]);
    return $st->fetch() ?: null;
}

function page_by_id(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([normalize_id($id)]);
    return $st->fetch() ?: null;
}

function grimoire_root(string $slug): ?array
{
    $st = db()->prepare("SELECT * FROM pages WHERE grimoire = ? AND kind = 'grimoire'");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** A page's icon as shown: none for a picture icon in a grimoire without pictures (the Entity Grimoire). */
function shown_icon(array $p): ?string
{
    $icon = $p['icon'] ?? null;
    // Entities show no icon at all: no picture, no emoji.
    if (in_array($p['grimoire'] ?? '', (array) config('no_icons', ['entity']), true) && ($p['kind'] ?? 'entry') === 'entry') return null;
    return $icon && str_starts_with((string) $icon, 'file:') && in_array($p['grimoire'] ?? '', (array) config('no_pictures', ['entity']), true) ? null : $icon;
}

/** Light-weight lookup of many pages at once: id => [title, url, icon]. */
function page_index(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, parent_id FROM pages WHERE id IN ($place)");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $p) $out[$p['id']] = ['icon' => shown_icon($p)] + $p + ['url' => page_url($p)];
    }
    // Archived duplicates in Notion stand in for their live entry.
    $gone = array_values(array_diff($ids, array_keys($out)));
    foreach (array_chunk($gone, 500) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT a.id AS alias, p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.parent_id FROM aliases a JOIN pages p ON p.id = a.to_id WHERE a.id IN ($place)");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $p) { $a = $p['alias']; unset($p['alias']); $out[$a] = ['icon' => shown_icon($p)] + $p + ['url' => page_url($p)]; }
    }
    return $out;
}

function icon_html(?string $icon, string $class = 'pg-icon'): string
{
    if (!$icon || $icon === 'file:') return ''; // "file:" alone: an icon whose picture never downloaded
    if (str_starts_with($icon, 'asset:')) {
        return '<img class="' . $class . '" src="' . e(url('assets/' . substr($icon, 6))) . '" alt="" width="24" height="24" loading="lazy">';
    }
    if (str_starts_with($icon, 'file:')) {
        return '<img class="' . $class . '" src="' . e(media_url(substr($icon, 5))) . '" alt="" width="24" height="24" loading="lazy">';
    }
    return '<span class="' . $class . '" aria-hidden="true">' . e($icon) . '</span>';
}

/** Takes out bookmarks to Notion-only widgets, and any columns or column rows left empty by that. */
function strip_skipped_links(string $html): string
{
    $html = preg_replace_callback('~<p class="bookmark"><a href="([^"]*)"[^>]*>.*?</a></p>~s',
        fn($m) => preg_match(skip_links_pattern(), html_entity_decode($m[1])) ? '' : $m[0], $html);
    // Any Discord invite in the Manuscript uses the website's own invite link, so new members can be traced to the website.
    $discord = (string) config('discord_url', '');
    if ($discord !== '') {
        $html = preg_replace_callback('~(href=")https?://(?:www\.)?(?:discord\.gg|discord(?:app)?\.com/invite)/[A-Za-z0-9-]+/?(")~',
            fn($m) => $m[1] . e($discord) . $m[2], $html);
    }
    $html = preg_replace('~<div class="column">\s*</div>~', '', $html);
    // Columns that hold nothing but page links (e.g. a "Guide Pages" list) become one list that wraps,
    // so there is no empty strip between half-width columns or hole under a shorter column.
    $html = preg_replace_callback('~<div class="columns">((?:<div class="column">(?:<p class="page-link">.*?</p>)+</div>)+)</div>~s', function ($m) {
        preg_match_all('~<p class="page-link">.*?</p>~s', $m[1], $links);
        return '<div class="link-flow">' . implode('', $links[0]) . '</div>';
    }, $html);
    $html = preg_replace('~<div class="columns">\s*</div>~', '', $html);
    return group_page_links($html);
}

/** Two or more guide-page links in a row become one list (shown in two columns), unless they already are one. */
function group_page_links(string $html): string
{
    return preg_replace_callback('~(<div class="link-flow">)?((?:<p class="page-link">.*?</p>\s*){2,})~s',
        fn($m) => $m[1] !== '' ? $m[0] : '<div class="link-flow">' . $m[2] . '</div>', $html);
}

/** Turns stored data-mm-id anchors into real links; links to pages not yet moved over become plain text. */
function resolve_links(string $html): string
{
    $html = strip_skipped_links($html);
    preg_match_all('/data-mm-id="([0-9a-f]{32})"/', $html, $m);
    $idx = page_index($m[1]);
    $html = preg_replace_callback('/<a data-mm-id="([0-9a-f]{32})">(.*?)<\/a>/s', function ($mm) use ($idx) {
        $p = $idx[$mm[1]] ?? null;
        if (!$p && $mm[2] === '…') return '';
        // Notion gives "Untitled" for a mention it can't read the title of: the site knows the page's real title.
        $text = ($mm[2] === '…' || ($p && trim(strip_tags($mm[2])) === 'Untitled')) ? e($p['title']) : $mm[2];
        // A link to a page that isn't on the site (archived, private, or a how-to-use-Notion guide) reads as plain words.
        // A mention of a page deleted in Notion comes through as "Untitled": it shows as nothing.
        if (!$p) return ($text === '' || trim(strip_tags($text)) === 'Untitled') ? '' : '<span class="unmoved">' . $text . '</span>';
        // Plants, oils, minerals, runes and astrology show their little picture (or symbol) beside their name.
        $pic = in_array($p['grimoire'], (array) config('mention_icon_grimoires', ['plant', 'oil', 'mineral', 'rune', 'astrology']), true)
            && $p['kind'] === 'entry' ? icon_html($p['icon'], 'inline-icon') : '';
        return '<a href="' . e($p['url']) . '">' . $pic . $text . '</a>';
    }, $html);
    $html = str_replace('<p class="page-link"></p>', '', $html);
    $html = preg_replace_callback('/<div data-mm-collection="([0-9a-f]{32})"><\/div>/', fn($mm) => collection_block($mm[1], 60), $html);
    // Dividers that end up next to each other (e.g. around a skipped Counter database) become one,
    // and a page never starts or ends with one.
    $rule = '<div class="star-rule" role="separator"><i class="spark"></i></div>';
    $html = preg_replace('~(?:' . preg_quote($rule, '~') . '\s*){2,}~', $rule, $html);
    $html = preg_replace('~^\s*' . preg_quote($rule, '~') . '|' . preg_quote($rule, '~') . '\s*$~', '', $html);
    return $html;
}

function collection_block(string $id, int $limit): string
{
    $c = page_by_id($id);
    if (!$c) return '';
    // The full list with its "Sort & filter" lives right on the grimoire page.
    [$g, $several] = grimoire_lists($c['grimoire']);
    $v = collection_view($c, page_url($g), $several ? $c['slug'] : '');
    return collection_html($v, $several);
}

/** The grimoire's landing page, and whether it holds more than one list. */
function grimoire_lists(string $grimoire): array
{
    $st = db()->prepare("SELECT COUNT(*) FROM pages WHERE grimoire = ? AND kind = 'collection'");
    $st->execute([$grimoire]);
    return [grimoire_root($grimoire), (int) $st->fetchColumn() > 1];
}

/** Relations from this page, grouped by property, plus pages that point back here. */
function connections(array $page): array
{
    $st = db()->prepare('SELECT prop, to_id FROM relations WHERE from_id = ? ORDER BY prop, sort');
    $st->execute([$page['id']]);
    $rows = $st->fetchAll();

    $st = db()->prepare('SELECT r.from_id, p.parent_id FROM relations r JOIN pages p ON p.id = r.from_id
        WHERE r.to_id = ? OR r.to_id IN (SELECT id FROM aliases WHERE to_id = ?)');
    $st->execute([$page['id'], $page['id']]);
    $back = $st->fetchAll();

    $idx = page_index(array_merge(array_column($rows, 'to_id'), array_column($back, 'from_id'), array_column($back, 'parent_id')));

    $out = [];
    $shown = [];
    foreach ($rows as $r) {
        $t = $idx[$r['to_id']] ?? null;
        if (!$t || $t['id'] === $page['id']) continue;
        $out[trim($r['prop'])][$t['id']] = $t;
        $shown[$t['id']] = true;
    }
    // Backlinks that the outgoing relations did not already cover, grouped by their database.
    // A group with the same name as a relation (e.g. "Correspondences") joins that relation's row.
    $byLower = [];
    foreach (array_keys($out) as $k) $byLower[mb_strtolower($k)] = $k;
    foreach ($back as $b) {
        if (isset($shown[$b['from_id']]) || !isset($idx[$b['from_id']]) || $b['from_id'] === $page['id']) continue;
        $group = $idx[$b['parent_id']]['title'] ?? 'Other pages';
        $group = $byLower[mb_strtolower($group)] ?? $group;
        $shown[$b['from_id']] = true;
        $out[$group][$b['from_id']] = $idx[$b['from_id']];
    }
    foreach ($out as &$list) uasort($list, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function breadcrumbs(array $page): string
{
    $trail = [];
    $p = $page;
    $guard = 0;
    while ($p && $p['parent_id'] && $guard++ < 8) {
        $p = page_by_id($p['parent_id']);
        if ($p) array_unshift($trail, $p);
    }
    $html = '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' . e(url()) . '">Manuscript</a></li>';
    // A grimoire with a single database: its name ("Animals" after "Animal Grimoire") only repeats the grimoire, so leave it out.
    $st = db()->prepare("SELECT COUNT(*) FROM pages WHERE grimoire = ? AND kind = 'collection'");
    $st->execute([$page['grimoire']]);
    $several = (int) $st->fetchColumn() > 1;
    foreach ($trail as $t) {
        if ($t['kind'] === 'collection' && !$several) continue;
        $html .= '<li><a href="' . e(page_url($t)) . '">' . e($t['title']) . '</a></li>';
    }
    return $html . '</ol></nav>';
}

// ------------------------------------------------------------------ layout

function layout(string $title, string $body, array $opt = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    // The checkout page alone may load Stripe's payment form (Stripe's published CSP list for Stripe.js and embedded Checkout).
    $stripe = !empty($opt['stripe']);
    header("Content-Security-Policy: default-src 'self'; script-src 'self'" . ($stripe ? ' https://js.stripe.com https://*.js.stripe.com' : '')
        . "; style-src 'self'; font-src 'self'; img-src 'self' data: https://images.pexels.com https://images.unsplash.com" . ($stripe ? ' https://*.stripe.com' : '')
        . "; connect-src 'self'" . ($stripe ? ' https://api.stripe.com https://checkout.stripe.com https://merchant-ui-api.stripe.com' : '')
        . ($stripe ? '; frame-src https://js.stripe.com https://*.js.stripe.com https://hooks.stripe.com https://checkout.stripe.com' : '')
        . "; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Anything shown to a signed-in person must never be stored by a cache and shown to someone else.
    if (!empty($opt['private']) || current_email()) header('Cache-Control: private, no-store');
    else header('Cache-Control: public, max-age=300');
    header('Vary: Cookie');

    $email = current_email();
    $acct = $email
        ? (has_access() ? '<a href="' . e(url('saved')) . '"><span aria-hidden="true">★</span> Saved</a><span class="dot" aria-hidden="true">·</span>' : '') . '<a href="' . e(url('account')) . '">Account</a>'
        : '<a href="' . e(url('login')) . '">Sign in</a>';
    $q = e($_GET['q'] ?? '');
    $robots = !empty($opt['index']) ? 'index, follow' : 'noindex, nofollow';
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title) ?> | The Magick Manuscript</title>
  <meta name="robots" content="<?= $robots ?>">
  <?php if (!empty($opt['description'])): ?><meta name="description" content="<?= e($opt['description']) ?>"><?php endif ?>
  <link rel="icon" href="<?= e(site_url('favicon.ico')) ?>" sizes="48x48">
  <link rel="apple-touch-icon" href="<?= e(site_url('apple-touch-icon.png')) ?>">
  <?php // The fonts load "optional": fetched first thing, they're ready in time; otherwise a reload could fall back to the browser's own, bigger serif. ?>
  <?php foreach (['jost-300', 'cormorant-400', 'cormorant-400-italic', 'jost-400'] as $font): ?><link rel="preload" href="<?= e(site_url('fonts/' . $font . '.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <?php endforeach ?>  <script src="<?= e(site_url('prefs.js')) ?>"></script>
  <link rel="stylesheet" href="<?= e(site_url('styles.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/wiki.css')) ?>?v=68">
  <meta name="theme-color" content="#f6efe8" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#1c1415" media="(prefers-color-scheme: dark)">
  <script src="<?= e(url('assets/wiki.js')) ?>?v=52" defer></script>
<?php if ($stripe): ?>
  <script src="https://js.stripe.com/dahlia/stripe.js" defer></script>
  <script src="<?= e(url('assets/checkout.js')) ?>?v=2" defer></script>
<?php endif; ?>
</head>
<?php $visitor = !has_access(); $openUrls = $visitor ? array_merge([url('sneak-peek')], array_map('page_url', open_pages())) : []; ?>
<body class="mm<?= !empty($opt['protect']) && !is_admin() ? ' no-copy' : '' ?>"<?php if ($visitor): ?> data-visitor data-base="<?= e((string) parse_url(url(), PHP_URL_PATH)) ?>" data-open="<?= e(json_encode(array_map(fn($u) => rtrim((string) parse_url($u, PHP_URL_PATH), '/'), $openUrls))) ?>"<?php endif ?>>
<a class="skip" href="#main">Skip to main content</a>
<header class="site">
  <div class="wrap nav">
    <a class="logo" href="<?= e(site_url('')) ?>">Magick Muse</a>
    <button class="menu" type="button" aria-expanded="false" aria-controls="site-menu">Menu</button>
    <nav id="site-menu" aria-label="Main">
      <ul class="left">
        <li><a href="<?= e(url()) ?>" aria-current="page"><span>Manuscript</span><small>the grimoire</small></a></li>
        <li><a href="<?= e(site_url('meetup')) ?>"><span>Muses</span><small>the coven</small></a></li>
      </ul>
      <ul class="right">
        <li><a href="<?= e(site_url('mastery')) ?>"><span>Mastery</span><small>guides &amp; courses</small></a></li>
        <li><a href="<?= e(site_url('shop/')) ?>"><span>Market</span><small>the shop</small></a></li>
        <li><a href="<?= e(site_url('story')) ?>"><span>Mythos</span><small>our story</small></a></li>
      </ul>
    </nav>
  </div>
  <div class="wrap mm-bar">
    <?php if (has_access()): ?>
    <form class="mm-search" action="<?= e(url('search')) ?>" method="get" role="search">
      <label class="sr-only" for="mm-q">Search the Manuscript</label>
      <input id="mm-q" type="search" name="q" value="<?= $q ?>" placeholder="Search the Manuscript: protection, rose quartz, Hekate…">
      <button class="btn" type="submit">Search</button>
    </form>
    <?php endif ?>
    <p class="mm-acct"><?= $acct ?></p>
  </div>
</header>
<main id="main" class="wrap mm-main">
<?= $body ?>
</main>
<?= $visitor ? join_popup(!empty($opt['peek'])) : '' ?>
<footer class="site">
  <div class="wrap foot">
    <div class="star-rule" aria-hidden="true"><i class="spark big"></i></div>
    <a class="logo" href="<?= e(site_url('')) ?>">Magick Muse</a>
    <p class="legal"><a href="<?= e(site_url('settings')) ?>">Accessibility &amp; display</a><span aria-hidden="true">✦</span><a href="<?= e(site_url('privacy-policy')) ?>">Privacy Policy</a></p>
    <small>&copy; <?= date('Y') ?> The Magick Muse. All rights reserved. The Manuscript is for members only: please do not copy or share its pages.</small>
  </div>
</footer>
</body>
</html>
<?php
}

function not_found(): void
{
    http_response_code(404);
    layout('Not found', '<section class="mm-empty"><h1>This page is <em>not in the Manuscript</em></h1><p class="lede">It may not have been moved over from Notion yet, or the link has changed.</p><p><a class="link" href="' . e(url()) . '">Back to the Manuscript</a></p></section>');
}

function redirect(string $to, int $code = 303): never
{
    header('Location: ' . $to, true, $code);
    exit;
}
