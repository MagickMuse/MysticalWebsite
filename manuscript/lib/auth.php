<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = str_starts_with(config('base_url'), 'https://');
    session_name('mm_session');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 60,          // stay signed in for 60 days
        'path'     => '/',                   // shared by the wiki and the /market pages
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 60));
    $dir = config('data_dir') . '/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    session_start();
}

function current_email(): ?string
{
    start_session();
    return $_SESSION['email'] ?? null;
}

function member_row(?string $email): ?array
{
    if (!$email) return null;
    $st = db()->prepare('SELECT * FROM members WHERE email = ?');
    $st->execute([strtolower($email)]);
    return $st->fetch() ?: null;
}

function is_admin(?string $email = null): bool
{
    $email = $email ?? current_email();
    if (!$email) return false;
    return in_array(strtolower($email), array_map('strtolower', config('admins', [])), true);
}

/** True when the signed-in reader may see full entries. */
function has_access(): bool
{
    $email = current_email();
    if (!$email) return false;
    if (is_admin($email)) return true;
    return member_has_access(member_row($email));
}

/** Whether a members row grants access (admins aside). */
function member_has_access(?array $m): bool
{
    if (!$m) return false;
    if ($m['status'] === 'active') return true;
    if ($m['status'] === 'disputed') return false; // paused while the bank looks at a chargeback, whatever the plan
    // Grace: a canceled or past-due subscription keeps access until the paid period ends.
    return $m['paid_through'] !== null && $m['paid_through'] >= now();
}

function upsert_member(string $email, array $fields): void
{
    $email = strtolower(trim($email));
    $existing = member_row($email);
    $fields['updated_at'] = now();
    if ($existing) {
        // A lifetime or comp member never gets downgraded by a later subscription event.
        if (in_array($existing['plan'], ['lifetime', 'comp'], true) && $existing['status'] === 'active'
            && ($fields['plan'] ?? $existing['plan']) === 'monthly') {
            unset($fields['plan'], $fields['status'], $fields['paid_through']);
        }
        if (!$fields) return;
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $st = db()->prepare("UPDATE members SET $sets WHERE email = :email");
        $st->execute($fields + ['email' => $email]);
    } else {
        $fields += ['plan' => null, 'status' => 'active', 'created_at' => now()];
        $fields['email'] = $email;
        $cols = implode(', ', array_keys($fields));
        $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        db()->prepare("INSERT INTO members ($cols) VALUES ($vals)")->execute($fields);
    }
}

function owns(string $email, string $product): bool
{
    $st = db()->prepare('SELECT 1 FROM purchases WHERE email = ? AND product = ?');
    $st->execute([strtolower($email), $product]);
    return (bool) $st->fetchColumn();
}

function grant(string $email, string $product, string $source): void
{
    db()->prepare('INSERT OR IGNORE INTO purchases (email, product, source, created_at) VALUES (?, ?, ?, ?)')
        ->execute([strtolower(trim($email)), $product, $source, now()]);
}

/** Has this email bought or been given anything at all (so it may receive a sign-in link)? */
function is_customer(string $email): bool
{
    $st = db()->prepare('SELECT 1 FROM purchases WHERE email = ? LIMIT 1');
    $st->execute([strtolower($email)]);
    return (bool) $st->fetchColumn() || member_row($email) !== null;
}

// ---------- magic-link sign in ----------

function issue_login_token(string $email): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM login_tokens WHERE expires_at < ?')->execute([time()]);
    db()->prepare('INSERT INTO login_tokens (token_hash, email, expires_at) VALUES (?, ?, ?)')
        ->execute([hash_hmac('sha256', $token, config('app_secret')), strtolower($email), time() + 30 * 60]);
    return $token;
}

function redeem_login_token(string $token): ?string
{
    $hash = hash_hmac('sha256', $token, config('app_secret'));
    $st = db()->prepare('SELECT email, expires_at FROM login_tokens WHERE token_hash = ?');
    $st->execute([$hash]);
    $row = $st->fetch();
    db()->prepare('DELETE FROM login_tokens WHERE token_hash = ?')->execute([$hash]);
    if (!$row || $row['expires_at'] < time()) return null;
    return $row['email'];
}

function sign_in(string $email): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['email'] = strtolower($email);
}

function sign_out(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

// ---------- CSRF + throttling ----------

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function check_csrf(): void
{
    start_session();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form expired. Go back, refresh the page, and try again.');
    }
}

/**
 * Per-address limit on sign-in emails, kept in the database. The per-session throttle() below is skipped
 * by anyone who simply doesn't send the cookie, which would let a script flood a customer's inbox.
 */
function too_many_links(string $email, int $max = 3, int $seconds = 900): bool
{
    // A token lives 30 minutes, so one issued in the last $seconds expires after now + 30 min - $seconds.
    $st = db()->prepare('SELECT COUNT(*) FROM login_tokens WHERE email = ? AND expires_at > ?');
    $st->execute([strtolower($email), time() + 30 * 60 - $seconds]);
    return (int) $st->fetchColumn() >= $max;
}

/** Simple per-session limit so the login form can't be used to spam inboxes. */
function throttle(string $key, int $max, int $seconds): bool
{
    start_session();
    $now = time();
    $hits = array_filter($_SESSION['throttle'][$key] ?? [], fn($t) => $t > $now - $seconds);
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    $_SESSION['throttle'][$key] = array_values($hits);
    return true;
}

/** Only same-site paths may be used as a post-login destination. */
function safe_next(?string $next): string
{
    $next = (string) $next;
    return preg_match('~^/(?![/\\\\])[^\s]*$~', $next) ? $next : (parse_url(config('base_url'), PHP_URL_PATH) ?: '/');
}
