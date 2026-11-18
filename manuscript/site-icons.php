<?php
/*
 * Icons the site sets itself, whatever the page has in Notion: "grimoire/page" => a file in manuscript/assets.
 * The planets and zodiac signs use gold astrological symbols (drawn from the free DejaVu Sans font).
 * Applied after every Notion import (lib/notion.php: applySiteRules).
 */
$astro = ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto',
    'aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo', 'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces'];
return array_combine(array_map(fn($s) => "astrology/$s", $astro), array_map(fn($s) => "astro/$s.png", $astro));
