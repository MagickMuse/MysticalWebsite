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
    // The main website's address (for the shop pages, styles and menu links).
    'site_url' => 'https://magickmuse.com',

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
    // The list of grimoires to import is in manuscript/grimoires.php.

    // Notion pages the importer leaves behind (title pattern). Default: the "… Grimoire Guide" pages,
    // which are about using Notion.
    // 'skip_pages' => '/\\bgrimoire guide\\b/i',

    // Toggle sections the importer leaves out, by title (they explain using Notion).
    // 'skip_sections' => ['Notion tips for new readers', 'Using search and comments'],

    // Properties never offered in a grimoire list's Sort & filter (entries still show them). Default: Precautions.
    // 'hidden_filters' => ['Precautions'],

    // Links left out of the Manuscript because they only work inside Notion (pattern). Default: the widgets.
    // 'skip_links' => '~^https?://magickmuse\\.github\\.io/Widgets/~i',

    // ---------- Stripe paywall ----------
    // Dashboard → Developers → API keys. Use test keys (sk_test_...) until you are ready.
    'stripe_secret_key'     => 'sk_test_xxx',
    // Publishable key (pk_test_… / pk_live_…). Set it to show the payment form on our own page; leave it out to send buyers to Stripe's page.
    // 'stripe_publishable_key' => 'pk_test_xxx',
    // Dashboard → Developers → Webhooks → add endpoint {base_url}/stripe-webhook
    // with events: checkout.session.completed, checkout.session.async_payment_succeeded, invoice.paid, charge.refunded, charge.dispute.created, charge.dispute.closed,
    // customer.subscription.updated, customer.subscription.deleted. Paste its signing secret here.
    'stripe_webhook_secret' => 'whsec_xxx',
    // The Manuscript is sold as lifetime access for $333 (Stripe → Product catalog → one-time price).
    'plans' => [
        // amount_label is only what the page shows; the real price is the Stripe price.
        'lifetime' => ['price' => 'price_yyy', 'label' => 'Manuscript access', 'amount_label' => '$333', 'blurb' => 'One payment. Every update ever made, and every one to come.', 'mode' => 'payment'],
        // A monthly plan could be added later, e.g.:
        // 'monthly' => ['price' => 'price_…', 'label' => 'Monthly', 'amount_label' => '$__/month', 'blurb' => 'Full access, cancel anytime.', 'mode' => 'subscription'],
    ],

    // ---------- Shop (replaces Gumroad) ----------
    'currency' => 'usd',
    // Product names, prices and descriptions live in manuscript/products.php (public).
    // The links buyers get in their library are private, so they live here instead:
    'product_links' => [
        'witchcraft-101'   => ['Open the course in Google Classroom' => 'https://classroom.google.com/c/REPLACE-ME'],
        'witchcraft-111'   => ['Open the course in Google Classroom' => 'https://classroom.google.com/c/REPLACE-ME'],
        'blank-grimoire'   => ['Duplicate the template' => 'https://www.notion.so/REPLACE-ME'],
        'crystal-grimoire' => ['Duplicate the template' => 'https://www.notion.so/REPLACE-ME'],
    ],
    // Files to deliver (PDFs, zips…) go on the server in manuscript-data/products/<product-slug>/.

    'discord_url' => 'https://discord.com/invite/PBr2BtCpxk',

    // For importing your Gumroad sales history (cli/gumroad-import.php):
    // words in the Gumroad product name => what it becomes here.
    'gumroad_products' => [
        // Checked top to bottom, first match wins. Run the import with --dry-run first to check.
        'monthly'              => 'membership-monthly',
        'magick manuscript'    => 'membership-lifetime',   // "The Magick Manuscript ✦ 20,000+ Entries…"
        'witchcraft 101'       => 'witchcraft-101',
        'witchcraft 111'       => 'witchcraft-111',
        'blank interconnected' => 'blank-grimoire',        // "Blank Interconnected 14 Grimoires ✧ Notion Template Framework"
        'crystal grimoire'     => 'crystal-grimoire',      // "Free Blank Crystal Grimoire ✧ Notion Starter Database"
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

    // The sneak peek: one page visitors can read in full at {base_url}/sneak-peek. Its links open the join pop-up.
    // Same forms as above; set to '' to turn it off.
    // Archived duplicates in Notion that the importer can't match by name: archived title => 'grimoire/slug' of the live entry.
    // 'duplicates' => ['Coatlicue' => 'entity/coatlique'],
    'sneak_peek' => 'correspondence/protection',
];
