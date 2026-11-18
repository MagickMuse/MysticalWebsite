<?php
declare(strict_types=1);

/*
 * The Manuscript home: the grimoires as book spines on two shelves, in the website's colours.
 * A grimoire with a long name (Correspondences) lies flat on a little stack of plain books, its title read left to right.
 * Hovering (or focusing) a spine turns it to its front cover: the grimoire's photo with its title on top.
 * Clicking lifts the book out and opens it (assets/wiki.js), then goes into the grimoire.
 * Each spine carries its grimoire's icon, the same as on the website's Grimoires page.
 * Colours come from classes, since the site's security rules allow no inline styles.
 */

/** Spine names, as on the website's Grimoires page. */
function grimoire_label(array $g): string
{
    $names = [
        'animal' => 'Animals', 'astrology' => 'Astrology', 'card' => 'Cards', 'correspondence' => 'Correspondences',
        'dream' => 'Dreams', 'entity' => 'Entities', 'material' => 'Materials', 'mineral' => 'Minerals', 'oil' => 'Oils',
        'plant' => 'Plants', 'recipe' => 'Recipes', 'ritual' => 'Rituals', 'rune' => 'Runes', 'symbol' => 'Symbols', 'timing' => 'Timing',
    ];
    return $names[$g['grimoire']] ?? trim(preg_replace('/\s*Grimoire\s*$/i', '', $g['title']));
}

function real_book(array $g, int $n, int $i): string
{
    $label = grimoire_label($g);
    $inside = grimoire_summary($g);
    $photo = grimoire_photo($g['grimoire'], 440, 600);
    $tone = 't' . (1 + $i % 5); // burgundy, rose, ink, gold, blush, in turn
    $len = ' n' . max(3, min(16, mb_strlen($label))); // letters on the spine, so the title can size itself to fit
    return '<a class="bk ' . $tone . '" href="' . e(page_url($g)) . '"'
        . ' aria-label="' . e($g['title'] . ($inside ? ': ' . $inside : '')) . '"'
        . ' data-title="' . e($g['title']) . '" data-label="' . e($label) . '"' . ($photo ? ' data-photo="' . e($photo) . '"' : '') . '>'
        . '<span class="spine" aria-hidden="true"><i class="band top"></i><span class="bk-title' . $len . '">' . e($label) . '</span>' . (grimoire_glyph($g['grimoire'], 'bk-star') ?: '<i class="bk-star"></i>') . '<i class="band bottom"></i></span>'
        . '<span class="cover" aria-hidden="true">' . ($photo ? '<img src="' . e($photo) . '" alt="" loading="lazy">' : '')
        . '<span class="cover-title">' . e($label) . '</span>'
        . '<span class="cover-sub">' . ($n ? number_format($n) . ' entries' : 'Guides') . '</span></span></a>';
}

/** Names this long don't fit standing up, so the book lies flat on a stack. */
const LYING_FROM = 13;

/** Grimoires that lie flat with a lit candle on top, one per shelf to balance the stacks (the Rituals). */
const LYING_WITH_CANDLE = ['ritual'];

/** A grimoire lying on its side on top of two plain books. Clicking it opens it like the others. */
function lying_book(array $g, int $n, int $i, bool $candle = false, string $side = 'at-end'): string
{
    $label = grimoire_label($g);
    $short = mb_strlen($label) < 10 ? ' short' : '';
    $inside = grimoire_summary($g);
    $photo = grimoire_photo($g['grimoire'], 440, 600);
    $tone = 't' . (1 + $i % 5);
    return '<div class="stack ' . $side . $short . '">'
        // A candle in a glass jar burning on top of the book (just for the look).
        . ($candle ? '<span class="candle" aria-hidden="true"><i class="glow"></i><i class="jar"><i class="wax"></i><i class="wick"></i><i class="flame"></i><i class="glass"></i></i></span>' : '')
        . '<a class="bk lying ' . $tone . '" href="' . e(page_url($g)) . '"'
        . ' aria-label="' . e($g['title'] . ($inside ? ': ' . $inside : '') . ($n ? ' (' . number_format($n) . ' entries)' : '')) . '"'
        . ' data-title="' . e($g['title']) . '" data-label="' . e($label) . '"' . ($photo ? ' data-photo="' . e($photo) . '"' : '') . '>'
        . '<span class="spine" aria-hidden="true"><i class="band left"></i>' . (grimoire_glyph($g['grimoire'], 'bk-star') ?: '<i class="bk-star"></i>')
        . '<span class="bk-title">' . e($label) . '</span><i class="band right"></i></span></a>'
        // Plain books underneath, each bigger than the one on it, just for the look: not links, hidden from screen readers.
        . '<span class="slab s1 t' . (1 + ($i + 2) % 5) . '" aria-hidden="true"></span>'
        . '<span class="slab s2 t' . (1 + ($i + 4) % 5) . '" aria-hidden="true"></span>'
        . '</div>';
}

/** The grimoires on two shelves, in alphabetical order; each shelf scrolls sideways if the screen is narrow. */
function bookshelf_html(array $grimoires, array $counts): string
{
    // Standing books in alphabetical order, split over the two shelves. The flat stacks don't mirror each other:
    // one ends the top shelf on the right, the other starts the bottom shelf on the left, so the two balance on a diagonal.
    $up = $flat = [];
    foreach (array_values($grimoires) as $i => $g) {
        $n = $counts[$g['grimoire']] ?? 0;
        $candle = in_array($g['grimoire'], LYING_WITH_CANDLE, true);
        if ($candle || mb_strlen(grimoire_label($g)) >= LYING_FROM) $flat[] = [$g, $n, $i, $candle];
        else $up[] = real_book($g, $n, $i);
    }
    $half = (int) ceil(count($up) / 2);
    $rows = [array_slice($up, 0, $half), array_slice($up, $half)];
    foreach ($flat as $k => [$g, $n, $i, $candle]) {
        if ($k % 2 === 0) $rows[0][] = lying_book($g, $n, $i, $candle, 'at-end');
        else array_unshift($rows[1], lying_book($g, $n, $i, $candle, 'at-start'));
    }
    $html = '';
    foreach ($rows as $k => $row) {
        $html .= '<section class="carousel" aria-label="' . ($k === 0 ? 'Top shelf' : 'Bottom shelf') . '">'
            . '<button class="car-btn prev" type="button" aria-label="Previous grimoires" data-car="-1">‹</button>'
            . '<div class="car-track" data-carousel>' . implode('', $row) . '</div>'
            . '<button class="car-btn next" type="button" aria-label="More grimoires" data-car="1">›</button>'
            . '<div class="ledge" aria-hidden="true"></div></section>';
    }
    return '<div class="shelves" role="group" aria-label="The grimoires">' . $html . '</div>';
}
