<?php
declare(strict_types=1);

/*
 * Private dashboard for the curator (emails listed in config "admins").
 * Sales and members, from Stripe webhooks plus any history imported from Gumroad.
 */

function route_dashboard(array $parts, string $method): void
{
    if (!is_admin()) {
        current_email() ? not_found() : view_login('<p class="notice">Sign in to see your dashboard.</p>', '/' . trim((string) parse_url(url('dashboard'), PHP_URL_PATH), '/'));
        return;
    }
    $tab = $parts[0] ?? '';
    match (true) {
        $tab === '' => dash_overview(),
        $tab === 'members' && $method === 'POST' => dash_members_post(),
        $tab === 'members' && isset($_GET['export']) => dash_member_export(),
        $tab === 'members' => dash_members(),
        $tab === 'sales' => dash_sales(),
        $tab === 'emails' => dash_leads(),
        $tab === 'pages' => dash_pages(),
        $tab === 'export' => dash_export($parts[1] ?? ''),
        default => not_found(),
    };
}

function dash_layout(string $active, string $title, string $body): void
{
    $tabs = '';
    foreach (['' => 'Overview', 'pages' => 'Pages', 'sales' => 'Sales', 'members' => 'Members', 'emails' => 'Email sign-ups'] as $k => $label) {
        $cur = $k === $active ? ' aria-current="page"' : '';
        $tabs .= '<li><a href="' . e(url('dashboard' . ($k ? "/$k" : ''))) . "\"$cur>" . e($label) . '</a></li>';
    }
    layout($title . ' · Dashboard', '<section class="dash"><header class="dash-head"><p class="eyebrow labelled">Curator’s dashboard</p><h1>' . $title . '</h1>'
        . '<nav class="dash-tabs" aria-label="Dashboard"><ul>' . $tabs . '</ul></nav></header>' . $body . '</section>', ['private' => true]);
}

function cur(): string { return config('currency', 'usd'); }

/** Net revenue (after refunds) in cents between two UTC datetimes. */
function revenue(string $from, string $to, ?string $kind = null): int
{
    $sql = 'SELECT COALESCE(SUM(amount_cents - refunded_cents), 0) FROM payments WHERE created_at >= ? AND created_at < ?';
    if ($kind === 'membership') $sql .= " AND product LIKE 'membership-%'";
    if ($kind === 'shop') $sql .= " AND product NOT LIKE 'membership-%'";
    $st = db()->prepare($sql);
    $st->execute([$from, $to]);
    return (int) $st->fetchColumn();
}

function product_name(string $slug): string
{
    return match ($slug) {
        'membership-monthly' => 'Manuscript · monthly',
        'membership-lifetime' => 'Manuscript access',
        default => ($pr = product($slug)) ? short_name($pr) : ucwords(str_replace('-', ' ', $slug)),
    };
}

function stat_tile(string $label, string $value, string $note = ''): string
{
    return '<div class="tile"><p class="tile-label">' . e($label) . '</p><p class="tile-value">' . $value . '</p>' . ($note ? '<p class="tile-note">' . $note . '</p>' : '') . '</div>';
}

function delta(int $now, int $before): string
{
    if ($before === 0) return $now > 0 ? 'new this period' : 'no change';
    $pct = (int) round(($now - $before) / $before * 100);
    $arrow = $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '■');
    return '<span class="delta">' . $arrow . ' ' . abs($pct) . '%</span> vs previous 30 days';
}

// ------------------------------------------------------------------ overview

function dash_overview(): void
{
    $now = now();
    $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $d60 = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
    $month = gmdate('Y-m-01 00:00:00');

    $rev30 = revenue($d30, $now);
    $revPrev = revenue($d60, $d30);
    $revMonth = revenue($month, $now);
    $revAll = revenue('0000', '9999');

    $members = db()->query("SELECT plan, COUNT(*) n FROM members WHERE status = 'active' OR (paid_through IS NOT NULL AND paid_through >= " . db()->quote($now) . ") GROUP BY plan")->fetchAll(PDO::FETCH_KEY_PAIR);
    $activeTotal = array_sum($members);
    // Monthly recurring revenue: each active monthly member's latest subscription payment.
    $mrr = (int) db()->query("SELECT COALESCE(SUM(amt), 0) FROM (
            SELECT (SELECT amount_cents FROM payments p WHERE p.email = m.email AND p.product = 'membership-monthly' ORDER BY created_at DESC LIMIT 1) AS amt
            FROM members m WHERE m.plan = 'monthly' AND m.status = 'active')")->fetchColumn();
    $q = fn(string $sql, array $args) => (function () use ($sql, $args) { $st = db()->prepare($sql); $st->execute($args); return (int) $st->fetchColumn(); })();
    $new30 = $q('SELECT COUNT(*) FROM members WHERE created_at >= ?', [$d30]);
    $newPrev = $q('SELECT COUNT(*) FROM members WHERE created_at >= ? AND created_at < ?', [$d60, $d30]);
    $cancel30 = $q("SELECT COUNT(*) FROM members WHERE status = 'canceled' AND updated_at >= ?", [$d30]);
    $leads = $q('SELECT COUNT(DISTINCT email) FROM leads', []);
    $leads30 = $q('SELECT COUNT(DISTINCT email) FROM leads WHERE created_at >= ?', [$d30]);

    $breakdown = implode(' · ', array_filter([
        isset($members['monthly']) ? $members['monthly'] . ' monthly' : '',
        isset($members['lifetime']) ? $members['lifetime'] . ' paid' : '',
        isset($members['comp']) ? $members['comp'] . ' gifted / from Gumroad' : '',
        isset($members['']) ? $members[''] . ' former monthly (until paid date)' : '',
    ])) ?: 'none yet';

    $tiles = '<div class="tiles">'
        . stat_tile('Revenue, last 30 days', e(money($rev30, cur())), delta($rev30, $revPrev))
        . stat_tile('This month so far', e(money($revMonth, cur())), e(gmdate('F')))
        . ($mrr > 0 || !empty(config('plans')['monthly']['price']) ? stat_tile('Monthly recurring revenue', e(money($mrr, cur())), 'from active monthly members') : '')
        . stat_tile('Active members', number_format($activeTotal), e($breakdown))
        . stat_tile('New members, 30 days', number_format($new30), delta($new30, $newPrev) . ($cancel30 ? ' · ' . $cancel30 . ' canceled' : ''))
        . stat_tile('Email sign-ups', number_format($leads), number_format($leads30) . ' in the last 30 days')
        . stat_tile('All-time revenue', e(money($revAll, cur())), 'after refunds, including Gumroad history')
        . '</div>';

    // Last 12 months
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $start = gmdate('Y-m-01', strtotime(gmdate('Y-m-01') . " -$i months"));
        $end = gmdate('Y-m-01', strtotime("$start +1 month"));
        $months[] = [
            'label' => gmdate('M', strtotime($start)),
            'long' => gmdate('F Y', strtotime($start)),
            'membership' => revenue("$start 00:00:00", "$end 00:00:00", 'membership'),
            'shop' => revenue("$start 00:00:00", "$end 00:00:00", 'shop'),
            'members' => $q('SELECT COUNT(*) FROM members WHERE created_at >= ? AND created_at < ?', ["$start 00:00:00", "$end 00:00:00"]),
        ];
    }

    // Top products, last 90 days
    $st = db()->prepare('SELECT product, COUNT(*) n, SUM(amount_cents - refunded_cents) cents FROM payments WHERE created_at >= ? GROUP BY product ORDER BY cents DESC');
    $st->execute([gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    $rows = '';
    foreach ($st->fetchAll() as $r) {
        $rows .= '<tr><td>' . e(product_name($r['product'])) . '</td><td class="num">' . (int) $r['n'] . '</td><td class="num">' . e(money((int) $r['cents'], cur())) . '</td></tr>';
    }
    $top = '<div class="dash-card"><h2>By product <small>last 90 days</small></h2>'
        . ($rows ? '<div class="table-wrap"><table><tr><th>Product</th><th class="num">Sales</th><th class="num">Revenue</th></tr>' . $rows . '</table></div>' : '<p class="small">No sales yet.</p>') . '</div>';

    $recent = dash_sales_table(8);
    $popular = popular_pages(7, 5);
    $popularCard = '<div class="dash-card"><h2>Most read <small>last 7 days</small></h2>'
        . ($popular ? page_rank_list($popular, false) : '<p class="small">No reading yet this week.</p>')
        . '<p><a class="small" href="' . e(url('dashboard/pages')) . '">All pages</a></p></div>';

    dash_layout('', 'Overview', $tiles
        . '<div class="dash-grid">'
        . '<div class="dash-card wide"><h2>Revenue by month</h2>' . revenue_chart($months) . '</div>'
        . '<div class="dash-card"><h2>New members by month</h2>' . members_chart($months) . '</div>'
        . $top
        . $popularCard
        . '<div class="dash-card wide"><h2>Latest sales <a class="small" href="' . e(url('dashboard/sales')) . '">See all</a></h2>' . $recent . '</div>'
        . '</div>');
}

/** Stacked monthly bars: Manuscript memberships + shop. Server-rendered SVG, hover tips via wiki.js. */
function revenue_chart(array $months): string
{
    $W = 720; $H = 260; $padL = 56; $padB = 28; $padT = 12; $padR = 8;
    $max = max(1, ...array_map(fn($m) => $m['membership'] + $m['shop'], $months));
    $step = nice_step($max / 100);
    $top = (int) round(ceil(($max / 100) / $step) * $step * 100);
    $plotH = $H - $padB - $padT;
    $slot = ($W - $padL - $padR) / count($months);
    $bw = min(36, $slot * 0.62);
    $y = fn(float $c) => $padT + $plotH - $c / $top * $plotH;

    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Revenue by month for the last 12 months, Manuscript membership and shop">';
    for ($v = 0; $v <= $top; $v += $step * 100) {
        $yy = round($y($v), 1);
        $svg .= '<line class="grid" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="axis" x="' . ($padL - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . e(money((int) round($v), cur())) . '</text>';
    }
    foreach ($months as $i => $m) {
        $cx = $padL + $slot * $i + $slot / 2;
        $x = round($cx - $bw / 2, 1);
        $base = $y(0);
        $hM = $base - $y($m['membership']);
        $hS = $base - $y($m['shop']);
        $gap = ($hM > 0 && $hS > 0) ? 2 : 0;
        // Membership on the baseline, shop stacked above it with a 2px gap; the top segment gets the rounded end.
        $topIsShop = $hS > 0;
        if ($hM > 0) $svg .= bar_path($x, $base - $hM, $bw, $hM, !$topIsShop, 'series-1');
        if ($hS > 0) $svg .= bar_path($x, $base - $hM - $gap - $hS, $bw, $hS, true, 'series-2');
        $tip = $m['long'] . '|Manuscript: ' . money($m['membership'], cur()) . '|Shop: ' . money($m['shop'], cur()) . '|Total: ' . money($m['membership'] + $m['shop'], cur());
        $svg .= '<rect class="hit" x="' . round($padL + $slot * $i, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $plotH . '" data-tip="' . e($tip) . '"/>';
        $svg .= '<text class="axis" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($m['label']) . '</text>';
    }
    $svg .= '<line class="baseline" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $y(0) . '" y2="' . $y(0) . '"/></svg>';

    $legend = '<ul class="legend"><li><span class="key series-1"></span>Manuscript membership</li><li><span class="key series-2"></span>Shop (courses, templates)</li></ul>';
    $table = '<details class="chart-table"><summary>Show as table</summary><div class="table-wrap"><table><tr><th>Month</th><th class="num">Manuscript</th><th class="num">Shop</th><th class="num">Total</th></tr>';
    foreach ($months as $m) {
        $table .= '<tr><td>' . e($m['long']) . '</td><td class="num">' . e(money($m['membership'], cur())) . '</td><td class="num">' . e(money($m['shop'], cur())) . '</td><td class="num">' . e(money($m['membership'] + $m['shop'], cur())) . '</td></tr>';
    }
    return $legend . '<div class="chart-wrap">' . $svg . '</div>' . $table . '</table></div></details>';
}

function members_chart(array $months): string
{
    $W = 360; $H = 260; $padL = 32; $padB = 28; $padT = 12; $padR = 4;
    $max = max(1, ...array_column($months, 'members'));
    $step = nice_step((float) $max);
    $top = (int) (ceil($max / $step) * $step);
    $plotH = $H - $padB - $padT;
    $slot = ($W - $padL - $padR) / count($months);
    $bw = min(18, $slot * 0.6);
    $y = fn(float $v) => $padT + $plotH - $v / $top * $plotH;
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="New members by month for the last 12 months">';
    for ($v = 0; $v <= $top; $v += $step) {
        $yy = round($y($v), 1);
        $svg .= '<line class="grid" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="axis" x="' . ($padL - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $v . '</text>';
    }
    foreach ($months as $i => $m) {
        $cx = $padL + $slot * $i + $slot / 2;
        $h = $y(0) - $y($m['members']);
        if ($h > 0) $svg .= bar_path(round($cx - $bw / 2, 1), $y(0) - $h, $bw, $h, true, 'series-1');
        $svg .= '<rect class="hit" x="' . round($padL + $slot * $i, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $plotH . '" data-tip="' . e($m['long'] . '|New members: ' . $m['members']) . '"/>';
        if ($i % 2 === 1) $svg .= '<text class="axis" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($m['label']) . '</text>';
    }
    $svg .= '<line class="baseline" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $y(0) . '" y2="' . $y(0) . '"/></svg>';
    $table = '<details class="chart-table"><summary>Show as table</summary><div class="table-wrap"><table><tr><th>Month</th><th class="num">New members</th></tr>';
    foreach ($months as $m) $table .= '<tr><td>' . e($m['long']) . '</td><td class="num">' . (int) $m['members'] . '</td></tr>';
    return '<div class="chart-wrap">' . $svg . '</div>' . $table . '</table></div></details>';
}

/** A bar with 4px rounded corners on its top end only (anchored flat on the baseline). */
function bar_path(float $x, float $y, float $w, float $h, bool $round, string $class): string
{
    $r = $round ? min(4, $h, $w / 2) : 0;
    $x2 = $x + $w; $yb = $y + $h;
    $d = $r > 0
        ? "M$x,$yb V" . ($y + $r) . " Q$x,$y " . ($x + $r) . ",$y H" . ($x2 - $r) . " Q$x2,$y $x2," . ($y + $r) . " V$yb Z"
        : "M$x,$yb V$y H$x2 V$yb Z";
    return '<path class="bar ' . $class . '" d="' . $d . '"/>';
}

function nice_step(float $max): float
{
    if ($max <= 0) return 1;
    $raw = $max / 4;
    $mag = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($raw <= $m * $mag) return max(1, $m * $mag);
    return 10 * $mag;
}

// ------------------------------------------------------------------ sales

function dash_sales_table(int $limit, int $offset = 0): string
{
    $st = db()->prepare('SELECT * FROM payments ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $st->execute([$limit, $offset]);
    $rows = '';
    foreach ($st->fetchAll() as $p) {
        $ref = $p['refunded_cents'] > 0 ? ' <span class="tag">refunded ' . e(money((int) $p['refunded_cents'], $p['currency'])) . '</span>' : '';
        $ref .= match ($p['dispute'] ?? null) {
            'open' => ' <span class="tag">chargeback open</span>',
            'lost' => ' <span class="tag">chargeback lost</span>',
            'won' => ' <span class="tag">chargeback won</span>',
            default => '',
        };
        $src = $p['source'] === 'gumroad' ? ' <span class="tag">Gumroad</span>' : '';
        $rows .= '<tr><td>' . e(substr($p['created_at'], 0, 10)) . '</td><td>' . e($p['email']) . '</td><td>' . e(product_name($p['product'])) . $src . '</td><td class="num">' . e(money((int) $p['amount_cents'], $p['currency'])) . $ref . '</td></tr>';
    }
    return $rows ? '<div class="table-wrap"><table><tr><th>Date</th><th>Customer</th><th>Product</th><th class="num">Amount</th></tr>' . $rows . '</table></div>' : '<p class="small">No sales yet.</p>';
}

function dash_sales(): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) db()->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    $pages = max(1, (int) ceil($total / 100));
    $nav = $pages > 1 ? '<p class="small">Page ' . $page . ' of ' . $pages
        . ($page > 1 ? ' · <a href="?page=' . ($page - 1) . '">Newer</a>' : '') . ($page < $pages ? ' · <a href="?page=' . ($page + 1) . '">Older</a>' : '') . '</p>' : '';
    dash_layout('sales', 'Sales', '<p class="dash-actions"><a class="btn ghost" href="' . e(url('dashboard/export/sales.csv')) . '">Download CSV</a> <span class="small">' . number_format($total) . ' sales. Stripe sends a receipt for every sale, and refunds are made from the Stripe dashboard.</span></p>'
        . '<div class="dash-card wide">' . dash_sales_table(100, ($page - 1) * 100) . $nav . '</div>');
}

// ------------------------------------------------------------------ members

function dash_members(string $notice = ''): void
{
    $filter = (string) ($_GET['q'] ?? '');
    $sql = 'SELECT m.*, (SELECT COALESCE(SUM(amount_cents - refunded_cents), 0) FROM payments p WHERE p.email = m.email) AS spent FROM members m';
    $args = [];
    if ($filter !== '') { $sql .= ' WHERE m.email LIKE ?'; $args[] = '%' . $filter . '%'; }
    $st = db()->prepare($sql . ' ORDER BY m.created_at DESC LIMIT 500');
    $st->execute($args);
    $tr = '';
    $kept = member_data_counts();
    foreach ($st->fetchAll() as $r) {
        $plan = ['monthly' => 'Monthly', 'lifetime' => 'Paid', 'comp' => 'Gifted / Gumroad'][$r['plan']] ?? '—';
        $status = $r['status'] === 'active' ? 'Active' : ucfirst(str_replace('_', ' ', $r['status'])) . ($r['paid_through'] && $r['paid_through'] >= now() ? ' (access until ' . substr($r['paid_through'], 0, 10) . ')' : '');
        $tr .= '<tr><td>' . e($r['email']) . '</td><td>' . e($plan) . '</td><td>' . e($status) . '</td><td class="num">' . e(money((int) $r['spent'], cur())) . '</td><td>' . e(substr($r['created_at'], 0, 10)) . '</td><td>' . e($r['note']) . '</td>'
            . '<td>' . (!empty($kept[$r['email']]) ? '<a href="' . e(url('dashboard/members') . '?export=1&email=' . rawurlencode($r['email'])) . '">Export (' . $kept[$r['email']] . ')</a>' : '') . '</td></tr>';
    }
    $csrf = e(csrf_token());
    $action = e(url('dashboard/members'));
    $qv = e($filter);
    $export = e(url('dashboard/export/members.csv'));
    $productOptions = '';
    foreach (products() as $slug => $p) {
        $productOptions .= '<option value="product:' . e($slug) . '">' . e(short_name($p)) . '</option>';
    }
    dash_layout('members', 'Members', $notice . <<<HTML
<div class="dash-grid">
<div class="dash-card wide">
  <form class="dash-filter" method="get" action="$action"><label class="sr-only" for="mq">Find a member</label><input id="mq" name="q" type="search" value="$qv" placeholder="Find by email"><button class="btn ghost" type="submit">Find</button></form>
  <div class="table-wrap"><table><tr><th>Email</th><th>Plan</th><th>Status</th><th class="num">Spent</th><th>Joined</th><th>Note</th><th>Their notes</th></tr>$tr</table></div>
  <p class="dash-actions"><a class="btn ghost" href="$export">Download CSV</a></p>
  <p class="small">“Export” downloads that member’s notes, saved workings and saved pages as a text file to send them. Members whose access ended more than 90 days ago have theirs cleared automatically.</p>
</div>
<div class="dash-card">
  <h2>Give or remove access</h2>
  <form class="mm-form" method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <label for="emails">Emails, one per line</label>
    <textarea id="emails" name="emails" rows="4" required></textarea>
    <label for="what">Access</label>
    <select id="what" name="what">
      <option value="comp">Manuscript access (gift / moved from Gumroad)</option>
      $productOptions
      <option value="revoke">Remove Manuscript access</option>
    </select>
    <input name="note" placeholder="Note (optional)" aria-label="Note">
    <button class="btn" type="submit">Save</button>
  </form>
</div>
</div>
HTML);
}

function dash_members_post(): void
{
    check_csrf();
    $what = (string) ($_POST['what'] ?? '');
    $n = 0;
    foreach (preg_split('/[\s,;]+/', (string) ($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if ($what === 'revoke') {
            upsert_member($email, ['status' => 'canceled', 'paid_through' => null]);
        } elseif ($what === 'comp') {
            upsert_member($email, ['plan' => 'comp', 'status' => 'active', 'paid_through' => null, 'note' => mb_substr((string) ($_POST['note'] ?? ''), 0, 200)]);
        } elseif (str_starts_with($what, 'product:') && product(substr($what, 8))) {
            grant($email, substr($what, 8), 'gift');
        } else {
            continue;
        }
        $n++;
    }
    dash_members('<p class="notice">Updated ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . '.</p>');
}

// ------------------------------------------------------------------ leads + export

function dash_leads(): void
{
    $rows = db()->query('SELECT email, GROUP_CONCAT(product, \', \') products, MIN(created_at) first FROM leads GROUP BY email ORDER BY first DESC LIMIT 500')->fetchAll();
    $tr = '';
    foreach ($rows as $r) {
        $names = implode(', ', array_map('product_name', explode(', ', $r['products'])));
        $tr .= '<tr><td>' . e($r['email']) . '</td><td>' . e($names) . '</td><td>' . e(substr($r['first'], 0, 10)) . '</td></tr>';
    }
    dash_layout('emails', 'Email sign-ups', '<p class="dash-actions"><a class="btn ghost" href="' . e(url('dashboard/export/emails.csv')) . '">Download CSV</a> <span class="small">People who picked up a free download. Import the CSV into your email tool to write to them.</span></p>'
        . '<div class="dash-card wide">' . ($tr ? '<div class="table-wrap"><table><tr><th>Email</th><th>Downloaded</th><th>First seen</th></tr>' . $tr . '</table></div>' : '<p class="small">No sign-ups yet.</p>') . '</div>');
}

function dash_export(string $file): void
{
    [$sql, $name] = match ($file) {
        'sales.csv' => ['SELECT created_at, email, product, amount_cents / 100.0 AS amount, refunded_cents / 100.0 AS refunded, currency, source, id FROM payments ORDER BY created_at DESC', 'sales'],
        'members.csv' => ['SELECT email, plan, status, paid_through, created_at, note FROM members ORDER BY created_at DESC', 'members'],
        'emails.csv' => ['SELECT email, product, created_at FROM leads ORDER BY created_at DESC', 'email-signups'],
        default => [null, null],
    };
    if (!$sql) { not_found(); return; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="magick-muse-' . $name . '-' . gmdate('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    $first = true;
    foreach (db()->query($sql, PDO::FETCH_ASSOC) as $row) {
        if ($first) { fputcsv($out, array_keys($row)); $first = false; }
        // Stop spreadsheet apps from treating a cell as a formula.
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row));
    }
}

// ------------------------------------------------------------------ page views

/** Most-viewed pages over the last $days days (0 = all time). */
function popular_pages(int $days, int $limit, bool $manuscriptOnly = true): array
{
    $since = $days ? gmdate('Y-m-d', time() - ($days - 1) * 86400) : '0000-00-00';
    $where = $manuscriptOnly ? "AND v.page_id NOT LIKE 'route:%'" : "AND v.page_id LIKE 'route:%'";
    $st = db()->prepare("SELECT v.page_id, SUM(v.views) views,
            (SELECT COUNT(DISTINCT r.reader) FROM page_readers r WHERE r.page_id = v.page_id AND r.day >= :since_r) readers,
            (SELECT COUNT(*) FROM bookmarks b WHERE b.page_id = v.page_id) saves
        FROM page_views v WHERE v.day >= :since $where
        GROUP BY v.page_id ORDER BY views DESC LIMIT " . (int) $limit);
    $st->execute(['since' => $since, 'since_r' => $since]);
    return $st->fetchAll();
}

function plural(int $n, string $word): string { return $n === 1 ? $word : $word . 's'; }

function route_label(string $id): string
{
    $r = substr($id, 6);
    return match (true) {
        $r === 'members-only' => 'Members-only screen (shown to visitors)',
        $r === '/manuscript/' => 'Manuscript home (visitors)',
        $r === '/manuscript/join' => 'Join page',
        $r === '/shop' => 'Shop',
        str_starts_with($r, '/shop/') => 'Shop: ' . (($pr = product(substr($r, 6))) ? short_name($pr) : substr($r, 6)),
        default => $r,
    };
}

/** Ranked list with a bar for each page's views (bars drawn as SVG: the page's style rules allow no inline styles). */
function page_rank_list(array $rows, bool $full): string
{
    if (!$rows) return '<p class="small">No views in this period yet.</p>';
    $idx = page_index(array_filter(array_column($rows, 'page_id'), fn($id) => !str_starts_with($id, 'route:')));
    $max = max(1, ...array_map(fn($r) => (int) $r['views'], $rows));
    $out = '<ol class="rank">';
    foreach ($rows as $i => $r) {
        $p = $idx[$r['page_id']] ?? null;
        if (str_starts_with($r['page_id'], 'route:')) {
            $name = e(route_label($r['page_id'])); $where = '';
        } elseif ($p) {
            $g = grimoire_root($p['grimoire']);
            $name = '<a href="' . e($p['url']) . '">' . icon_html($p['icon'], 'chip-icon') . e($p['title']) . '</a>';
            $where = $p['kind'] === 'grimoire' ? '' : e($g['title'] ?? '');
        } else {
            $name = '<span class="small">(page no longer in the Manuscript)</span>'; $where = '';
        }
        $w = round((int) $r['views'] / $max * 100, 1);
        $out .= '<li><span class="rank-n">' . ($i + 1) . '</span><div class="rank-main"><div class="rank-name">' . $name
            . ($where ? '<small>' . $where . '</small>' : '') . '</div>'
            . '<svg class="rank-bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect class="track" width="100" height="6" rx="3"/><rect class="fill" width="' . $w . '" height="6" rx="3"/></svg></div>'
            . '<span class="num rank-v"><span><strong>' . number_format((int) $r['views']) . '</strong> ' . plural((int) $r['views'], 'view') . '</span>'
            . ($full ? '<small>' . number_format((int) $r['readers']) . ' ' . plural((int) $r['readers'], 'reader') . ' · ' . number_format((int) $r['saves']) . ' saved</small>' : '') . '</span></li>';
    }
    return $out . '</ol>';
}

function dash_pages(): void
{
    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 365, 0], true)) $days = 30;
    $since = $days ? gmdate('Y-m-d', time() - ($days - 1) * 86400) : '0000-00-00';
    $q = function (string $sql) use ($since) { $st = db()->prepare($sql); $st->execute([$since]); return (int) $st->fetchColumn(); };
    $views = $q("SELECT COALESCE(SUM(views), 0) FROM page_views WHERE day >= ? AND page_id NOT LIKE 'route:%'");
    $readers = $q("SELECT COUNT(DISTINCT reader) FROM page_readers WHERE day >= ? AND page_id NOT LIKE 'route:%'");
    $pagesRead = $q("SELECT COUNT(DISTINCT page_id) FROM page_views WHERE day >= ? AND page_id NOT LIKE 'route:%'");
    $saves = (int) db()->query('SELECT COUNT(*) FROM bookmarks')->fetchColumn();

    $chips = '<nav class="filters" aria-label="Period">';
    foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 365 => 'Last year', 0 => 'All time'] as $d => $label) {
        $chips .= '<a class="chip" href="' . e(url('dashboard/pages?days=' . $d)) . '"' . ($d === $days ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    }
    $chips .= '</nav>';
    $tiles = '<div class="tiles">'
        . stat_tile('Manuscript page views', number_format($views))
        . stat_tile('Different readers', number_format($readers), 'members who opened at least one page')
        . stat_tile('Pages read', number_format($pagesRead), 'different pages opened')
        . stat_tile('Pages saved', number_format($saves), 'stars across all members, all time')
        . '</div>';

    // Most saved, all time
    $saved = db()->query("SELECT page_id, 0 views, 0 readers, COUNT(*) saves FROM bookmarks GROUP BY page_id ORDER BY saves DESC LIMIT 10")->fetchAll();
    $savedList = '';
    if ($saved) {
        $idx = page_index(array_column($saved, 'page_id'));
        $savedList = '<ol class="rank plain">';
        foreach ($saved as $i => $r) {
            if (!($p = $idx[$r['page_id']] ?? null)) continue;
            $savedList .= '<li><span class="rank-n">' . ($i + 1) . '</span><div class="rank-main"><div class="rank-name"><a href="' . e($p['url']) . '">' . icon_html($p['icon'], 'chip-icon') . e($p['title']) . '</a></div></div><span class="num rank-v"><span><strong>' . (int) $r['saves'] . '</strong> ★</span></span></li>';
        }
        $savedList .= '</ol>';
    }
    dash_layout('pages', 'Pages', $chips . $tiles . '<div class="dash-grid">'
        . '<div class="dash-card wide"><h2>Most read <small>Manuscript pages</small></h2>' . page_rank_list(popular_pages($days, 50), true) . '</div>'
        . '<div class="dash-card"><h2>Most saved <small>all time</small></h2>' . ($savedList ?: '<p class="small">Nothing saved yet.</p>') . '</div>'
        . '<div class="dash-card wide"><h2>Join &amp; shop pages <small>visits, including visitors</small></h2>' . page_rank_list(popular_pages($days, 20, false), false) . '</div>'
        . '</div><p class="small">Your own visits and search engines aren\'t counted. "Readers" counts different members; it never shows who read what.</p>');
}
