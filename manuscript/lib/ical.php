<?php
declare(strict_types=1);

/*
 * The calendar feed (/manuscript/calendar.ics): the year's festivals and the moon's phases, for people to add to
 * Google, Apple or Outlook calendars. Calendar apps fetch it again every day, so it stays current by itself.
 *
 * Nothing here touches the database: view_calendar_feed() in lib/pages.php gathers the festivals and passes them in.
 * The preview's static copy is made by running this file: php manuscript/lib/ical.php < festivals.json > calendar.ics
 */

/**
 * The moon's new, first quarter, full and third quarter moments between two Unix times, as [time, phase 0-3].
 * Meeus, Astronomical Algorithms, chapter 49: within a minute of the exact moment.
 */
function moon_phases(int $from, int $to): array
{
    $out = [];
    $k0 = floor(((gmdate('Y', $from) + (gmdate('z', $from) / 365.25)) - 2000) * 12.3685) - 1;
    for ($k = $k0; ; $k++) {
        for ($q = 0; $q < 4; $q++) {
            $t = moon_phase_time($k + $q / 4, $q);
            if ($t > $to) return $out;
            if ($t >= $from) $out[] = [$t, $q];
        }
    }
}

function moon_phase_time(float $k, int $q): int
{
    $T = $k / 1236.85;
    $jde = 2451550.09766 + 29.530588861 * $k + 0.00015437 * $T ** 2 - 0.000000150 * $T ** 3 + 0.00000000073 * $T ** 4;
    $E = 1 - 0.002516 * $T - 0.0000074 * $T ** 2;
    $r = fn(float $deg) => deg2rad(fmod($deg, 360));
    $M = $r(2.5534 + 29.10535670 * $k - 0.0000014 * $T ** 2 - 0.00000011 * $T ** 3);
    $Mp = $r(201.5643 + 385.81693528 * $k + 0.0107582 * $T ** 2 + 0.00001238 * $T ** 3 - 0.000000058 * $T ** 4);
    $F = $r(160.7108 + 390.67050284 * $k - 0.0016118 * $T ** 2 - 0.00000227 * $T ** 3 + 0.000000011 * $T ** 4);
    $O = $r(124.7746 - 1.56375588 * $k + 0.0020672 * $T ** 2 + 0.00000215 * $T ** 3);
    if ($q === 0 || $q === 2) {
        $c = $q === 0 ? [-0.40720, 0.17241, 0.01608, 0.01039, 0.00739, -0.00514, 0.00208] : [-0.40614, 0.17302, 0.01614, 0.01043, 0.00734, -0.00515, 0.00209];
        $jde += $c[0] * sin($Mp) + $c[1] * $E * sin($M) + $c[2] * sin(2 * $Mp) + $c[3] * sin(2 * $F)
            + $c[4] * $E * sin($Mp - $M) + $c[5] * $E * sin($Mp + $M) + $c[6] * $E * $E * sin(2 * $M)
            - 0.00111 * sin($Mp - 2 * $F) - 0.00057 * sin($Mp + 2 * $F) + 0.00056 * $E * sin(2 * $Mp + $M)
            - 0.00042 * sin(3 * $Mp) + 0.00042 * $E * sin($M + 2 * $F) + 0.00038 * $E * sin($M - 2 * $F)
            - 0.00024 * $E * sin(2 * $Mp - $M) - 0.00017 * sin($O) - 0.00007 * sin($Mp + 2 * $M)
            + 0.00004 * sin(2 * $Mp - 2 * $F) + 0.00004 * sin(3 * $M) + 0.00003 * sin($Mp + $M - 2 * $F)
            + 0.00003 * sin(2 * $Mp + 2 * $F) - 0.00003 * sin($Mp + $M + 2 * $F) + 0.00003 * sin($Mp - $M + 2 * $F)
            - 0.00002 * sin($Mp - $M - 2 * $F) - 0.00002 * sin(3 * $Mp + $M) + 0.00002 * sin(4 * $Mp);
    } else {
        $jde += -0.62801 * sin($Mp) + 0.17172 * $E * sin($M) - 0.01183 * $E * sin($Mp + $M) + 0.00862 * sin(2 * $Mp)
            + 0.00804 * sin(2 * $F) + 0.00454 * $E * sin($Mp - $M) + 0.00204 * $E * $E * sin(2 * $M)
            - 0.00180 * sin($Mp - 2 * $F) - 0.00070 * sin($Mp + 2 * $F) - 0.00040 * sin(3 * $Mp)
            - 0.00034 * $E * sin(2 * $Mp - $M) + 0.00032 * $E * sin($M + 2 * $F) + 0.00032 * $E * sin($M - 2 * $F)
            - 0.00028 * $E * $E * sin($Mp + 2 * $M) + 0.00027 * $E * sin(2 * $Mp + $M) - 0.00017 * sin($O)
            - 0.00005 * sin($Mp - $M - 2 * $F) + 0.00004 * sin(2 * $Mp + 2 * $F) - 0.00004 * sin($Mp + $M + 2 * $F)
            + 0.00004 * sin($Mp - 2 * $M) + 0.00003 * sin($Mp + $M - 2 * $F) + 0.00003 * sin(3 * $M)
            + 0.00002 * sin(2 * $Mp - 2 * $F) + 0.00002 * sin($Mp - $M + 2 * $F) - 0.00002 * sin(3 * $Mp + $M);
        $W = 0.00306 - 0.00038 * $E * cos($M) + 0.00026 * cos($Mp) - 0.00002 * cos($Mp - $M) + 0.00002 * cos($Mp + $M) + 0.00002 * cos(2 * $F);
        $jde += $q === 1 ? $W : -$W;
    }
    // The planets' small pulls on the moon.
    $a = [[299.77, 0.107408, 0.000325], [251.88, 0.016321, 0.000165], [251.83, 26.651886, 0.000164], [349.42, 36.412478, 0.000126],
        [84.66, 18.206239, 0.000110], [141.74, 53.303771, 0.000062], [207.14, 2.453732, 0.000060], [154.84, 7.306860, 0.000056],
        [34.52, 27.261239, 0.000047], [207.19, 0.121824, 0.000042], [291.34, 1.844379, 0.000040], [161.72, 24.198154, 0.000037],
        [239.56, 25.513099, 0.000035], [331.55, 3.592518, 0.000023]];
    foreach ($a as $i => [$base, $rate, $amp]) $jde += $amp * sin($r($base + $rate * $k - ($i === 0 ? 0.009173 * $T ** 2 : 0)));
    // Terrestrial time to UTC (about 69 seconds this decade), then Julian day to Unix time.
    return (int) round(($jde - 2440587.5) * 86400 - 69);
}

/**
 * The feed. $festivals: [['month' => 1-12, 'day' => 1-31, 'title', 'icon' (emoji or ''), 'url' (or '')], …].
 * $moonUrls: the phases' pages, by phase 0-3 (or ''). Moon phases run from a month ago to two years ahead.
 */
function calendar_ics(array $festivals, array $moonUrls, int $now): string
{
    $esc = fn(string $s) => str_replace(["\\", ';', ',', "\r", "\n"], ["\\\\", '\\;', '\\,', '', '\\n'], $s);
    $stamp = gmdate('Ymd\THis\Z', $now);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//The Magick Muse//The Magick Manuscript//EN', 'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH', 'X-WR-CALNAME:The Magick Manuscript', 'X-WR-CALDESC:' . $esc('Festivals and moon phases from The Magick Manuscript.'),
        'REFRESH-INTERVAL;VALUE=DURATION:P1D', 'X-PUBLISHED-TTL:P1D'];
    $event = function (string $uid, array $when, string $title, string $url, string $desc) use (&$lines, $esc, $stamp) {
        array_push($lines, 'BEGIN:VEVENT', 'UID:' . $uid . '@magickmuse.com', 'DTSTAMP:' . $stamp, ...$when);
        array_push($lines, 'SUMMARY:' . $esc($title), 'DESCRIPTION:' . $esc($desc . ($url !== '' ? "\n\n" . $url : '')), 'TRANSP:TRANSPARENT');
        if ($url !== '') $lines[] = 'URL:' . $url;
        $lines[] = 'END:VEVENT';
    };
    foreach ($festivals as $f) {
        // Every year on the same date. 2024 was a leap year, so a February 29 festival exists (and then recurs on leap years).
        $start = mktime(12, 0, 0, (int) $f['month'], (int) $f['day'], 2024);
        $event('festival-' . sprintf('%02d%02d', $f['month'], $f['day']) . '-' . substr(md5($f['title']), 0, 10),
            ['DTSTART;VALUE=DATE:' . date('Ymd', $start), 'DTEND;VALUE=DATE:' . date('Ymd', $start + 86400), 'RRULE:FREQ=YEARLY'],
            trim($f['icon'] . ' ' . $f['title']), $f['url'], 'Its correspondences and rituals are in The Magick Manuscript.');
    }
    $names = [['🌑', 'New Moon'], ['🌓', 'First Quarter Moon'], ['🌕', 'Full Moon'], ['🌗', 'Third Quarter Moon']];
    foreach (moon_phases($now - 31 * 86400, $now + 2 * 366 * 86400) as [$t, $q]) {
        $event('moon-' . gmdate('YmdHi', $t), ['DTSTART:' . gmdate('Ymd\THis\Z', $t), 'DTEND:' . gmdate('Ymd\THis\Z', $t + 3600)],
            $names[$q][0] . ' ' . $names[$q][1], $moonUrls[$q] ?? '', 'The exact moment of the ' . strtolower($names[$q][1]) . ', in your own time zone.');
    }
    $lines[] = 'END:VCALENDAR';
    // Lines longer than 75 bytes are folded onto continuation lines that start with a space, without splitting a character.
    $out = '';
    foreach ($lines as $line) {
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            $out .= substr($line, 0, $cut) . "\r\n";
            $line = ' ' . substr($line, $cut);
        }
        $out .= $line . "\r\n";
    }
    return $out;
}

// The preview's static copy: php manuscript/lib/ical.php < festivals.json (the festivals and moon page addresses as JSON).
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $in = json_decode((string) file_get_contents($_SERVER['argv'][1] ?? 'php://stdin'), true);
    echo calendar_ics($in['festivals'], $in['moons'], time());
}
