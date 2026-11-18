<?php
declare(strict_types=1);

/*
 * The shop: courses, templates and free downloads, sold through Stripe and delivered in a
 * signed-in "library". Products are listed in the config file (see config.sample.php).
 * Files to deliver go in {data_dir}/products/{product-slug}/, outside the public website.
 */

/** Catalog from products.php, plus private delivery links and any overrides from the config. */
function products(): array
{
    static $all = null;
    if ($all !== null) return $all;
    $all = require MM_ROOT . '/products.php';
    foreach (config('products', []) as $slug => $over) $all[$slug] = array_merge($all[$slug] ?? [], $over);
    foreach (config('product_links', []) as $slug => $links) {
        if (isset($all[$slug])) $all[$slug]['links'] = $links;
    }
    return $all;
}

function short_name(array $p): string { return $p['short_name'] ?? $p['name']; }

/** Product descriptions are our own copy, but still limited to simple formatting tags. */
function safe_html(string $html): string
{
    $html = strip_tags($html, '<p><br><h2><h3><ul><ol><li><strong><em><hr><a>');
    $html = preg_replace('/<(p|br|h2|h3|ul|ol|li|strong|em|hr)\b[^>]*>/i', '<$1>', $html);
    return preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
        $href = preg_match('/href="([^"]*)"/i', $m[0], $h) ? html_entity_decode($h[1]) : '';
        if (!preg_match('~^(https?://|/)~', $href)) $href = '#';
        $ext = str_starts_with($href, 'http') && !str_contains($href, (string) parse_url(config('base_url'), PHP_URL_HOST));
        return '<a href="' . e($href) . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>';
    }, $html);
}

function community_links(): string
{
    $discord = (string) config('discord_url', 'https://discord.com/invite/PBr2BtCpxk');
    return '<div class="actions community"><a class="link" href="' . e($discord) . '" target="_blank" rel="noopener">Join our Discord coven</a>'
        . '<a class="link" href="' . e(site_url('story.html')) . '">Curious about the Curator?</a></div>';
}

function product(string $slug): ?array
{
    $p = products()[$slug] ?? null;
    return $p ? $p + ['slug' => $slug] : null;
}

function money(int $cents, string $currency = 'usd'): string
{
    $sym = ['usd' => '$', 'eur' => '€', 'gbp' => '£', 'cad' => 'CA$', 'aud' => 'A$'][strtolower($currency)] ?? strtoupper($currency) . ' ';
    return $sym . number_format($cents / 100, $cents % 100 ? 2 : 0);
}

function price_label(array $p): string
{
    if (!empty($p['free'])) return 'Free';
    $s = money((int) $p['price_cents'], config('currency', 'usd'));
    return !empty($p['pay_what_you_want']) ? "$s+" : $s;
}

function product_image(array $p): string
{
    if (empty($p['image'])) return '<div class="frame arch shop-art"><i class="spark big" aria-hidden="true"></i></div>';
    $src = preg_match('~^https?://~', $p['image']) ? $p['image'] : site_url($p['image']);
    return '<div class="frame arch shop-art"><img src="' . e($src) . '" alt="" loading="lazy"></div>';
}

function product_files(string $slug): array
{
    $dir = config('data_dir') . '/products/' . basename($slug);
    if (!is_dir($dir)) return [];
    return array_values(array_filter(scandir($dir), fn($f) => $f[0] !== '.' && is_file("$dir/$f")));
}

function route_shop(array $parts, string $method): void
{
    $a = $parts[0] ?? '';
    match (true) {
        $a === '' => view_shop(),
        $a === 'library' => view_library(),
        $a === 'thanks' => view_shop_thanks(),
        $a === 'download' => shop_download($parts[1] ?? '', $parts[2] ?? ''),
        $a === 'claim' && $method === 'POST' => do_claim(),
        $a === 'buy' && $method === 'POST' => do_buy(),
        product($a) !== null && count($parts) === 1 => view_product(product($a)),
        default => not_found(),
    };
}

function view_shop(): void
{
    record_view('route:/shop');
    $cards = '';
    foreach (products() as $slug => $p) {
        $p['slug'] = $slug;
        if (!empty($p['hidden'])) continue;
        $cards .= '<a class="card shop-card" href="' . e(site_url('shop/' . $slug)) . '">' . product_image($p)
            . '<h3>' . e(short_name($p)) . '</h3><p>' . e($p['tagline'] ?? '') . '</p><p class="price">' . e(price_label($p)) . '</p></a>';
    }
    // The Manuscript itself leads the shop.
    $ms = '<a class="card shop-card featured" href="' . e(url('join')) . '"><div class="frame arch shop-art"><img src="' . e(site_url('assets/grimoire-selection-screenshot-700.webp')) . '" alt="" loading="lazy"></div>'
        . '<h3>The Magick Manuscript</h3><p>15 cross-linked grimoires, 20,000+ pages, growing weekly.</p><p class="price">Membership</p></a>';
    layout('Shop', <<<HTML
<section class="mm-hero">
  <p class="eyebrow labelled">The Magick Muse</p>
  <h1>The <em>Shop</em></h1>
  <p class="lede">Courses, grimoire templates and the Manuscript. Everything you buy stays in your library, ready whenever you are.</p>
</section>
<section class="cards shop-grid">$ms$cards</section>
HTML, ['index' => true, 'description' => 'Witchcraft courses, grimoire templates and The Magick Manuscript from The Magick Muse.']);
}

function view_product(array $p): void
{
    record_view('route:/shop/' . $p['slug']);
    $email = current_email();
    $csrf = e(csrf_token());
    $slug = e($p['slug']);
    $desc = safe_html((string) ($p['description_html'] ?? ''));
    $includes = '';
    if (!empty($p['includes'])) {
        $includes = '<ul class="includes">' . implode('', array_map(fn($i) => '<li class="inline-star">' . e($i) . '</li>', $p['includes'])) . '</ul>';
    }

    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['code'] ?? ''));
    if ($email && owns($email, $p['slug'])) {
        $action = '<p class="notice">You own this. <a href="' . e(site_url('shop/library')) . '">Open your library</a></p>';
    } elseif (!empty($p['free'])) {
        $action = '<form class="mm-form buy" method="post" action="' . e(site_url('shop/claim')) . '">'
            . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><input type=\"hidden\" name=\"product\" value=\"$slug\">"
            . '<label for="claim-email">Where should we send it?</label><input id="claim-email" type="email" name="email" autocomplete="email" required value="' . e($email) . '">'
            . '<button class="btn" type="submit">Send it to me free</button><p class="small">You’ll also hear from The Magick Muse now and then. Unsubscribe anytime.</p></form>';
    } else {
        $min = (int) $p['price_cents'];
        $amount = !empty($p['pay_what_you_want'])
            ? '<label for="amount">Pay what you can (minimum ' . e(money($min)) . ')</label><div class="amount"><span aria-hidden="true">$</span><input id="amount" name="amount" type="number" inputmode="decimal" min="' . ($min / 100) . '" step="1" value="' . ($min / 100) . '" required></div>'
            : '';
        $action = '<form class="mm-form buy" method="post" action="' . e(site_url('shop/buy')) . '">'
            . "<input type=\"hidden\" name=\"csrf\" value=\"$csrf\"><input type=\"hidden\" name=\"product\" value=\"$slug\"><input type=\"hidden\" name=\"code\" value=\"" . e($code) . '">'
            . ($code ? '<p class="notice">Code <strong>' . e($code) . '</strong> will be applied at checkout.</p>' : '')
            . $amount . '<button class="btn" type="submit">' . (!empty($p['pay_what_you_want']) ? 'Buy now' : 'Buy for ' . e(price_label($p))) . '</button>'
            . '<p class="small">Secure checkout by Stripe. Discount code? Enter it at checkout.</p></form>';
    }
    $refund = !empty($p['refund_policy']) ? '<p class="small refund">' . e($p['refund_policy']) . '</p>' : '';
    $full = $p['name'] !== short_name($p) ? '<p class="aka">' . e(trim(preg_replace('/^[^:✧]*[:✧]/u', '', $p['name']))) . '</p>' : '';
    layout(short_name($p), '<article class="product">' . product_image($p) . '<div class="product-text"><p class="eyebrow">' . e($p['kind'] ?? 'The Magick Muse') . '</p>'
        . '<h1>' . e(short_name($p)) . '</h1>' . $full . '<p class="price big">' . e(price_label($p)) . '</p>'
        . $includes . $action . $refund
        . '<div class="mm-prose product-desc">' . $desc . '</div>' . community_links() . '</div></article>',
        ['index' => true, 'description' => $p['tagline'] ?? '']);
}

function do_buy(): void
{
    check_csrf();
    $slug = (string) ($_POST['product'] ?? '');
    $p = product($slug);
    if (!$p || !empty($p['free'])) { not_found(); return; }
    $amount = isset($_POST['amount']) ? (int) round((float) $_POST['amount'] * 100) : null;
    $session = create_product_checkout($slug, $amount, current_email(), (string) ($_POST['code'] ?? ''));
    show_checkout($session, short_name($p), site_url('shop/' . $slug));
}

function do_claim(): void
{
    check_csrf();
    $p = product((string) ($_POST['product'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!$p || empty($p['free'])) { not_found(); return; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { view_product($p); return; }
    if (!throttle('claim', 5, 900) || too_many_links($email)) { layout('Slow down', '<section class="mm-empty"><h1>Too many tries</h1><p class="lede">Please wait a few minutes.</p></section>'); return; }

    db()->prepare('INSERT OR IGNORE INTO leads (email, product, created_at) VALUES (?, ?, ?)')->execute([$email, $p['slug'], now()]);
    grant($email, $p['slug'], 'free');
    // Delivered by email, which also confirms the address is real.
    $link = url('auth?token=' . issue_login_token($email) . '&next=' . rawurlencode('/shop/library'));
    $nm = short_name($p);
    send_mail($email, 'Your ' . $nm . ' is here',
        "Thank you for picking up {$nm}.\n\nOpen your library to get it:\n$link\n\nThe link signs you in and works for 30 minutes. After that, sign in any time at " . site_url('shop/library') . "\n\nThe Magick Muse",
        '<p>Thank you for picking up <strong>' . e($nm) . '</strong>.</p><p><a href="' . e($link) . '">Open your library</a> to get it.</p><p>The link signs you in and works for 30 minutes. After that, sign in any time at ' . e(site_url('shop/library')) . '.</p><p>The Magick Muse</p>');
    layout('Check your email', '<section class="mm-hero narrow"><p class="eyebrow labelled">On its way</p><h1>Check your <em>inbox</em></h1><p class="lede">We sent ' . e($nm) . ' to ' . e($email) . '. If it isn’t there in a few minutes, look in spam or promotions.</p></section>', ['private' => true]);
}

function view_shop_thanks(): void
{
    $sid = (string) ($_GET['session_id'] ?? '');
    $slug = null;
    if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
        try {
            $s = stripe('GET', 'checkout/sessions/' . $sid);
            $email = $s['customer_details']['email'] ?? null;
            if (($s['status'] ?? '') === 'complete' && $email) {
                // Normally the webhook records it; doing it here too means no waiting.
                handle_stripe_event(['id' => 'thanks_' . $sid, 'type' => 'checkout.session.completed', 'data' => ['object' => $s]]);
                if (checkout_link_fresh($s)) sign_in($email);
                $slug = $s['metadata']['product'] ?? null;
            }
        } catch (Throwable $ex) {
            error_log('[shop] thanks: ' . $ex->getMessage());
        }
    }
    redirect(site_url('shop/library' . ($slug ? '?new=' . rawurlencode($slug) : '')));
}

function view_library(): void
{
    $email = current_email();
    if (!$email) {
        view_login('<p class="notice">Sign in with the email you bought with to open your library.</p>', '/shop/library');
        return;
    }
    $st = db()->prepare('SELECT product, created_at FROM purchases WHERE email = ? ORDER BY created_at DESC');
    $st->execute([$email]);
    $owned = $st->fetchAll();
    $new = (string) ($_GET['new'] ?? '');

    $items = '';
    if (has_access()) {
        $items .= '<li class="lib-item"><div><h3>The Magick Manuscript</h3><p class="small">Full member access</p></div><div class="lib-links"><a class="btn" href="' . e(url()) . '">Open the Manuscript</a></div></li>';
    }
    foreach ($owned as $o) {
        $p = product($o['product']);
        if (!$p) continue;
        $links = '';
        foreach ($p['links'] ?? [] as $label => $href) {
            $links .= '<a class="btn" href="' . e($href) . '" target="_blank" rel="noopener">' . e($label) . '</a>';
        }
        foreach (product_files($p['slug']) as $f) {
            $links .= '<a class="btn ghost" href="' . e(site_url('shop/download/' . rawurlencode($p['slug']) . '/' . rawurlencode($f))) . '">Download ' . e($f) . '</a>';
        }
        $badge = $new === $p['slug'] ? '<span class="tag">Just added</span>' : '';
        $items .= '<li class="lib-item"><div><h3>' . e(short_name($p)) . " $badge</h3>"
            . (!empty($p['instructions']) ? '<p class="small">' . e($p['instructions']) . '</p>' : '')
            . '</div><div class="lib-links">' . ($links ?: '<span class="small">Delivery details coming soon.</span>') . '</div></li>';
    }
    $thanks = $new ? '<p class="notice">Thank you! Your purchase is below, and a receipt from Stripe is in your email.</p>' : '';
    $body = $items
        ? '<ul class="library">' . $items . '</ul>'
        : '<p class="lede">Nothing here yet. <a href="' . e(site_url('shop')) . '">Visit the shop</a>.</p>';
    layout('Your library', '<section class="lib"><p class="eyebrow labelled">' . e($email) . '</p><h1>Your <em>library</em></h1>' . $thanks . $body
        . '<p class="small"><a href="' . e(url('account')) . '">Account &amp; billing</a></p></section>', ['private' => true]);
}

function shop_download(string $slug, string $file): void
{
    $email = current_email();
    $file = basename($file);
    $path = config('data_dir') . '/products/' . basename($slug) . '/' . $file;
    if (!$email || !(owns($email, $slug) || is_admin($email)) || $file === '' || $file[0] === '.' || !is_file($path)) {
        not_found();
        return;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}
