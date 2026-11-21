# Stripe integration: what's left to do

Checkout for The Magick Manuscript ($333 access) and the shop (courses, Notion templates) uses
**Stripe Checkout with the custom payment form** (`ui_mode: form`), shown on our own page in the site's colours.

## Status (live account "The Magick Muse")

Done:
- Fixed **$333** price on The Magick Manuscript: `price_1UcdYXD79698XBfZonGEOVnY` (lookup key `manuscript_access`), set in config.live.php.
- **SEEKER10**: promotion code for a 10% coupon (`FaWP0Gr4`, once, The Magick Manuscript only).
- **$33 a month** price on The Magick Manuscript - Monthly Access: `price_1Ue3oeD79698XBfZezUUsLg4`. Both live prices are
  built into the code (`membership_plans()` in lib/stripe.php), so the Join page offers both with a live key.
- **Webhook** `we_1UcwtxD79698XBfZHD0Mgb1a` → `https://magickmuse.com/manuscript/stripe-webhook`, all 8 events including the two dispute events (added November 21, 2026), API `2026-03-25.dahlia`; its signing secret is in config.live.php.
- **Account review:** approved; card, Apple Pay, Google Pay, Link, Klarna and others are on.
- **Customer portal:** on; monthly members can cancel (at the end of the paid month), change their card and see invoices.
- Stripe Tax: active, head office address set. Products carry tax code `txcd_20060058`.

Blocking live checkout:
- **Tax registrations.** None yet: add one for each place you must collect tax (Settings → Tax → Registrations),
  or Stripe Tax collects nothing.
- **Secret key** in config.live.php (`sk_live_CHANGE-ME`): paste it there yourself, then put the whole file in the
  GitHub secret `MANUSCRIPT_CONFIG_LIVE`.

## Values to Replace

The following values are placeholders and must be updated before going live.

**Files containing placeholders:**
- [manuscript/config.live.php](manuscript/config.live.php) (private, never committed; pasted into the GitHub secret `MANUSCRIPT_CONFIG_LIVE`)
- [manuscript/config.sample.php](manuscript/config.sample.php) (the template it was made from)

| Field | Current Value | What to Set |
|-------|--------------|-------------|
| mode | payment (lifetime access, shop) / subscription (monthly access) | Already correct: "payment" for one-time charges, "subscription" for recurring billing. |
| line_items[].price (Manuscript access, `plans.lifetime.price`) | price_1UcdYXD79698XBfZonGEOVnY (set) | The live Price ID of a **fixed $333** price on The Magick Manuscript product ([Dashboard → Prices](https://dashboard.stripe.com/prices)). In test mode it's `price_1UcdUBDhYa4oIVD7NZpMDlIQ`. A "customer chooses the amount" price does not work with discount codes. |
| line_items[].price_data (shop products) | built from [manuscript/products.php](manuscript/products.php) | Already real: name, price in cents and `tax_code` per product. |
| stripe_secret_key | sk_live_CHANGE-ME | Live secret key, or better a restricted key (`rk_live_…`), from [Dashboard → API keys](https://dashboard.stripe.com/apikeys). |
| stripe_publishable_key | set (pk_live_…) | Already filled in. Must come from the **same** Stripe account as the secret key. |
| stripe_webhook_secret | set | Done: secret of the live webhook endpoint. |

## Configured Parameters

These parameters were configured in Checkout Studio and are already set correctly.

**Files containing these parameters:**
- [manuscript/lib/stripe.php](manuscript/lib/stripe.php) (`open_checkout()`, API version `2026-03-25.dahlia`)
- [manuscript/assets/checkout.js](manuscript/assets/checkout.js) (the form and its appearance)

| Parameter | Value |
|-----------|-------|
| ui_mode | form (when a publishable key is set; otherwise buyers go to Stripe's hosted page) |
| billing_address_collection | auto |
| phone_number_collection | { enabled: false } |
| automatic_tax | { enabled: true } |
| payment_method_collection | always (subscriptions only) |
| submit_type | auto (one-time payments only; Stripe rejects it for subscriptions) |
| name_collection | { individual: { enabled: true, optional: true } } |
| integration_identifier | custom_embedded_web_0001 |
| appearance | theme stripe, labels auto, inputs spaced, burgundy #7a1f2b primary, PT Serif, 16px, 4px radius |

Kept from the existing integration because the site depends on them: `line_items`, `mode`, `return_url`,
`customer_email`, `metadata` (tells the webhook what to unlock), `payment_intent_data`/`subscription_data`
metadata, `customer_creation`, and `allow_promotion_codes`/`discounts` (SEEKER10 and other codes).

## Setup

1. **Stripe Tax.** In Stripe → Settings → Tax: set the head office address, then add a registration for every
   place you must collect tax (for EU buyers, VAT is due from the first sale). Products already carry tax codes.
2. **Keys, all from the same Stripe account.** Test: the test secret key in this environment and the test
   publishable key must belong to the same account (today the secret key is from a sandbox and the publishable
   key from the main account's test mode: they don't match, so the form can't load). Live: fill
   [manuscript/config.live.php](manuscript/config.live.php) and paste it into the GitHub secret `MANUSCRIPT_CONFIG_LIVE`.
3. **Fixed $333 price** on The Magick Manuscript in live mode; put its ID in `plans.lifetime.price`.
4. **SEEKER10**: a coupon plus a promotion code `SEEKER10` (test and live).
5. **Webhook** at `https://magickmuse.com/manuscript/stripe-webhook` with the events
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `invoice.paid`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`,
   `customer.subscription.updated`, `customer.subscription.deleted`. Its signing secret goes in `stripe_webhook_secret`.
6. **Branding** (optional): Stripe → Settings → Branding for the logo on receipts and emails.

No packages to install: the site talks to Stripe's API directly (PHP + curl), and Stripe.js loads from
`https://js.stripe.com/dahlia/stripe.js` on the checkout page only.

## How it works

1. A buyer presses **Become a member** (`/manuscript/join`) or **Buy** on a shop product. The form posts
   (CSRF-protected) to the server, which creates the Checkout Session ([manuscript/lib/stripe.php](manuscript/lib/stripe.php)).
2. The server shows our own **Secure checkout** page with the session's client secret; [checkout.js](manuscript/assets/checkout.js)
   starts `stripe.initCheckoutFormSdk`, mounts the form in `#checkout-form` and confirms the payment.
3. Stripe returns the buyer to `/manuscript/welcome` or `/market/thanks`.
4. The **webhook** (`/manuscript/stripe-webhook`) verifies Stripe's signature and grants access on
   `checkout.session.completed`, or on `checkout.session.async_payment_succeeded` for payments that clear later.
   Refunds remove access. A chargeback pauses access, restores it if won and ends it if lost. Each event is handled once.

The checkout page's security policy (CSP) opens to Stripe only on that page ([manuscript/lib/render.php](manuscript/lib/render.php)).

## Testing

- Use test keys (`sk_test_…`, `pk_test_…`) from the same account. No real money moves.
- Card `4242 4242 4242 4242`, any future date, any CVC. Declined: `4000 0000 0000 0002`.
  3-D Secure: `4000 0027 6000 3184`. More: https://docs.stripe.com/testing
- Try different billing addresses to see tax change (once registrations exist).

## Next steps

- Products: names, prices and tax codes for the shop live in [manuscript/products.php](manuscript/products.php);
  the Manuscript price lives in Stripe.
- Fulfillment and order tracking: already handled by the webhook; sales show in the site dashboard
  (`/manuscript/dashboard/sales`) and in Stripe.
- Monthly plan: switch on by uncommenting `plans.monthly` in the config with a recurring price ID.

## Resources

- https://support.stripe.com
- https://docs.stripe.com/mcp
