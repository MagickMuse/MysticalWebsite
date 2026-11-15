<?php
/*
 * The Magick Manuscript wiki: configuration.
 *
 * Copy this file to ONE of these places and fill it in:
 *   1. One folder ABOVE public_html, named manuscript-config.php   (best: never web-reachable)
 *   2. manuscript/config.local.php                                   (blocked by .htaccess and .gitignore)
 *
 * Never commit the filled-in copy: the GitHub repo is public.
 */
return [
    // Public URL of the wiki, no trailing slash.
    'base_url' => 'https://magickmuse.com/manuscript',

    // Where the database and downloaded images live. Keep this OUTSIDE public_html
    // so the paid content can never be downloaded directly and Git deploys never touch it.
    // Leave this commented out to use the default: a folder named manuscript-data
    // next to public_html (e.g. /home/u123456789/domains/magickmuse.com/manuscript-data).
    // 'data_dir' => '/full/path/to/manuscript-data',

    // Long random string used to sign login links. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_secret' => 'CHANGE-ME',

    // ---------- Notion import ----------
    // Create an internal integration at https://www.notion.so/profile/integrations,
    // then share "The Magick Manuscript" page with it (••• menu → Connections).
    'notion_token' => 'ntn_xxx',
    // Each grimoire's landing page in Notion. The importer pulls its guide pages,
    // its databases, and every entry inside them.
    'grimoires' => [
        // slug => Notion page id
        'begin-here'     => '2a516dcf7164804bb461c1a91571d60d',
        'correspondence' => '28216dcf716480e3918dfab86e4c0b1c',
        'timing'         => '28216dcf71648045b6e8cabf80242cb5',
        // 'plant'        => '28216dcf71648075856dd4f77f6fce89',
        // 'astrology'    => '28216dcf716480f9a3ced0b72e30a2a9',
        // 'animal'       => '28216dcf7164801cad82efa4a4e25424',
        // 'recipe'       => '28216dcf7164803eb959d824f8b44fb3',
        // 'card'         => '28216dcf716480299d34f1ca3f2e7b79',
        // add the rest here as you move them over
    ],

    // ---------- Stripe paywall ----------
    // Dashboard → Developers → API keys. Use test keys (sk_test_...) until you are ready.
    'stripe_secret_key'     => 'sk_test_xxx',
    // Dashboard → Developers → Webhooks → add endpoint {base_url}/stripe-webhook
    // with events: checkout.session.completed, customer.subscription.updated,
    // customer.subscription.deleted. Paste its signing secret here.
    'stripe_webhook_secret' => 'whsec_xxx',
    // Price ids (Dashboard → Product catalog). Leave one empty to hide that plan.
    'plans' => [
        'monthly'  => ['price' => 'price_xxx', 'label' => 'Monthly',  'blurb' => 'Full access, cancel anytime', 'mode' => 'subscription'],
        'lifetime' => ['price' => 'price_yyy', 'label' => 'Lifetime', 'blurb' => 'One payment, yours forever',  'mode' => 'payment'],
    ],

    // ---------- Email (login links) ----------
    'mail_from'      => 'The Magick Muse <hello@magickmuse.com>',
    // Leave smtp_host empty to use PHP mail(). Hostinger email SMTP: smtp.hostinger.com, port 465.
    'smtp_host'      => '',
    'smtp_port'      => 465,
    'smtp_user'      => '',
    'smtp_pass'      => '',

    // Emails that can see the admin page ({base_url}/admin).
    'admins' => ['you@example.com'],

    // Non-members see the first N characters of an entry before the paywall (0 = titles only).
    'teaser_chars' => 320,
];
