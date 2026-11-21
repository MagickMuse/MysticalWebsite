<?php
/*
 * Sales copy for the Manuscript's join page (/manuscript/join), in parts so each can be designed (account.php view_join()).
 * Plain text, except *stars* around words, which show them highlighted in gold. Edit freely.
 */
return [
    'name' => 'The Magick Manuscript ✦ 20,000+ Entries ✦ Interactive Digital Grimoire',

    // The opening: a large line, the paragraph after it, and the line it builds to.
    'opening' => [
        'quote' => 'Imagine having a witchcraft library that never stops growing.',
        'text' => 'One that knows when you look up protection, you probably also want to know which crystals carry that energy, which plants strengthen it, which deities preside over it, and which rituals have been used across centuries to invoke it. One that lets you pull on any thread and follow it as deep as you want to go.',
        'reveal' => 'That is what the Magick Manuscript is.',
    ],
    // The numbers, as a row of figures.
    'stats' => [['20,000+', 'pages'], ['15', 'cross-linked grimoires'], ['170+', 'guide pages'], ['209,000+', 'additions and edits in the first year']],
    'about' => 'A living grimoire built from years of research across *hundreds of published witchcraft books*. Every single entry is cross-linked to every related entry across every other grimoire. Nothing exists in isolation here.',
    // The fifteen grimoires, as little labels.
    'grimoires' => ['Rituals & Spells', 'Deities & Spirits', 'Plants', 'Minerals', 'Oils', 'Recipes', 'Animals', 'Correspondences', 'Symbols', 'Runes', 'Tarot & Cards', 'Astrology', 'Timing', 'Dreams', 'Ritual Materials'],
    // The tools: [icon (an assets/icons name), name, what it does].
    'tools_heading' => 'It doesn’t just sit there. It works with you.',
    'tools_intro' => 'The Manuscript comes with tools that put all of that research to use in your actual practice, every single day.',
    'tools' => [
        ['calendar-dots', 'The Calendar, in your own calendar', 'Add it to Google, Apple or Outlook in one tap: every festival of the year and every new, quarter and full moon, at the exact moment in *your own time zone*. Each event links straight to its page, and it keeps itself up to date.'],
        ['sun', 'Today’s Correspondences', 'The moon phase, the planet that rules the day, the Sun’s sign and the season, with the herbs, crystals, colors, oils and spells that suit them. Worked out fresh every day.'],
        ['cards', 'Draw a Card', 'Pull your daily tarot card from a face-down fan. It lands upright or reversed and takes you straight to that card’s meanings, keywords and interpretations.'],
        ['layout', '300+ Tarot Spreads', 'Deal any spread digitally and turn the cards over one by one, or follow its layout with your own deck at your altar.'],
        ['sparkle', 'Dream Decoder', 'Write down your dream and the symbols in it light up, each with what it means.'],
        ['hourglass', 'Planetary Hours', 'Worked out from your own sunrise and sunset, so you always know which planet rules the hour you’re working in.'],
        ['key', 'Pull a Rune', 'Draw from the Elder Futhark or the Witch’s Runes and go straight to the stone’s meaning.'],
        ['flame', 'Spell Builder', 'Choose what your spell is for and the Manuscript gathers the days, colors, herbs, stones, oils, deities and rituals tied to it. Save it as a working to come back to.'],
        ['circles-three', 'Correspondence Wheels', 'Choose a planet, a zodiac sign or a moon phase and see everything tied to it at once.'],
        ['note-pencil', 'Your Own Notes', 'Write private notes on any page, save the pages you keep coming back to, and find it all in your account.'],
        ['scroll', 'A 30-Day Beginner Path', 'One page a day, from the basics to working with deities and spirits, emailed to you at 11:11 each morning or read at your own pace.'],
    ],
    // What membership includes, as a checklist.
    'gets' => [
        '20,000+ pages of witchcraft reference sourced *exclusively from published books*',
        '15 cross-linked grimoires covering every area of practice',
        '170+ guide pages across the grimoires to help you apply the knowledge meaningfully',
        'Every tool above, on your phone or your computer',
        'Access to every update ever made and every update that will ever be made',
        'Instant access to our Discord community of 950+ practitioners, where members share knowledge, experiences and tips *(and where I drop exclusive discount codes)*',
    ],
    // Why it's different: [title, text], as tiles.
    'highlights' => [
        ['Reputable Sources Only', 'You won’t find social media spells or random unsourced information here.'],
        ['Ethically Flagged', 'Closed practices are clearly marked, so you know what’s meant only for those within the originating culture.'],
        ['Guide Pages', 'In every grimoire, to help you use the knowledge.'],
        ['Consult the Curator', 'Questions, recommendations or requests? Message me personally.'],
        ['Join the Community', 'Over 950 witches in our Discord coven use this as the basis of their craft.'],
    ],
    // The closing callout.
    'closing' => 'It is always being added to. In its first year, over *209,000 additions and edits* were made across all 15 grimoires, and that number grows every week.',
    'closing_note' => 'Pricing will increase as the Manuscript continues to grow. Lock in the current price while it lasts.',
    'terms_html' => <<<'HTML'
<p>Terms of Access</p><p>This work is the property of the creator, Avelynn of The Magick Muse.</p><p>Lifetime access lasts for the life of the Manuscript itself, not a fixed term. Monthly access lasts while your subscription is active: cancel anytime from your account, and you keep access until the end of the month you have paid for. Access is to read only. You may read, reference, and discuss the content. You may not duplicate, copy, export, screenshot for redistribution, share your sign-in, or reproduce any part of this manuscript elsewhere, in whole or in part, without express written permission.</p><p>Access is to the live, continuously updated Manuscript. It grows and changes over time; your access reflects its current and future state for as long as it remains active, not a fixed copy.</p><p>All sales are final. The Manuscript is a digital product and access is granted immediately after payment, so no refunds are given.</p><p>Violating these terms (including unauthorized copying, sharing access, or redistributing content) will result in immediate loss of access without refund, and may result in legal action.</p>
HTML,
];
