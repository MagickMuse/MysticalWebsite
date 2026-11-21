<?php
declare(strict_types=1);

/*
 * Joining and signing in: the join page, checkout, the welcome page after paying, emailed sign-in links,
 * the account page, billing, and Stripe's webhook.
 * Routed from index.php.
 */

// ------------------------------------------------------------------ membership

function view_join(): void
{
    record_view('route:/manuscript/join');
    $copy = require MM_ROOT . '/manuscript-copy.php';
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['code'] ?? ''));
    $csrf = e(csrf_token());
    $plans = '';
    foreach (membership_plans() as $key => $plan) {
        if (empty($plan['price'])) continue;
        $plans .= '<form class="card plan" method="post" action="' . e(url('checkout')) . '">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="plan" value="' . e($key) . '"><input type="hidden" name="code" value="' . e($code) . '">'
            . '<h3>' . e($plan['label']) . '</h3>'
            . (!empty($plan['amount_label']) ? '<p class="price big">' . e($plan['amount_label']) . '</p>' : '')
            . '<p>' . e($plan['blurb'] ?? '') . '</p>'
            . '<label class="agree"><input type="checkbox" name="agree" value="1" required> I agree to the <a href="#terms">Terms of Access</a></label>'
            . '<button class="btn" type="submit">' . e($plan['button'] ?? 'Get access') . '</button></form>';
    }
    $login = e(url('login'));
    $note = has_access() ? '<p class="notice">You already have full access. <a href="' . e(url()) . '">Open the Manuscript</a>.</p>' : '';
    // A code from a link (e.g. the SEEKER10 pop-up) is applied quietly at checkout; no note on the page.
    $desc = join_copy_html($copy);
    $terms = safe_html($copy['terms_html']);
    layout('Join the Manuscript', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow">Membership</p>
  <h1>The Magick Manuscript</h1>
  <p class="lede">20,000+ entries · 15 cross-linked grimoires · an interactive digital grimoire that never stops growing.</p>
  $note
  <p class="join-jump"><a class="btn ghost" href="#plans">See Plans</a></p>
</section>
<div class="join-layout">
  <section class="join-copy">
    $desc
    <details class="terms" id="terms"><summary>Terms of Access</summary><div class="mm-prose">$terms</div></details>
  </section>
  <aside class="join-plans" id="plans" aria-label="Membership plans">
    <section class="cards pairs plans stack">$plans</section>
    <p class="center small">Secure checkout by Stripe. After paying you're signed in straight away. Already a member? <a href="$login">Sign In</a></p>
  </aside>
</div>
HTML, ['index' => true, 'description' => 'Join The Magick Manuscript: 20,000+ pages of cross-linked, sourced witchcraft reference across 15 grimoires.',
        'ld' => [membership_ld()]]);
}

/** The membership as Google reads it (schema.org Product): its pictures and each plan's price. */
function membership_ld(): array
{
    $offers = [];
    foreach (membership_plans() as $plan) {
        if (empty($plan['price']) || empty($plan['amount'])) continue;
        $o = ['@type' => 'Offer', 'name' => $plan['label'], 'price' => (string) $plan['amount'], 'priceCurrency' => 'USD',
            'availability' => 'https://schema.org/InStock', 'url' => url('join')];
        if ($plan['mode'] === 'subscription') $o['priceSpecification'] = ['@type' => 'UnitPriceSpecification', 'price' => (string) $plan['amount'],
            'priceCurrency' => 'USD', 'billingDuration' => 1, 'unitCode' => 'MON'];
        $offers[] = $o;
    }
    return ['@type' => 'Product', 'name' => 'The Magick Manuscript', 'brand' => ['@type' => 'Brand', 'name' => 'The Magick Muse'],
        'description' => '20,000+ pages of witchcraft research in one online grimoire: 15 cross-linked grimoires sourced from published books.',
        'image' => [site_url('assets/og-manuscript.jpg'), site_url('assets/manuscript-protection.jpg')], 'offers' => $offers];
}

/** Copy text: escaped, with *stars* around words turned into gold highlights. */
function copy_text(string $t): string
{
    return preg_replace('/\*([^*]+)\*/u', '<strong class="gold">$1</strong>', e($t));
}

/** The join page's description (manuscript-copy.php), each part in its own design. */
function join_copy_html(array $c): string
{
    $o = $c['opening'];
    $html = '<div class="jc-open"><p class="jc-quote">' . copy_text($o['quote']) . '</p><p>' . copy_text($o['text']) . '</p>'
        . '<p class="jc-reveal">' . copy_text($o['reveal']) . '</p></div>';
    $html .= '<dl class="jc-stats">' . implode('', array_map(fn($s) => '<div><dt>' . e($s[0]) . '</dt><dd>' . e($s[1]) . '</dd></div>', $c['stats'])) . '</dl>';
    $html .= '<p class="jc-about">' . copy_text($c['about']) . '</p>';
    $html .= '<ul class="jc-grimoires" aria-label="The fifteen grimoires">' . implode('', array_map(fn($g) => '<li>' . e($g) . '</li>', $c['grimoires'])) . '</ul>';
    // A look inside: the Protection page as members see it, leading to the free sneak peek of it.
    if (is_file(dirname(MM_ROOT) . '/assets/manuscript-protection.jpg')) {
        $peek = sneak_peek_page() ? url('sneak-peek') : '';
        $img = '<img src="' . e(asset_url(site_url('assets/manuscript-protection.jpg'), 'assets/manuscript-protection.jpg')) . '" alt="The Protection page in the Manuscript: its connections to animals, astrology and dreams beside its guide to protective magick" width="1600" height="1200" loading="lazy">';
        $html .= '<figure class="jc-peek">' . ($peek ? '<a href="' . e($peek) . '">' . $img . '</a>' : $img)
            . '<figcaption><span class="eyebrow">A Look Inside</span> The Protection page, exactly as members see it.'
            . ($peek ? ' <a class="link" href="' . e($peek) . '">Read it free</a>' : '') . '</figcaption></figure>';
    }
    $html .= '<section class="jc-tools"><h2>' . copy_text($c['tools_heading']) . '</h2><p class="jc-lead">' . copy_text($c['tools_intro']) . '</p><ul>'
        . implode('', array_map(fn($t) => '<li><span class="prop-glyph pi-' . e($t[0]) . '" aria-hidden="true"></span><h3>' . e($t[1]) . '</h3><p>' . copy_text($t[2]) . '</p></li>', $c['tools']))
        . '</ul></section>';
    $html .= '<section class="jc-gets"><h2>What You Get</h2><ul>' . implode('', array_map(fn($g) => '<li>' . copy_text($g) . '</li>', $c['gets'])) . '</ul></section>';
    $html .= '<section class="jc-why"><h2>Why It’s Different</h2><ul>'
        . implode('', array_map(fn($h) => '<li><h3>' . e($h[0]) . '</h3><p>' . copy_text($h[1]) . '</p></li>', $c['highlights'])) . '</ul></section>';
    $html .= '<aside class="jc-closing"><p>' . copy_text($c['closing']) . '</p><p class="jc-urgent">' . copy_text($c['closing_note']) . '</p></aside>';
    return $html;
}

function do_checkout(): void
{
    check_csrf();
    if (empty($_POST['agree'])) redirect(url('join') . '#terms');
    $plan = (string) ($_POST['plan'] ?? '');
    if (empty(membership_plans()[$plan]['price'])) { not_found(); return; }
    show_checkout(create_checkout($plan, current_email(), (string) ($_POST['code'] ?? '')), membership_plans()[$plan]['label'] ?? 'The Magick Manuscript', url('join'));
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
                // The webhook normally records the membership; doing it here too means no waiting. This also covers a
                // returning member whose old membership ended (their row exists but gives no access).
                if (!member_has_access(member_row($buyer))) {
                    handle_stripe_event(['id' => 'welcome_' . $sid, 'type' => 'checkout.session.completed', 'data' => ['object' => $s]]);
                }
                // Only say "you're in" when access really was granted (a bank transfer may still be pending).
                if (checkout_link_fresh($s) && member_has_access(member_row($buyer))) {
                    sign_in($buyer);
                    $email = $buyer;
                }
            }
        } catch (Throwable $ex) {
            error_log('[manuscript] welcome: ' . $ex->getMessage());
        }
    }
    $msg = $email
        ? '<p class="lede">Your membership is active and you are signed in as ' . e($email) . '. On another device, sign in with this email.</p><div class="actions"><a class="btn" href="' . e(url('begin-here')) . '">Begin Here</a><a class="link" href="' . e(url()) . '">All Grimoires</a></div>'
        : '<p class="lede">Thank you. If your access is not ready yet, <a href="' . e(url('login')) . '">sign in with the email you paid with</a>.</p>';
    layout('Welcome', '<section class="mm-hero narrow"><p class="eyebrow">Welcome, Seeker</p><h1>The Manuscript Is Open to You</h1>' . $msg . '</section>', ['private' => true]);
}

function view_login(string $notice = '', ?string $next = null): void
{
    $next = safe_next($next ?? ($_GET['next'] ?? $_POST['next'] ?? null));
    $csrf = e(csrf_token());
    $action = e(url('login'));
    $nextAttr = e($next);
    layout('Sign In', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow">Welcome Back</p>
  <h1>Sign In</h1>
  $notice
  <div class="signin">
  <form class="mm-form" method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <input type="hidden" name="next" value="$nextAttr">
    <label for="email">Your email</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
    <button class="btn" type="submit">Email Me a Sign-In Link</button>
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
            '<p>Open this link to sign in to The Magick Muse:</p><p><a href="' . e($link) . '">Sign In</a></p><p>It works once and expires in 30 minutes. If you didn’t ask for it, ignore this email.</p>');
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
        $m['status'] === 'disputed' => 'Access paused: your bank is disputing the payment. Access comes back if the dispute is withdrawn or settled in our favor.',
        $m['plan'] === 'comp' && $m['status'] === 'active' => 'Member (complimentary)',
        in_array($m['plan'], ['lifetime', 'comp'], true) => 'Membership ended',
        $m['status'] === 'active' => 'Monthly member, renews ' . e(substr((string) $m['paid_through'], 0, 10)),
        default => 'Membership ' . e($m['status']) . ($m['paid_through'] ? ', access until ' . e(substr($m['paid_through'], 0, 10)) : ''),
    };
    // My Account: one square card for each place a member goes from here.
    $card = fn(string $icon, string $label, string $href) => '<a class="account-card" href="' . e($href) . '"><span class="prop-glyph pi-' . $icon . '" aria-hidden="true"></span>' . e($label) . '</a>';
    $button = fn(string $icon, string $label, string $action) => '<form method="post" action="' . e($action) . '"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<button class="account-card" type="submit"><span class="prop-glyph pi-' . $icon . '" aria-hidden="true"></span>' . e($label) . '</button></form>';
    $cards = '';
    if (has_access()) $cards .= $card('star-four', 'Saved Pages', url('saved')) . $card('note-pencil', 'My Notes', url('notes/'));
    $cards .= $card('text-aa', 'Display & Accessibility', site_url('settings'));
    $cards .= $card('book-open-text', 'My Resources', site_url('market/library'));
    // Billing (change card, invoices, cancel) is only for subscriptions; lifetime buyers get their Stripe receipt by email.
    if ($m && $m['stripe_customer'] && $m['plan'] === 'monthly' && $m['status'] !== 'canceled') $cards .= $button('arrows-clockwise', 'Manage Billing', url('account'));
    if (is_admin($email)) $cards .= $card('chart-bar', 'Dashboard', url('dashboard'));
    $cards .= $button('door-open', 'Sign Out', url('logout'));
    $join = has_access() ? '' : '<div class="actions"><a class="btn" href="' . e(url('join')) . '">Become a Member</a></div>';
    $email = e($email);
    layout('My Account', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow">My Account</p>
  <h1>{$email}</h1>
  <p class="lede">$status</p>
  $join
</section>
<nav class="account-grid" aria-label="My Account">$cards</nav>
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
