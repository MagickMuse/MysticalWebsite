<?php
declare(strict_types=1);

/** Minimal Stripe API client: no SDK needed, works on any PHP host with curl. */
function stripe(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => config('stripe_secret_key') . ':',
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $res, true) ?: [];
    if ($code >= 400 || !$res) {
        throw new RuntimeException('Stripe error: ' . ($data['error']['message'] ?? "HTTP $code"));
    }
    return $data;
}

function create_checkout(string $planKey, ?string $email): string
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
    return stripe('POST', 'checkout/sessions', $params)['url'];
}

/** Checkout for a shop product. $amountCents is only used for pay-what-you-want products. */
function create_product_checkout(string $slug, ?int $amountCents, ?string $email): string
{
    $p = config('products')[$slug] ?? null;
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
                'product_data' => ['name' => $p['name']],
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
    return stripe('POST', 'checkout/sessions', $params)['url'];
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
