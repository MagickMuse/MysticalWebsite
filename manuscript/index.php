<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/render.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/stripe.php';

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
        case 'admin':         $method === 'POST' ? do_admin() : view_admin(); return;
    }
    if (count($parts) === 1) {
        $g = grimoire_root($first);
        $g ? view_page($g) : not_found();
        return;
    }
    if (count($parts) === 2) {
        $p = find_page($parts[0], $parts[1]);
        $p ? view_page($p) : not_found();
        return;
    }
    not_found();
}

// ------------------------------------------------------------------ reading

function view_home(): void
{
    $grimoires = db()->query("SELECT * FROM pages WHERE kind = 'grimoire'")->fetchAll();
    $order = array_keys(config('grimoires', []));
    $rank = array_flip($order);
    usort($grimoires, fn($a, $b) => ($rank[$a['grimoire']] ?? 99) <=> ($rank[$b['grimoire']] ?? 99));
    $total = (int) db()->query("SELECT COUNT(*) FROM pages WHERE kind IN ('entry', 'guide')")->fetchColumn();

    $cards = '';
    foreach ($grimoires as $g) {
        $n = (int) db()->query("SELECT COUNT(*) FROM pages WHERE grimoire = " . db()->quote($g['grimoire']) . " AND kind = 'entry'")->fetchColumn();
        $cover = $g['cover'] ? '<div class="frame arch g-cover"><img src="' . e(media_url($g['cover'])) . '" alt="" loading="lazy"></div>' : '<div class="frame arch g-cover"><i class="spark big" aria-hidden="true"></i></div>';
        $cards .= '<a class="card g-card" href="' . e(page_url($g)) . '">' . $cover . '<h3>' . e($g['title']) . '</h3>'
            . ($n ? '<p>' . number_format($n) . ' entries</p>' : '<p>Orientation &amp; guides</p>') . '</a>';
    }
    if (!$grimoires) {
        $cards = '<p class="lede">Nothing has been moved over yet. Run the importer (see README) to bring grimoires in from Notion.</p>';
    }
    $join = has_access() ? '' : '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a member</a><a class="link" href="' . e(url('login')) . '">Member sign in</a></div>';
    layout('The Magick Manuscript', <<<HTML
<section class="mm-hero">
  <p class="eyebrow labelled">A Living Digital Grimoire</p>
  <h1>The Magick <em>Manuscript</em></h1>
  <p class="lede">Fifteen cross-linked grimoires. Follow a thread from a deity to the herb to the day of the week to the ritual that uses all three. <span class="count-line">{$total} pages inscribed so far.</span></p>
  $join
</section>
<section class="g-cards cards">$cards</section>
HTML, ['index' => true, 'description' => 'The Magick Manuscript: a members-only, cross-linked digital grimoire by The Magick Muse.']);
}

function view_page(array $p): void
{
    $access = has_access();
    $isPublic = in_array($p['kind'], ['grimoire', 'collection'], true);
    $crumbs = $p['kind'] === 'grimoire' ? '' : breadcrumbs($p);
    $g = $p['kind'] === 'grimoire' ? $p : grimoire_root($p['grimoire']);
    $eyebrow = $p['kind'] === 'grimoire' ? 'The Magick Manuscript' : ($g['title'] ?? '');

    $tags = json_decode($p['tags_json'] ?? '[]', true) ?: [];
    $images = $tags['_images'] ?? [];
    unset($tags['_images']);

    $head = '<header class="pg-head">' . $crumbs . '<p class="eyebrow">' . e($eyebrow) . '</p>'
        . '<h1>' . icon_html($p['icon'], 'pg-icon big') . e($p['title']) . '</h1>'
        . ($p['aka'] ? '<p class="aka">Also called ' . e($p['aka']) . '</p>' : '');
    if ($tags) {
        $head .= '<dl class="props">';
        foreach ($tags as $k => $vals) {
            $head .= '<div><dt>' . e($k) . '</dt><dd>' . implode('', array_map(fn($v) => '<span class="tag">' . e($v) . '</span>', $vals)) . '</dd></div>';
        }
        $head .= '</dl>';
    }
    $head .= '</header>';

    $cover = $p['cover'] && ($access || $isPublic) ? '<div class="pg-cover"><img src="' . e(media_url($p['cover'])) . '" alt=""></div>' : '';

    if ($p['kind'] === 'collection') {
        $body = ($p['plain'] ? '<p class="lede">' . e($p['plain']) . '</p>' : '') . entry_list(entries_of($p['id']), true);
    } elseif ($access || $p['kind'] === 'grimoire') {
        $gallery = '';
        if ($images && $access) {
            $gallery = '<div class="gallery">' . implode('', array_map(fn($f) => '<figure><img src="' . e(media_url($f)) . '" alt="' . e($p['title']) . '" loading="lazy"></figure>', $images)) . '</div>';
        }
        // Landing pages: intro text is public, the guide pages it links to are for members.
        $body = $gallery . '<div class="mm-prose">' . resolve_links((string) $p['body_html']) . '</div>';
        if ($access && $p['kind'] !== 'grimoire') $body .= connections_html(connections($p));
        if (!$access && $p['kind'] === 'grimoire') $body .= paywall();
    } else {
        $body = teaser($p) . paywall();
    }
    layout($p['title'], '<article class="pg pg-' . e($p['kind']) . '">' . $cover . $head . $body . '</article>', ['private' => $access]);
}

function view_search(string $q): void
{
    if ($q === '') redirect(url());
    // Each word becomes a prefix match, so "rose qua" finds Rose Quartz.
    $terms = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q), -1, PREG_SPLIT_NO_EMPTY);
    $match = implode(' ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"*', $terms));
    $rows = [];
    if ($match !== '') {
        $st = db()->prepare("SELECT p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.aka, p.parent_id,
                snippet(search, 3, '<mark>', '</mark>', '…', 24) AS snip
            FROM search JOIN pages p ON p.id = search.id
            WHERE search MATCH ? ORDER BY bm25(search, 0, 12.0, 6.0, 1.0) LIMIT 80");
        $st->execute(["{title aka plain} : $match"]);
        $rows = $st->fetchAll();
    }
    $access = has_access();
    $parents = page_index(array_column($rows, 'parent_id'));
    $list = '';
    foreach ($rows as $r) {
        $where = $parents[$r['parent_id']]['title'] ?? '';
        // Snippet text is escaped, then our own <mark> tags are restored.
        $snip = $access ? str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], e($r['snip'])) : '';
        $list .= '<li><a href="' . e(page_url($r)) . '">' . icon_html($r['icon']) . '<strong>' . e($r['title']) . '</strong></a>'
            . ($where ? '<small>' . e($where) . '</small>' : '') . ($snip ? '<p>' . $snip . '</p>' : '') . '</li>';
    }
    $count = count($rows);
    $body = '<section class="mm-results"><p class="eyebrow">Search</p><h1>' . ($count ? ($count >= 80 ? '80+' : $count) . ' results for <em>' . e($q) . '</em>' : 'Nothing found for <em>' . e($q) . '</em>') . '</h1>'
        . ($count ? '<ol class="results">' . $list . '</ol>' : '<p class="lede">Try another spelling, or a related word.</p>') . '</section>';
    layout('Search: ' . $q, $body, ['private' => true]);
}

function serve_media(string $file): void
{
    $file = basename($file);
    $path = config('data_dir') . '/media/' . $file;
    if (!preg_match('/^[a-z0-9-]+\.(jpe?g|png|gif|webp|svg|avif)$/', $file) || !is_file($path)) { http_response_code(404); exit; }
    // Covers and icons of grimoire and database pages are public; everything else is for members.
    if (!has_access()) {
        $owner = substr($file, 0, 32);
        $p = page_by_id($owner);
        if (!$p || !in_array($p['kind'], ['grimoire', 'collection'], true)) { http_response_code(403); exit; }
    }
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'avif' => 'image/avif'];
    header('Content-Type: ' . $types[pathinfo($file, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=604800');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

/** /go/<notion-id>: old Notion links (Discord posts, bookmarks) land on the right wiki page. */
function go_notion(string $id): void
{
    $id = NotionImporterLinks::idFrom($id);
    $p = $id ? page_by_id($id) : null;
    $p ? redirect(page_url($p), 301) : not_found();
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
    $plans = '';
    foreach (config('plans', []) as $key => $plan) {
        if (empty($plan['price'])) continue;
        $plans .= '<form class="card plan" method="post" action="' . e(url('checkout')) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="plan" value="' . e($key) . '">'
            . '<h3>' . e($plan['label']) . '</h3><p>' . e($plan['blurb'] ?? '') . '</p>'
            . '<button class="btn" type="submit">Choose ' . e($plan['label']) . '</button></form>';
    }
    $login = e(url('login'));
    $note = has_access() ? '<p class="lede">You already have full access. <a href="' . e(url()) . '">Open the Manuscript</a>.</p>' : '';
    layout('Join', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Membership</p>
  <h1>Enter <em>the Manuscript</em></h1>
  <p class="lede">Every grimoire, every entry, every cross-link, and every page added from here on. Payment is handled securely by Stripe.</p>
  $note
</section>
<section class="cards pairs plans">$plans</section>
<p class="center small">After paying you'll get an email link to sign in. Already a member? <a href="$login">Sign in</a></p>
HTML);
}

function do_checkout(): void
{
    check_csrf();
    redirect(create_checkout((string) ($_POST['plan'] ?? ''), current_email()));
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

function view_login(string $notice = ''): void
{
    $csrf = e(csrf_token());
    $action = e(url('login'));
    layout('Sign in', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Members</p>
  <h1>Sign in to <em>the Manuscript</em></h1>
  $notice
  <form class="mm-form" method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <label for="email">Your email</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
    <button class="btn" type="submit">Email me a sign-in link</button>
    <p class="small">No password needed. The link works once and expires in 30 minutes.</p>
  </form>
</section>
HTML, ['private' => true]);
}

function do_login(): void
{
    check_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $ok = '<p class="notice">If that email belongs to a member, a sign-in link is on its way. Check your inbox (and spam).</p>';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { view_login('<p class="notice error">That email doesn’t look right.</p>'); return; }
    if (!throttle('login', 5, 900)) { view_login('<p class="notice error">Too many tries. Please wait a few minutes.</p>'); return; }
    // Only members and admins get a link; everyone sees the same message so emails can't be probed.
    if (member_row($email) || is_admin($email)) {
        $link = url('auth?token=' . issue_login_token($email));
        send_mail($email, 'Your sign-in link for the Magick Manuscript',
            "Open this link to sign in to the Magick Manuscript:\n\n$link\n\nIt works once and expires in 30 minutes. If you didn't ask for it, ignore this email.",
            '<p>Open this link to sign in to the Magick Manuscript:</p><p><a href="' . e($link) . '">Sign in to the Manuscript</a></p><p>It works once and expires in 30 minutes. If you didn’t ask for it, ignore this email.</p>');
    }
    view_login($ok);
}

function do_auth(string $token): void
{
    $email = preg_match('/^[0-9a-f]{48}$/', $token) ? redeem_login_token($token) : null;
    if (!$email) { view_login('<p class="notice error">That link has expired or was already used. Request a new one below.</p>'); return; }
    sign_in($email);
    redirect(url());
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
        $m['plan'] === 'lifetime' => 'Lifetime member',
        $m['plan'] === 'comp' => 'Member (complimentary)',
        $m['status'] === 'active' => 'Monthly member, renews ' . e(substr((string) $m['paid_through'], 0, 10)),
        default => 'Membership ' . e($m['status']) . ($m['paid_through'] ? ', access until ' . e(substr($m['paid_through'], 0, 10)) : ''),
    };
    $billing = $m && $m['stripe_customer']
        ? '<form method="post" action="' . e(url('account')) . '"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="btn ghost" type="submit">Manage billing</button></form>' : '';
    $join = has_access() ? '' : '<a class="btn" href="' . e(url('join')) . '">Become a member</a>';
    $logout = e(url('logout'));
    $email = e($email);
    $admin = is_admin(current_email()) ? '<a class="link" href="' . e(url('admin')) . '">Members admin</a>' : '';
    layout('Account', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow labelled">Account</p>
  <h1><em>{$email}</em></h1>
  <p class="lede">$status</p>
  <div class="actions">$join $billing $admin
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
    handle_stripe_event($event);
    http_response_code(200);
    echo 'ok';
}

// ------------------------------------------------------------------ admin

function view_admin(string $notice = ''): void
{
    if (!is_admin()) { not_found(); return; }
    $rows = db()->query('SELECT * FROM members ORDER BY created_at DESC')->fetchAll();
    $active = count(array_filter($rows, fn($r) => $r['status'] === 'active'));
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td>' . e($r['email']) . '</td><td>' . e($r['plan']) . '</td><td>' . e($r['status']) . '</td><td>' . e(substr((string) $r['paid_through'], 0, 10)) . '</td><td>' . e($r['note']) . '</td><td>' . e(substr($r['created_at'], 0, 10)) . '</td></tr>';
    }
    $csrf = e(csrf_token());
    $action = e(url('admin'));
    $last = db()->query('SELECT MAX(imported_at) FROM pages')->fetchColumn() ?: 'never';
    $pages = (int) db()->query('SELECT COUNT(*) FROM pages')->fetchColumn();
    layout('Members', <<<HTML
<section class="admin">
  <p class="eyebrow">Curator</p>
  <h1>Members <em>($active active)</em></h1>
  <p class="small">$pages pages in the wiki · last import $last UTC</p>
  $notice
  <form class="mm-form inline" method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <label for="add">Give someone access (existing Gumroad member, gift, collaborator)</label>
    <textarea id="add" name="emails" rows="3" placeholder="one email per line"></textarea>
    <label for="plan">Access</label>
    <select id="plan" name="plan"><option value="comp">Lifetime (complimentary / moved from Gumroad)</option><option value="revoke">Remove access</option></select>
    <input name="note" placeholder="Note, e.g. Gumroad lifetime" aria-label="Note">
    <button class="btn" type="submit">Save</button>
  </form>
  <div class="table-wrap"><table><tr><th>Email</th><th>Plan</th><th>Status</th><th>Paid through</th><th>Note</th><th>Joined</th></tr>$tr</table></div>
</section>
HTML, ['private' => true]);
}

function do_admin(): void
{
    if (!is_admin()) { not_found(); return; }
    check_csrf();
    $n = 0;
    foreach (preg_split('/[\s,;]+/', (string) ($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (($_POST['plan'] ?? '') === 'revoke') {
            upsert_member($email, ['status' => 'canceled', 'paid_through' => null]);
            db()->prepare("UPDATE members SET plan = NULL WHERE email = ?")->execute([strtolower($email)]);
        } else {
            upsert_member($email, ['plan' => 'comp', 'status' => 'active', 'paid_through' => null, 'note' => mb_substr((string) ($_POST['note'] ?? ''), 0, 200)]);
        }
        $n++;
    }
    view_admin("<p class=\"notice\">Updated $n " . ($n === 1 ? 'member' : 'members') . '.</p>');
}
