# The Magick Manuscript wiki, shop & dashboard

Everything Gumroad did, on magickmuse.com:

- **magickmuse.com/manuscript**: the members-only Manuscript, moved over from Notion.
- **magickmuse.com/market**: courses, templates and free downloads, with a "library" for each buyer.
- **magickmuse.com/manuscript/dashboard**: private sales and members dashboard (only the `admins` emails).

It runs as a small PHP app inside the existing site. Hostinger already runs PHP, so there is no new
hosting, no database server to set up, and nothing to install. Stripe takes the payments.

- **Reading:** every grimoire, guide page and entry, with Notion's cross-links kept as clickable
  "Connections" (Protection → Hekate → Rosemary…), plus links back from every page that points here.
- **Search** across titles, alternate names and full text, with suggestions as you type and a
  filter by grimoire.
- **Paywall:** lifetime access for $333 through Stripe Checkout (a monthly plan can be switched on
  later in the config). Everyone signs in with an emailed link (no passwords).
- **Shop:** fixed-price and pay-what-you-want products, free downloads that collect emails, files and
  links delivered in the buyer's library, discount codes (made in Stripe → Product catalog → Coupons).
- **Dashboard:** revenue (30 days, this month, all time), active members,
  new and canceled members, sales by product, refunds, email sign-ups, and CSV downloads.
- **Members' tools** (members only), each listed under *Enchanted Tools* beside *Guide Pages* in its grimoire's intro:
  the *Spell Builder* in Rituals (`/manuscript/spell-builder`), the *Planet, Zodiac and Moon Phase Wheels* in Astrology
  (`/manuscript/wheel`), *Today* and the *Calendar* in Timing (`/manuscript/today`), *Draw a Card* in Cards
  (`/manuscript/draw-a-card`: seven face-down cards, one picked turns over, upright or reversed, with its keywords), and
  *Pull a Rune* in Runes (`/manuscript/pull-a-rune`: seven river stones; the one picked turns over to its Elder Futhark rune).
  Today and the Manuscript home also count down to the next sabbat.
- **On every ritual and recipe** (after Quantities, from the Manuscript's own pages only): *Substitutions* (those written
  on an ingredient's page, then herbs, stones and oils sharing the same correspondences in that working and carrying no
  warnings of their own), *Why these ingredients* (each one's correspondences that the working shares), and *Before you
  begin* (standard fire safety when the working uses fire, and each ingredient's own warnings, word for word).
- **Beginner path:** members can start a 30-day email course, one Manuscript page a day (its title, its own opening lines
  word for word, and a link to read it). The days are listed in `manuscript/beginner-path.php` (edit freely). Emails go
  out once a day with the first visit, or set a daily cron job: `php …/public_html/manuscript/cli/beginner-path.php`.
  Members follow along at `/manuscript/path`, and every email has a link to stop them.
- **Notes:** "✎ Notes" at the top left (beside ★ Saved) opens a pop-up note, named after the page it's written on and
  renamable; `/manuscript/notes` lists every note with its first lines, and each opens in the pop-up.
- **One copy of the tools:** they all live in `assets/tools.js`, which runs in the browser on this site and on the
  GitHub Pages preview. `lib/tools.php` only serves their data (`/manuscript/tools/data.json`, members only, rebuilt when
  the content changes) and keeps members' notes and workings. On the preview, notes and workings stay in the browser,
  and `python3 .github/scripts/build-static-tools.py` rebuilds its copy of the data after the pages are regenerated.
- **Importer:** pulls straight from Notion, including images, and can be re-run any time to pick up new pages.

> **The paid content never goes into GitHub.** This repository is public. The wiki's database and
> images live in a `manuscript-data` folder *next to* `public_html` on the server. Only the code is here.

---

## Setting it up

GitHub can upload the site to Hostinger over FTP and run the Notion imports itself
(`.github/workflows/`, switched off until launch). The sections below describe doing it by hand.

## One-time setup by hand (about 30 minutes)

### 1. Deploy the code
Merge this branch (or deploy it however the site is normally deployed). The `manuscript/` folder needs to end up at
`public_html/manuscript/`.

### 2. Create the config file
In hPanel → **File Manager**, go to the folder that *contains* `public_html` (one level up).
Upload `manuscript/config.sample.php` there, rename it to **`manuscript-config.php`**, and fill it in:

| Setting | Where to get it |
|---|---|
| `app_secret` | Any long random text. Over SSH: `php -r "echo bin2hex(random_bytes(32));"` |
| `notion_token` | notion.so/profile/integrations → **New integration** (internal) → copy the secret. Then in Notion open **The Magick Manuscript** → ••• → **Connections** → add the integration. |
| grimoires | Listed in `manuscript/grimoires.php` (not the settings). Uncomment a line to bring that grimoire over. |
| `stripe_secret_key` | Stripe Dashboard → Developers → API keys. Start with the **test** key (`sk_test_…`). |
| `plans` → `price` | Stripe → Product catalog → create "The Magick Manuscript" with a **$333 one-time** price. Copy its `price_…` id. |
| `stripe_webhook_secret` | Stripe → Developers → Webhooks → **Add endpoint** `https://magickmuse.com/manuscript/stripe-webhook`, events `checkout.session.completed`, `invoice.paid`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`, `customer.subscription.updated`, `customer.subscription.deleted`. Copy its `whsec_…` secret. |
| `products` | Already filled in with the four Gumroad products. Replace each `REPLACE-ME` link with the real Notion/course link, and check prices. Files to deliver (PDFs etc.) go on the server in `manuscript-data/products/<product-slug>/`. |
| `mail_from`, `smtp_*` | A Hostinger email account (e.g. hello@magickmuse.com). Host `smtp.hostinger.com`, port `465`, the mailbox's address and password. |
| `admins` | The email(s) that can open the dashboard. Nobody else can see it. |


### 3. Import from Notion
Over SSH (hPanel → Advanced → SSH Access):

```bash
cd ~/domains/magickmuse.com/public_html      # or wherever public_html is
php manuscript/cli/import.php correspondence  # one grimoire first, to check
php manuscript/cli/import.php                 # everything listed in the config
```

The importer is slow on purpose, because Notion allows about 3 requests a second. Expect roughly
**1–2 minutes per 100 entries** the first time. Re-runs only download pages edited since the last run.
If it stops part-way, run it again and it carries on.

No SSH? Add it as a Cron Job in hPanel instead (Advanced → Cron Jobs):
`php /home/USER/domains/magickmuse.com/public_html/manuscript/cli/import.php`. A **weekly** schedule
also keeps the wiki in step while the Manuscript is still being written in Notion.

### 4. Bring Gumroad over (customers + sales history)
In Gumroad, export your sales as CSV, upload it to the server, then:

```bash
php manuscript/cli/gumroad-import.php gumroad-sales.csv --dry-run   # check what it will do
php manuscript/cli/gumroad-import.php gumroad-sales.csv
```

- Past sales appear in the dashboard (marked "Gumroad").
- Lifetime Manuscript buyers get lifetime access. Course and template buyers get them in their library.
- **Monthly Manuscript members** get access until a month after their last Gumroad payment. Card
  subscriptions can't be moved from Gumroad to Stripe, so they need to subscribe again at `/manuscript/join`.
  It's worth emailing them before that date.
- The `gumroad_products` list in the config decides which Gumroad product becomes what. The dry run
  lists anything it didn't recognise.

Everyone signs in at `/manuscript/login` (or `/market/library`) with the email they bought with.

### 5. Test, then go live
1. With Stripe **test** keys, buy each plan using card `4242 4242 4242 4242`. Check you land signed in and can read entries.
2. Swap in the **live** keys, live price ids, and a live webhook secret.
3. Point the site's Gumroad links at the new pages: `…/l/manuscript` → `/manuscript/join`,
   `…/l/witchcraft101` → `/market/witchcraft-101`, `…/l/witchcraft111` → `/market/witchcraft-111`,
   `…/l/blankgrimoire` → `/market/blank-grimoire`, `…/l/freesample` → `/market/crystal-grimoire`,
   the store link → `/market`. Recreate the SEEKER10 code in Stripe (Coupons → promotion code).

---

## Day-to-day

| Task | How |
|---|---|
| New pages written in Notion | Re-run the importer, or let the weekly cron do it |
| Add a whole grimoire | Add its slug and Notion id to `grimoires` in the config, then run `php manuscript/cli/import.php <slug>` |
| Remove pages deleted in Notion | `php manuscript/cli/import.php --prune` (skipped for a grimoire if any page failed to load that run, so a Notion hiccup never deletes pages) |
| Gift access / remove access | Dashboard → Members, or `php manuscript/cli/member.php add|remove email` |
| Send a member their notes | Dashboard → Members → "Export" beside their email downloads a text file of their notes, workings and saved pages to email them |
| Refund someone | In Stripe (Payments → the payment → Refund). The dashboard updates, and a full refund removes their access. |
| Add a product | Add it to `products` in the config. Its page is `/market/<slug>` |
| Old Notion links (Discord, bookmarks) | `magickmuse.com/manuscript/go/<notion-page-id>` redirects to the wiki page |

## What non-members see
The whole Manuscript is members-only. Visitors and signed-out readers only see a "Become a member"
page: no grimoire list, entry titles, previews, search results or images. To open a few pages to
everyone (a sampler, say), list them under `public_pages` in the config.

## Files
```
manuscript/
  index.php          the wiki (pages, search, sign-in, membership checkout)
  lib/               notion importer, shop, dashboard, Stripe, auth, email, page rendering
  lib/tools.php      the tools' data, their pages, and members' notes and workings (the tools are assets/tools.js)
  cli/import.php     Notion → wiki
  cli/gumroad-import.php   Gumroad sales history + customers → here
  cli/member.php     manage members from the command line
  assets/            wiki.css (builds on the site's styles.css) and wiki.js
  config.sample.php  copy to ../manuscript-config.php (outside public_html)
```
