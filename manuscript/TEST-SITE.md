# Setting up the test site (the GitHub way)

A private copy of the new Manuscript wiki, shop and dashboard at **test.magickmuse.com**, using Stripe's test mode so no real money moves. The real magickmuse.com isn't touched.

**How it works:** everything is managed on GitHub. When anything changes on the `manuscript-wiki` branch, GitHub uploads the site to Hostinger over FTP, the same thing FileZilla does, using the FTP login you give it once. Imports from Notion also run on GitHub, and the pages go straight into a private folder on Hostinger. Nothing paid is ever stored on GitHub.

Two rules:

1. The **data folder** (the Manuscript's pages and images) sits **next to** `public_html`, never inside it. GitHub and the site both refuse to run if it's inside.
2. The repository is **public**. Passwords, keys and course links go only into GitHub's **Secrets** (step 4), never into files.

---

## 1. Hostinger (your husband, about 10 minutes, once)

Do these in hPanel:

1. **Subdomain:** Domains → Subdomains → create `test` on `magickmuse.com`. Turn on SSL for it (Security → SSL).
2. **PHP:** Advanced → PHP Configuration → choose **PHP 8.2 or newer**.
3. **Email:** Emails → make sure a mailbox exists for sending sign-in links (e.g. `hello@magickmuse.com`) and note its password.
4. **FTP details**, the same ones you use in FileZilla (or Files → FTP Accounts):
   - **Host**, e.g. `ftp.magickmuse.com` or the IP address
   - **Username**
   - **Password**
5. **Two folder paths, exactly as FileZilla shows them** after you log in:
   - The test subdomain's folder, e.g. `/domains/magickmuse.com/public_html/test` (or `/public_html/test`). This is `FTP_TEST_DIR`.
   - A data folder **next to** `public_html` (same level, not inside it), e.g. `/domains/magickmuse.com/manuscript-data-test`. This is `FTP_TEST_DATA_DIR`. You don't need to create it; GitHub will.

That's all the Hostinger work. No uploading, no files to edit.

## 2. Notion key (Avelynn, 2 minutes)

1. Go to [notion.so/profile/integrations](https://www.notion.so/profile/integrations) → **New integration**: type Internal, the Manuscript's workspace, name "Manuscript wiki", capabilities **Read content** only.
2. Copy the **Internal Integration Secret** (starts with `ntn_`).
3. In Notion open **The Magick Manuscript** → **•••** (top right) → **Connections** → add "Manuscript wiki".

## 3. Stripe in test mode (Avelynn, 10 minutes)

At [dashboard.stripe.com](https://dashboard.stripe.com), turn on **Test mode** (toggle at the top) first.

1. **API key:** Developers → API keys → copy the **Secret key** (`sk_test_…`).
2. **Manuscript price:** Product catalog → Add product "The Magick Manuscript" → price **$333, one-time** → copy its id (`price_…`).
3. **Webhook:** Developers → Webhooks → Add endpoint
   - URL: `https://test.magickmuse.com/manuscript/stripe-webhook`
   - Events: `checkout.session.completed`, `invoice.paid`, `charge.refunded`, `customer.subscription.updated`, `customer.subscription.deleted`
   - Copy its **Signing secret** (`whsec_…`).
4. **SEEKER10:** Product catalog → Coupons → new coupon "10% off" → add the promotion code `SEEKER10`.

Shop products (courses, templates) need nothing in Stripe.

## 4. Put everything into GitHub's Secrets (either of you)

On GitHub: **MagickMuse/MysticalWebsite → Settings → Secrets and variables → Actions → New repository secret**. Add these six, one at a time, with the names spelled exactly like this:

| Secret name | What to paste |
|---|---|
| `FTP_HOST` | FTP host from step 1 |
| `FTP_USER` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_TEST_DIR` | Test folder path from step 1, e.g. `/domains/magickmuse.com/public_html/test` |
| `FTP_TEST_DATA_DIR` | Data folder path from step 1, e.g. `/domains/magickmuse.com/manuscript-data-test` |
| `MANUSCRIPT_CONFIG_TEST` | The **whole** private settings file you were sent (`config.local.php`), after replacing each `CHANGE-ME`: the `ntn_…` Notion key, `sk_test_…`, `whsec_…`, the `price_…` id, and the email password |

Secrets can't be read back by anyone, including you, and never appear on the public page. If you need to change one, use **Update** on it.

## 5. Go

Both jobs start by themselves. You don't need to press anything in GitHub. (A "Run workflow" button only appears once they're also on the `main` branch.)

1. **Upload the site:** happens every time the `manuscript-wiki` branch changes. Once the secrets are in, tell Claude "the secrets are in" and it will start the first upload. Watch it in the **Actions** tab: **Deploy test site** with a green tick means it's on Hostinger.
2. **Import the Manuscript:** happens whenever `manuscript/import.txt` changes on the `manuscript-wiki` branch. Claude can do that for you, or you can do it on GitHub yourself: open the file, click the pencil, change the "Run" number, then **Commit changes**.
   - Notion allows about 3 requests a second, so the first full import is slow, roughly 1–2 minutes per 100 entries.
   - GitHub stops any job after 6 hours, so a big import **pauses itself** before then, saves everything to Hostinger, and shows a yellow "paused" note. Start it again (change the Run number) and it carries on where it stopped. Later runs only fetch what changed.
   - To import more grimoires, uncomment their lines in `manuscript/grimoires.php`.

If a run has a red ✗, click it: the first red step says what's wrong in plain words (a missing secret, a typo in the settings, a wrong folder). If the upload step mentions a **certificate**, add a repository *variable* (same page, Variables tab) named `FTP_VERIFY_CERT` with the value `no`. It's the same as clicking "trust this certificate" in FileZilla, and the connection stays encrypted.

## 6. Try everything

Stripe test card: `4242 4242 4242 4242`, any future date, any CVC and postcode. Tick each one as it works:

- [ ] Signed out, `/manuscript/`, a grimoire, an entry and search all show only "Members only". No text, titles or images.
- [ ] Avelynn signs in at `/manuscript/login` with her admin email, the link arrives, and `/manuscript/dashboard` opens.
- [ ] With another email, `/manuscript/join` → Lifetime $333 → test card → back on the site, signed in, entries readable, "Connections" links work.
- [ ] That sale appears on the dashboard.
- [ ] `/manuscript/join?code=SEEKER10` shows "Code SEEKER10 will be applied" and Stripe shows 10% off.
- [ ] `/shop/witchcraft-101` → buy → it appears in `/shop/library` with the Google Classroom link.
- [ ] `/shop/crystal-grimoire` → enter an email → the email arrives with a library link, and the address shows under Dashboard → Email sign-ups.
- [ ] Refund the Witchcraft 101 test payment in Stripe → it disappears from that buyer's library and shows as refunded on the dashboard.
- [ ] Search as a member: typing "prot" suggests Protection. Results can be filtered by grimoire.

If something fails on the site itself: hPanel → Advanced → Error Logs (lines start with `[manuscript]`). Stripe shows each webhook delivery under Developers → Webhooks.

## 7. Going live later

Once the test site passes everything above, the real site works the same way with its own secrets (`FTP_LIVE_DIR`, `FTP_LIVE_DATA_DIR`, `MANUSCRIPT_CONFIG_LIVE` with Stripe **live** keys and a live webhook to `https://magickmuse.com/manuscript/stripe-webhook`). Merging into `main` switches the site's buttons from Gumroad to the new shop, so the live upload, the import and the Gumroad customer import (README step 4) all need to happen together. Ask Claude to set up the live version when you're ready.
