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
  <p class="eyebrow">Membership</p>
  <h1>The Magick Manuscript</h1>
  <p class="lede">20,000+ entries · 15 cross-linked grimoires · an interactive digital grimoire that never stops growing.</p>
  $note
</section>
<section class="cards plans $planClass">$plans</section>
<p class="center small">Secure checkout by Stripe. After paying you're signed in straight away. Already a member? <a href="$login">Sign In</a></p>
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
    layout('Sign in', <<<HTML
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
    // Billing management is only for subscriptions; lifetime buyers get their Stripe receipt by email.
    $billing = $m && $m['stripe_customer'] && $m['plan'] === 'monthly' && $m['status'] !== 'canceled'
        ? '<form method="post" action="' . e(url('account')) . '"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="btn ghost" type="submit">Manage Billing</button></form>' : '';
    $join = has_access() ? '' : '<a class="btn" href="' . e(url('join')) . '">Become a Member</a>';
    $logout = e(url('logout'));
    $email = e($email);
    $admin = is_admin(current_email()) ? '<a class="link" href="' . e(url('dashboard')) . '">Dashboard</a>' : '';
    $library = '<a class="link" href="' . e(site_url('market/library')) . '">Your Library</a>';
    // A member's saved pages and notes live here rather than in the header.
    $mine = has_access() ? '<a class="link" href="' . e(url('saved')) . '">Saved Pages</a><a class="link" href="' . e(url('notes')) . '">Your Notes</a>' : '';
    // Display & accessibility: kept in the browser by prefs.js, the same panel as the website's /settings page.
    $prefs = (string) @file_get_contents(__DIR__ . '/prefs-panel.html');
    layout('Account', <<<HTML
<section class="mm-hero narrow">
  <p class="eyebrow">Account</p>
  <h1>{$email}</h1>
  <p class="lede">$status</p>
  <div class="actions">$join $billing $mine $library $admin
    <form method="post" action="$logout"><input type="hidden" name="csrf" value="$csrf"><button class="link as-button" type="submit">Sign Out</button></form>
  </div>
</section>
<section class="account-prefs" id="settings" aria-labelledby="prefs-title">
  <h2 id="prefs-title">Display &amp; Accessibility</h2>
  <p class="lede small">Dark mode, an easier-to-read font, larger text, more spacing, fewer animations. Changes show straight away.</p>
  $prefs
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
