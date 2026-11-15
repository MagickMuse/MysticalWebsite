> Also available as a styled page: `manuscript/index.html` on this branch.

# Setting up the test site

A private copy of the new Manuscript wiki, shop and dashboard at **test.magickmuse.com**, using Stripe's test mode so no real money moves. The real magickmuse.com isn't touched. About an hour, most of it waiting for the Notion import.

**What you're installing:** the `manuscript-wiki` branch of [MagickMuse/MysticalWebsite](https://github.com/MagickMuse/MysticalWebsite/tree/manuscript-wiki). It's the existing static site plus a PHP app in `manuscript/` (members-only wiki, `/shop`, sign-in by emailed link, Stripe checkout, private dashboard). It needs PHP 8.1+ with SQLite. No database server, Composer or Node.

**Full reference:** [manuscript/README.md](https://github.com/MagickMuse/MysticalWebsite/blob/manuscript-wiki/manuscript/README.md) on the branch. This page is the test-site walkthrough.

**Two rules that matter.**

1.  The data folder (the database of paid pages and the downloaded images) must be **outside every `public_html`**, or it could be downloaded by anyone. The app refuses to start if it isn't, and tells you why.
2.  The repository is **public**. Never commit the settings file, API keys, or delivery links. They only ever live on the server.

## 1. Create the subdomain

*Where: hPanel → Domains → Subdomains*

1.  Create `test` on `magickmuse.com`.
2.  Note the **folder** hPanel gives it. It's usually something like `/home/u123456789/domains/magickmuse.com/public_html/test`. You'll need this path in the steps below; this page calls it `TEST_FOLDER`.
3.  Make sure SSL is on for it (hPanel → Security → SSL), so it opens on `https://test.magickmuse.com`.

## 2. Deploy the branch

*Where: hPanel → Advanced → Git*

1.  Repository: `https://github.com/MagickMuse/MysticalWebsite.git` (public, so no deploy key needed)
2.  Branch: `manuscript-wiki`
3.  Install path: the subdomain's folder, e.g. `public_html/test`. Git deploy needs the folder **empty**, so delete hPanel's default `default.php` or `index.html` first.
4.  Click **Create**, then **Deploy**.

Check: `https://test.magickmuse.com` shows the normal homepage, and `https://test.magickmuse.com/manuscript/` says "The Manuscript is not configured yet". That's expected at this point.

*No Git option on the plan?* On GitHub switch to the `manuscript-wiki` branch → Code → Download ZIP, then upload and extract it into the subdomain folder with File Manager. Upload the files *inside* the zip's top folder, not the folder itself.

## 3. PHP version

*Where: hPanel → Advanced → PHP Configuration*

Choose **PHP 8.2 or newer**. Under PHP extensions, make sure `pdo_sqlite`, `sqlite3`, `curl` and `mbstring` are ticked (they normally are). `intl` is nice to have, for tidier page addresses from accented titles.

## 4. Private data folder

*Where: hPanel → Files → File Manager*

Create a folder **outside** `public_html`, next to it, for example:

    /home/u123456789/domains/magickmuse.com/manuscript-data-test

Copy its full path (File Manager shows it at the top). This is `DATA_FOLDER`. Keep the test site's data separate from the real site's later `manuscript-data`, so testing never mixes with real members.

## 5. Notion key

1.  Go to [notion.so/profile/integrations](https://www.notion.so/profile/integrations) → **New integration**. Type: Internal, workspace: the Manuscript's, name: "Manuscript wiki". Capabilities: **Read content** only.
2.  Copy the **Internal Integration Secret** (starts with `ntn_`).
3.  In Notion, open **The Magick Manuscript** page → **•••** (top right) → **Connections** → add "Manuscript wiki". This gives it read access to everything underneath.

## 6. Stripe in test mode

*Where: dashboard.stripe.com → turn on **Test mode** (toggle at the top) for everything below*

1.  **API key:** Developers → API keys → copy the **Secret key** (`sk_test_…`).
2.  **Manuscript prices:** Product catalog → Add product "The Magick Manuscript".
    - Price 1: **\$333, one-time** → copy its id (`price_…`). This is lifetime access, as it was on Gumroad.
    - Optional price 2: a **monthly recurring** price, only if Avelynn wants a monthly plan. Otherwise leave monthly out of the settings and only Lifetime shows.

    Shop products (courses, templates) need nothing in Stripe; their prices come from `manuscript/products.php`.
3.  **Webhook:** Developers → Webhooks → Add endpoint
    - URL: `https://test.magickmuse.com/manuscript/stripe-webhook`
    - Events: `checkout.session.completed`, `invoice.paid`, `charge.refunded`, `customer.subscription.updated`, `customer.subscription.deleted`
    - Copy the endpoint's **Signing secret** (`whsec_…`).
4.  **Customer portal:** Settings → Billing → Customer portal → activate (lets monthly members cancel or change cards themselves).
5.  **SEEKER10:** Product catalog → Coupons → new coupon "10% off" → add a promotion code `SEEKER10`.

## 7. Email for sign-in links

*Where: hPanel → Emails*

Nobody has a password; people sign in from a link that's emailed to them, so email has to work. Create a mailbox such as `hello@magickmuse.com` (or use an existing one) and note its password. Hostinger's mail server is `smtp.hostinger.com`, port `465`.

## 8. The settings file

*Where: File Manager → `TEST_FOLDER/manuscript/`*

1.  Copy `config.sample.php` to **`config.local.php`** in the same folder. (The app blocks this file from ever being downloaded, and Git deploys leave it alone because it's ignored.)
2.  Edit `config.local.php` and set:

| Setting                 | Test-site value                                                                                                                         |
|-------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `base_url`              | `https://test.magickmuse.com/manuscript`                                                                                                |
| `site_url`              | `https://test.magickmuse.com`                                                                                                           |
| `data_dir`              | Uncomment it and use `DATA_FOLDER` from step 4. **Required** for the test site.                                                         |
| `app_secret`            | Any long random text, at least 40 characters. Over SSH: `php -r "echo bin2hex(random_bytes(32));"`                                      |
| `notion_token`          | The `ntn_…` secret from step 5                                                                                                          |
| `grimoires`             | Leave as is: Begin Here, Correspondence and Timing to start                                                                             |
| `stripe_secret_key`     | `sk_test_…`                                                                                                                             |
| `stripe_webhook_secret` | `whsec_…`                                                                                                                               |
| `plans`                 | Put the lifetime `price_…` id in `lifetime`. For no monthly plan, set the monthly `'price' => ''`.                                      |
| `product_links`         | The real Google Classroom links and Notion "duplicate" links for each product (Avelynn has these). They show only in buyers' libraries. |
| `mail_from`, `smtp_*`   | The mailbox from step 7: host `smtp.hostinger.com`, port `465`, its address and password                                                |
| `admins`                | Avelynn's email, plus yours if you want dashboard access. Only these emails can see the dashboard.                                      |

Check: `https://test.magickmuse.com/manuscript/` now shows the "Members only" page. If it shows a "Setup problem" message instead, the data folder is inside `public_html`; fix `data_dir`.

## 9. Import from Notion

*Where: hPanel → Advanced → SSH Access, then connect with the details shown there*

    cd TEST_FOLDER        # e.g. cd ~/domains/magickmuse.com/public_html/test
    php manuscript/cli/import.php begin-here correspondence

It prints progress as it goes. Notion only allows about 3 requests a second, so expect roughly **1–2 minutes per 100 entries** the first time. If it stops (closed laptop, dropped connection), just run the same command again; it carries on and skips what's already done.

To bring in more grimoires, add each one to `grimoires` in the settings (its id is the 32-character code at the end of its Notion address) and run `php manuscript/cli/import.php` with its name.

*No SSH on the plan?* hPanel → Advanced → Cron Jobs → a custom job running `php TEST_FOLDER/manuscript/cli/import.php` once, a few minutes from now. It's safe for it to overlap with itself, because a second copy exits straight away.

## 10. Try everything

Stripe test card: `4242 4242 4242 4242`, any future date, any CVC and postcode. Tick each one as it works:

- [ ] Signed out, `/manuscript/`, a grimoire, an entry and search all show only "Members only". No text, titles or images.
- [ ] Avelynn signs in at `/manuscript/login` with her admin email, the link arrives, and `/manuscript/dashboard` opens.
- [ ] With another email, `/manuscript/join` → Lifetime → test card → back on the site, signed in, entries readable, cross-links ("Connections") work.
- [ ] That sale appears on the dashboard.
- [ ] `/manuscript/join?code=SEEKER10` shows "Code SEEKER10 will be applied" and Stripe shows 10% off.
- [ ] `/shop/witchcraft-101` → buy → it appears in `/shop/library` with its course link.
- [ ] `/shop/crystal-grimoire` → enter an email → the email arrives with a library link, and the address shows under Dashboard → Email sign-ups.
- [ ] Refund the Witchcraft 101 test payment in Stripe → it disappears from that buyer's library and shows as refunded on the dashboard.
- [ ] Search as a member: typing "prot" suggests Protection. Results can be filtered by grimoire.
- [ ] Optional: Gumroad export → `php manuscript/cli/gumroad-import.php sales.csv --dry-run` lists every product it recognised.

If something fails: the error log is in hPanel → Advanced → Error Logs (lines start with `[manuscript]`), and Stripe shows each webhook delivery and its response under Developers → Webhooks.

## 11. Updating & going live

### Getting new changes onto the test site

hPanel → Advanced → Git → **Deploy** (or turn on auto-deploy). Settings and data are untouched.

### Going live, once everything above works

1.  Merge `manuscript-wiki` into `main` and deploy magickmuse.com as usual. The site's buttons switch from Gumroad to `/manuscript/join` and `/shop` at that moment, so do the next steps straight away.
2.  Put a settings file at `public_html/manuscript/config.local.php` for the real site: `https://magickmuse.com` addresses, its own `data_dir` (e.g. `…/manuscript-data`), a new `app_secret`, and Stripe **live** keys, live price ids and a live webhook pointing at `https://magickmuse.com/manuscript/stripe-webhook`. Recreate SEEKER10 in live mode.
3.  Run the import for the real site, then the Gumroad import (README step 4), so existing buyers keep their access.
4.  Add a weekly Cron Job running the importer, so new Notion pages appear on the wiki.
5.  Delete the test subdomain, its Git deployment, and `manuscript-data-test`.

Questions about the code: it's all in `manuscript/` on the branch, with comments.
