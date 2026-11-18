<?php
declare(strict_types=1);

/** Minimal Stripe API client: no SDK needed, works on any PHP host with curl. */
function stripe(string $method, string $path, array $params = [], string $version = '2024-06-20'): array
{
    // One key for every try, so a POST retried after a network hiccup can't create a second checkout or portal session.
    $headers = ['Stripe-Version: ' . $version];
    if ($method === 'POST') $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    for ($attempt = 1; ; $attempt++) {
        $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => config('stripe_secret_key') . ':',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (($code === 0 || $code === 429 || $code >= 500) && $attempt < 3) { usleep(400000 * $attempt); continue; }
        $data = json_decode((string) $res, true) ?: [];
        if ($code >= 400 || !$res) {
            throw new RuntimeException('Stripe error: ' . ($data['error']['message'] ?? "HTTP $code"));
        }
        return $data;
    }
}

const MANAGED_PAYMENTS_API_VERSION = '2026-02-25.preview';

/**
 * Creates the Checkout Session. With a publishable key set, the payment form is embedded in our own page
 * (ui_mode embedded: Stripe returns a client_secret and comes back to return_url); without one, buyers go to
 * Stripe's hosted page (url). Either way fulfillment happens in the webhook.
 */
function open_checkout(array $params): array
{
    if (config('stripe_publishable_key')) {
        $params['ui_mode'] = 'embedded';
        $params['return_url'] = $params['success_url'];
        unset($params['success_url'], $params['cancel_url']);
    }
    // Managed Payments: Stripe is the seller of record and handles sales tax/VAT, fraud and order support.
    // It needs this API version (or later) and a tax code on every product.
    $version = '2024-06-20';
    if (config('managed_payments', false)) {
        $params['managed_payments'] = ['enabled' => 'true'];
        $version = MANAGED_PAYMENTS_API_VERSION;
    }
    $s = stripe('POST', 'checkout/sessions', $params, $version);
    return ['url' => $s['url'] ?? null, 'client_secret' => $s['client_secret'] ?? null];
}

function create_checkout(string $planKey, ?string $email, string $code = ''): array
{
    $plan = config('plans')[$planKey] ?? null;
    if (!$plan || empty($plan['price'])) throw new InvalidArgumentException('Unknown plan');
    $params = [
        'mode' => $plan['mode'],
        'line_items' => [['price' => $plan['price'], 'quantity' => 1]],
        'success_url' => url('welcome?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => url('join'),
        'allow_promotion_codes' => 'true',
        'metadata' => ['plan' => $planKey],
    ];
    if ($plan['mode'] === 'payment') $params['customer_creation'] = 'always';
    if ($plan['mode'] === 'subscription') $params['subscription_data'] = ['metadata' => ['plan' => $planKey]];
    if ($email) $params['customer_email'] = $email;
    return open_checkout(apply_code($params, $code));
}

/** A discount code from a link (e.g. ?code=SEEKER10) is applied for the buyer, if it exists in Stripe. */
function apply_code(array $params, string $code): array
{
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', $code);
    if ($code === '') return $params;
    try {
        $found = stripe('GET', 'promotion_codes', ['code' => $code, 'active' => 'true', 'limit' => 1])['data'][0]['id'] ?? null;
    } catch (Throwable $ex) {
        $found = null;
    }
    if ($found) {
        unset($params['allow_promotion_codes']); // Stripe allows one or the other
        $params['discounts'] = [['promotion_code' => $found]];
    }
    return $params;
}

/** Checkout for a shop product. $amountCents is only used for pay-what-you-want products. */
function create_product_checkout(string $slug, ?int $amountCents, ?string $email, string $code = ''): array
{
    $p = products()[$slug] ?? null;
    if (!$p || !empty($p['free'])) throw new InvalidArgumentException('Unknown product');
    $min = (int) ($p['price_cents'] ?? 0);
    $amount = !empty($p['pay_what_you_want']) ? max($min, (int) $amountCents) : $min;
    if ($amount < 50) throw new InvalidArgumentException('Price too low');
    $params = [
        'mode' => 'payment',
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => config('currency', 'usd'),
                'unit_amount' => $amount,
                'product_data' => ['name' => $p['short_name'] ?? $p['name']] + (!empty($p['tax_code']) ? ['tax_code' => $p['tax_code']] : []),
            ],
        ]],
        'success_url' => site_url('shop/thanks?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => site_url('shop/' . $slug),
        'allow_promotion_codes' => 'true',
        'customer_creation' => 'always',
        'metadata' => ['product' => $slug],
        'payment_intent_data' => ['metadata' => ['product' => $slug]],
    ];
    if ($email) $params['customer_email'] = $email;
    return open_checkout(apply_code($params, $code));
}

/** Our own page holding Stripe's embedded payment form, or a redirect to Stripe's hosted page. */
function show_checkout(array $session, string $title, string $back): void
{
    if (empty($session['client_secret'])) { redirect((string) $session['url']); return; }
    layout('Checkout', '<section class="pay"><p class="eyebrow labelled">Secure checkout</p><h1>' . e($title) . '</h1>'
        . '<div class="pay-frame"><div id="checkout" data-pk="' . e((string) config('stripe_publishable_key')) . '" data-secret="' . e($session['client_secret']) . '">'
        . '<p class="pay-loading">Opening the secure payment form…</p></div></div>'
        . '<noscript><p class="lede">The payment form needs JavaScript. Please turn it on and reload this page.</p></noscript>'
        . '<p class="small pay-note">Payments are processed by Stripe; your card details never touch this site. <a class="link" href="' . e($back) . '">Go back</a></p></section>',
        ['private' => true, 'stripe' => true]);
}

function record_payment(array $row): void
{
    $row += ['currency' => 'usd', 'refunded_cents' => 0, 'source' => 'stripe', 'intent' => null, 'created_at' => now()];
    $row['email'] = strtolower(trim($row['email']));
    $cols = array_keys($row);
    db()->prepare('INSERT OR IGNORE INTO payments (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ":$c", $cols)) . ')')
        ->execute($row);
}

/** Verifies the Stripe-Signature header (v1 scheme) and returns the event. */
function verify_webhook(string $payload, string $header): ?array
{
    $secret = (string) config('stripe_webhook_secret');
    $parts = [];
    foreach (explode(',', $header) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k][] = $v;
    }
    $t = (int) ($parts['t'][0] ?? 0);
    if (!$t || abs(time() - $t) > 300) return null;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) {
        if (hash_equals($expected, $sig)) return json_decode($payload, true);
    }
    return null;
}

function handle_stripe_event(array $event): void
{
    // Mark-as-seen and the work happen together: if anything fails, nothing is saved,
    // the webhook answers with an error, and Stripe retries the event later.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $seen = $pdo->prepare('INSERT OR IGNORE INTO stripe_events (id, received_at) VALUES (?, ?)');
        $seen->execute([$event['id'], now()]);
        if ($seen->rowCount() > 0) apply_stripe_event($event); // otherwise already handled
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function apply_stripe_event(array $event): void
{
    $obj = $event['data']['object'];
    switch ($event['type']) {
        case 'checkout.session.completed':
        // Bank transfers and some wallets finish later: Stripe sends this when the money actually arrives.
        case 'checkout.session.async_payment_succeeded':
            if (!in_array($obj['payment_status'] ?? 'paid', ['paid', 'no_payment_required'], true)) return;
            $email = $obj['customer_details']['email'] ?? $obj['customer_email'] ?? null;
            if (!$email) return;
            $when = gmdate('Y-m-d H:i:s', (int) ($obj['created'] ?? time()));

            // Shop product (course, template…)
            if (!empty($obj['metadata']['product'])) {
                $slug = $obj['metadata']['product'];
                grant($email, $slug, 'stripe');
                record_payment(['id' => $obj['id'], 'email' => $email, 'product' => $slug, 'amount_cents' => (int) ($obj['amount_total'] ?? 0),
                    'currency' => $obj['currency'] ?? 'usd', 'intent' => $obj['payment_intent'] ?? null, 'created_at' => $when]);
                return;
            }

            // Manuscript membership
            $plan = $obj['metadata']['plan'] ?? ($obj['mode'] === 'payment' ? 'lifetime' : 'monthly');
            $fields = ['plan' => $plan, 'status' => 'active', 'stripe_customer' => $obj['customer'] ?? null];
            if ($obj['mode'] === 'subscription') {
                $fields['stripe_subscription'] = $obj['subscription'];
                $sub = stripe('GET', 'subscriptions/' . $obj['subscription']);
                $fields['paid_through'] = gmdate('Y-m-d H:i:s', period_end($sub));
                // Subscription money is recorded from invoice.paid (first payment and every renewal).
            } else {
                $fields['paid_through'] = null;
                record_payment(['id' => $obj['id'], 'email' => $email, 'product' => 'membership-' . $plan, 'amount_cents' => (int) ($obj['amount_total'] ?? 0),
                    'currency' => $obj['currency'] ?? 'usd', 'intent' => $obj['payment_intent'] ?? null, 'created_at' => $when]);
            }
            upsert_member($email, $fields);
            break;

        case 'invoice.paid':
            if (empty($obj['subscription']) && empty($obj['parent']['subscription_details']['subscription'])) return;
            if ((int) ($obj['amount_paid'] ?? 0) <= 0) return;
            record_payment(['id' => $obj['id'], 'email' => $obj['customer_email'] ?? '', 'product' => 'membership-monthly',
                'amount_cents' => (int) $obj['amount_paid'], 'currency' => $obj['currency'] ?? 'usd',
                'intent' => is_string($obj['payment_intent'] ?? null) ? $obj['payment_intent'] : null,
                'created_at' => gmdate('Y-m-d H:i:s', (int) ($obj['status_transitions']['paid_at'] ?? $obj['created'] ?? time()))]);
            break;

        case 'charge.refunded':
            $intent = $obj['payment_intent'] ?? null;
            if (!$intent) return;
            db()->prepare('UPDATE payments SET refunded_cents = ? WHERE intent = ?')->execute([(int) $obj['amount_refunded'], $intent]);
            // A full refund of a one-off purchase also removes what was bought.
            if (!empty($obj['refunded'])) {
                $st = db()->prepare('SELECT email, product FROM payments WHERE intent = ?');
                $st->execute([$intent]);
                if ($pay = $st->fetch()) {
                    if ($pay['product'] === 'membership-lifetime') {
                        upsert_member($pay['email'], ['status' => 'canceled', 'paid_through' => null]);
                    } elseif (!str_starts_with($pay['product'], 'membership-')) {
                        db()->prepare('DELETE FROM purchases WHERE email = ? AND product = ?')->execute([$pay['email'], $pay['product']]);
                    }
                }
            }
            break;

        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $st = db()->prepare('SELECT email FROM members WHERE stripe_customer = ? OR stripe_subscription = ?');
            $st->execute([$obj['customer'], $obj['id']]);
            $email = $st->fetchColumn();
            if (!$email) {
                $cust = stripe('GET', 'customers/' . $obj['customer']);
                $email = $cust['email'] ?? null;
            }
            if (!$email) return;
            $status = match ($obj['status']) {
                'active', 'trialing' => 'active',
                'past_due', 'unpaid', 'incomplete' => 'past_due',
                default => 'canceled',
            };
            if ($event['type'] === 'customer.subscription.deleted') $status = 'canceled';
            upsert_member($email, [
                'plan' => 'monthly',
                'status' => $status,
                'stripe_customer' => $obj['customer'],
                'stripe_subscription' => $obj['id'],
                'paid_through' => gmdate('Y-m-d H:i:s', period_end($obj)),
            ]);
            break;
    }
}

/** Newer Stripe API versions moved current_period_end onto the subscription items. */
function period_end(array $sub): int
{
    return (int) ($sub['current_period_end']
        ?? $sub['items']['data'][0]['current_period_end']
        ?? $sub['ended_at']
        ?? time());
}

function billing_portal_url(string $customer): string
{
    return stripe('POST', 'billing_portal/sessions', ['customer' => $customer, 'return_url' => url('account')])['url'];
}
