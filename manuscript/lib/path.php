<?php
declare(strict_types=1);

/*
 * The 30-day beginner path: members choose to start it, then get one Manuscript page a day by email
 * (the days are in manuscript/beginner-path.php). Each email has the page's title, its own opening lines word for
 * word, a button to read it, sometimes a tool to try, and a link to stop the emails. Members can also follow the path
 * at their own pace on /path. The day's emails go out once a day: from a cron job (cli/beginner-path.php) or, without
 * one, with the first visit of the day. A member whose access has ended gets no more emails.
 */

const PATH_TOOLS = [
    'today' => 'Today', 'spell-builder' => 'the Spell Builder', 'draw-a-card' => 'Draw a Card', 'pull-a-rune' => 'Pull a Rune',
    'wheel?of=planets' => 'the Planet Wheel', 'wheel?of=signs' => 'the Zodiac Wheel', 'wheel?of=moon' => 'the Moon Phase Wheel', 'notes' => 'your Notes',
];

/** The path's days that point to a page in the Manuscript: [[page row, try]…]. */
function path_days(): array
{
    static $days = null;
    if ($days !== null) return $days;
    $days = [];
    foreach ((array) require MM_ROOT . '/beginner-path.php' as $d) {
        [$g, $slug] = array_pad(explode('/', (string) ($d['page'] ?? ''), 2), 2, '');
        $p = $slug !== '' ? find_page($g, $slug) : null;
        if ($p) $days[] = [$p, isset(PATH_TOOLS[$d['try'] ?? '']) ? $d['try'] : null];
    }
    return $days;
}

function path_row(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM beginner_path WHERE email = ?');
    $st->execute([strtolower($email)]);
    return $st->fetch() ?: null;
}

/** A page's own opening lines, word for word: from its first paragraphs (not headings or labels), about 140–320 characters. */
function path_excerpt(array $p): string
{
    $html = preg_replace('~<(h[1-6]|summary|figure|table)\b.*?</\1>~si', ' ', (string) $p['body_html']);
    preg_match_all('~<p(?![^>]*class="label")[^>]*>(.*?)</p>~si', $html, $m);
    $out = '';
    foreach ($m[1] as $para) {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($para), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($t) < 40) continue; // a label or a stray line, not a paragraph
        preg_match_all('/[^.!?]+[.!?]+["”’)]?/u', $t, $sm);
        foreach ($sm[0] ?: [$t] as $sentence) {
            if ($out !== '' && mb_strlen($out . $sentence) > 320) break 2;
            $out .= ($out === '' ? '' : ' ') . trim($sentence);
            if (mb_strlen($out) >= 140) break 2;
        }
    }
    // Never stop inside an open bracket: end before it instead.
    if (substr_count($out, '(') > substr_count($out, ')')) $out = rtrim(mb_substr($out, 0, (int) mb_strrpos($out, '(')));
    return mb_strlen($out) > 360 ? rtrim(mb_substr($out, 0, 340)) . '…' : $out;
}

/** Sends day $n (1-based) to a member. */
function send_path_day(array $row, int $n): bool
{
    $days = path_days();
    if (!isset($days[$n - 1])) return false;
    [$p, $try] = $days[$n - 1];
    $total = count($days);
    $link = page_url($p);
    $stop = url('path/stop') . '?t=' . $row['token'];
    $excerpt = path_excerpt($p);
    $tryLine = $try ? 'Try it: ' . PATH_TOOLS[$try] . ' (' . url($try) . ')' : '';
    $text = "Day $n of $total: {$p['title']}\n\n" . ($excerpt !== '' ? "“{$excerpt}”\n\n" : '') . "Keep reading: $link\n"
        . ($tryLine ? "\n$tryLine\n" : '') . "\nYour whole path: " . url('path') . "\n\nStop these emails: $stop\n";
    $c = 'font-family:Georgia,serif;color:#2e1f20';
    $html = '<div style="background:#f6efe8;padding:32px 16px"><div style="max-width:560px;margin:0 auto;background:#fffaf5;border:1px solid #e5d6c8;border-top:3px solid #b08d3c;padding:32px 28px;' . $c . '">'
        . '<p style="margin:0 0 6px;font:600 11px/1.4 Arial,sans-serif;letter-spacing:3px;text-transform:uppercase;color:#7a1f2b">The beginner path · Day ' . $n . ' of ' . $total . '</p>'
        . '<h1 style="margin:0 0 18px;font:400 30px/1.2 Georgia,serif;color:#2e1f20">' . e($p['title']) . '</h1>'
        . ($excerpt !== '' ? '<p style="margin:0 0 22px;font:italic 17px/1.6 Georgia,serif;color:#4a3a3b">“' . e($excerpt) . '”</p>' : '')
        . '<p style="margin:0 0 24px"><a href="' . e($link) . '" style="display:inline-block;background:#7a1f2b;color:#f6efe8;text-decoration:none;font:600 13px Arial,sans-serif;letter-spacing:2px;text-transform:uppercase;padding:14px 22px">Keep reading</a></p>'
        . ($try ? '<p style="margin:0 0 20px;font:15px/1.6 Arial,sans-serif;color:#4a3a3b">Try it: <a href="' . e(url($try)) . '" style="color:#7a1f2b">' . e(PATH_TOOLS[$try]) . '</a></p>' : '')
        . '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e5d6c8;font:13px/1.6 Arial,sans-serif;color:#7d6c6d"><a href="' . e(url('path')) . '" style="color:#7d6c6d">Your whole path</a> · <a href="' . e($stop) . '" style="color:#7d6c6d">Stop these emails</a></p>'
        . '</div></div>';
    return send_mail($row['email'], "Day $n of $total: {$p['title']}", $text, $html);
}

/** Today's emails: the next day for everyone on the path who hasn't had one today. Returns how many went out. */
function run_beginner_path(int $limit = 200): int
{
    $today = gmdate('Y-m-d');
    $total = count(path_days());
    $st = db()->prepare("SELECT * FROM beginner_path WHERE stopped_at IS NULL AND day < ? AND substr(COALESCE(sent_at, ''), 1, 10) < ? ORDER BY sent_at LIMIT ?");
    $st->execute([$total, $today, $limit]);
    $sent = 0;
    foreach ($st->fetchAll() as $row) {
        $m = member_row($row['email']);
        $active = $m && ($m['status'] === 'active' || ($m['paid_through'] && $m['paid_through'] >= now()));
        if (!$active && !is_admin($row['email'])) continue; // no access, no emails (they resume if access comes back)
        $n = (int) $row['day'] + 1;
        if (send_path_day($row, $n)) {
            db()->prepare('UPDATE beginner_path SET day = ?, sent_at = ? WHERE email = ?')->execute([$n, now(), $row['email']]);
            $sent++;
        }
    }
    return $sent;
}

/** Once a day, with the first visit (for sites without a cron job). */
function maybe_run_beginner_path(): void
{
    $stamp = config('data_dir') . '/last-path-run';
    if (is_file($stamp) && gmdate('Y-m-d', (int) filemtime($stamp)) === gmdate('Y-m-d')) return;
    @touch($stamp);
    run_beginner_path(60);
}

/** /path (the whole path), POST /path (start or stop), /path/stop?t=… (stop from an email, no sign-in needed). */
function route_path(array $parts, string $method): void
{
    if (($parts[0] ?? '') === 'stop') {
        $t = (string) ($_GET['t'] ?? '');
        $ok = false;
        if (preg_match('/^[0-9a-f]{32}$/', $t)) {
            $st = db()->prepare('UPDATE beginner_path SET stopped_at = ? WHERE token = ? AND stopped_at IS NULL');
            $st->execute([now(), $t]);
            $ok = true;
        }
        layout('Beginner path', '<section class="mm-hero narrow"><p class="eyebrow labelled">The beginner path</p><h1>' . ($ok ? 'No more <em>emails</em>' : 'That link <em>has expired</em>') . '</h1>'
            . '<p class="lede">' . ($ok ? 'You won’t get any more beginner path emails. You can still follow the path at your own pace in the Manuscript.' : 'Sign in and open the beginner path to stop the emails.') . '</p>'
            . '<div class="actions"><a class="btn" href="' . e(url('path')) . '">The beginner path</a></div></section>', ['private' => true]);
        return;
    }
    $email = current_email();
    if (!$email) redirect(url('login') . '?next=' . rawurlencode((string) parse_url(url('path'), PHP_URL_PATH)));
    if (!has_access()) { view_gate(['locked' => true]); return; }
    $row = path_row($email);
    if ($method === 'POST') {
        check_csrf();
        if (($_POST['do'] ?? '') === 'stop' && $row) {
            db()->prepare('UPDATE beginner_path SET stopped_at = ? WHERE email = ?')->execute([now(), strtolower($email)]);
        } elseif (($_POST['do'] ?? '') === 'start') {
            if (!$row) {
                db()->prepare('INSERT INTO beginner_path (email, started_at, day, token) VALUES (?, ?, 0, ?)')->execute([strtolower($email), now(), bin2hex(random_bytes(16))]);
            } else {
                db()->prepare('UPDATE beginner_path SET stopped_at = NULL WHERE email = ?')->execute([strtolower($email)]);
            }
            $row = path_row($email);
            if ((int) $row['day'] === 0 && send_path_day($row, 1)) { // day one straight away
                db()->prepare('UPDATE beginner_path SET day = 1, sent_at = ? WHERE email = ?')->execute([now(), strtolower($email)]);
            }
        }
        redirect(url('path'));
    }
    view_path($row);
}

function view_path(?array $row): void
{
    record_view('route:/manuscript/path');
    $days = path_days();
    $total = count($days);
    $done = $row ? (int) $row['day'] : 0;
    $csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $list = '';
    foreach ($days as $i => [$p, $try]) {
        $n = $i + 1;
        $list .= '<li class="' . ($n <= $done ? 'done' : ($n === $done + 1 && $row && !$row['stopped_at'] ? 'next' : '')) . '"><span class="path-n">' . $n . '</span>'
            . '<a href="' . e(page_url($p)) . '">' . e($p['title']) . '</a>'
            . ($try ? '<small>Try it: <a href="' . e(url($try)) . '">' . e(PATH_TOOLS[$try]) . '</a></small>' : '') . '</li>';
    }
    $status = !$row ? '<p class="lede">One page of the Manuscript a day for ' . $total . ' days, by email, from the basics to working with deities and spirits. Read along here at your own pace, or have each day sent to you.</p>'
        . '<form method="post" action="' . e(url('path')) . '">' . $csrf . '<input type="hidden" name="do" value="start"><button class="btn" type="submit">Start the emails</button></form>'
        : ($row['stopped_at'] ? '<p class="lede">The emails are stopped. You’re on day ' . $done . ' of ' . $total . '.</p><form method="post" action="' . e(url('path')) . '">' . $csrf . '<input type="hidden" name="do" value="start"><button class="btn" type="submit">Start them again</button></form>'
        : ($done >= $total ? '<p class="lede">You’ve finished the path. Every page is still here whenever you want it.</p>'
        : '<p class="lede">Day ' . $done . ' of ' . $total . '. The next page arrives by email tomorrow.</p><form method="post" action="' . e(url('path')) . '">' . $csrf . '<input type="hidden" name="do" value="stop"><button class="link as-button" type="submit">Stop the emails</button></form>'));
    layout('The beginner path', '<section class="saved path-page"><p class="eyebrow labelled">Your Manuscript</p><h1>The beginner <em>path</em></h1>' . $status
        . '<ol class="path-list">' . $list . '</ol></section>', ['private' => true]);
}

/** On the Manuscript home: an invitation to start, or where the member is up to. */
function path_home_line(): string
{
    $email = current_email();
    if (!$email || !has_access()) return '';
    $row = path_row($email);
    $total = count(path_days());
    if (!$row) return '<p class="path-invite"><span aria-hidden="true">✦</span> New to the Manuscript? <a href="' . e(url('path')) . '">Take the ' . $total . '-day beginner path</a>, one page a day.</p>';
    $done = (int) $row['day'];
    if ($done >= $total) return '';
    $next = path_days()[max(0, $done - 1)][0] ?? null;
    return '<p class="path-invite"><span aria-hidden="true">✦</span> Beginner path: day ' . $done . ' of ' . $total
        . ($next ? ' · <a href="' . e(page_url($next)) . '">' . e($next['title']) . '</a>' : '') . ' · <a href="' . e(url('path')) . '">See the path</a></p>';
}
