<?php
declare(strict_types=1);

function site_url(string $path = ''): string
{
    $site = rtrim((string) config('site_url', dirname(config('base_url'))), '/');
    return $site . '/' . ltrim($path, '/');
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

/** Light-weight lookup of many pages at once: id => [title, url, icon]. */
function page_index(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, parent_id FROM pages WHERE id IN ($place)");
        $st->execute($chunk);
        foreach ($st->fetchAll() as $p) $out[$p['id']] = $p + ['url' => page_url($p)];
    }
    return $out;
}

function icon_html(?string $icon, string $class = 'pg-icon'): string
{
    if (!$icon) return '';
    if (str_starts_with($icon, 'file:')) {
        return '<img class="' . $class . '" src="' . e(media_url(substr($icon, 5))) . '" alt="" width="24" height="24" loading="lazy">';
    }
    return '<span class="' . $class . '" aria-hidden="true">' . e($icon) . '</span>';
}

/** Turns stored data-mm-id anchors into real links; links to pages not yet moved over become plain text. */
function resolve_links(string $html): string
{
    preg_match_all('/data-mm-id="([0-9a-f]{32})"/', $html, $m);
    $idx = page_index($m[1]);
    $html = preg_replace_callback('/<a data-mm-id="([0-9a-f]{32})">(.*?)<\/a>/s', function ($mm) use ($idx) {
        $p = $idx[$mm[1]] ?? null;
        $text = $mm[2] === '…' ? e($p['title'] ?? 'Untitled page') : $mm[2];
        if (!$p) return '<span class="unmoved" title="Not moved over from Notion yet">' . $text . '</span>';
        return '<a href="' . e($p['url']) . '">' . $text . '</a>';
    }, $html);
    return preg_replace_callback('/<div data-mm-collection="([0-9a-f]{32})"><\/div>/', fn($mm) => collection_block($mm[1], 60), $html);
}

function entries_of(string $collectionId, ?int $limit = null): array
{
    $st = db()->prepare("SELECT id, slug, grimoire, kind, title, icon, aka FROM pages WHERE parent_id = ? AND kind = 'entry'");
    $st->execute([$collectionId]);
    $rows = $st->fetchAll();
    usort($rows, fn($a, $b) => strnatcasecmp($a['title'], $b['title'])); // "Day 3" before "Day 10"
    return $limit ? array_slice($rows, 0, $limit) : $rows;
}

function count_entries(string $collectionId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM pages WHERE parent_id = ? AND kind = 'entry'");
    $st->execute([$collectionId]);
    return (int) $st->fetchColumn();
}

function entry_list(array $entries, bool $filterable): string
{
    $html = '';
    if ($filterable) {
        $html .= '<label class="filter"><span class="sr-only">Filter entries</span><input type="search" placeholder="Filter by name…" data-filter></label>';
    }
    $html .= '<ul class="entry-grid" data-filterable>';
    foreach ($entries as $en) {
        $html .= '<li data-name="' . e(mb_strtolower($en['title'] . ' ' . ($en['aka'] ?? ''))) . '"><a href="' . e(page_url($en)) . '">'
            . icon_html($en['icon']) . '<span>' . e($en['title']) . '</span></a></li>';
    }
    return $html . '</ul>';
}

function collection_block(string $id, int $limit): string
{
    $c = page_by_id($id);
    if (!$c) return '';
    $total = count_entries($id);
    $html = '<section class="collection"><div class="collection-head"><h2>' . icon_html($c['icon']) . e($c['title']) . '</h2>'
        . '<p class="count">' . number_format($total) . ' ' . ($total === 1 ? 'entry' : 'entries') . '</p></div>';
    $html .= entry_list(entries_of($id, $limit), false);
    if ($total > $limit) {
        $html .= '<p><a class="link" href="' . e(page_url($c)) . '">Browse all ' . number_format($total) . '</a></p>';
    }
    return $html . '</section>';
}

/** Relations from this page, grouped by property, plus pages that point back here. */
function connections(array $page): array
{
    $st = db()->prepare('SELECT prop, to_id FROM relations WHERE from_id = ? ORDER BY prop, sort');
    $st->execute([$page['id']]);
    $rows = $st->fetchAll();

    $st = db()->prepare('SELECT r.from_id, p.parent_id FROM relations r JOIN pages p ON p.id = r.from_id WHERE r.to_id = ?');
    $st->execute([$page['id']]);
    $back = $st->fetchAll();

    $idx = page_index(array_merge(array_column($rows, 'to_id'), array_column($back, 'from_id'), array_column($back, 'parent_id')));

    $out = [];
    $shown = [];
    foreach ($rows as $r) {
        if (!isset($idx[$r['to_id']]) || $r['to_id'] === $page['id']) continue;
        $out[trim($r['prop'])][$r['to_id']] = $idx[$r['to_id']];
        $shown[$r['to_id']] = true;
    }
    // Backlinks that the outgoing relations did not already cover, grouped by their database.
    foreach ($back as $b) {
        if (isset($shown[$b['from_id']]) || !isset($idx[$b['from_id']]) || $b['from_id'] === $page['id']) continue;
        $group = $idx[$b['parent_id']]['title'] ?? 'Other pages';
        $out[$group][$b['from_id']] = $idx[$b['from_id']];
    }
    foreach ($out as &$list) uasort($list, fn($a, $b) => strnatcasecmp($a['title'], $b['title']));
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function connections_html(array $groups): string
{
    if (!$groups) return '';
    $html = '<section class="connections" aria-labelledby="conn-h"><p class="eyebrow labelled" id="conn-h">Connections</p><div class="conn-groups">';
    foreach ($groups as $name => $items) {
        $n = count($items);
        $chips = '';
        foreach ($items as $it) $chips .= '<li><a class="chip" href="' . e($it['url']) . '">' . icon_html($it['icon'], 'chip-icon') . e($it['title']) . '</a></li>';
        $open = $n <= 24 ? ' open' : '';
        $html .= "<details class=\"conn\"$open><summary><span>" . e($name) . '</span><small>' . $n . '</small></summary><ul class="chips">' . $chips . '</ul></details>';
    }
    return $html . '</div></section>';
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
    foreach ($trail as $t) $html .= '<li><a href="' . e(page_url($t)) . '">' . e($t['title']) . '</a></li>';
    return $html . '</ol></nav>';
}

// ------------------------------------------------------------------ layout

function layout(string $title, string $body, array $opt = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data: https://images.pexels.com https://images.unsplash.com; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Anything shown to a signed-in person must never be stored by a cache and shown to someone else.
    if (!empty($opt['private']) || current_email()) header('Cache-Control: private, no-store');
    else header('Cache-Control: public, max-age=300');
    header('Vary: Cookie');

    $email = current_email();
    $acct = $email
        ? '<a href="' . e(url('account')) . '">Account</a>'
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
  <link rel="stylesheet" href="<?= e(site_url('styles.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/wiki.css')) ?>?v=5">
  <meta name="theme-color" content="#f6efe8" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#1c1415" media="(prefers-color-scheme: dark)">
  <script src="<?= e(url('assets/wiki.js')) ?>?v=5" defer></script>
</head>
<body class="mm">
<a class="skip" href="#main">Skip to main content</a>
<header class="site">
  <div class="wrap nav">
    <a class="logo" href="<?= e(site_url('index.html')) ?>">Magick Muse</a>
    <button class="menu" type="button" aria-expanded="false" aria-controls="site-menu">Menu</button>
    <nav id="site-menu" aria-label="Main">
      <ul class="left">
        <li><a href="<?= e(url()) ?>" aria-current="page"><span>Manuscript</span><small>the grimoire</small></a></li>
        <li><a href="<?= e(site_url('meetup.html')) ?>"><span>Muses</span><small>the coven</small></a></li>
      </ul>
      <ul class="right">
        <li><a href="<?= e(site_url('mastery.html')) ?>"><span>Mastery</span><small>guides &amp; courses</small></a></li>
        <li><a href="<?= e(site_url('story.html')) ?>"><span>Mythos</span><small>our story</small></a></li>
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
<footer class="site">
  <div class="wrap foot">
    <div class="star-rule" aria-hidden="true"><i class="spark big"></i></div>
    <a class="logo" href="<?= e(site_url('index.html')) ?>">Magick Muse</a>
    <p class="legal"><a href="<?= e(site_url('privacy-policy.html')) ?>">Privacy Policy</a></p>
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
