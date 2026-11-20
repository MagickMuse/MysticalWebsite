<?php
/*
 * Give or remove access from the command line (Dashboard → Members does the same).
 *
 *   php manuscript/cli/member.php add someone@example.com "Gumroad lifetime"
 *   php manuscript/cli/member.php import gumroad-customers.csv    (any CSV with an email column)
 *   php manuscript/cli/member.php remove someone@example.com
 *   php manuscript/cli/member.php list
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';

[$cmd, $arg, $note] = array_pad(array_slice($argv, 1), 3, null);
switch ($cmd) {
    case 'add':
        upsert_member($arg, ['plan' => 'comp', 'status' => 'active', 'paid_through' => null, 'note' => $note]);
        echo "Added $arg\n";
        break;
    case 'remove':
        upsert_member($arg, ['status' => 'canceled', 'paid_through' => null]);
        echo "Removed access for $arg\n";
        break;
    case 'import':
        $fh = fopen($arg, 'r');
        $head = array_map(fn($h) => strtolower(trim($h)), fgetcsv($fh) ?: []);
        $col = array_search('email', $head);
        if ($col === false) $col = array_search('purchase email', $head);
        if ($col === false) { fwrite(STDERR, "No 'email' column found.\n"); exit(1); }
        $n = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $email = trim($row[$col] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            upsert_member($email, ['plan' => 'comp', 'status' => 'active', 'paid_through' => null, 'note' => $note ?? 'Moved from Gumroad']);
            $n++;
        }
        echo "Imported $n members\n";
        break;
    case 'list':
        foreach (db()->query('SELECT email, plan, status, paid_through FROM members ORDER BY email') as $r) echo implode("\t", $r), "\n";
        break;
    default:
        fwrite(STDERR, "Usage: add <email> [note] | remove <email> | import <file.csv> | list\n");
        exit(1);
}
