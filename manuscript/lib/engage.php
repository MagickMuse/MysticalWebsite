<?php
declare(strict_types=1);

/*
 * Page-view counts for the dashboard, and members' saved (starred) pages.
 */

/** Counts one view of a page. Skips bots and the curator's own visits so the numbers mean something. */
function record_view(string $pageId): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|lighthouse/i', $ua)) return;
    if (is_admin()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    try {
        start_session();
        $who = current_email() ?: ('s:' . session_id());
        $reader = substr(hash_hmac('sha256', $who, (string) config('app_secret')), 0, 16);
        $day = gmdate('Y-m-d');
        db()->prepare('INSERT INTO page_views (page_id, day, views) VALUES (?, ?, 1) ON CONFLICT(page_id, day) DO UPDATE SET views = views + 1')
            ->execute([$pageId, $day]);
        db()->prepare('INSERT OR IGNORE INTO page_readers (page_id, day, reader) VALUES (?, ?, ?)')->execute([$pageId, $day, $reader]);
    } catch (Throwable $ex) {
        error_log('[manuscript] view count: ' . $ex->getMessage()); // never break a page over a counter
    }
}

function is_saved(string $pageId): bool
{
    $email = current_email();
    if (!$email) return false;
    $st = db()->prepare('SELECT 1 FROM bookmarks WHERE email = ? AND page_id = ?');
    $st->execute([$email, $pageId]);
    return (bool) $st->fetchColumn();
}

function save_button(array $p): string
{
    if (!current_email() || !has_access()) return '';
    $saved = is_saved($p['id']);
    return '<form class="save-form" method="post" action="' . e(url('saved')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="page" value="' . e($p['id']) . '">'
        . '<button class="save-btn" type="submit" aria-pressed="' . ($saved ? 'true' : 'false') . '">'
        . '<span class="save-star" aria-hidden="true">' . ($saved ? '★' : '☆') . '</span><span class="save-label">' . ($saved ? 'Saved' : 'Save') . '</span></button></form>';
}

/** POST /saved: star or un-star a page. Answers JSON to the page's script, or redirects back without it. */
function toggle_saved(): void
{
    check_csrf();
    $email = current_email();
    $page = page_by_id((string) ($_POST['page'] ?? ''));
    if (!$email || !has_access() || !$page) { http_response_code(403); exit; }
    if (is_saved($page['id'])) {
        db()->prepare('DELETE FROM bookmarks WHERE email = ? AND page_id = ?')->execute([$email, $page['id']]);
        $saved = false;
    } else {
        db()->prepare('INSERT OR IGNORE INTO bookmarks (email, page_id, created_at) VALUES (?, ?, ?)')->execute([$email, $page['id'], now()]);
        $saved = true;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        echo json_encode(['saved' => $saved]);
        return;
    }
    redirect(($_POST['back'] ?? '') === 'saved' ? url('saved') : page_url($page));
}

/** GET /saved: the member's starred pages, grouped by grimoire. */
function view_saved(): void
{
    $email = current_email();
    if (!$email) redirect(url('login') . '?next=' . rawurlencode((string) parse_url(url('saved'), PHP_URL_PATH)));
    if (!has_access()) { view_gate(['locked' => true]); return; }
    $st = db()->prepare('SELECT b.page_id, b.created_at FROM bookmarks b WHERE b.email = ? ORDER BY b.created_at DESC');
    $st->execute([$email]);
    $rows = $st->fetchAll();
    $idx = page_index(array_column($rows, 'page_id'));
    $groups = [];
    foreach ($rows as $r) {
        $p = $idx[$r['page_id']] ?? null;
        if (!$p) continue; // removed from the Manuscript since
        $g = grimoire_root($p['grimoire']);
        $groups[$g['title'] ?? 'Other'][] = $p;
    }
    $csrf = e(csrf_token());
    $html = '';
    foreach ($groups as $title => $pages) {
        $html .= '<section class="saved-group"><h2>' . e($title) . '</h2><ul class="saved-list">';
        foreach ($pages as $p) {
            $html .= '<li><a href="' . e($p['url']) . '">' . icon_html($p['icon']) . '<span>' . e($p['title']) . '</span></a>'
                . '<form method="post" action="' . e(url('saved')) . '"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="page" value="' . e($p['id']) . '"><input type="hidden" name="back" value="saved">'
                . '<button class="link as-button" type="submit">Remove</button></form></li>';
        }
        $html .= '</ul></section>';
    }
    if (!$html) $html = '<p class="lede">Nothing saved yet. Tap <strong>☆ Save</strong> at the top of any page to keep it here.</p>';
    layout('Saved pages', '<section class="saved"><p class="eyebrow">Your Manuscript</p><h1>Saved Pages</h1>' . $html . '</section>', ['private' => true]);
}
