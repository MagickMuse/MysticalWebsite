<?php
declare(strict_types=1);

/*
 * The members' tools (Today, the Spell Builder, the Planet / Zodiac / Moon Phase Wheels) and notes.
 *
 * There is ONE implementation of the tools: assets/tools.js, which runs in the browser, on this site and on the
 * GitHub Pages preview alike. The server's part is only what a browser can't do on its own:
 *   GET  /tools/data.json   the Manuscript's pages and connections for tools.js (members only; cached in data_dir)
 *   GET  /today, /spell-builder, /wheel, /notes   the tools' pages: the site's frame around an empty <article> tools.js fills
 *   GET|POST /notes, /workings (?json)   a member's notes and saved workings, kept in the database
 * On the preview, which has no server, tools.js reads a copy of data.json (.github/scripts/build-static-tools.py)
 * and keeps notes and workings in the browser instead.
 */

const TOOL_PAGES = [
    'today' => ['Today', 'today'],
    'spell-builder' => ['Spell Builder', 'spell'],
    'wheel' => ['Correspondence Wheel', 'wheel'],
    'draw-a-card' => ['Draw a Card', 'tarot'],
    'pull-a-rune' => ['Pull a Rune', 'rune'],
    'dream-decoder' => ['Dream Decoder', 'dream'],
    'planetary-hours' => ['Planetary Hours', 'hours'],
];

/** A tool's page: members only; tools.js fills in the article. */
function view_tool_page(string $route): void
{
    if (!has_access()) { view_gate(['locked' => true]); return; }
    [$title, $tool] = TOOL_PAGES[$route];
    record_view('route:/manuscript/' . $route);
    layout($title, '<article class="pg pg-tool" data-static-tool="' . e($tool) . '"><p class="lede">Opening the ' . e($title) . '…</p>'
        . '<noscript><p class="notice">The ' . e($title) . ' needs JavaScript turned on.</p></noscript></article>', ['private' => true, 'protect' => true]);
}

// ------------------------------------------------------------------ the data tools.js works from

/** Kinds of page the tools look for (an entry's Type), plus "Tarot" for a tarot card. */
const TOOL_KINDS = ['Planets', 'Moon Phases', 'Western Zodiac', 'Days of the Week', 'Days of the Year', 'Holidays', 'Seasons', 'Time of Day', 'Color', 'Elder Futhark Runes'];

/** The fields copied, word for word, for the safety notes on rituals and recipes, and the grimoires they come from. */
const SAFETY_FIELDS = ['Safety & Uses', 'Pregnancy', 'Drug Interactions', 'Notes/Warnings', 'Water Safe?', 'Sun Safe?'];
const SAFETY_GRIMOIRES = ['plant', 'oil', 'mineral', 'material'];

/** /tools/data.json: built from the database when the content changes, then served from the cache. */
function serve_tools_data(): void
{
    if (!has_access()) { http_response_code(403); exit; }
    // The version changes when the data's shape does, so an older copy is never served.
    $cache = config('data_dir') . '/tools-data-v5.json';
    $content = config('data_dir') . '/content.sqlite';
    if (!is_file($cache) || filemtime($cache) < (int) @filemtime($content)) {
        file_put_contents($cache . '.tmp', json_encode(tools_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($cache . '.tmp', $cache);
    }
    $etag = '"' . filemtime($cache) . '-' . filesize($cache) . '"';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return; }
    readfile($cache);
}

/**
 * Every page as [grimoire, slug, title, icon, kinds, date range, words of text], every connection (both ways, by position in
 * the list), each calendar day's festivals, and the tarot cards: [page, picture, upright keywords, reversed keywords]. The same shape build-static-tools.py writes for the preview.
 */
function tools_data(): array
{
    $grimoires = db()->query('SELECT DISTINCT grimoire FROM pages ORDER BY grimoire')->fetchAll(PDO::FETCH_COLUMN);
    $gi = array_flip($grimoires);
    $pages = [];
    $pos = [];
    $pageRow = [];
    $safety = [];
    foreach (db()->query("SELECT id, grimoire, slug, kind, title, icon, cover, tags_json, plain FROM pages WHERE kind IN ('entry', 'guide') ORDER BY grimoire, slug") as $p) {
        $tags = json_decode((string) $p['tags_json'], true) ?: [];
        $types = array_map('strval', (array) ($tags['Type'] ?? $tags['Category'] ?? []));
        $kinds = array_values(array_intersect($types, TOOL_KINDS));
        $pageRow[$p['id']] = $p;
        // Safety fields, exactly as written, for the safety notes on rituals and recipes (tools.js decides what to show).
        if (in_array($p['grimoire'], SAFETY_GRIMOIRES, true)) {
            $f = [];
            foreach (SAFETY_FIELDS as $name) if (!empty($tags[$name])) $f[] = [$name, array_values(array_map('strval', (array) $tags[$name]))];
            if ($f) $safety[count($pages)] = $f;
        }
        $icon = (string) shown_icon($p);
        $icon = str_starts_with($icon, 'file:') ? 'm:' . substr($icon, 5) : (str_starts_with($icon, 'asset:') ? 'a:' . substr($icon, 6) : $icon);
        $pos[$p['id']] = count($pages);
        // How much the page has to read: its text, and a recipe's or ritual's Description, Quantities and Directions.
        $text = (string) $p['plain'];
        foreach (CONTENT_PROPERTIES[$p['grimoire']] ?? [] as $name) $text .= ' ' . implode(' ', array_map('strval', (array) ($tags[$name] ?? [])));
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        $pages[] = [$gi[$p['grimoire']], $p['slug'], $p['title'], $icon, implode('|', $kinds), implode(' ', array_map('strval', (array) ($tags['Date Range'] ?? []))), $words];
    }
    $links = array_fill(0, count($pages), []);
    $festivals = [];
    $tarot = []; // tarot cards (they have upright and reversed meanings): page => [picture, upright keywords, reversed keywords]
    foreach (db()->query('SELECT from_id, prop, to_id FROM relations') as $r) {
        $a = $pos[$r['from_id']] ?? null;
        $b = $pos[$r['to_id']] ?? null;
        if ($a === null || $b === null || $a === $b) continue;
        $links[$a][$b] = true;
        $links[$b][$a] = true;
        if (in_array($r['prop'], ['Upright Correspondences', 'Reversed Correspondences'], true) && $grimoires[$pages[$a][0]] === 'card') {
            $tarot[$a][$r['prop'] === 'Upright Correspondences' ? 1 : 2][] = $b;
        }
        if ($r['prop'] === 'Related Timing' && $grimoires[$pages[$a][0]] === 'timing' && preg_match('/^[a-z]+-\d{1,2}$/', $pages[$a][1])) $festivals[$pages[$a][1]][] = $b;
    }
    $cards = [];
    $ids = array_flip($pos);
    foreach ($tarot as $i => $t) {
        $cover = (string) ($pageRow[$ids[$i]]['cover'] ?? '');
        $cover = str_starts_with($cover, 'asset:') ? 'a:' . substr($cover, 6) : ($cover !== '' && !str_starts_with($cover, 'https://') ? 'm:' . $cover : '');
        $pages[$i][4] = ltrim($pages[$i][4] . '|Tarot', '|');
        $cards[] = [$i, $cover, $t[1] ?? [], $t[2] ?? []];
    }
    return ['g' => $grimoires, 'p' => $pages, 'l' => array_map(fn($l) => array_keys($l), $links), 'f' => (object) $festivals, 't' => $cards, 'w' => (object) $safety];
}

// ------------------------------------------------------------------ notes and workings, kept for each member

/** /notes and /workings: ?json lists them, POST saves or deletes one; /notes is also the notes page (tools.js lists them). */
function route_member_store(string $what, string $method): void
{
    $email = current_email();
    if (!$email) redirect(url('login') . '?next=' . rawurlencode((string) parse_url(url($what), PHP_URL_PATH)));
    if (!has_access()) { view_gate(['locked' => true]); return; }
    if ($method !== 'POST' && !isset($_GET['json'])) {
        if ($what === 'workings') redirect(url('spell-builder') . '#workings'); // workings are listed in the Spell Builder
        layout('Your notes', '<article class="saved notes-page" data-static-tool="notes"><p class="lede">Opening your notes…</p>'
            . '<noscript><p class="notice">Your notes need JavaScript turned on.</p></noscript></article>', ['private' => true]);
        return;
    }
    header('Content-Type: application/json');
    header('Cache-Control: private, no-store');
    if ($method === 'POST') {
        check_csrf();
        echo json_encode($what === 'notes' ? save_member_note($email, $_POST) : save_member_working($email, $_POST));
        return;
    }
    echo json_encode($what === 'notes' ? member_notes($email) : member_workings($email));
}

function member_notes(string $email): array
{
    $st = db()->prepare('SELECT id, title, body, page_path AS page, page_title AS pageTitle, created_at AS created, updated_at AS updated
        FROM member_notes WHERE email = ? ORDER BY updated_at DESC, id DESC');
    $st->execute([$email]);
    return array_map(fn($n) => ['id' => (int) $n['id']] + $n, $st->fetchAll());
}

/** Saves (or with do=delete, deletes) one note. An empty new note isn't kept. */
function save_member_note(string $email, array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if (($in['do'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM member_notes WHERE id = ? AND email = ?')->execute([$id, $email]);
        return ['deleted' => true];
    }
    $title = trim(mb_substr(preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')), 0, 160)) ?: 'Untitled note';
    $body = rtrim(mb_substr(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')), 0, 20000));
    if ($id) {
        $st = db()->prepare('UPDATE member_notes SET title = ?, body = ?, updated_at = ? WHERE id = ? AND email = ?');
        $st->execute([$title, $body, now(), $id, $email]);
        if ($st->rowCount()) return ['id' => $id];
    }
    if (trim($body) === '') return ['id' => 0];
    $st = db()->prepare('SELECT COUNT(*) FROM member_notes WHERE email = ?');
    $st->execute([$email]);
    if ((int) $st->fetchColumn() >= 2000) { http_response_code(400); return ['error' => 'You have 2,000 notes, the most there can be.']; }
    $page = (string) ($in['page'] ?? '');
    if (!preg_match('~^/[^\s]*$~', $page) || strlen($page) > 300) $page = '';
    db()->prepare('INSERT INTO member_notes (email, page_path, page_title, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $page, mb_substr(trim((string) ($in['pageTitle'] ?? '')), 0, 160), $title, $body, now(), now()]);
    return ['id' => (int) db()->lastInsertId()];
}

function member_workings(string $email): array
{
    $st = db()->prepare('SELECT id, title, intentions_json, items_json, notes, created_at FROM workings WHERE email = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$email]);
    return array_map(fn($w) => ['id' => (int) $w['id'], 'title' => $w['title'], 'intents' => json_decode($w['intentions_json'], true) ?: [],
        'items' => json_decode($w['items_json'], true) ?: [], 'notes' => $w['notes'], 'created' => substr($w['created_at'], 0, 10)], $st->fetchAll());
}

/** Saves (or with do=delete, deletes) a working: its intentions (Correspondence slugs) and ticked entries ([grimoire, slug]…). */
function save_member_working(string $email, array $in): array
{
    if (($in['do'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM workings WHERE id = ? AND email = ?')->execute([(int) ($in['id'] ?? 0), $email]);
        return ['deleted' => true];
    }
    $slug = fn($s) => is_string($s) && preg_match('/^[a-z0-9-]{1,80}$/', $s);
    $intents = array_values(array_filter(array_slice((array) json_decode((string) ($in['intents'] ?? '[]'), true), 0, 3), $slug));
    $items = array_values(array_filter(array_slice((array) json_decode((string) ($in['items'] ?? '[]'), true), 0, 80),
        fn($it) => is_array($it) && count($it) === 2 && $slug($it[0]) && $slug($it[1])));
    $st = db()->prepare('SELECT COUNT(*) FROM workings WHERE email = ?');
    $st->execute([$email]);
    if ((int) $st->fetchColumn() >= 300) { http_response_code(400); return ['error' => 'You have 300 saved workings, the most there can be.']; }
    db()->prepare('INSERT INTO workings (email, title, intentions_json, items_json, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([
        $email, trim(mb_substr(preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')), 0, 120)) ?: 'My working',
        json_encode($intents), json_encode($items), trim(mb_substr((string) ($in['notes'] ?? ''), 0, 5000)), now(),
    ]);
    return ['id' => (int) db()->lastInsertId()];
}

// ------------------------------------------------------------------ members' data: copies on request, cleared after access ends

/** How long a member's notes, workings and saved pages are kept after their access ends (see the privacy policy). */
const MEMBER_DATA_DAYS = 90;

/** Everything a member has kept in the Manuscript: their notes, saved workings and saved pages. */
function member_data(string $email): array
{
    $email = strtolower(trim($email));
    $st = db()->prepare('SELECT page_id, created_at FROM bookmarks WHERE email = ? ORDER BY created_at');
    $st->execute([$email]);
    $marks = $st->fetchAll();
    $idx = page_index(array_column($marks, 'page_id'));
    $saved = [];
    foreach ($marks as $b) if (isset($idx[$b['page_id']])) $saved[] = ['title' => $idx[$b['page_id']]['title'], 'url' => $idx[$b['page_id']]['url'], 'saved' => $b['created_at']];
    return ['notes' => member_notes($email), 'workings' => member_workings($email), 'saved' => $saved];
}

/** A member's data as a plain text file anyone can open: for answering "please send me my notes". */
function member_data_text(string $email): string
{
    $d = member_data($email);
    $site = rtrim((string) config('site_url', ''), '/');
    $line = str_repeat('-', 60);
    $out = "Your notes from The Magick Manuscript\n" . $email . "\nExported " . gmdate('F j, Y') . "\n\n";
    $out .= "NOTES (" . count($d['notes']) . ")\n" . $line . "\n";
    foreach (array_reverse($d['notes']) as $n) {
        $out .= "\n" . $n['title'] . "\nWritten " . substr($n['created'], 0, 10) . ($n['pageTitle'] ? ' on ' . $n['pageTitle'] : '')
            . ($n['page'] ? ' (' . $site . $n['page'] . ')' : '') . "\n\n" . $n['body'] . "\n\n" . $line . "\n";
    }
    $out .= "\n\nWORKINGS (" . count($d['workings']) . ")\n" . $line . "\n";
    foreach (array_reverse($d['workings']) as $w) {
        $items = array_map(function ($it) { $p = find_page($it[0], $it[1]); return $p ? $p['title'] : $it[1]; }, $w['items']);
        $out .= "\n" . $w['title'] . "\nSaved " . $w['created'] . "\nFor: " . implode(', ', $w['intents']) . "\nUsing: " . implode(', ', $items)
            . ($w['notes'] !== '' ? "\n\n" . $w['notes'] : '') . "\n\n" . $line . "\n";
    }
    $out .= "\n\nSAVED PAGES (" . count($d['saved']) . ")\n" . $line . "\n";
    foreach ($d['saved'] as $s) $out .= $s['title'] . "  " . $s['url'] . "\n";
    return $out;
}

/** Dashboard → Members → Export: downloads one member's notes, workings and saved pages. */
function dash_member_export(): void
{
    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { not_found(); return; }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="manuscript-notes-' . preg_replace('/[^a-z0-9]+/', '-', $email) . '.txt"');
    header('Cache-Control: private, no-store');
    echo member_data_text($email);
}

/** How much each member has kept, for the dashboard: email => number of notes, workings and saved pages. */
function member_data_counts(): array
{
    $out = [];
    foreach (['member_notes', 'workings', 'bookmarks'] as $t) {
        foreach (db()->query("SELECT email, COUNT(*) FROM $t GROUP BY email")->fetchAll(PDO::FETCH_KEY_PAIR) as $e => $n) $out[$e] = ($out[$e] ?? 0) + (int) $n;
    }
    return $out;
}

/**
 * Clears the notes, workings and saved pages of members whose access ended more than MEMBER_DATA_DAYS ago
 * (a canceled monthly plan, a refund, a lost chargeback, access removed in the dashboard). If they come back
 * before then, everything is still there. Runs at most once a day, on whatever visit comes first; the admins'
 * own data is never touched. Returns how many members were cleared.
 */
function clear_lapsed_member_data(bool $force = false): int
{
    $stamp = config('data_dir') . '/last-cleanup';
    if (!$force && is_file($stamp) && filemtime($stamp) > time() - 86400) return 0;
    @touch($stamp);
    $cutoff = gmdate('Y-m-d H:i:s', time() - MEMBER_DATA_DAYS * 86400);
    $st = db()->prepare("SELECT email FROM members WHERE status != 'active' AND MAX(COALESCE(paid_through, ''), updated_at) < ?");
    $st->execute([$cutoff]);
    $admins = array_map('strtolower', (array) config('admins', []));
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
        if (in_array(strtolower($email), $admins, true)) continue;
        $had = 0;
        foreach (['member_notes', 'workings', 'bookmarks', 'beginner_path'] as $t) {
            $del = db()->prepare("DELETE FROM $t WHERE email = ?");
            $del->execute([$email]);
            $had += $del->rowCount();
        }
        if ($had) { $n++; error_log('[manuscript] cleared the notes of ' . $email . ' (access ended over ' . MEMBER_DATA_DAYS . ' days ago)'); }
    }
    return $n;
}
