<?php
declare(strict_types=1);

/*
 * The Manuscript home: the grimoires as book spines in one row (a carousel), in the website's colours.
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

/** All the grimoires in one row, in alphabetical order, with arrows to move along. */
function bookshelf_html(array $grimoires, array $counts): string
{
    $books = '';
    foreach (array_values($grimoires) as $i => $g) $books .= real_book($g, $counts[$g['grimoire']] ?? 0, $i);
    return '<section class="carousel" aria-label="The grimoires">'
        . '<button class="car-btn prev" type="button" aria-label="Previous grimoires" data-car="-1">‹</button>'
        . '<div class="car-track" data-carousel>' . $books . '</div>'
        . '<button class="car-btn next" type="button" aria-label="More grimoires" data-car="1">›</button>'
        . '<div class="ledge" aria-hidden="true"></div></section>';
}
