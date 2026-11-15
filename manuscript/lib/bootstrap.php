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
        $cfg['base_url'] = rtrim($cfg['base_url'] ?? '', '/');
        $cfg['data_dir'] = rtrim($cfg['data_dir'] ?? (dirname(MM_ROOT, 2) . '/manuscript-data'), '/');
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

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = config('data_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $dir . '/manuscript.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS pages (
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
    CREATE INDEX IF NOT EXISTS pages_parent ON pages(parent_id);
    CREATE INDEX IF NOT EXISTS pages_kind ON pages(grimoire, kind);

    CREATE TABLE IF NOT EXISTS relations (
        from_id TEXT NOT NULL,
        prop    TEXT NOT NULL,                  -- property name, e.g. "Plants"
        to_id   TEXT NOT NULL,
        sort    INTEGER DEFAULT 0,
        PRIMARY KEY (from_id, prop, to_id)
    );
    CREATE INDEX IF NOT EXISTS relations_to ON relations(to_id);

    CREATE VIRTUAL TABLE IF NOT EXISTS search USING fts5(id UNINDEXED, title, aka, plain, tokenize = 'porter unicode61 remove_diacritics 2');

    CREATE TABLE IF NOT EXISTS members (
        email        TEXT PRIMARY KEY,
        plan         TEXT,                      -- monthly | lifetime | comp
        status       TEXT NOT NULL,             -- active | past_due | canceled
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
        intent      TEXT,                       -- Stripe payment_intent, to match refunds
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

    CREATE TABLE IF NOT EXISTS stripe_events (
        id TEXT PRIMARY KEY,
        received_at TEXT NOT NULL
    );
    SQL);
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
    return $file ? url('media/' . rawurlencode($file)) : null;
}

function normalize_id(string $id): string
{
    return strtolower(str_replace('-', '', $id));
}
