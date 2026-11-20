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
require __DIR__ . '/lib/tools.php';
require __DIR__ . '/lib/ical.php';
require __DIR__ . '/lib/path.php';
require __DIR__ . '/lib/pages.php';
require __DIR__ . '/lib/search.php';
require __DIR__ . '/lib/account.php';

// Path relative to the wiki's base, e.g. "correspondence/protection".
$base = parse_url(config('base_url'), PHP_URL_PATH) ?: '';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = trim($path, '/');
$parts = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // Once a day: clear the notes of members whose access ended more than 90 days ago (lib/tools.php).
    try { clear_lapsed_member_data(); } catch (Throwable $ex) { error_log('[manuscript] cleanup: ' . $ex->getMessage()); }
    // Once a day: the beginner path's emails (lib/path.php), unless a cron job already sent them.
    try { maybe_run_beginner_path(); } catch (Throwable $ex) { error_log('[manuscript] beginner path: ' . $ex->getMessage()); }
    route($parts, $method);
} catch (Throwable $ex) {
    error_log('[manuscript] ' . $ex);
    http_response_code(500);
    layout('Something went wrong', '<section class="mm-empty"><h1>Something Went Astray</h1><p class="lede">Please try again in a moment.</p></section>');
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
        case 'market':        route_shop(array_slice($parts, 1), $method); return;
        case 'shop':          // the Market's old address: links forward, posts from open tabs still work
            if ($method === 'POST') { route_shop(array_slice($parts, 1), $method); return; }
            $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
            redirect(site_url(implode('/', array_merge(['market'], array_slice($parts, 1)))) . ($q !== '' ? '?' . $q : ''), 301);
        case 'suggest':       search_suggest(trim((string) ($_GET['q'] ?? ''))); return;
        case 'calendar':      view_calendar(); return;
        case 'calendar.ics':  view_calendar_feed(); return;
        case 'today': case 'spell-builder': case 'wheel': case 'draw-a-card': case 'pull-a-rune': case 'dream-decoder': case 'planetary-hours': case 'guide-pages': case 'enchanted-tools': view_tool_page($first); return;
        case 'tools':
            match ($parts[1] ?? '') { 'data.json' => serve_tools_data(), 'today.json' => serve_today_data(), default => not_found() };
            return;
        case 'notes': case 'workings': route_member_store($first, $method); return;
        case 'path':          route_path(array_slice($parts, 1), $method); return;
        case 'library':       view_the_library(); return;
        case 'picture-credits': view_picture_credits(); return;
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
