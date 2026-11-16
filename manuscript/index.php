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
        case 'saved':         $method === 'POST' ? toggle_saved() : view_saved(); return;
        case 'dashboard':     route_dashboard(array_slice($parts, 1), $method); return;
        case 'shop':          route_shop(array_slice($parts, 1), $method); return;
        case 'suggest':       search_suggest(trim((string) ($_GET['q'] ?? ''))); return;
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
    if (!has_access()) { view_gate(); return; }
    $grimoires = db()->query("SELECT * FROM pages WHERE kind = 'grimoire'")->fetchAll();
    $order = array_keys(config('grimoires', []));
    $rank = array_flip($order);
    usort($grimoires, fn($a, $b) => ($rank[$a['grimoire']] ?? 99) <=> ($rank[$b['grimoire']] ?? 99));
    $total = (int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn();

    $cards = '';
    foreach ($grimoires as $g) {
        $n = (int) db()->query("SELECT COUNT(*) FROM pages WHERE grimoire = " . db()->quote($g['grimoire']) . " AND kind = 'entry'")->fetchColumn();
        $photo = grimoire_photo($g['grimoire'], 480, 600);
        $cover = $photo
            ? '<div class="frame arch g-cover"><img src="' . e($photo) . '" srcset="' . e(grimoire_photo($g['grimoire'], 480, 600)) . ' 1x, ' . e(grimoire_photo($g['grimoire'], 960, 1200)) . ' 2x" alt="" width="480" height="600" loading="lazy"></div>'
            : '<div class="frame arch g-cover"><i class="spark big" aria-hidden="true"></i></div>';
        $cards .= '<a class="card g-card" href="' . e(page_url($g)) . '">' . $cover . '<h3>' . e($g['title']) . '</h3>'
            . ($n ? '<p>' . number_format($n) . ' entries</p>' : '<p>Orientation &amp; guides</p>') . '</a>';
    }
    if (!$grimoires) {
        $cards = '<p class="lede">Nothing has been moved over yet. Run the importer (see README) to bring grimoires in from Notion.</p>';
    }
    $join = '';
    layout('The Magick Manuscript', <<<HTML
<section class="mm-hero">
  <p class="eyebrow labelled">A Living Digital Grimoire</p>
  <h1>The Magick <em>Manuscript</em></h1>
  <p class="lede">Fifteen cross-linked grimoires. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three. <span class="count-line">{$total} pages inscribed so far.</span></p>
  $join
</section>
<section class="g-cards cards">$cards</section>
HTML, ['private' => true, 'protect' => true]);
}

/** What non-members see in place of the Manuscript: no titles, lists or previews. */
function view_gate(?array $locked = null): void
{
    $total = number_format((int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn());
    $signin = current_email()
        ? '<p class="small">Signed in as ' . e(current_email()) . ', but this email has no active membership. <a href="' . e(url('account')) . '">See your account</a></p>'
        : '<p class="small">Already a member? <a href="' . e(url('login') . '?next=' . rawurlencode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) . '">Sign in</a></p>';
    $join = e(url('join'));
    $heading = $locked ? 'This page lives inside <em>the Manuscript</em>' : 'The Magick <em>Manuscript</em>';
    if ($locked) http_response_code(403);
    record_view($locked ? 'route:members-only' : 'route:/manuscript/');
    layout($locked ? 'Members only' : 'The Magick Manuscript', <<<HTML
<section class="mm-hero narrow gate">
  <p class="eyebrow labelled">Members only</p>
  <h1>$heading</h1>
  <p class="lede">Fifteen cross-linked grimoires and {$total} pages of researched magick, open to members. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three.</p>
  <div class="actions"><a class="btn" href="{$join}">Become a member</a></div>
  $signin
</section>
HTML, ['index' => !$locked, 'description' => 'The Magick Manuscript: a members-only, cross-linked digital grimoire by The Magick Muse.']);
}

/** Pages listed in config "public_pages" can be read by anyone. */
function is_public_page(array $p): bool
{
    $list = config('public_pages', []);
    return in_array($p['id'], array_map('normalize_id', $list), true)
        || in_array($p['grimoire'] . '/' . $p['slug'], $list, true)
        || ($p['kind'] === 'grimoire' && in_array($p['grimoire'], $list, true));
}

function view_page(array $p): void
{
    $access = has_access() || is_public_page($p);
    if (!$access) { view_gate($p); return; }
    record_view($p['id']);
    $crumbs = $p['kind'] === 'grimoire' ? '' : breadcrumbs($p);
    $g = $p['kind'] === 'grimoire' ? $p : grimoire_root($p['grimoire']);
    $eyebrow = $p['kind'] === 'grimoire' ? 'The Magick Manuscript' : ($g['title'] ?? '');

    $tags = json_decode($p['tags_json'] ?? '[]', true) ?: [];
    $images = $tags['_images'] ?? [];
    unset($tags['_images']);

    $head = '<header class="pg-head">' . $crumbs . '<p class="eyebrow">' . e($eyebrow) . '</p>'
        . '<div class="pg-title"><h1>' . icon_html($p['icon'], 'pg-icon big') . e($p['title']) . '</h1>' . save_button($p) . '</div>'
        . ($p['aka'] ? '<p class="aka">Also called ' . e($p['aka']) . '</p>' : '');
    if ($tags) {
        $head .= '<dl class="props">';
        foreach ($tags as $k => $vals) {
            $head .= '<div><dt>' . e($k) . '</dt><dd>' . implode('', array_map(fn($v) => '<span class="tag">' . e($v) . '</span>', $vals)) . '</dd></div>';
        }
        $head .= '</dl>';
    }
    $head .= '</header>';

    // Grimoires use their photo from the website; other pages use their own cover, if any.
    $photo = $p['kind'] === 'grimoire' ? grimoire_photo($p['grimoire'], 1600, 640) : null;
    $cover = $photo ? '<div class="pg-cover arch-banner"><img src="' . e($photo) . '" alt="" width="1600" height="640"></div>' : '';
    if (!$photo) $cover = $p['cover'] ? '<div class="pg-cover"><img src="' . e(media_url($p['cover'])) . '" alt=""></div>' : '';

    if ($p['kind'] === 'collection') {
        $body = ($p['plain'] ? '<p class="lede">' . e($p['plain']) . '</p>' : '') . entry_list(entries_of($p['id']), true);
    } else {
        $gallery = '';
        if ($images) {
            $gallery = '<div class="gallery">' . implode('', array_map(fn($f) => '<figure><img src="' . e(media_url($f)) . '" alt="' . e($p['title']) . '" loading="lazy"></figure>', $images)) . '</div>';
        }
        // Landing pages: intro text is public, the guide pages it links to are for members.
        $body = $gallery . '<div class="mm-prose">' . resolve_links((string) $p['body_html']) . '</div>';
        if ($p['kind'] !== 'grimoire') $body .= connections_html(connections($p));
    }
    layout($p['title'], '<article class="pg pg-' . e($p['kind']) . '">' . $cover . $head . $body . '</article>', ['private' => true, 'protect' => true]);
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
        'icon' => ($r['icon'] && !str_starts_with($r['icon'], 'file:')) ? $r['icon'] : '',
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
    foreach (config('public_pages', []) as $ref) {
        $p = preg_match('/^[0-9a-f-]{32,36}$/i', $ref) ? page_by_id($ref)
            : (str_contains($ref, '/') ? find_page(...explode('/', $ref, 2)) : grimoire_root($ref));
        if ($p && (str_contains((string) $p['body_html'], $file) || $p['cover'] === $file || $p['icon'] === 'file:' . $file
            || str_contains((string) $p['tags_json'], $file))) return true;
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
    redirect(create_checkout((string) ($_POST['plan'] ?? ''), current_email(), (string) ($_POST['code'] ?? '')));
}

function view_welcome(): void
{
    $email = null;
    $sid = (string) ($_GET['session_id'] ?? '');
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
        try {
            $s = stripe('GET', 'checkout/sessions/' . $sid);
            if (($s['status'] ?? '') === 'complete') {
                $email = $s['customer_details']['email'] ?? null;
                // The webhook normally records the membership; doing it here too means no waiting.
                if ($email && !member_row($email)) {
                    handle_stripe_event(['id' => 'welcome_' . $sid, 'type' => 'checkout.session.completed', 'data' => ['object' => $s]]);
                }
                if ($email) sign_in($email);
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
    if (is_customer($email) || is_admin($email)) {
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
        $m['plan'] === 'lifetime' => 'Member: full Manuscript access',
        $m['plan'] === 'comp' => 'Member (complimentary)',
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
