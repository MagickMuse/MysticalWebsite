<?php
declare(strict_types=1);

/*
 * Automated tests for the Manuscript's money and access rules. No extra tools needed:
 *
 *     php tests/run.php
 *
 * Each test gets a fresh, empty database in a temporary folder and a fake Stripe (stripe_fake()), so nothing
 * real is touched and no keys are needed. Exits with 1 if any test fails (the CI runs this on every push).
 */

$tmp = sys_get_temp_dir() . '/mm-tests-' . getmypid();
@mkdir($tmp, 0700, true);
file_put_contents("$tmp/config.php", "<?php return " . var_export([
    'base_url' => 'https://example.test/manuscript',
    'site_url' => 'https://example.test',
    'app_secret' => 'test-secret',
    'admins' => ['admin@example.test'],
    'plans' => ['lifetime' => ['price' => 'price_test', 'mode' => 'payment']],
], true) . ";\n");
putenv("MM_CONFIG=$tmp/config.php");
putenv("MM_DATA_DIR=$tmp/data");

$app = dirname(__DIR__) . '/manuscript/lib';
foreach (['bootstrap', 'auth', 'render', 'mail', 'stripe', 'shop'] as $lib) require "$app/$lib.php";

$tests = [];
$failures = 0;
function test(string $name, callable $body): void { $GLOBALS['tests'][$name] = $body; }
function same($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new Exception(($what ? "$what: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Empties every table, so each test starts from nothing. */
function fresh(): void
{
    foreach (db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        db()->exec("DELETE FROM \"$t\"");
    }
    stripe_fake(fn($method, $path) => throw new Exception("unexpected Stripe call: $method $path"));
}

$eventId = 0;
function event(string $type, array $object): void
{
    handle_stripe_event(['id' => 'evt_test_' . ++$GLOBALS['eventId'], 'type' => $type, 'data' => ['object' => $object]]);
}

function status(string $email): string
{
    $m = member_row($email);
    return ($m['status'] ?? 'none') . (member_has_access($m) ? ' +access' : ' -access');
}

function later(int $days): string { return gmdate('Y-m-d H:i:s', time() + 86400 * $days); }

// ---------------------------------------------------------------- access rules

test('access: who gets in', function () {
    same(false, member_has_access(null), 'no member');
    same(true, member_has_access(['status' => 'active', 'paid_through' => null]), 'active');
    same(true, member_has_access(['status' => 'canceled', 'paid_through' => later(5)]), 'canceled, paid period left');
    same(false, member_has_access(['status' => 'canceled', 'paid_through' => later(-1)]), 'canceled, period over');
    same(false, member_has_access(['status' => 'canceled', 'paid_through' => null]), 'canceled lifetime');
    same(false, member_has_access(['status' => 'disputed', 'paid_through' => later(5)]), 'disputed, even with a paid period');
});

test('sign-in redirects only go to this site', function () {
    same('/manuscript/plant/rosemary/', safe_next('/manuscript/plant/rosemary/'));
    foreach (['https://evil.test/', '//evil.test/', '/\\evil.test', 'javascript:alert(1)', '/a b', ''] as $bad) {
        same('/manuscript', safe_next($bad), $bad);
    }
});

test('e() escapes HTML', function () {
    same('&lt;a href=&quot;x&quot;&gt;&amp;&#039;', e('<a href="x">&\''));
    same('', e(null));
});

// ---------------------------------------------------------------- purchases

test('lifetime checkout makes an active member and records the payment once', function () {
    fresh();
    $session = ['id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid', 'customer' => 'cus_1', 'amount_total' => 33300,
        'payment_intent' => 'pi_1', 'customer_details' => ['email' => 'Buyer@Example.test'], 'metadata' => ['plan' => 'lifetime']];
    event('checkout.session.completed', $session);
    event('checkout.session.completed', $session); // Stripe can send an event twice
    same('active +access', status('buyer@example.test'));
    same(1, (int) db()->query('SELECT COUNT(*) FROM payments')->fetchColumn(), 'payments');
});

test('an unpaid checkout (bank transfer still on its way) gives nothing yet', function () {
    fresh();
    event('checkout.session.completed', ['id' => 'cs_2', 'mode' => 'payment', 'payment_status' => 'unpaid',
        'customer_details' => ['email' => 'slow@example.test'], 'metadata' => []]);
    same('none -access', status('slow@example.test'));
});

test('a shop product is granted, and a full refund takes it back', function () {
    fresh();
    event('checkout.session.completed', ['id' => 'cs_3', 'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 1000,
        'payment_intent' => 'pi_3', 'customer_details' => ['email' => 'p@example.test'], 'metadata' => ['product' => 'witchcraft-101']]);
    $owned = fn() => (int) db()->query("SELECT COUNT(*) FROM purchases WHERE email = 'p@example.test' AND product = 'witchcraft-101'")->fetchColumn();
    same(1, $owned(), 'after purchase');
    event('charge.refunded', ['payment_intent' => 'pi_3', 'amount_refunded' => 1000, 'refunded' => true]);
    same(0, $owned(), 'after refund');
});

test('a full refund ends a lifetime membership', function () {
    fresh();
    upsert_member('l@example.test', ['plan' => 'lifetime', 'status' => 'active']);
    record_payment(['id' => 'cs_4', 'email' => 'l@example.test', 'product' => 'membership-lifetime', 'amount_cents' => 33300, 'intent' => 'pi_4']);
    event('charge.refunded', ['payment_intent' => 'pi_4', 'amount_refunded' => 33300, 'refunded' => true]);
    same('canceled -access', status('l@example.test'));
});

// ---------------------------------------------------------------- monthly plan

test('a canceled subscription keeps access until the paid month ends', function () {
    fresh();
    upsert_member('m@example.test', ['plan' => 'monthly', 'status' => 'active', 'stripe_customer' => 'cus_m', 'stripe_subscription' => 'sub_m']);
    event('customer.subscription.deleted', ['id' => 'sub_m', 'customer' => 'cus_m', 'status' => 'canceled', 'current_period_end' => time() + 86400 * 10]);
    same('canceled +access', status('m@example.test'));
});

test('a monthly invoice without an email is recorded under the member', function () {
    fresh();
    upsert_member('m@example.test', ['plan' => 'monthly', 'status' => 'active', 'stripe_customer' => 'cus_m']);
    event('invoice.paid', ['id' => 'in_1', 'subscription' => 'sub_m', 'customer' => 'cus_m', 'amount_paid' => 1100, 'created' => time()]);
    same('m@example.test', db()->query("SELECT email FROM payments WHERE id = 'in_1'")->fetchColumn());
});

// ---------------------------------------------------------------- chargebacks

test('lifetime chargeback: paused while open, back if won', function () {
    fresh();
    upsert_member('l@example.test', ['plan' => 'lifetime', 'status' => 'active']);
    record_payment(['id' => 'cs_5', 'email' => 'l@example.test', 'product' => 'membership-lifetime', 'amount_cents' => 33300, 'intent' => 'pi_5']);
    event('charge.dispute.created', ['payment_intent' => 'pi_5', 'charge' => 'ch_5']);
    same('disputed -access', status('l@example.test'), 'open');
    event('charge.dispute.closed', ['payment_intent' => 'pi_5', 'charge' => 'ch_5', 'status' => 'won']);
    same('active +access', status('l@example.test'), 'won');
});

test('monthly chargeback: paused, a renewal does not lift it, ends if lost', function () {
    fresh();
    upsert_member('m@example.test', ['plan' => 'monthly', 'status' => 'active', 'stripe_customer' => 'cus_m', 'stripe_subscription' => 'sub_m', 'paid_through' => later(20)]);
    // This invoice was recorded without its payment intent, so the member is found through the charge's customer.
    stripe_fake(fn($method, $path) => $path === 'charges/ch_m' ? ['customer' => 'cus_m'] : throw new Exception("unexpected $path"));
    event('charge.dispute.created', ['payment_intent' => 'pi_unrecorded', 'charge' => 'ch_m']);
    same('disputed -access', status('m@example.test'), 'open');
    event('customer.subscription.updated', ['id' => 'sub_m', 'customer' => 'cus_m', 'status' => 'active', 'current_period_end' => time() + 86400 * 50]);
    same('disputed -access', status('m@example.test'), 'renewal while open');
    event('charge.dispute.closed', ['payment_intent' => 'pi_unrecorded', 'charge' => 'ch_m', 'status' => 'lost']);
    same('canceled -access', status('m@example.test'), 'lost');
});

test('a chargeback from an unknown customer changes nothing', function () {
    fresh();
    stripe_fake(fn() => ['customer' => 'cus_nobody']);
    event('charge.dispute.created', ['payment_intent' => 'pi_x', 'charge' => 'ch_x']);
    same(0, (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn());
});

// ---------------------------------------------------------------- run

foreach ($tests as $name => $body) {
    try {
        $body();
        echo "  ok    $name\n";
    } catch (Throwable $ex) {
        $failures++;
        echo "  FAIL  $name\n        " . $ex->getMessage() . "\n";
    }
}
foreach (glob("$tmp/data/*") ?: [] as $f) @unlink($f);
@rmdir("$tmp/data");
@unlink("$tmp/config.php");
@rmdir($tmp);
echo $failures ? "\n$failures of " . count($tests) . " failed\n" : "\nAll " . count($tests) . " passed\n";
exit($failures ? 1 : 0);
