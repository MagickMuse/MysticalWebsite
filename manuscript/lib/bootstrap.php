<?php
declare(strict_types=1);

define('MM_ROOT', dirname(__DIR__));

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $candidates = [
            getenv('MM_CONFIG') ?: '',
            dirname(MM_ROOT, 2) . '/manuscript-config.php',
            MM_ROOT . '/config.local.php',
        ];
        foreach ($candidates as $file) {
            if ($file !== '' && is_file($file)) {
                $cfg = require $file;
                break;
            }
        }
        if (!is_array($cfg)) {
            http_response_code(500);
            exit("The Manuscript is not configured yet. Copy manuscript/config.sample.php (see manuscript/README.md).\n");
        }
        // Written by the GitHub upload: where the private data folder is, relative to this app.
        if (is_file(MM_ROOT . '/deploy.local.php')) $cfg = array_merge($cfg, (array) require MM_ROOT . '/deploy.local.php');
        // The Manuscript's grimoires: manuscript/grimoires.php, unless the config lists its own.
        if (empty($cfg['grimoires']) && is_file(MM_ROOT . '/grimoires.php')) $cfg['grimoires'] = require MM_ROOT . '/grimoires.php';
        $cfg['base_url'] = rtrim($cfg['base_url'] ?? '', '/');
        $cfg['data_dir'] = rtrim(getenv('MM_DATA_DIR') ?: ($cfg['data_dir'] ?? (dirname(MM_ROOT, 2) . '/manuscript-data')), '/');
        // A relative data_dir is relative to the manuscript/ folder, e.g. '../../../manuscript-data-test'.
        if ($cfg['data_dir'] !== '' && $cfg['data_dir'][0] !== '/') $cfg['data_dir'] = MM_ROOT . '/' . $cfg['data_dir'];
        // Safety: the database and paid images must never sit inside the public website folder.
        $web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
        $dataParent = realpath(dirname($cfg['data_dir'])) ?: dirname($cfg['data_dir']);
        $dataPath = rtrim($dataParent, '/') . '/' . basename($cfg['data_dir']);
        $inWeb = ($web !== '' && $web !== '/' && str_starts_with($dataPath . '/', rtrim($web, '/') . '/'))
            || preg_match('~/(public_html|htdocs|httpdocs|www)(/|$)~', $dataPath);   // e.g. a test subdomain inside the main site's folder
        if ($inWeb && empty($cfg['allow_data_in_webroot'])) {
            http_response_code(500);
            exit("Setup problem: 'data_dir' ($dataPath) is inside the public website folder ($web), where it could be downloaded. "
                . "Set 'data_dir' in the config to a folder outside public_html (see manuscript/README.md).\n");
        }
    }
    return $key === null ? $cfg : ($cfg[$key] ?? $default);
}

/*
 * Two database files in data_dir:
 *   content.sqlite  the Manuscript itself (pages, cross-links, search). Built by the Notion importer,
 *                   which can run on GitHub and upload the finished file, so it is replaced as a whole.
 *   site.sqlite     members, sales, sign-in links. Only ever written by the live site, never replaced.
 * Queries don't need to say which file a table is in; SQLite finds it.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = config('data_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $dir . '/site.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $pdo->prepare('ATTACH DATABASE ? AS content')->execute([$dir . '/content.sqlite']);
    // The content file is uploaded as one piece, so it must not use a separate write-ahead log.
    $pdo->exec('PRAGMA content.journal_mode = DELETE;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS content.pages (
        id          TEXT PRIMARY KEY,           -- Notion id, 32 hex chars, no dashes
        slug        TEXT NOT NULL,
        grimoire    TEXT NOT NULL,              -- grimoire slug from config
        kind        TEXT NOT NULL,              -- grimoire | guide | collection | entry
        parent_id   TEXT,                       -- page or collection this lives under
        title       TEXT NOT NULL,
        icon        TEXT,                       -- emoji, or media file name
        cover       TEXT,                       -- media file name
        aka         TEXT,                       -- alternate names, searched too
        tags_json   TEXT,                       -- select / multi-select / text properties to show
        body_html   TEXT,
        plain       TEXT,                       -- body as text, for search
        sort        INTEGER DEFAULT 0,
        notion_edited TEXT,
        imported_at TEXT,
        UNIQUE (grimoire, slug)
    );
    CREATE INDEX IF NOT EXISTS content.pages_parent ON pages(parent_id);
    CREATE INDEX IF NOT EXISTS content.pages_kind ON pages(grimoire, kind);

    CREATE TABLE IF NOT EXISTS content.relations (
        from_id TEXT NOT NULL,
        prop    TEXT NOT NULL,                  -- property name, e.g. "Plants"
        to_id   TEXT NOT NULL,
        sort    INTEGER DEFAULT 0,
        PRIMARY KEY (from_id, prop, to_id)
    );
    CREATE INDEX IF NOT EXISTS content.relations_to ON relations(to_id);

    -- Archived or trashed duplicates in Notion, pointed at the live entry (to_id NULL: checked, no live copy found).
    CREATE TABLE IF NOT EXISTS content.aliases (
        id      TEXT PRIMARY KEY,
        to_id   TEXT,
        title   TEXT
    );

    CREATE VIRTUAL TABLE IF NOT EXISTS content.search USING fts5(id UNINDEXED, title, aka, plain, tokenize = 'porter unicode61 remove_diacritics 2');

    CREATE TABLE IF NOT EXISTS members (
        email        TEXT PRIMARY KEY,
        plan         TEXT,                      -- monthly | lifetime | comp
        status       TEXT NOT NULL,             -- active | past_due | canceled | disputed (chargeback open)
        stripe_customer TEXT,
        stripe_subscription TEXT,
        paid_through TEXT,
        note         TEXT,
        created_at   TEXT NOT NULL,
        updated_at   TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS members_customer ON members(stripe_customer);

    CREATE TABLE IF NOT EXISTS login_tokens (
        token_hash TEXT PRIMARY KEY,
        email      TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    );

    -- Every sale: Stripe checkouts, subscription renewals, and history imported from Gumroad.
    CREATE TABLE IF NOT EXISTS payments (
        id          TEXT PRIMARY KEY,           -- Stripe session/invoice id, or gumroad:<sale id>
        email       TEXT NOT NULL,
        product     TEXT NOT NULL,              -- product slug, or "membership-monthly" / "membership-lifetime"
        amount_cents INTEGER NOT NULL,
        currency    TEXT NOT NULL DEFAULT 'usd',
        refunded_cents INTEGER NOT NULL DEFAULT 0,
        source      TEXT NOT NULL,              -- stripe | gumroad
        intent      TEXT,                       -- Stripe payment_intent, to match refunds and disputes
        dispute     TEXT,                       -- chargeback: open | won | lost (NULL: none)
        created_at  TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS payments_created ON payments(created_at);
    CREATE INDEX IF NOT EXISTS payments_intent ON payments(intent);

    -- Who owns which shop product (courses, templates, freebies).
    CREATE TABLE IF NOT EXISTS purchases (
        email      TEXT NOT NULL,
        product    TEXT NOT NULL,
        source     TEXT NOT NULL,               -- stripe | free | gumroad | gift
        created_at TEXT NOT NULL,
        PRIMARY KEY (email, product)
    );

    -- Emails collected by free products.
    CREATE TABLE IF NOT EXISTS leads (
        email      TEXT NOT NULL,
        product    TEXT NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (email, product)
    );

    -- Page views per day (members reading the Manuscript, plus visits to the join and shop pages).
    CREATE TABLE IF NOT EXISTS page_views (
        page_id TEXT NOT NULL,                  -- Manuscript page id, or "route:/shop/…" for other pages
        day     TEXT NOT NULL,                  -- YYYY-MM-DD (UTC)
        views   INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (page_id, day)
    );
    -- Who read what, per day, as an anonymous fingerprint, to count different readers.
    CREATE TABLE IF NOT EXISTS page_readers (
        page_id TEXT NOT NULL,
        day     TEXT NOT NULL,
        reader  TEXT NOT NULL,
        PRIMARY KEY (page_id, day, reader)
    );
    CREATE INDEX IF NOT EXISTS page_readers_day ON page_readers(day);

    -- Pages members have starred to come back to.
    CREATE TABLE IF NOT EXISTS bookmarks (
        email      TEXT NOT NULL,
        page_id    TEXT NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (email, page_id)
    );
    CREATE INDEX IF NOT EXISTS bookmarks_page ON bookmarks(page_id);

    CREATE TABLE IF NOT EXISTS stripe_events (
        id TEXT PRIMARY KEY,
        received_at TEXT NOT NULL
    );
    SQL);
    // Columns added after the first release: CREATE TABLE IF NOT EXISTS leaves existing tables as they were.
    $cols = array_column($pdo->query('PRAGMA table_info(payments)')->fetchAll(), 'name');
    if (!in_array('dispute', $cols, true)) $pdo->exec('ALTER TABLE payments ADD COLUMN dispute TEXT');
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function url(string $path = ''): string { return config('base_url') . '/' . ltrim($path, '/'); }

function slugify(string $s): string
{
    $s = trim($s);
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    } else {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 80) : 'page';
}

function page_url(array $p): string
{
    if ($p['kind'] === 'grimoire') return url($p['grimoire']);
    return url($p['grimoire'] . '/' . $p['slug']);
}

function media_url(?string $file): ?string
{
    if ($file && preg_match('~^https://~', $file)) return $file; // a picture the site links to (site-covers.php)
    return $file ? url('media/' . rawurlencode($file)) : null;
}

function normalize_id(string $id): string
{
    return strtolower(str_replace('-', '', $id));
}

/** Links that only work inside Notion (the Notion widgets), left out of the Manuscript. */
function skip_links_pattern(): string
{
    return (string) config('skip_links', '~^https?://magickmuse\\.github\\.io/Widgets/~i');
}
