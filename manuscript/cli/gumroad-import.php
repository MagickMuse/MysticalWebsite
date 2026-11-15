<?php
/*
 * Brings your Gumroad history over: past sales show up in the dashboard, and everyone who
 * bought something gets the same thing in their library here (the Manuscript, a course…).
 *
 * In Gumroad: Analytics or Sales → Export (CSV). Then:
 *   php manuscript/cli/gumroad-import.php gumroad-sales.csv --dry-run   (shows what would happen)
 *   php manuscript/cli/gumroad-import.php gumroad-sales.csv
 *
 * Safe to run more than once: each Gumroad sale is only recorded once.
 * Monthly Manuscript members from Gumroad get access until a month after their last payment.
 * After that they need to subscribe here, because card subscriptions can't be moved between companies.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/stripe.php';

$file = $argv[1] ?? '';
$dry = in_array('--dry-run', $argv, true);
if (!is_file($file)) { fwrite(STDERR, "Usage: php gumroad-import.php <export.csv> [--dry-run]\n"); exit(1); }

$fh = fopen($file, 'r');
$head = array_map(fn($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($fh) ?: []);
$find = function (array $names) use ($head) {
    foreach ($names as $n) foreach ($head as $i => $h) if ($h === $n) return $i;
    foreach ($names as $n) foreach ($head as $i => $h) if (str_contains($h, $n)) return $i;
    return null;
};
$col = [
    'id' => $find(['purchase id', 'sale id', 'order id', 'id']),
    'email' => $find(['purchase email', 'buyer email', 'customer email', 'email']),
    'item' => $find(['item name', 'product name', 'product title', 'product', 'item']),
    'date' => $find(['sale date', 'purchase date', 'created at', 'date']),
    'time' => $find(['sale time']),
    'price' => $find(['sale price', 'price ($)', 'price', 'amount', 'total']),
    'refunded' => $find(['refunded?', 'refunded', 'fully refunded']),
];
foreach (['email', 'item', 'price', 'date'] as $need) {
    if ($col[$need] === null) { fwrite(STDERR, "Couldn't find a '$need' column. Columns are: " . implode(', ', $head) . "\n"); exit(1); }
}
echo "Columns used: " . json_encode(array_map(fn($i) => $i === null ? null : $head[$i], $col)) . "\n";

$map = config('gumroad_products', []);
$counts = [];
$unknown = [];
$monthly = [];
$n = 0;
while (($row = fgetcsv($fh)) !== false) {
    $email = strtolower(trim($row[$col['email']] ?? ''));
    $item = trim($row[$col['item']] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $item === '') continue;

    $slug = null;
    foreach ($map as $words => $target) {
        if (stripos($item, $words) !== false) { $slug = $target; break; }
    }
    if (!$slug) { $unknown[$item] = ($unknown[$item] ?? 0) + 1; continue; }

    $cents = (int) round((float) preg_replace('/[^0-9.]/', '', (string) ($row[$col['price']] ?? '0')) * 100);
    $when = strtotime(trim(($row[$col['date']] ?? '') . ' ' . ($col['time'] !== null ? ($row[$col['time']] ?? '') : ''))) ?: time();
    $refunded = $col['refunded'] !== null && preg_match('/^(true|yes|1)$/i', trim($row[$col['refunded']] ?? ''));
    $id = 'gumroad:' . ($col['id'] !== null && trim($row[$col['id']] ?? '') !== '' ? trim($row[$col['id']]) : md5($email . $item . $when));
    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
    $n++;
    if ($dry) continue;

    record_payment(['id' => $id, 'email' => $email, 'product' => $slug, 'amount_cents' => $cents,
        'refunded_cents' => $refunded ? $cents : 0, 'source' => 'gumroad', 'created_at' => gmdate('Y-m-d H:i:s', $when)]);
    if ($refunded) continue;

    if ($slug === 'membership-lifetime') {
        upsert_member($email, ['plan' => 'comp', 'status' => 'active', 'paid_through' => null, 'note' => 'Gumroad lifetime']);
    } elseif ($slug === 'membership-monthly') {
        $monthly[$email] = max($monthly[$email] ?? 0, $when);
    } else {
        grant($email, $slug, 'gumroad');
        if (!empty(config('products')[$slug]['free'])) {
            db()->prepare('INSERT OR IGNORE INTO leads (email, product, created_at) VALUES (?, ?, ?)')->execute([$email, $slug, gmdate('Y-m-d H:i:s', $when)]);
        }
    }
}

// Monthly members: access until ~a month after their latest Gumroad payment, then they subscribe here.
foreach ($monthly as $email => $last) {
    $until = gmdate('Y-m-d H:i:s', $last + 32 * 86400);
    $m = member_row($email);
    if ($m && in_array($m['plan'], ['lifetime', 'comp', 'monthly'], true) && $m['status'] === 'active') continue;
    upsert_member($email, ['plan' => null, 'status' => 'canceled', 'paid_through' => $until, 'note' => 'Gumroad monthly, access until ' . substr($until, 0, 10)]);
}

// Date imported members from their first Gumroad purchase, so the dashboard's history is right.
if (!$dry) {
    db()->exec("UPDATE members SET created_at = (SELECT MIN(p.created_at) FROM payments p WHERE p.email = members.email AND p.product LIKE 'membership-%')
        WHERE note LIKE 'Gumroad%' AND EXISTS (SELECT 1 FROM payments p WHERE p.email = members.email AND p.product LIKE 'membership-%' AND p.created_at < members.created_at)");
}

echo ($dry ? "[dry run] would import " : "Imported ") . "$n sales:\n";
foreach ($counts as $slug => $c) echo "  $c × $slug\n";
if ($monthly) echo count($monthly) . " Gumroad monthly members get access until a month after their last payment.\n";
if ($unknown) {
    echo "Skipped (add words from these names to 'gumroad_products' in the config if they should count):\n";
    foreach ($unknown as $item => $c) echo "  $c × $item\n";
}
