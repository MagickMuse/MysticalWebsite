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
/** Thrown when the importer's time budget runs out; everything saved so far stays, and the next run continues. */
final class ImportPaused extends RuntimeException {}

final class NotionImporter
{
    private ?float $deadline = null;

    /** Stop cleanly after this many minutes (GitHub ends any job after 6 hours). */
    private bool $guidesOnly = false;

    /** Bring over grimoire and guide pages only, leaving the (slow, numerous) database entries for later. */
    public function setGuidesOnly(bool $on): void { $this->guidesOnly = $on; }

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

    // ------------------------------------------------------------------ HTTP

    private function call(string $method, string $path, ?array $body = null): array
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
                    'Notion-Version: 2022-06-28',
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
            throw new RuntimeException("Notion $method $path → $code " . ($data['message'] ?? ''));
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
        $this->importPage($page, 'grimoire', null, 0, $slug);

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
        $ctx = ['page' => $id, 'children' => []];
        $html = $this->renderChildren($page['id'], $ctx);
        $plain = self::plainFromHtml($html);

        $icon = $this->icon($page['icon'] ?? null, $id . '-icon');
        // Grimoire pages use the website's photos (grimoire-photos.php), not their Notion covers.
        $cover = $kind === 'grimoire' ? null : $this->fileUrl($page['cover'] ?? null);
        // The file name changes when the picture does, so a new cover in Notion replaces the old one.
        $cover = $cover ? $this->download($cover, $id . '-cover-' . self::version($cover)) : null;

        $this->savePage([
            'id' => $id, 'slug' => $slug, 'grimoire' => $this->grimoire, 'kind' => $kind,
            'parent_id' => $parentId, 'title' => $title, 'icon' => $icon, 'cover' => $cover,
            'aka' => $aka, 'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
            'body_html' => $html, 'plain' => $plain, 'sort' => $sort,
            'notion_edited' => $edited, 'imported_at' => now(),
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
        if (preg_match((string) config('skip_databases', '/counter/i'), $title)) {
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
            case 'heading_1': case 'heading_2': case 'heading_3':
                // Notion-only sections (e.g. "Notion tips for new readers") are left out, with their contents.
                if (!empty($d['is_toggleable']) && self::skipSection($this->plainText($d['rich_text'] ?? []))) return '';
                // Page titles are h1, so Notion's three heading levels map to h2–h4.
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
                return $u && ($d['type'] ?? '') === 'external' ? '<p><a href="' . e($u) . '" rel="noopener">' . e(ucfirst($type)) . '</a></p>' : '';
            case 'bookmark': case 'embed': case 'link_preview':
                $u = $d['url'] ?? '';
                return $u ? '<p class="bookmark"><a href="' . e($u) . '" rel="noopener">' . e($this->plainText($d['caption'] ?? []) ?: $u) . '</a></p>' : '';
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
            elseif ($href) $s = '<a href="' . e($href) . "\" rel=\"noopener\">$s</a>";
            $out .= $s;
        }
        return $out;
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
            if (($prop['type'] ?? '') === 'title') return $this->plainText($prop['title']) ?: 'Untitled';
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
        return $u ? 'file:' . $this->download($u, $name . '-' . self::version($u)) : null;
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
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if (!$ok || !$bytes) { ($this->log)("  ! image download failed for $name"); return null; }
        file_put_contents("$dir/$file", $bytes);
        $this->stats['images']++;
        return $file;
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
