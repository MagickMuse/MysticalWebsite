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
    $seen = db()->prepare('INSERT OR IGNORE INTO stripe_events (id, received_at) VALUES (?, ?)');
    $seen->execute([$event['id'], now()]);
    if ($seen->rowCount() === 0) return; // already handled (Stripe retries)

    $obj = $event['data']['object'];
    switch ($event['type']) {
        case 'checkout.session.completed':
            $email = $obj['customer_details']['email'] ?? $obj['customer_email'] ?? null;
            if (!$email) return;
            $plan = $obj['metadata']['plan'] ?? ($obj['mode'] === 'payment' ? 'lifetime' : 'monthly');
            $fields = ['plan' => $plan, 'status' => 'active', 'stripe_customer' => $obj['customer'] ?? null];
            if ($obj['mode'] === 'subscription') {
                $fields['stripe_subscription'] = $obj['subscription'];
                $sub = stripe('GET', 'subscriptions/' . $obj['subscription']);
                $fields['paid_through'] = gmdate('Y-m-d H:i:s', period_end($sub));
            } else {
                $fields['paid_through'] = null;
            }
            upsert_member($email, $fields);
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
