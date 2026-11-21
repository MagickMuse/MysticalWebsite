# The Magick Muse website

magickmuse.com: the public website (home, Mastery, Muses, Mythos, the blog) and **The Magick Manuscript**, a
members-only digital grimoire with a shop, built as a small PHP app. It runs on ordinary shared hosting
(Hostinger) with no framework, database server or build step.

## What's where

| Path | What it is |
| --- | --- |
| `*.html`, `blog/` | The website's pages, written by hand. Their header and footer come from `partials/`. |
| `partials/` | The site header and footer, and the top of `llms.txt` (what the site is, for AI assistants). Edit here; the page build (below) does the rest. |
| `styles.css` | The one stylesheet for the website and the Manuscript. A contents guide is at the top. |
| `site.js`, `prefs.js`, `cookie-consent.js` | The website's scripts: menu and blog search, display settings, cookie consent. |
| `manuscript/` | The Manuscript app. `index.php` routes every request; the code is in `manuscript/lib/`. See `manuscript/README.md`. |
| `manuscript/lib/` | `pages` (reading), `search`, `account` (joining, sign-in, Stripe webhook), `auth`, `stripe`, `shop`, `dashboard`, `notion` (the importer), `render` (page layout), and the members' tools. |
| `manuscript/assets/` | The Manuscript's scripts: `wiki.js` (bookshelf, search), `tools.js` (members' tools), `checkout.js` (payment form). |
| `manuscript/*/`, `market/` | A static copy of the Manuscript for the GitHub Pages preview. Not uploaded to Hostinger. |
| `tests/` | Automated tests for payments and access. |
| `.github/workflows/` | Checks on every push; uploads to Hostinger and Notion imports, run by hand. |

Private settings (Stripe, email, the app secret) and the Manuscript's database are never in this repository:
they live on the server, outside the public website folder.

## Making changes

Edit the header or footer in `partials/`, or any page, stylesheet or script, and push. That's all: GitHub runs
`.github/scripts/build-pages.py` (the **Build pages** workflow), which writes the shared header and footer into every
page and stamps each stylesheet and script link with a fingerprint of the file, then saves the result to the same
branch. The upload to Hostinger runs it too. The Manuscript versions its files on the server from each file's change
time, so nobody bumps version numbers by hand.

To run the checks on your own computer (optional): `npm install` once, then `npm run check`.

## Checks

Every push runs `.github/workflows/checks.yml`: ESLint on the scripts, PHP syntax on the app, the automated tests
(`php tests/run.php`: who gets access, checkout, refunds, chargebacks, with a fake Stripe and an empty database).

## Deploying

From the Actions tab: **Deploy test site** or **Deploy live site** uploads the site to Hostinger over FTP, with the
private settings from the repository's secrets. **Import from Notion** brings the Manuscript's pages over from
Notion into the server's database. Setup details are in `manuscript/README.md`.

**Discord daily correspondences** posts Today's Correspondences to the Discord server at 11:11 Pacific every morning
(`.github/scripts/discord-today.js`), through the webhook in the repository secret `DISCORD_WEBHOOK_URL`. Run it by
hand from the Actions tab to post straight away.
