<?php
declare(strict_types=1);

/**
 * Notion → Manuscript importer.
 *
 * Walks a grimoire's landing page: its text becomes the grimoire intro, sub-pages become guide
 * pages, inline databases become collections, and every database row becomes an entry with
 * its relations kept, so cross-links (Protection → Hekate → Rosemary…) survive the move.
 * Re-running only re-downloads pages edited in Notion since the last run.
 */
/** A Notion API call that failed (the HTTP status is the exception code). */
final class NotionError extends RuntimeException {}

/** Thrown when the importer's time budget runs out; everything saved so far stays, and the next run continues. */
class ImportPaused extends RuntimeException {}

final class NotionImporter
{
    private ?float $deadline = null;
    private bool $guidesOnly = false;

    /** Bring over grimoire and guide pages only, leaving the (slow, numerous) database entries for later. */
    public function setGuidesOnly(bool $on): void { $this->guidesOnly = $on; }

    /** Stop cleanly after this many minutes (GitHub ends any job after 6 hours). */
    public function setTimeLimit(?int $minutes): void { $this->deadline = $minutes ? microtime(true) + $minutes * 60 : null; }

    private string $api;
    private string $token;
    private float $lastCall = 0;
    private array $seen = [];
    private array $stats = ['fetched' => 0, 'skipped' => 0, 'images' => 0, 'errors' => 0];
    private string $grimoire = '';
    /** @var callable */
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->api = rtrim((string) config('notion_api', 'https://api.notion.com/v1'), '/');
        $this->token = (string) config('notion_token');
        $this->log = $log ?? fn(string $m) => fwrite(STDERR, $m . "\n");
    }

    public function stats(): array { return $this->stats; }

    /**
     * Links that point at a page not on the site are usually to an archived or trashed duplicate in Notion.
     * Each one is matched to the live entry in the same grimoire: same name, the name in its "also known as",
     * singular/plural, or a longer name that starts with it ("Tonka Bean" → "Tonka"). Only a single clear match is used.
     */
    public function linkDuplicates(): void
    {
        $missing = db()->query('SELECT DISTINCT r.to_id FROM relations r LEFT JOIN pages p ON p.id = r.to_id WHERE p.id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach (db()->query("SELECT body_html FROM pages WHERE body_html LIKE '%data-mm-id%'") as $r) {
            preg_match_all('/data-mm-id="([0-9a-f]{32})"/', $r['body_html'], $m);
            $missing = array_merge($missing, $m[1]);
        }
        $missing = array_values(array_unique($missing));
        if (!$missing) return;
        $have = array_flip(db()->query('SELECT id FROM pages')->fetchAll(PDO::FETCH_COLUMN));
        $done = array_flip(db()->query('SELECT id FROM aliases WHERE to_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)); // the rest are checked again
        $save = db()->prepare('INSERT OR REPLACE INTO aliases (id, to_id, title) VALUES (?, ?, ?)');
        $linked = 0;
        foreach ($missing as $id) {
            if (isset($have[$id]) || isset($done[$id])) continue;
            try {
                $page = $this->call('GET', "/pages/$id", null, '2025-09-03');
            } catch (NotionError $ex) {
                if ($ex->getCode() === 404 || $ex->getCode() === 400) { $save->execute([$id, null, null]); continue; } // private or outside the Manuscript
                throw $ex;
            }
            $title = $this->pageTitle($page);
            $to = null;
            if (!empty($page['is_archived']) || !empty($page['in_trash']) || !empty($page['archived'])) {
                $db = normalize_id((string) ($page['parent']['database_id'] ?? ''));
                $st = db()->prepare('SELECT grimoire FROM pages WHERE id = ?');
                $st->execute([$db]);
                $g = $st->fetchColumn();
                $manual = (array) config('duplicates', ['Coatlicue' => 'entity/coatlique']);
                if (isset($manual[$title])) {
                    [$mg, $ms] = explode('/', $manual[$title], 2) + [1 => ''];
                    $q = db()->prepare('SELECT id FROM pages WHERE grimoire = ? AND slug = ?');
                    $q->execute([$mg, $ms]);
                    $to = $q->fetchColumn() ?: null;
                } else {
                    $to = $g ? self::liveCopy($g, $title, $id, $this->scientificNames($page)) : null;
                }
            }
            $save->execute([$id, $to, $title]);
            if ($to) $linked++;
        }
        ($this->log)("Archived duplicates: $linked links now go to the live entry.");
    }

    /**
     * Pages with the same name in the same list are duplicates: the one with the most content stays,
     * the others' connections move onto it, and links to them go to it.
     */
    public function mergeDuplicates(): void
    {
        $groups = db()->query("SELECT parent_id, lower(trim(title)) AS t FROM pages WHERE kind = 'entry' GROUP BY 1, 2 HAVING COUNT(*) > 1")->fetchAll();
        $merged = 0;
        foreach ($groups as $g) {
            $st = db()->prepare("SELECT p.id, p.title, p.slug, length(p.body_html) AS len,
                    (SELECT COUNT(*) FROM relations r WHERE r.from_id = p.id OR r.to_id = p.id) AS links
                FROM pages p WHERE p.kind = 'entry' AND p.parent_id = ? AND lower(trim(p.title)) = ?
                ORDER BY len DESC, links DESC, p.id");
            $st->execute([$g['parent_id'], $g['t']]);
            $rows = $st->fetchAll();
            $keep = array_shift($rows);
            foreach ($rows as $dup) {
                db()->prepare('UPDATE OR IGNORE relations SET from_id = ? WHERE from_id = ?')->execute([$keep['id'], $dup['id']]);
                db()->prepare('UPDATE OR IGNORE relations SET to_id = ? WHERE to_id = ?')->execute([$keep['id'], $dup['id']]);
                db()->prepare('DELETE FROM relations WHERE (from_id = ? OR to_id = ?) OR (from_id = ? AND to_id = ?)')->execute([$dup['id'], $dup['id'], $keep['id'], $keep['id']]);
                db()->prepare('INSERT OR REPLACE INTO aliases (id, to_id, title) VALUES (?, ?, ?)')->execute([$dup['id'], $keep['id'], $dup['title']]);
                foreach (['DELETE FROM pages WHERE id = ?', 'DELETE FROM search WHERE id = ?'] as $sql) db()->prepare($sql)->execute([$dup['id']]);
                // The page that stays takes the plain address (coven, not coven-2).
                if (strlen($dup['slug']) < strlen($keep['slug'])) {
                    db()->prepare('UPDATE pages SET slug = ? WHERE id = ?')->execute([$dup['slug'], $keep['id']]);
                    $keep['slug'] = $dup['slug'];
                }
                ($this->log)("  – duplicate “{$dup['title']}” merged into the fuller copy");
                $merged++;
            }
        }
        if ($merged) ($this->log)("Duplicates: $merged merged.");
    }

    /**
     * Rules the site applies to what came from Notion, after every import (also to pages not re-downloaded):
     *  - grimoires without pictures (config "no_pictures", default the Entity Grimoire): no cover, no picture icon,
     *    no pictures in the text or in picture properties
     *  - icons set by the site (manuscript/site-icons.php), e.g. the astrological symbols for planets and signs
     */
    /**
     * The Library (config "library_db"): the books the Manuscript draws on, saved to data_dir/library.json for /library.
     * Only title, author and topics are kept: the reading-progress columns (status, pages read) stay in Notion.
     */
    public function importLibrary(): void
    {
        $db = normalize_id((string) config('library_db', '22e16dcf71648045bb89f82e5ebefc8c'));
        $books = []; $cursor = null;
        do {
            $r = $this->call('POST', "/databases/$db/query", ['page_size' => 100] + ($cursor ? ['start_cursor' => $cursor] : []));
            foreach ($r['results'] ?? [] as $row) {
                if (!empty($row['archived']) || !empty($row['in_trash'])) continue;
                $title = $author = ''; $topics = [];
                foreach ($row['properties'] ?? [] as $name => $prop) {
                    $t = $prop['type'] ?? '';
                    if ($t === 'title') $title = $this->plainText($prop['title']);
                    elseif ($t === 'rich_text' && strcasecmp(trim($name), 'Author') === 0) $author = $this->plainText($prop['rich_text']);
                    elseif ($t === 'multi_select' && stripos($name, 'Topic') !== false) $topics = array_column($prop['multi_select'], 'name');
                }
                if ($title !== '') $books[] = ['title' => $title, 'author' => $author, 'topics' => $topics];
            }
            $cursor = !empty($r['has_more']) ? ($r['next_cursor'] ?? null) : null;
        } while ($cursor);
        if (!$books) return; // keep the last good list if Notion returned nothing
        usort($books, fn($a, $b) => strnatcasecmp(preg_replace('/^(the|a|an)\s+/i', '', $a['title']), preg_replace('/^(the|a|an)\s+/i', '', $b['title'])));
        file_put_contents(config('data_dir') . '/library.json', json_encode($books, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        ($this->log)('  ◦ The Library: ' . count($books) . ' books');
    }

    public function applySiteRules(): void
    {
        $none = (array) config('no_pictures', ['entity']);
        $n = 0;
        foreach ($none as $g) {
            $st = db()->prepare("SELECT id, body_html, tags_json FROM pages WHERE grimoire = ? AND (cover IS NOT NULL OR icon LIKE 'file:%' OR body_html LIKE '%<img%' OR tags_json LIKE '%_images%')");
            $st->execute([$g]);
            $up = db()->prepare("UPDATE pages SET cover = NULL, icon = CASE WHEN icon LIKE 'file:%' THEN NULL ELSE icon END, body_html = ?, tags_json = ? WHERE id = ?");
            foreach ($st->fetchAll() as $p) {
                $tags = json_decode((string) $p['tags_json'], true) ?: [];
                unset($tags['_images']);
                $body = preg_replace('~<figure>.*?</figure>~s', '', (string) $p['body_html']);
                $up->execute([$body, json_encode($tags, JSON_UNESCAPED_UNICODE), $p['id']]);
                $n++;
            }
        }
        db()->exec("UPDATE pages SET icon = NULL WHERE icon = 'file:'"); // icons whose picture never downloaded
        $icons = is_file(MM_ROOT . '/site-icons.php') ? (array) require MM_ROOT . '/site-icons.php' : [];
        $set = db()->prepare('UPDATE pages SET icon = ? WHERE grimoire = ? AND slug = ?');
        foreach ($icons as $ref => $file) {
            [$g, $slug] = explode('/', $ref, 2);
            $set->execute(['asset:' . $file, $g, $slug]);
        }
        $this->intentionIcons();
        // Covers the site chooses (site-covers.php) replace the Notion cover.
        $covers = is_file(MM_ROOT . '/site-covers.php') ? (array) require MM_ROOT . '/site-covers.php' : [];
        $setCover = db()->prepare('UPDATE pages SET cover = ? WHERE grimoire = ? AND slug = ?');
        foreach ($covers as $ref => $src) {
            [$g, $slug] = array_pad(explode('/', $ref, 2), 2, '');
            $setCover->execute([$src, $g, $slug]);
        }
        ($this->log)("Site rules: pictures taken off $n page(s); " . count($icons) . ' icons set by the site.');
    }

    /** Scientific or Latin names on a Notion page (property name contains "scientific", "latin" or "botanical"). */
    /**
     * Rituals and recipes without an icon get the icon of their intention, from the correspondences:
     * one they link to that their title names ("Protection Oil"), else the one their category names (Divination),
     * else the first linked action, else the first linked one, else an intention their title names,
     * else an icon for their type (Oil, Powder, Incense…). The same rules made the GitHub Pages copy's icons.
     */
    private function intentionIcons(): void
    {
        $tagList = function (?string $json, string $name): array {
            $v = (json_decode((string) $json, true) ?: [])[$name] ?? [];
            return array_values(array_filter(array_map('strval', is_array($v) ? $v : [$v]), 'strlen'));
        };
        $words = fn(string $t): array => preg_match_all('/[a-z]+/', mb_strtolower($t), $m) ? $m[0] : [];
        $namedIn = function (string $title, string $name) use ($words): bool {
            $tw = $words($title); $nw = $words($name);
            if (!$nw) return false;
            if (count($nw) === 1) {
                foreach ($tw as $w) if ($w === $nw[0] || (strlen($nw[0]) >= 5 && strlen($w) >= 5 && substr($w, 0, 5) === substr($nw[0], 0, 5))) return true;
                return false;
            }
            return str_contains(implode(' ', $tw), implode(' ', $nw));
        };
        $cors = [];
        $rows = db()->query("SELECT id, title, icon, tags_json FROM pages WHERE grimoire = 'correspondence' AND kind = 'entry' AND icon IS NOT NULL AND icon NOT LIKE 'file:%' AND icon NOT LIKE 'asset:%'");
        foreach ($rows as $c) {
            $type = $tagList($c['tags_json'], 'Type');
            $cors[$c['id']] = [$c['title'], $c['icon'], in_array('Action', $type, true), (bool) array_intersect($type, ['Action', 'State/Quality', 'Emotion'])];
        }
        if (!$cors) return;
        uasort($cors, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $fallback = ['oil' => '💧', 'powder' => '🧂', 'incense' => '🔥', 'ink' => '🖋️', 'bath blend' => '🛁', 'bath' => '🛁', 'tea' => '🍵', 'water' => '💧'];
        $links = db()->prepare("SELECT to_id FROM relations WHERE from_id = ? AND prop = 'Correspondences' ORDER BY sort");
        $set = db()->prepare('UPDATE pages SET icon = ? WHERE id = ?');
        $n = 0;
        foreach (db()->query("SELECT id, grimoire, title, tags_json FROM pages WHERE grimoire IN ('ritual', 'recipe') AND kind = 'entry' AND (icon IS NULL OR icon = '')")->fetchAll() as $p) {
            $links->execute([$p['id']]);
            $linked = array_values(array_filter($links->fetchAll(PDO::FETCH_COLUMN), fn($id) => isset($cors[$id])));
            $cat = $tagList($p['tags_json'], $p['grimoire'] === 'recipe' ? 'Type' : 'Category')[0] ?? '';
            $icon = null;
            foreach ($linked as $c) if ($namedIn($p['title'], $cors[$c][0])) { $icon = $cors[$c][1]; break; }
            if (!$icon && $cat !== '') foreach ($cors as $c) if (mb_strtolower($c[0]) === mb_strtolower($cat)) { $icon = $c[1]; break; }
            if (!$icon) foreach ($linked as $c) if ($cors[$c][2]) { $icon = $cors[$c][1]; break; }
            if (!$icon && $linked) $icon = $cors[$linked[0]][1];
            if (!$icon) {
                $tw = ' ' . implode(' ', $words($p['title'])) . ' ';
                foreach ($cors as $c) {
                    if ($c[3] && $words($c[0]) && !in_array(mb_strtolower($c[0]), ['sweet', 'construction'], true)
                        && str_contains($tw, ' ' . implode(' ', $words($c[0])) . ' ')) { $icon = $c[1]; break; }
                }
            }
            $icon ??= $fallback[mb_strtolower($cat)] ?? ($p['grimoire'] === 'recipe' ? '🌿' : '✨');
            $set->execute([$icon, $p['id']]);
            $n++;
        }
        if ($n) ($this->log)("Site rules: $n ritual/recipe page(s) given their intention's icon.");
    }

    private function scientificNames(array $page): array
    {
        $out = [];
        foreach ($page['properties'] ?? [] as $name => $prop) {
            if (!preg_match('/scientific|latin|botanical/i', $name)) continue;
            $v = $prop[$prop['type']] ?? null;
            $text = is_array($v) && isset($v[0]['plain_text']) ? $this->plainText($v) : (is_array($v) ? implode(',', array_column($v, 'name')) : (string) ($v['name'] ?? $v ?? ''));
            foreach (preg_split('/[,;]/', $text) as $t) if (trim($t) !== '') $out[] = trim($t);
        }
        return $out;
    }

    private static function liveCopy(string $grimoire, string $title, string $id, array $sci = []): ?string
    {
        $norm = fn(string $t) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: $t)));
        $one = fn(string $t) => preg_replace('/(ies)$/', 'y', preg_replace('/(?<=[^s])s$/', '', $t));
        $want = $norm($title);
        if ($want === '') return null;
        $st = db()->prepare("SELECT id, title, aka, tags_json FROM pages WHERE grimoire = ? AND kind = 'entry' AND id != ?");
        $st->execute([$grimoire, $id]);
        $rows = $st->fetchAll();
        $tests = [
            fn($r) => $norm($r['title']) === $want,
            fn($r) => in_array($want, array_map($norm, preg_split('/[,;]/', (string) $r['aka'])), true),
            fn($r) => $one($norm($r['title'])) === $one($want),
            fn($r) => str_starts_with($want . ' ', $norm($r['title']) . ' ') || str_starts_with($norm($r['title']), $want . ' '),
        ];
        foreach ($tests as $test) {
            $hits = array_values(array_filter($rows, $test));
            if (count($hits) === 1) return $hits[0]['id'];
            if (count($hits) > 1) {
                // Several entries share the name (e.g. "Gravel Root"): the scientific name decides, else don't guess.
                $sciNorm = array_map($norm, $sci);
                $bySci = array_values(array_filter($hits, function ($r) use ($sciNorm, $norm) {
                    $names = preg_split('/[,;]/', (string) $r['aka']);
                    foreach ((array) json_decode((string) $r['tags_json'], true) as $prop => $vals) {
                        if (preg_match('/scientific|latin|botanical/i', (string) $prop)) $names = array_merge($names, (array) $vals);
                    }
                    return (bool) array_intersect($sciNorm, array_map($norm, $names));
                }));
                return $sciNorm && count($bySci) === 1 ? $bySci[0]['id'] : null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ HTTP

    private function call(string $method, string $path, ?array $body = null, string $version = '2022-06-28'): array
    {
        for ($attempt = 1; ; $attempt++) {
            // Notion allows ~3 requests per second.
            $wait = 0.34 - (microtime(true) - $this->lastCall);
            if ($wait > 0) usleep((int) ($wait * 1e6));
            $this->lastCall = microtime(true);

            $ch = curl_init($this->api . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token,
                    'Notion-Version: ' . $version,
                    'Content-Type: application/json',
                ],
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $headers = substr((string) $raw, 0, $hsize);
            $data = json_decode(substr((string) $raw, $hsize), true) ?: [];

            if ($code === 200) return $data;
            if (($code === 429 || $code >= 500 || $code === 0) && $attempt < 6) {
                $retry = preg_match('/retry-after:\s*(\d+)/i', $headers, $m) ? (int) $m[1] : 2 ** $attempt;
                ($this->log)("  … Notion said $code, waiting {$retry}s");
                sleep($retry);
                continue;
            }
            throw new NotionError("Notion $method $path → $code " . ($data['message'] ?? ''), $code);
        }
    }

    private function paginate(string $method, string $path, array $body = []): Generator
    {
        $cursor = null;
        do {
            if ($method === 'GET') {
                $q = $cursor ? (str_contains($path, '?') ? '&' : '?') . 'start_cursor=' . urlencode($cursor) : '';
                $res = $this->call('GET', $path . $q);
            } else {
                $res = $this->call('POST', $path, $body + ($cursor ? ['start_cursor' => $cursor] : []) + ['page_size' => 100]);
            }
            foreach ($res['results'] ?? [] as $r) yield $r;
            $cursor = $res['has_more'] ?? false ? $res['next_cursor'] : null;
        } while ($cursor);
    }

    // ------------------------------------------------------------------ walk

    public function importGrimoire(string $slug, string $pageId, bool $prune = false): void
    {
        $this->grimoire = $slug;
        $this->seen = [];
        $pageId = normalize_id($pageId);
        $page = $this->call('GET', "/pages/$pageId");
        $title = $this->pageTitle($page);
        ($this->log)("▸ $title ($slug)");
        $errorsBefore = $this->stats['errors'];
        $this->importPage($page, 'grimoire', null, 0, $slug);
        // Pages kept outside the grimoire in Notion that belong to it on the site (manuscript/extra-pages.php).
        $extra = is_file(MM_ROOT . '/extra-pages.php') ? (array) ((require MM_ROOT . '/extra-pages.php')[$slug] ?? []) : [];
        $n = 1000;
        foreach ($extra as $extraSlug => $extraId) {
            try {
                $this->importPage($this->call('GET', '/pages/' . normalize_id((string) $extraId)), 'guide', $pageId, $n++, (string) $extraSlug);
            } catch (ImportPaused $ex) {
                throw $ex;
            } catch (Throwable $ex) {
                $this->stats['errors']++;
                ($this->log)('  ! ' . $extraSlug . ': ' . $ex->getMessage());
            }
        }

        // A page or database that failed to load was never marked as seen: pruning now would delete it
        // (a whole database's entries, after one Notion hiccup). Only prune after a clean walk.
        if ($prune && $this->stats['errors'] > $errorsBefore) {
            ($this->log)("  ! $slug had errors, so nothing was removed this time (run again to prune)");
            $prune = false;
        }
        if ($prune) {
            $ids = array_keys($this->seen);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT id FROM pages WHERE grimoire = ? AND id NOT IN ($place)");
            $st->execute(array_merge([$slug], $ids));
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gone) $this->deletePage($gone);
        }
    }

    /** Imports a Notion page (landing page, guide page, or database row). */
    private function importPage(array $page, string $kind, ?string $parentId, int $sort, ?string $forceSlug = null): void
    {
        if ($this->deadline !== null && microtime(true) > $this->deadline) throw new ImportPaused('time limit reached');
        $id = normalize_id($page['id']);
        $this->seen[$id] = true;
        $title = $this->pageTitle($page);
        $edited = $page['last_edited_time'] ?? '';

        $existing = db()->prepare('SELECT notion_edited, slug FROM pages WHERE id = ?');
        $existing->execute([$id]);
        $row = $existing->fetch();
        $slug = $forceSlug ?? ($row['slug'] ?? $this->uniqueSlug($title, $id));

        // Properties (relations, tags, AKA) come with the page itself, so always refresh them.
        [$aka, $tags] = $kind === 'entry' ? $this->importProperties($id, $page) : [null, []];

        $unchanged = $row && $row['notion_edited'] === $edited && $kind !== 'grimoire';
        if ($unchanged) {
            $this->stats['skipped']++;
            db()->prepare('UPDATE pages SET parent_id = ?, sort = ?, aka = ?, tags_json = ? WHERE id = ?')
                ->execute([$parentId, $sort, $aka, json_encode($tags, JSON_UNESCAPED_UNICODE), $id]);
            // Guide pages and databases nested inside still need walking for their own changes,
            // but a landing page we skip contains them by reference only, so walk the stored children.
            $this->rewalkChildren($id);
            return;
        }

        $this->stats['fetched']++;
        $failedBefore = $this->stats['img_failed'] ?? 0;
        $ctx = ['page' => $id, 'children' => []];
        $html = $this->renderChildren($page['id'], $ctx);
        $plain = self::plainFromHtml($html);

        $icon = $this->icon($page['icon'] ?? null, $id . '-icon');
        // Grimoire pages use the website's photos (grimoire-photos.php), not their Notion covers.
        $cover = $kind === 'grimoire' ? null : $this->fileUrl($page['cover'] ?? null);
        // The file name changes when the picture does, so a new cover in Notion replaces the old one.
        $cover = $cover ? $this->transparent($this->download($cover, $id . '-cover-' . self::version($cover))) : null;

        $this->savePage([
            'id' => $id, 'slug' => $slug, 'grimoire' => $this->grimoire, 'kind' => $kind,
            'parent_id' => $parentId, 'title' => $title, 'icon' => $icon, 'cover' => $cover,
            'aka' => $aka, 'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'body_html' => $html, 'plain' => $plain, 'sort' => $sort,
            // A picture that could not be downloaded leaves the page marked as not yet imported, so the next run tries again.
            'notion_edited' => ($this->stats['img_failed'] ?? 0) > $failedBefore ? '' : $edited, 'imported_at' => now(),
        ]);

        foreach ($ctx['children'] as $i => [$childKind, $childId]) {
            if ($childKind === 'database' && $this->guidesOnly) continue;
            try {
                if ($childKind === 'page') {
                    $this->importPage($this->call('GET', "/pages/$childId"), 'guide', $id, $i);
                } else {
                    $this->importDatabase($childId, $id, $i);
                }
            } catch (ImportPaused $ex) {
                throw $ex;
            } catch (Throwable $ex) {
                $this->stats['errors']++;
                ($this->log)('  ! ' . $ex->getMessage());
            }
        }
    }

    private function rewalkChildren(string $id): void
    {
        $st = db()->prepare('SELECT id, kind, sort FROM pages WHERE parent_id = ? AND kind IN (\'guide\', \'collection\') ORDER BY sort');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $child) {
            if ($child['kind'] === 'collection' && $this->guidesOnly) continue;
            try {
                if ($child['kind'] === 'guide') {
                    $this->importPage($this->call('GET', '/pages/' . $child['id']), 'guide', $id, (int) $child['sort']);
                } else {
                    $this->importDatabase($child['id'], $id, (int) $child['sort']);
                }
            } catch (ImportPaused $ex) {
                throw $ex;
            } catch (Throwable $ex) {
                $this->stats['errors']++;
                ($this->log)('  ! ' . $ex->getMessage());
            }
        }
    }

    private function importDatabase(string $dbId, string $parentId, int $sort): void
    {
        $dbId = normalize_id($dbId);
        try {
            $meta = $this->call('GET', "/databases/$dbId");
        } catch (RuntimeException $ex) {
            // Linked views and helper databases (like the Counters) can't be read through the API: skip them quietly.
            if (str_contains($ex->getMessage(), '→ 400') || str_contains($ex->getMessage(), '→ 404')) {
                ($this->log)('  – skipping a database the importer cannot read (a linked view or helper)');
                return;
            }
            throw $ex;
        }
        $title = $this->plainText($meta['title'] ?? []) ?: 'Entries';
        if (preg_match((string) config('skip_databases', '/counter|^card totals$|^calendar$/i'), $title)) {
            ($this->log)("  – skipping database “{$title}”");
            return;
        }
        ($this->log)("  ◦ database “{$title}”");
        $this->seen[$dbId] = true;
        $this->savePage([
            'id' => $dbId, 'slug' => $this->uniqueSlug($title, $dbId), 'grimoire' => $this->grimoire,
            'kind' => 'collection', 'parent_id' => $parentId, 'title' => $title,
            'icon' => $this->icon($meta['icon'] ?? null, $dbId . '-icon'), 'cover' => null, 'aka' => null,
            'tags_json' => '[]', 'body_html' => '', 'plain' => $this->plainText($meta['description'] ?? []),
            'sort' => $sort, 'notion_edited' => $meta['last_edited_time'] ?? '', 'imported_at' => now(),
        ]);
        $n = 0;
        foreach ($this->paginate('POST', "/databases/$dbId/query", ['sorts' => [['timestamp' => 'created_time', 'direction' => 'ascending']]]) as $row) {
            try {
                $this->importPage($row, 'entry', $dbId, $n++);
            } catch (ImportPaused $ex) {
                throw $ex;
            } catch (Throwable $ex) {
                $this->stats['errors']++;
                ($this->log)('  ! ' . $ex->getMessage());
            }
            if ($n % 50 === 0) ($this->log)("    … $n entries");
        }
        ($this->log)("    $n entries");
    }

    /** @return array{0: ?string, 1: array} aka text and display tags */
    private function importProperties(string $id, array $page): array
    {
        $aka = null;
        $tags = [];
        db()->prepare('DELETE FROM relations WHERE from_id = ?')->execute([$id]);
        $ins = db()->prepare('INSERT OR IGNORE INTO relations (from_id, prop, to_id, sort) VALUES (?, ?, ?, ?)');

        foreach ($page['properties'] ?? [] as $name => $prop) {
            $name = trim($name);
            switch ($prop['type']) {
                case 'relation':
                    $targets = array_column($prop['relation'], 'id');
                    if (!empty($prop['has_more'])) {
                        try {
                            $targets = $this->allRelations($page['id'], $prop['id']);
                        } catch (ImportPaused $ex) {
                            throw $ex;
                        } catch (Throwable $ex) {
                            // Keep the links Notion already sent rather than losing the whole entry.
                            ($this->log)("  ~ kept the first " . count($targets) . " \"$name\" links on {$this->pageTitle($page)} (" . $ex->getMessage() . ')');
                        }
                    }
                    foreach ($targets as $i => $t) $ins->execute([$id, $name, normalize_id($t), $i]);
                    break;
                case 'rich_text':
                    $text = $this->plainText($prop['rich_text']);
                    if ($text === '') break;
                    if (preg_match('/other names|aka|also known|alias|common name/i', $name)) $aka = $text;
                    else $tags[$name] = [$text];
                    break;
                case 'select':
                    if ($prop['select']) $tags[$name] = [$prop['select']['name']];
                    break;
                case 'status':
                    break; // workflow status is for the curator, not readers
                case 'multi_select':
                    if ($prop['multi_select']) $tags[$name] = array_column($prop['multi_select'], 'name');
                    break;
                case 'number':
                    if ($prop['number'] !== null) $tags[$name] = [(string) $prop['number']];
                    break;
                case 'url':
                    if ($prop['url']) $tags[$name] = [$prop['url']];
                    break;
                case 'files':
                    foreach ($prop['files'] as $i => $f) {
                        $u = $this->fileUrl($f);
                        if ($u && ($file = $this->download($u, "$id-" . slugify($name) . "-$i"))) $tags['_images'][] = $file;
                    }
                    break;
            }
        }
        return [$aka, $tags];
    }

    private function allRelations(string $pageId, string $propId): array
    {
        $ids = [];
        foreach ($this->paginate('GET', '/pages/' . normalize_id($pageId) . '/properties/' . rawurlencode(rawurldecode($propId))) as $item) {
            if (isset($item['relation']['id'])) $ids[] = $item['relation']['id'];
        }
        return $ids;
    }

    // ------------------------------------------------------------------ blocks → HTML

    private function renderChildren(string $blockId, array &$ctx): string
    {
        $blocks = iterator_to_array($this->paginate('GET', '/blocks/' . normalize_id($blockId) . '/children?page_size=100'), false);
        return $this->renderBlocks($blocks, $ctx);
    }

    private function renderBlocks(array $blocks, array &$ctx): string
    {
        $out = '';
        $list = null;
        foreach ($blocks as $b) {
            $type = $b['type'];
            $listType = match ($type) { 'bulleted_list_item' => 'ul', 'numbered_list_item' => 'ol', 'to_do' => 'ul class="todo"', default => null };
            if ($list !== $listType) {
                if ($list) $out .= '</' . strtok($list, ' ') . '>';
                if ($listType) $out .= "<$listType>";
                $list = $listType;
            }
            $out .= $this->renderBlock($b, $ctx);
        }
        if ($list) $out .= '</' . strtok($list, ' ') . '>';
        return $out;
    }

    private function kids(array $b, array &$ctx): string
    {
        return !empty($b['has_children']) ? $this->renderChildren($b['id'], $ctx) : '';
    }

    private function renderBlock(array $b, array &$ctx): string
    {
        $type = $b['type'];
        $d = $b[$type] ?? [];
        $text = isset($d['rich_text']) ? $this->richText($d['rich_text']) : '';
        $color = isset($d['color']) && $d['color'] !== 'default' ? ' class="c-' . e($d['color']) . '"' : '';

        switch ($type) {
            case 'paragraph':
                $kids = $this->kids($b, $ctx);
                return ($text !== '' ? "<p$color>$text</p>" : '') . ($kids ? "<div class=\"indent\">$kids</div>" : '');
            case 'heading_1': case 'heading_2': case 'heading_3': case 'heading_4':
                // Notion-only sections (e.g. "Notion tips for new readers") are left out, with their contents.
                if (!empty($d['is_toggleable']) && self::skipSection($this->plainText($d['rich_text'] ?? []))) return '';
                // Page titles are h1, so Notion's heading levels map to h2–h5.
                $lvl = (int) substr($type, -1) + 1;
                $h = "<h$lvl id=\"" . e(slugify(strip_tags($text))) . "\"$color>$text</h$lvl>";
                if (!empty($d['is_toggleable'])) return "<details class=\"toggle h$lvl\"><summary>$h</summary>" . $this->kids($b, $ctx) . '</details>';
                return $h;
            case 'bulleted_list_item': case 'numbered_list_item':
                return "<li$color>$text" . $this->kids($b, $ctx) . '</li>';
            case 'to_do':
                $mark = !empty($d['checked']) ? '☑' : '☐';
                return "<li><span class=\"box\" aria-hidden=\"true\">$mark</span> $text" . $this->kids($b, $ctx) . '</li>';
            case 'toggle':
                if (self::skipSection($this->plainText($d['rich_text'] ?? []))) return '';
                return "<details class=\"toggle\"><summary>$text</summary>" . $this->kids($b, $ctx) . '</details>';
            case 'quote':
                return "<blockquote$color>$text" . $this->kids($b, $ctx) . '</blockquote>';
            case 'callout':
                $icon = $d['icon']['emoji'] ?? '';
                $cls = 'mm-callout' . (isset($d['color']) && $d['color'] !== 'default' ? ' c-' . e($d['color']) : '');
                return "<aside class=\"$cls\">" . ($icon ? '<span class="mm-callout-icon" aria-hidden="true">' . e($icon) . '</span>' : '')
                    . "<div>$text" . $this->kids($b, $ctx) . '</div></aside>';
            case 'divider':
                return '<div class="star-rule" role="separator"><i class="spark"></i></div>';
            case 'code':
                return '<pre><code>' . e($this->plainText($d['rich_text'])) . '</code></pre>';
            case 'equation':
                return '<p><code>' . e($d['expression'] ?? '') . '</code></p>';
            case 'image':
                $u = $this->fileUrl($d);
                $file = $u ? $this->download($u, normalize_id($b['id'])) : null;
                if (!$file) return '';
                $cap = $this->richText($d['caption'] ?? []);
                $alt = e($this->plainText($d['caption'] ?? []));
                return '<figure><img src="' . e(media_url($file)) . "\" alt=\"$alt\" loading=\"lazy\">" . ($cap ? "<figcaption>$cap</figcaption>" : '') . '</figure>';
            case 'video': case 'file': case 'pdf': case 'audio':
                $u = $this->fileUrl($d);
                return $u && ($d['type'] ?? '') === 'external' && self::safeUrl($u) ? '<p><a href="' . e($u) . '" rel="noopener">' . e(ucfirst($type)) . '</a></p>' : '';
            case 'bookmark': case 'embed': case 'link_preview':
                $u = $d['url'] ?? '';
                if ($u && preg_match(skip_links_pattern(), $u)) return ''; // Notion-only widgets
                return $u && self::safeUrl($u) ? '<p class="bookmark"><a href="' . e($u) . '" rel="noopener">' . e($this->plainText($d['caption'] ?? []) ?: $u) . '</a></p>' : '';
            case 'column_list':
                return '<div class="columns">' . $this->kids($b, $ctx) . '</div>';
            case 'column':
                return '<div class="column">' . $this->kids($b, $ctx) . '</div>';
            case 'table':
                $rows = iterator_to_array($this->paginate('GET', '/blocks/' . normalize_id($b['id']) . '/children?page_size=100'), false);
                $html = '<div class="table-wrap"><table>';
                foreach ($rows as $r => $row) {
                    $cellTag = ($r === 0 && !empty($d['has_column_header'])) ? 'th' : 'td';
                    $html .= '<tr>';
                    foreach ($row['table_row']['cells'] ?? [] as $c => $cell) {
                        $tag = ($c === 0 && !empty($d['has_row_header'])) ? 'th' : $cellTag;
                        $html .= "<$tag>" . $this->richText($cell) . "</$tag>";
                    }
                    $html .= '</tr>';
                }
                return $html . '</table></div>';
            case 'synced_block':
                $src = $d['synced_from']['block_id'] ?? null;
                return $src ? $this->renderChildren($src, $ctx) : $this->kids($b, $ctx);
            case 'child_page':
                // Pages about using Notion (e.g. "Plant Grimoire Guide") stay behind, links and all.
                if (preg_match((string) config('skip_pages', '/\\bgrimoire guide\\b/i'), (string) ($d['title'] ?? ''))) return '';
                $cid = normalize_id($b['id']);
                $ctx['children'][] = ['page', $cid];
                return '<p class="page-link"><a data-mm-id="' . $cid . '">' . e($d['title'] ?? 'Untitled') . '</a></p>';
            case 'child_database':
                $cid = normalize_id($b['id']);
                $ctx['children'][] = ['database', $cid];
                return '<div data-mm-collection="' . $cid . '"></div>';
            case 'link_to_page':
                $target = $d['page_id'] ?? $d['database_id'] ?? null;
                return $target ? '<p class="page-link"><a data-mm-id="' . normalize_id($target) . '">…</a></p>' : '';
            default:
                return ''; // table_of_contents, breadcrumb, unsupported → dropped
        }
    }

    private function richText(array $parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            $s = nl2br(e($p['plain_text'] ?? ''), false);
            $a = $p['annotations'] ?? [];
            if (!empty($a['code'])) $s = "<code>$s</code>";
            if (!empty($a['bold'])) $s = "<strong>$s</strong>";
            if (!empty($a['italic'])) $s = "<em>$s</em>";
            if (!empty($a['strikethrough'])) $s = "<s>$s</s>";
            if (!empty($a['underline'])) $s = "<u>$s</u>";
            if (!empty($a['color']) && $a['color'] !== 'default') $s = '<span class="c-' . e($a['color']) . "\">$s</span>";

            $target = null;
            if (($p['type'] ?? '') === 'mention') {
                $m = $p['mention'];
                $target = $m['page']['id'] ?? $m['database']['id'] ?? null;
            }
            $href = $p['href'] ?? ($p['text']['link']['url'] ?? null);
            if (!$target && $href && ($nid = self::notionIdFromUrl($href))) $target = $nid;

            if ($target) $s = '<a data-mm-id="' . normalize_id($target) . "\">$s</a>";
            elseif ($href && self::safeUrl($href)) $s = '<a href="' . e($href) . "\" rel=\"noopener\">$s</a>";
            $out .= $s;
        }
        return $out;
    }

    /** Only ordinary web, mail and same-site links are kept (no javascript: or data: links). */
    private static function safeUrl(string $u): bool
    {
        return (bool) preg_match('~^(https?://|mailto:|/|#)~i', trim($u));
    }

    /** Sections about using Notion that don't belong on the website. */
    private static function skipSection(string $title): bool
    {
        $titles = (array) config('skip_sections', ['Notion tips for new readers', 'Using search and comments']);
        $t = mb_strtolower(trim($title));
        foreach ($titles as $skip) if ($t === mb_strtolower(trim($skip))) return true;
        return false;
    }

    /** Short fingerprint of a file's address, ignoring Notion's expiring signature. */
    private static function version(string $url): string
    {
        return substr(hash('crc32b', (string) parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH)), 0, 8);
    }

    public static function plainFromHtml(string $html): string
    {
        $html = preg_replace('~<span class="mm-callout-icon".*?</span>~s', '', $html);
        $html = preg_replace('~<(/?(p|li|h\d|div|aside|summary|details|td|th|tr|blockquote|figcaption|ul|ol)\b[^>]*|br\s*/?)>~i', ' $0', $html);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    public static function notionIdFromUrl(string $href): ?string
    {
        if (str_starts_with($href, '/') && preg_match('~^/([0-9a-f]{32})~i', $href, $m)) return strtolower($m[1]);
        if (!preg_match('~^https?://([a-z0-9-]+\.)?(notion\.so|notion\.site|notion\.com)/~i', $href)) return null;
        $path = (string) parse_url($href, PHP_URL_PATH);
        return preg_match('~([0-9a-f]{32})(?![0-9a-f])~i', str_replace('-', '', $path), $m) ? strtolower($m[1]) : null;
    }

    // ------------------------------------------------------------------ helpers

    private function plainText(array $parts): string
    {
        return trim(implode('', array_column($parts, 'plain_text')));
    }

    private function pageTitle(array $page): string
    {
        foreach ($page['properties'] ?? [] as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                // a title of "0" is a real title (PHP would treat it as empty with ?:)
                $t = $this->plainText($prop['title']);
                return $t !== '' ? $t : 'Untitled';
            }
        }
        return 'Untitled';
    }

    private function fileUrl(?array $f): ?string
    {
        if (!$f) return null;
        return $f['file']['url'] ?? $f['external']['url'] ?? null;
    }

    private function icon(?array $icon, string $name): ?string
    {
        if (!$icon) return null;
        if (($icon['type'] ?? '') === 'emoji') return $icon['emoji'];
        $u = $this->fileUrl($icon);
        $file = $u ? $this->transparent($this->download($u, $name . '-' . self::version($u))) : null;
        return $file ? 'file:' . $file : null; // a picture that failed to download leaves no icon, not an empty one
    }

    /** Downloads an image into data_dir/media so it no longer depends on Notion's expiring links. */
    private function download(string $u, string $name): ?string
    {
        $dir = config('data_dir') . '/media';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $ext = strtolower(pathinfo((string) parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) $ext = 'jpg';
        $file = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) . '.' . $ext;
        if (is_file("$dir/$file") && filesize("$dir/$file") > 0) return $file;
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'MagickManuscript/1.0']);
        $bytes = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code !== 200 || !$bytes) {
            $this->stats['img_failed'] = ($this->stats['img_failed'] ?? 0) + 1;
            ($this->log)("  ! image download failed for $name (" . ($err ?: "HTTP $code") . ', ' . parse_url($u, PHP_URL_HOST) . ')');
            return null;
        }
        file_put_contents("$dir/$file", $bytes);
        $this->stats['images']++;
        return $file;
    }

    /**
     * A page icon or cover on a plain background (a stone photographed on white) gets that background made transparent,
     * saved as a PNG: from the edges, pixels close to the background colour are cleared, so a white stone in the middle
     * keeps its white. Card grimoire images (tarot, spreads, Lenormand: hand-made and hand-picked) are never touched; rune images keep theirs too ( black line runes would vanish
     * in dark mode). Busy photo backgrounds are left alone. Returns the file to use.
     */
    private function transparent(?string $file): ?string
    {
        if (!$file || in_array($this->grimoire, array_merge(['card'], (array) config('keep_backgrounds', ['rune'])), true) || !function_exists('imagecreatefromstring')) return $file;
        $dir = config('data_dir') . '/media';
        $png = preg_replace('/\.[a-z0-9]+$/', '', $file) . '-t.png';
        if (is_file("$dir/$png")) return $png;
        if (str_ends_with($file, '.svg')) return $file;
        $im = @imagecreatefromstring((string) @file_get_contents("$dir/$file"));
        if (!$im) return $file;
        if (max(imagesx($im), imagesy($im)) > 1400) { $im = imagescale($im, imagesx($im) >= imagesy($im) ? 1400 : (int) round(imagesx($im) * 1400 / imagesy($im))); }
        $w = imagesx($im); $h = imagesy($im);
        $rgb = function (int $x, int $y) use ($im): array { $c = imagecolorat($im, $x, $y); return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127]; };
        // already transparent at the edges: nothing to do
        if ($rgb(0, 0)[3] > 100 && $rgb($w - 1, $h - 1)[3] > 100) return $file;
        $pts = [];
        for ($x = 0; $x < $w; $x += max(1, intdiv($w, 60))) { $pts[] = $rgb($x, 0); $pts[] = $rgb($x, $h - 1); }
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 60))) { $pts[] = $rgb(0, $y); $pts[] = $rgb($w - 1, $y); }
        $keys = array_count_values(array_map(fn($p) => "$p[0],$p[1],$p[2]", $pts)); arsort($keys);
        $ref = array_map('intval', explode(',', (string) array_key_first($keys)));
        $diff = fn(array $p): int => max(abs($p[0] - $ref[0]), abs($p[1] - $ref[1]), abs($p[2] - $ref[2]));
        $near = count(array_filter($pts, fn($p) => $diff($p) <= 24)) / max(1, count($pts));
        if ($near < 0.9) return $file;
        $tol = 26; $clear = new \SplFixedArray($w * $h); $queue = new \SplQueue(); $n = 0;
        for ($x = 0; $x < $w; $x++) { $queue->enqueue([$x, 0]); $queue->enqueue([$x, $h - 1]); }
        for ($y = 0; $y < $h; $y++) { $queue->enqueue([0, $y]); $queue->enqueue([$w - 1, $y]); }
        while (!$queue->isEmpty()) {
            [$x, $y] = $queue->dequeue(); $i = $y * $w + $x;
            if ($clear[$i] !== null) continue;
            $clear[$i] = false;
            if ($diff($rgb($x, $y)) > $tol) continue;
            $clear[$i] = true; $n++;
            if ($x > 0) $queue->enqueue([$x - 1, $y]); if ($x < $w - 1) $queue->enqueue([$x + 1, $y]);
            if ($y > 0) $queue->enqueue([$x, $y - 1]); if ($y < $h - 1) $queue->enqueue([$x, $y + 1]);
        }
        $share = $n / ($w * $h);
        if ($share < 0.05 || $share > 0.97) return $file;
        $out = imagecreatetruecolor($w, $h); imagealphablending($out, false); imagesavealpha($out, true);
        for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
            [$r, $g, $b] = $rgb($x, $y); $i = $y * $w + $x;
            $alpha = 0;
            if ($clear[$i]) $alpha = 127;
            elseif (($x > 0 && $clear[$i - 1]) || ($x < $w - 1 && $clear[$i + 1]) || ($y > 0 && $clear[$i - $w]) || ($y < $h - 1 && $clear[$i + $w])) $alpha = 60; // soft edge
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
        }
        imagepng($out, "$dir/$png", 6);
        return $png;
    }

    private function uniqueSlug(string $title, string $id): string
    {
        $base = slugify($title);
        $slug = $base;
        $st = db()->prepare('SELECT id FROM pages WHERE grimoire = ? AND slug = ?');
        for ($i = 2; ; $i++) {
            $st->execute([$this->grimoire, $slug]);
            $owner = $st->fetchColumn();
            if (!$owner || $owner === $id) return $slug;
            $slug = "$base-$i";
        }
    }

    private function savePage(array $p): void
    {
        $cols = array_keys($p);
        $sql = 'INSERT INTO pages (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ":$c", $cols)) . ')'
            . ' ON CONFLICT(id) DO UPDATE SET ' . implode(',', array_map(fn($c) => "$c = excluded.$c", array_diff($cols, ['id'])));
        db()->prepare($sql)->execute($p);
        db()->prepare('DELETE FROM search WHERE id = ?')->execute([$p['id']]);
        if ($p['kind'] !== 'collection') {
            db()->prepare('INSERT INTO search (id, title, aka, plain) VALUES (?, ?, ?, ?)')->execute([$p['id'], $p['title'], $p['aka'] ?? '', $p['plain'] ?? '']);
        }
    }

    private function deletePage(string $id): void
    {
        ($this->log)("  – removed in Notion: $id");
        foreach (['DELETE FROM pages WHERE id = ?', 'DELETE FROM search WHERE id = ?', 'DELETE FROM relations WHERE from_id = ?'] as $sql) {
            db()->prepare($sql)->execute([$id]);
        }
    }
}
