# The Magick Manuscript wiki

The members-only home for the Manuscript at **magickmuse.com/manuscript**, moved over from Notion.
It runs as a small PHP app inside the existing site. Hostinger already runs PHP, so there is no new
hosting, no database server to set up, and nothing to install.

- **Reading:** every grimoire, guide page and entry, with Notion's cross-links kept as clickable
  "Connections" (Protection → Hekate → Rosemary…), plus links back from every page that points here.
- **Search** across titles, alternate names and full text.
- **Paywall:** Stripe Checkout for monthly and lifetime plans. Members sign in with an emailed
  link (no passwords). Canceled monthly members keep access until the end of the period they paid for.
- **Importer:** pulls straight from Notion, including images, and can be re-run any time to pick up new pages.
- **Admin page** (`/manuscript/admin`): see members, and give access to existing Gumroad buyers.

> **The paid content never goes into GitHub.** This repository is public. The wiki's database and
> images live in a `manuscript-data` folder *next to* `public_html` on the server. Only the code is here.

---

## One-time setup (about 30 minutes)

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
| `grimoires` | Already filled in for the first ones. Add a line for each grimoire as it moves over. The id is the 32-character code at the end of the grimoire's Notion URL. |
| `stripe_secret_key` | Stripe Dashboard → Developers → API keys. Start with the **test** key (`sk_test_…`). |
| `plans` → `price` | Stripe → Product catalog → create "The Magick Manuscript" with a **monthly recurring** price and a **one-time** price. Copy each `price_…` id. |
| `stripe_webhook_secret` | Stripe → Developers → Webhooks → **Add endpoint** `https://magickmuse.com/manuscript/stripe-webhook`, events `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`. Copy its `whsec_…` secret. |
| `mail_from`, `smtp_*` | A Hostinger email account (e.g. hello@magickmuse.com). Host `smtp.hostinger.com`, port `465`, the mailbox's address and password. |
| `admins` | The email(s) that can open `/manuscript/admin`. |

Also turn on Stripe → Settings → **Customer portal**, so monthly members can cancel or update their card themselves.

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

### 4. Move existing Gumroad members over
Export customers from Gumroad (Audience → Customers → Export) and either:
- paste the emails into `/manuscript/admin` (choose "Lifetime (complimentary / moved from Gumroad)"), or
- `php manuscript/cli/member.php import customers.csv "Moved from Gumroad"`

They can then sign in at `/manuscript/login` with the email they bought with.

### 5. Test, then go live
1. With Stripe **test** keys, buy each plan using card `4242 4242 4242 4242`. Check you land signed in and can read entries.
2. Swap in the **live** keys, live price ids, and a live webhook secret.
3. Point the site's "Get the Manuscript" buttons at `/manuscript/join` instead of Gumroad.

---

## Day-to-day

| Task | How |
|---|---|
| New pages written in Notion | Re-run the importer, or let the weekly cron do it |
| Add a whole grimoire | Add its slug and Notion id to `grimoires` in the config, then run `php manuscript/cli/import.php <slug>` |
| Remove pages deleted in Notion | `php manuscript/cli/import.php --prune` |
| Gift access / remove access | `/manuscript/admin`, or `php manuscript/cli/member.php add|remove email` |
| Old Notion links (Discord, bookmarks) | `magickmuse.com/manuscript/go/<notion-page-id>` redirects to the wiki page |

## What non-members see
The home page, each grimoire's introduction, and the list of entry titles are public, so people can see how much is
inside. Opening any entry or guide shows the first few lines, then a "Become a member" box. Images inside entries
are members-only too. `teaser_chars` in the config sets how much shows (0 = titles only).

## Files
```
manuscript/
  index.php          the wiki (pages, search, sign-in, checkout, admin)
  lib/               notion importer, Stripe, auth, email, page rendering
  cli/import.php     Notion → wiki
  cli/member.php     manage members from the command line
  assets/            wiki.css (builds on the site's styles.css) and wiki.js
  config.sample.php  copy to ../manuscript-config.php (outside public_html)
```
