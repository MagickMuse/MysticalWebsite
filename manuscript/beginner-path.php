<?php
/*
 * The 30-day beginner path: one Manuscript page a day, by email (lib/path.php sends them).
 * Each email shows the day's page title, its own opening lines (word for word), and a button to read it.
 * Edit freely: change the order, swap a page ('grimoire/slug', as in the page's address), or add or remove a
 * "try" (a tool to point to that day: today, spell-builder, draw-a-card, pull-a-rune, wheel?of=planets,
 * wheel?of=signs, wheel?of=moon, notes). Days that point to a page not in the Manuscript are skipped.
 */
return [
    ['page' => 'ritual/what-is-magick'],
    ['page' => 'ritual/the-witchcraft-method'],
    ['page' => 'ritual/closed-practices'],
    ['page' => 'correspondence/law-of-similars'],
    ['page' => 'ritual/witches-working-tools'],
    ['page' => 'mineral/cleansing-your-tools'],
    ['page' => 'material/caring-for-your-magick-tools'],
    ['page' => 'ritual/elements-in-spell-casting'],
    ['page' => 'astrology/using-the-moon-phases-in-magick', 'try' => 'today'],
    ['page' => 'symbol/the-nature-of-symbols'], // in place of 'Spell-casting Using the Power of Planets', whose opening is about stones (see TODO.md)
    ['page' => 'symbol/color-magick-practices'],
    ['page' => 'plant/introduction-to-plant-magick'],
    ['page' => 'mineral/introduction-to-crystal-magick'],
    ['page' => 'mineral/how-to-recognize-fake-crystals'],
    ['page' => 'plant/kitchen-witchery'],
    ['page' => 'ritual/anatomy-of-a-basic-spell', 'try' => 'spell-builder'],
    ['page' => 'ritual/crafting-incantations'],
    ['page' => 'ritual/candle-magick'],
    ['page' => 'ritual/ritual-types'],
    ['page' => 'ritual/spell-documentation-guide', 'try' => 'notes'],
    ['page' => 'ritual/ritual-disposal-guide'],
    ['page' => 'ritual/troubleshooting-spellwork'],
    ['page' => 'ritual/everyday-magick'],
    ['page' => 'entity/working-with-deities'],
    ['page' => 'entity/offerings'],
    ['page' => 'entity/working-with-spirits'],
    ['page' => 'astrology/a-brief-introduction-to-astrology', 'try' => 'wheel?of=signs'],
    ['page' => 'symbol/introduction-to-numerology', 'try' => 'draw-a-card'],
    ['page' => 'ritual/solitary-coven-practice', 'try' => 'pull-a-rune'],
    ['page' => 'begin-here/journal-prompts', 'try' => 'notes'],
];
