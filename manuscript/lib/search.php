<?php
declare(strict_types=1);

/*
 * Search: the results page, suggestions as you type, and "did you mean" for misspellings.
 * Routed from index.php.
 */

/** Turns what someone typed into an FTS5 query where every word is a prefix match ("rose qua" → Rose Quartz). */
function fts_query(string $q): string
{
    $terms = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"*', array_slice($terms, 0, 8)));
}

/**
 * "Did you mean": each word of the query that starts no word of any title or other name is swapped for the closest
 * word that does (one typo away for short words, up to three for long ones; common words win ties).
 * Null when nothing needs changing. The word list is kept beside the content database and rebuilt when it changes.
 */
function search_correction(string $q): ?string
{
    $fold = fn(string $t): string => mb_strtolower(preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t));
    $cache = config('data_dir') . '/search-words.json';
    $db = config('data_dir') . '/content.sqlite';
    $vocab = is_file($cache) && @filemtime($cache) >= @filemtime($db) ? json_decode((string) file_get_contents($cache), true) : null;
    if (!is_array($vocab)) {
        $vocab = [];
        foreach (db()->query("SELECT title, COALESCE(aka, '') aka FROM pages WHERE kind IN ('entry', 'guide', 'grimoire')") as $r) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $fold($r['title'] . ' ' . $r['aka']), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                if (mb_strlen($w) > 2) $vocab[$w] = ($vocab[$w] ?? 0) + 1;
            }
        }
        @file_put_contents($cache, json_encode($vocab, JSON_UNESCAPED_UNICODE));
    }
    $terms = array_slice(preg_split('/[^\p{L}\p{N}]+/u', $fold($q), -1, PREG_SPLIT_NO_EMPTY), 0, 8);
    $changed = false;
    foreach ($terms as &$t) {
        if (mb_strlen($t) < 3) continue;
        foreach ($vocab as $w => $n) if (str_starts_with((string) $w, $t)) continue 2;
        $max = mb_strlen($t) <= 4 ? 1 : (mb_strlen($t) <= 8 ? 2 : 3);
        $best = null; $bestD = $max + 1; $bestN = 0;
        foreach ($vocab as $w => $n) {
            $w = (string) $w;
            if (abs(strlen($w) - strlen($t)) > $max) continue;
            $d = levenshtein($t, $w);
            if ($d < $bestD || ($d === $bestD && $n > $bestN)) { $best = $w; $bestD = $d; $bestN = $n; }
        }
        if ($best !== null) { $t = $best; $changed = true; }
    }
    unset($t);
    return $changed ? implode(' ', $terms) : null;
}

function view_search(string $q): void
{
    if ($q === '') redirect(url());
    if (!has_access()) { view_gate(['search']); return; }
    $g = (string) ($_GET['g'] ?? '');
    // Few or no hits: try the query with its typos corrected. None at all: show the corrected results instead.
    $note = '';
    $hits = function (string $q): int {
        $m = fts_query($q);
        if ($m === '') return 0;
        $st = db()->prepare("SELECT COUNT(*) FROM search WHERE search MATCH ?");
        $st->execute(["{title aka plain} : $m"]);
        return (int) $st->fetchColumn();
    };
    $n = $hits($q);
    if ($n < 5 && ($c = search_correction($q)) !== null && $hits($c) > 0) {
        $link = '<a href="' . e(url('search?q=' . rawurlencode($c))) . '"><em>' . e($c) . '</em></a>';
        if ($n === 0) {
            $note = '<p class="did-you-mean">No pages match “' . e($q) . '”. Showing results for <em>' . e($c) . '</em>.</p>';
            $q = $c;
        } else {
            $note = '<p class="did-you-mean">Did you mean ' . $link . '?</p>';
        }
    }
    $match = fts_query($q);
    $rows = [];
    $counts = [];
    if ($match !== '') {
        $sql = "SELECT p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.aka, p.parent_id,
                snippet(search, 3, '<mark>', '</mark>', '…', 24) AS snip
            FROM search JOIN pages p ON p.id = search.id
            WHERE search MATCH ?" . ($g !== '' ? ' AND p.grimoire = ?' : '') . "
            ORDER BY bm25(search, 0, 12.0, 6.0, 1.0) LIMIT 100";
        $st = db()->prepare($sql);
        $st->execute($g !== '' ? ["{title aka plain} : $match", $g] : ["{title aka plain} : $match"]);
        $rows = $st->fetchAll();
        // How many hits in each grimoire, for the filter.
        $st = db()->prepare("SELECT p.grimoire, COUNT(*) n FROM search JOIN pages p ON p.id = search.id WHERE search MATCH ? GROUP BY p.grimoire");
        $st->execute(["{title aka plain} : $match"]);
        $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $parents = page_index(array_column($rows, 'parent_id'));
    $list = '';
    foreach ($rows as $r) {
        $where = $parents[$r['parent_id']]['title'] ?? '';
        // Snippet text is escaped, then our own <mark> tags are restored.
        $snip = str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], e($r['snip']));
        $list .= '<li><a href="' . e(page_url($r)) . '">' . icon_html($r['icon']) . '<strong>' . e($r['title']) . '</strong></a>'
            . ($where ? '<small>' . e($where) . '</small>' : '') . ($r['aka'] ? '<small class="aka-s">Also: ' . e($r['aka']) . '</small>' : '') . ($snip ? '<p>' . $snip . '</p>' : '') . '</li>';
    }
    $chips = '';
    if (count($counts) > 1 || $g !== '') {
        $roots = page_index(array_map(fn($k) => grimoire_root($k)['id'] ?? '', array_keys($counts)));
        $titles = [];
        foreach ($roots as $r) $titles[$r['grimoire']] = $r['title'];
        $base = url('search?q=' . rawurlencode($q));
        $chips = '<nav class="filters" aria-label="Filter by grimoire"><a class="chip" href="' . e($base) . '"' . ($g === '' ? ' aria-current="true"' : '') . '>All <small>' . array_sum($counts) . '</small></a>';
        arsort($counts);
        foreach ($counts as $slug => $n) {
            $chips .= '<a class="chip" href="' . e($base . '&g=' . rawurlencode($slug)) . '"' . ($g === $slug ? ' aria-current="true"' : '') . '>' . e($titles[$slug] ?? $slug) . ' <small>' . $n . '</small></a>';
        }
        $chips .= '</nav>';
    }
    $count = count($rows);
    $shown = $count >= 100 ? '100+' : (string) $count;
    $body = '<section class="mm-results"><p class="eyebrow">Search</p><h1>' . ($count ? $shown . ($count === 1 ? ' result' : ' results') . ' for ' . e($q) : 'Nothing found for ' . e($q)) . '</h1>'
        . $note . $chips
        . ($count ? '<ol class="results">' . $list . '</ol>' : '<p class="lede">Try another spelling, a shorter word, or a related term.</p>')
        . '</section>';
    layout('Search: ' . $q, $body, ['private' => true, 'protect' => true]);
}

/** Live suggestions for the search box: page titles and alternate names only. */
function search_suggest(string $q): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
    $match = fts_query($q);
    if (!has_access() || mb_strlen($q) < 2 || $match === '') { echo '[]'; return; }
    $st = db()->prepare("SELECT p.id, p.slug, p.grimoire, p.kind, p.title, p.icon, p.parent_id FROM search JOIN pages p ON p.id = search.id
        WHERE search MATCH ? ORDER BY bm25(search, 0, 10.0, 4.0, 0.0) LIMIT 8");
    $st->execute(["{title aka} : $match"]);
    $rows = $st->fetchAll();
    $did = null;
    if (!$rows && ($c = search_correction($q)) !== null && ($cm = fts_query($c)) !== '') {
        $st->execute(["{title aka} : $cm"]);
        $rows = $st->fetchAll();
        if ($rows) $did = $c;
    }
    $parents = page_index(array_column($rows, 'parent_id'));
    $list = array_map(fn($r) => [
        'title' => $r['title'],
        'url' => page_url($r),
        'where' => $parents[$r['parent_id']]['title'] ?? '',
        'icon' => ($r['icon'] && !preg_match('/^(file|asset):/', $r['icon'])) ? $r['icon'] : '', // emoji only
    ], $rows);
    // With a correction: {did, rows}, so the box can say "Did you mean …?"; otherwise the plain list.
    echo json_encode($did !== null ? ['did' => $did, 'rows' => $list] : $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
