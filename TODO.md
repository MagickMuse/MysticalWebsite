# To do: the Magick Muse

Things only you can do (accounts, Notion, decisions). Claude keeps this list up to date: say “add it to my to-do list”
in any session. Tick a box by changing `[ ]` to `[x]`, or ask Claude to.

_Last updated: November 21, 2026_

## Before launch
- [ ] **Stripe:** the four things blocking live checkout: dispute events on the webhook, Stripe's account review, tax
      registrations, and the live secret key. Steps in [STRIPE_INTEGRATION_TODO.md](STRIPE_INTEGRATION_TODO.md).
- [ ] **Email settings:** put your Hostinger mailbox login in the private settings (`smtp_host` = smtp.hostinger.com,
      `smtp_user`, `smtp_pass`, and `mail_from`). Sign-in links and the beginner path emails need it.
- [ ] **Daily backups on Hostinger:** included on the Business plan; a small add-on on Premium. Members' notes live on the site.
- [ ] **First upload:** GitHub → Actions → “Deploy live site” → Run workflow (Claude can walk you through it).
- [ ] **Beginner path at 11:11:** hPanel → Advanced → Cron Jobs → Custom: minute `11,26,41,56`, everything else `*`, command
      `php /home/YOUR-USERNAME/domains/magickmuse.com/public_html/manuscript/cli/beginner-path.php`
- [ ] **Try Share on your phone:** Draw a Card or Pull a Rune → Share should open your phone's share menu with the picture.
- [ ] **Try the new tools on the preview:** Do this spread (any tarot spread page), the Dream Decoder (Dreams), Planetary
      Hours (Timing; it asks for your location once), the moon on the Calendar, and Save to notes after a pull.
- [ ] **Product pictures (optional):** a picture for each Market product (Witchcraft 101 and 111, the Blank Grimoire, the
      Crystal Grimoire). Shared links and Google show the Manuscript picture until then, and Google Shopping needs one.
      Send them to Claude to add.
- [ ] **Rune pictures:** 18 runes still have no picture (Erda, Sol, Ul, Ziu, Hulinhjalmur, Wolfsangel and some letters
      of the less certain alphabets). Say which should get one and which are meant to stay blank.
- [ ] **Review the beginner path's 30 days:** `manuscript/beginner-path.php`, one line per day; reorder or swap freely.

## After launch
- [ ] **Google Search Console:** resubmit `sitemap.xml` so Google picks up the new addresses (/muses, /mythos, /market,
      /manuscript/today). Search Console also shows which searches already find you.
- [ ] **Bing Webmaster Tools:** add the site and submit `sitemap.xml` (Bing also feeds ChatGPT's and DuckDuckGo's search).
- [ ] **Check the shop prices for Google:** paste a Market product link into search.google.com/test/rich-results;
      it should find a “Product” with its price.
- [ ] **Try the calendar feed:** on the Calendar, Add to Your Calendar → Google, then on your phone → Apple. Festivals
      and moon phases should appear and update by themselves.
- [ ] **Delete the preview copy:** once the live site works, the GitHub Pages preview (the `manuscript/*/` and `market/`
      copies) can go. Ask Claude.
- [ ] **Social bios:** change any `/shop` links to `/market` (the old ones still forward).
- [ ] **Monthly plan:** decide whether to offer it. If yes: make a monthly price in Stripe and add it to the settings
      (Claude can do the settings part). Cancellations remove access automatically at the end of the paid month.
- [ ] **Making the repository private:** on GitHub's free plan, the GitHub Pages preview switches off for private repositories.

## Fix in Notion (the site shows what's there)
- [ ] **Hellebore:** its notes say “Substitution: Magickal Uses or Nettle”. Probably meant “For magickal uses: Nettle”.
- [ ] **Mugwort** and **Wormwood:** each has an empty “Substitution:” line.
- [ ] **Cinquefoil:** “Substitution: Clover; Clover” (Clover twice).
- [ ] **Spell-casting Using the Power of Planets:** its opening paragraph is about stones, so it was taken off the beginner
      path (The Nature of Symbols is in its place). Fix the opening and ask Claude to put it back if you like.
- [ ] **Optional, a “Warnings” field:** “Notes/Warnings” mixes warnings with general notes, so the safety notes on rituals
      only quote sentences with clear warning words. A separate Warnings field would let the site show exactly what you write.

## Write in Notion (your Discord members asked for these)
- [ ] Guide pages: **fire safety**, **energy work** (grounding, raising, directing, shielding), **warding**, **sigil-making**.
- [ ] A **Paths** database, one page per tradition: history, how to practice it, open or closed, the DO NOT list. Link
      each to its deities and herbs. (Rituals already carry 25 traditions.)
- [ ] Entity properties **Temperament** (volatile, patient…) and **Good for beginners**. Filters for them appear on their own.
- [ ] A guide page on **working with your sun, moon and rising sign**.
- [ ] **Merkstave (reversed) rune meanings**, if you want Pull a Rune to land stones upside-down like reversed tarot cards.

## Ideas for later (ask Claude when you're ready)
- Give the Manuscript as a gift (worth doing before Yule, December 21)
- Your birthday in the Manuscript (sun and moon sign, birthstone, Celtic tree, the day's profile)
- Referral codes, guest passes for a friend, coven (group) plans
- Free public daily pages: a card of the day, the sabbat countdown (Today’s Correspondences is already public)
- A weekly email: the week's moon phases, festivals and a featured page
- The full book of shadows (entries, @mentions of Manuscript pages, backlinks)
- Public previews of Manuscript entries for Google (the biggest discoverability win: name, picture and a few
  correspondences, the rest for members), and festival pages that answer “when is Samhain 2026”
- Pinterest: a tall 2:3 pin picture for each blog post
