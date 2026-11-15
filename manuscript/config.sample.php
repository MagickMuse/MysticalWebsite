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
    // with events: checkout.session.completed, invoice.paid, charge.refunded,
    // customer.subscription.updated, customer.subscription.deleted. Paste its signing secret here.
    'stripe_webhook_secret' => 'whsec_xxx',
    // Price ids (Dashboard → Product catalog). Leave one empty to hide that plan.
    'plans' => [
        'monthly'  => ['price' => 'price_xxx', 'label' => 'Monthly',  'blurb' => 'Full access, cancel anytime', 'mode' => 'subscription'],
        'lifetime' => ['price' => 'price_yyy', 'label' => 'Lifetime', 'blurb' => 'One payment, yours forever',  'mode' => 'payment'],
    ],

    // ---------- Shop (replaces Gumroad) ----------
    'currency' => 'usd',
    // One entry per product. Prices are in cents. Files to deliver go on the server in
    // manuscript-data/products/<product-slug>/ (any PDFs, zips…). "links" appear as buttons in
    // the buyer's library, e.g. a Notion "duplicate" link or a course page.
    'products' => [
        'witchcraft-101' => [
            'name' => 'Witchcraft 101',
            'kind' => 'Course',
            'tagline' => 'A self-paced beginner course in seven units.',
            'description' => "What witchcraft is, tools and altars, correspondences, spiritual hygiene, shadow work, spell preparation, and casting a spell.\n\nWork through it at your own pace. It's yours to keep.",
            'includes' => ['7 units', 'Self-paced', 'Lifetime access'],
            'price_cents' => 1000,
            'image' => 'assets/ritual-grimoire-home-700.webp',
            'links' => ['Open the course' => 'https://www.notion.so/REPLACE-ME'],
        ],
        'witchcraft-111' => [
            'name' => 'Witchcraft 111',
            'kind' => 'Course',
            'tagline' => 'Seven units: divination, astrology, herbalism, ancestors, deities, runes, crystals.',
            'description' => "The next step after Witchcraft 101, in seven self-paced units.",
            'includes' => ['7 units', 'Self-paced', 'Pay what you can'],
            'price_cents' => 2000,
            'pay_what_you_want' => true,
            'image' => 'assets/tarot-grimoire-700.webp',
            'links' => ['Open the course' => 'https://www.notion.so/REPLACE-ME'],
        ],
        'blank-grimoire' => [
            'name' => 'The Blank Grimoire',
            'kind' => 'Notion template',
            'tagline' => "Blank versions of 14 of the Manuscript's grimoires, ready for your own entries.",
            'description' => "Start with the Manuscript's structure and fill it with your own research.",
            'price_cents' => 1000,
            'pay_what_you_want' => true,
            'image' => 'assets/plant-grimoire-home-700.webp',
            'links' => ['Duplicate the template' => 'https://www.notion.so/REPLACE-ME'],
            'instructions' => 'Open the link while signed in to Notion, then click Duplicate (top right).',
        ],
        'crystal-grimoire' => [
            'name' => 'Blank Crystal Grimoire',
            'kind' => 'Free Notion template',
            'tagline' => 'Try the structure before you commit: a blank Crystal grimoire to start filling in today.',
            'description' => 'A free blank Notion template, the same structure the Manuscript uses for crystals.',
            'free' => true,
            'image' => 'assets/crystal-grimoire-700.webp',
            'links' => ['Duplicate the template' => 'https://www.notion.so/REPLACE-ME'],
            'instructions' => 'Open the link while signed in to Notion, then click Duplicate (top right).',
        ],
    ],

    // For importing your Gumroad sales history (cli/gumroad-import.php):
    // words in the Gumroad product name => what it becomes here.
    'gumroad_products' => [
        // Checked top to bottom, first match wins. Run the import with --dry-run first to check.
        'monthly'        => 'membership-monthly',    // e.g. "The Magick Manuscript - Monthly"
        'manuscript'     => 'membership-lifetime',
        'witchcraft 101' => 'witchcraft-101',
        'witchcraft 111' => 'witchcraft-111',
        'blank grimoire' => 'blank-grimoire',
        'crystal'        => 'crystal-grimoire',
    ],

    // ---------- Email (login links) ----------
    'mail_from'      => 'The Magick Muse <hello@magickmuse.com>',
    // Leave smtp_host empty to use PHP mail(). Hostinger email SMTP: smtp.hostinger.com, port 465.
    'smtp_host'      => '',
    'smtp_port'      => 465,
    'smtp_user'      => '',
    'smtp_pass'      => '',

    // Emails that can see the private dashboard ({base_url}/dashboard). Only these.
    'admins' => ['you@example.com'],

    // The whole Manuscript is members-only. To open a few pages to everyone, list them here,
    // as a Notion page id or as "grimoire/page-slug" from the wiki address, e.g.
    //   'public_pages' => ['begin-here/what-is-magick', '71d0d5fb571147849802c3446fbb8c58'],
    'public_pages' => [],
];
