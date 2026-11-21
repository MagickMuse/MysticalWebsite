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

/**
 * Each grimoire's thickness (wa thin … wd thick) and height (ha short … hd tall), so the shelf looks lived-in.
 * Fixed per grimoire rather than random, so the shelf looks the same on every visit. Unlisted grimoires get the middle size.
 */
const SPINE_SIZES = [
    'animal' => 'wc hc', 'astrology' => 'wb hd', 'card' => 'wa hb', 'correspondence' => 'hc', 'dream' => 'wd ha',
    'entity' => 'wb hc', 'material' => 'wc hd', 'mineral' => 'wa hb', 'oil' => 'wd hb', 'plant' => 'wb hd',
    'recipe' => 'wc ha', 'ritual' => 'wa hc', 'rune' => 'wd hb', 'symbol' => 'wb hd', 'timing' => 'wc hc',
];

/** The books' colours, in turn: red, pink, blackish grey, parchment (the two light ones never side by side). */
function shelf_tone(int $i): string
{
    return ['t1', 't5', 't3', 't4'][$i % 4];
}

function real_book(array $g, int $n, int $i): string
{
    $label = grimoire_label($g);
    $inside = grimoire_summary($g);
    $photo = grimoire_photo($g['grimoire'], 440, 600);
    $tone = shelf_tone($i);
    $len = ' n' . max(3, min(16, mb_strlen($label))); // letters on the spine, so the title can size itself to fit
    // One grimoire stands face out, its cover showing, with an "Open me" seal: it shows at a glance that the books open.
    $faced = $g['grimoire'] === (string) config('shelf_facing', 'correspondence');
    $size = isset(SPINE_SIZES[$g['grimoire']]) ? ' ' . SPINE_SIZES[$g['grimoire']] : '';
    return '<a class="bk ' . $tone . $size . ($faced ? ' faced' : '') . '" href="' . e(page_url($g)) . '"'
        . ' aria-label="' . e($g['title'] . ($inside ? ': ' . $inside : '')) . '"'
        . ' data-title="' . e($g['title']) . '" data-label="' . e($label) . '"' . ($photo ? ' data-photo="' . e($photo) . '"' : '') . '>'
        . '<span class="spine" aria-hidden="true"><i class="band top"></i><span class="bk-title' . $len . '">' . e($label) . '</span>' . (grimoire_glyph($g['grimoire'], 'bk-star') ?: '<i class="bk-star"></i>') . '<i class="band bottom"></i></span>'
        . '<span class="cover" aria-hidden="true">' . ($photo ? '<img src="' . e($photo) . '" alt="" loading="lazy">' : '')
        . '<span class="cover-title">' . e($label) . '</span>'
        . '<span class="cover-sub">' . ($n ? number_format($n) . ' entries' : 'Guides') . '</span>'
        . ($faced ? '<span class="dive">open me <i>✦</i></span>' : '') . '</span></a>';
}

/**
 * The two flat stacks on the shelves: books lying on their side that lead to guide pages and tools, not grimoires.
 * Listed top of the stack first; each book underneath is longer and thicker than the one on it.
 * A book is [title, a page ("grimoire/slug") or "route:<address>", tone] — no title for a plain one.
 * The top shelf ends with three tall candles on their own; the bottom shelf starts with the four guide books in one stack.
 */
const SHELF_STACKS = [
    'top' => ['candles' => true, 'books' => []],
    'bottom' => ['candles' => false, 'books' => [
        ['Guide Pages', 'route:guide-pages', 't1'],
        ['Enchanted Tools', 'route:enchanted-tools', 't3'],
        ['Calendar', 'route:calendar', 't5'],
        ['Sources', 'route:library', 't4'],
    ]],
];

/** A group of three pillar candles of different heights, the tallest in the middle, slightly overlapping. */
function candles_html(bool $tall = false): string
{
    $one = fn(string $c) => '<span class="candle ' . $c . '"><i class="wax"></i><i class="wick"></i><i class="flame"></i></span>';
    return '<span class="candles' . ($tall ? ' tall' : '') . '" aria-hidden="true"><i class="glow"></i>' . $one('c1') . $one('c2') . $one('c3') . '</span>';
}

/** One book lying in a stack ($k: 0 on top, then bigger): titled and a link once its page is on the site, else plain. */
function stack_book(?string $title, ?string $ref, string $tone, int $k): string
{
    $href = null;
    if ($ref && str_starts_with($ref, 'route:')) $href = url(substr($ref, 6));
    elseif ($ref && ($page = page_by_ref($ref))) $href = page_url($page);
    if (!$title || !$href) return '<span class="slab s' . $k . ' ' . $tone . '" aria-hidden="true"></span>';
    return '<a class="slab titled s' . $k . ' ' . $tone . '" href="' . e($href) . '" aria-label="' . e($title) . '">'
        . '<span class="slab-title" aria-hidden="true">' . e($title) . '</span></a>';
}

function stack_html(array $stack, string $side): string
{
    $html = '<div class="stack ' . $side . '">' . (!empty($stack['candles']) ? candles_html(!$stack['books']) : '');
    foreach ($stack['books'] as $k => [$title, $ref, $tone]) $html .= stack_book($title, $ref, $tone, $k);
    return $html . '</div>';
}

/** The grimoires standing on two shelves in alphabetical order, with a flat stack at the end of each; shelves scroll on narrow screens. */
function bookshelf_html(array $grimoires, array $counts): string
{
    $up = [];
    foreach (array_values($grimoires) as $i => $g) $up[] = real_book($g, $counts[$g['grimoire']] ?? 0, $i);
    $half = (int) ceil(count($up) / 2);
    $rows = [array_slice($up, 0, $half), array_slice($up, $half)];
    $rows[0][] = stack_html(SHELF_STACKS['top'], 'at-end');
    array_unshift($rows[1], stack_html(SHELF_STACKS['bottom'], 'at-start'));
    $html = '';
    foreach ($rows as $k => $row) {
        $html .= '<section class="carousel" aria-label="' . ($k === 0 ? 'Top shelf' : 'Bottom shelf') . '">'
            . '<button class="car-btn prev" type="button" aria-label="Previous grimoires" data-car="-1">‹</button>'
            . '<div class="car-track" data-carousel>' . implode('', $row) . '</div>'
            . '<button class="car-btn next" type="button" aria-label="More grimoires" data-car="1">›</button>'
            . '<div class="ledge" aria-hidden="true"></div></section>';
    }
    // Phones: every grimoire lying flat in one alphabetical pile, the candles on top and the titled stack books at the base.
    $pile = '';
    foreach (array_values($grimoires) as $i => $g) $pile .= pile_book($g, $counts[$g['grimoire']] ?? 0, $i);
    $base = '';
    foreach (SHELF_STACKS as $stack) foreach ($stack['books'] as $k => [$title, $ref, $tone]) if ($title) $base .= stack_book($title, $ref, $tone, $k + 1);
    return '<div class="shelves" role="group" aria-label="The grimoires">' . $html . '</div>'
        . '<div class="book-pile" role="group" aria-label="The grimoires">' . candles_html() . $pile . $base . '<div class="ledge" aria-hidden="true"></div></div>';
}

/** One grimoire lying flat, for the phone pile. */
function pile_book(array $g, int $n, int $i): string
{
    $label = grimoire_label($g);
    $inside = grimoire_summary($g);
    $photo = grimoire_photo($g['grimoire'], 440, 600);
    return '<a class="bk lying ' . shelf_tone($i) . '" href="' . e(page_url($g)) . '"'
        . ' aria-label="' . e($g['title'] . ($inside ? ': ' . $inside : '') . ($n ? ' (' . number_format($n) . ' entries)' : '')) . '"'
        . ' data-title="' . e($g['title']) . '" data-label="' . e($label) . '"' . ($photo ? ' data-photo="' . e($photo) . '"' : '') . '>'
        . '<span class="spine" aria-hidden="true"><i class="band left"></i>' . (grimoire_glyph($g['grimoire'], 'bk-star') ?: '<i class="bk-star"></i>')
        . '<span class="bk-title">' . e($label) . '</span><i class="band right"></i></span></a>';
}
