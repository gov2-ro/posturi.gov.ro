<?php
/**
 * UX-14 — email alerts: the storage and rules of the shared host.
 *
 * ADR 0003 and docs/briefs/2026-11-15-email-alerts.md. The host owns the
 * subscriptions. It can seal an address to the VPS public key but cannot open
 * it, and it holds no Brevo key: it queues each email it needs in `outbox`, and
 * the VPS pulls the queue over SSH (bin/alerts.php).
 *
 * Nothing here logs or returns a plain address after the request that typed it.
 * Every function takes the PDO of al_db(), so the tests can use a fixture.
 */
declare(strict_types=1);

const ALERTS_PENDING_TTL = 48 * 3600;   // an unconfirmed subscription is deleted after 48 h
const ALERTS_LOGIN_TTL   = 3600;        // a „Abonamentele mele” link works for 1 hour
const ALERTS_RATE_WINDOW = 3600;
const ALERTS_RATE_KEEP   = 2 * 3600;
const ALERTS_OUTBOX_KEEP = 48 * 3600;
const ALERTS_SORTS       = ['', 'deadline', 'employer', 'salary'];
const ALERTS_KEY_MAX     = 2000;
const ALERTS_LABEL_MAX   = 200;
const ALERTS_DEFAULT_LABEL = 'Căutare salvată';

/** The private directory: outside the web root, holds subscriptions.sqlite and alerts-config.php. */
function al_dir(): string {
    $d = getenv('ALERTS_DIR');
    if ($d === false || $d === '') {
        // <web root>/../posturi-private (the default of deploy-php.sh's ALERTS_DIR)
        $d = dirname(__DIR__, 2) . '/posturi-private';
    }
    return rtrim($d, '/');
}

/**
 * The config file, or null when the feature is not set up on this host.
 * Keys: vps_public_key (base64), pepper (base64), site_url; optional
 * rate_per_hour (default 10) and max_per_email (default 20).
 */
function al_config(): ?array {
    static $cache = [];
    $dir = al_dir();
    if (array_key_exists($dir, $cache)) return $cache[$dir];
    $file = $dir . '/alerts-config.php';
    $cfg = null;
    if (is_file($file)) {
        $c = require $file;
        if (is_array($c)
            && is_string($c['vps_public_key'] ?? null)
            && strlen((string)base64_decode($c['vps_public_key'], true)) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
            && is_string($c['pepper'] ?? null)
            && strlen((string)base64_decode($c['pepper'], true)) >= 16
            && is_string($c['site_url'] ?? null)) {
            $cfg = $c;
        }
    }
    return $cache[$dir] = $cfg;
}

function al_enabled(): bool {
    return function_exists('sodium_crypto_box_seal') && al_config() !== null;
}

/** The current time. A test sets $GLOBALS['al_now'] to move the clock. */
function al_now(): int {
    return (int)($GLOBALS['al_now'] ?? time());
}

function al_iso(?int $t = null): string {
    return gmdate('Y-m-d\TH:i:s\Z', $t ?? al_now());
}

function al_db(): PDO {
    static $pdos = [];
    $dir = al_dir();
    if (isset($pdos[$dir])) return $pdos[$dir];
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $pdo = new PDO('sqlite:' . $dir . '/subscriptions.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    al_schema($pdo);
    return $pdos[$dir] = $pdo;
}

function al_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscriptions (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            email_sealed   TEXT NOT NULL,
            email_index    TEXT NOT NULL,
            search_key     TEXT NOT NULL,
            sort           TEXT NOT NULL DEFAULT '',
            label          TEXT NOT NULL DEFAULT '',
            token_hash     TEXT NOT NULL,
            token_sealed   TEXT NOT NULL,
            status         TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed')),
            created_at     TEXT NOT NULL,
            confirmed_at   TEXT,
            last_digest_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_sub_email ON subscriptions(email_index);
        CREATE TABLE IF NOT EXISTS outbox (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            kind           TEXT NOT NULL CHECK (kind IN ('confirm', 'manage')),
            payload_sealed TEXT NOT NULL,
            created_at     TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_tokens (
            token_hash  TEXT PRIMARY KEY,
            email_index TEXT NOT NULL,
            expires_at  TEXT NOT NULL,
            used_at     TEXT
        );
        CREATE TABLE IF NOT EXISTS rate (
            ip_hash      TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            count        INTEGER NOT NULL,
            PRIMARY KEY (ip_hash, window_start)
        );
    ");
    // BUG-SALARY-MULTI: log the number of migrated keys once (the first open after the deploy); later runs change 0.
    $migrated = al_migrate_salary_keys($pdo);
    if ($migrated > 0) error_log("alerts: migrated $migrated salary_bucket key(s) to salary_bucket[] (ADR 0001 amendment)");
}

/**
 * Data migration, 2026-11-18 (ADR 0001, amendment): `salary_bucket` became a multi-value parameter, so
 * its canonical key form is `salary_bucket%5B%5D=x`. Rewrite the old form at the start of a key and after
 * each `&`. The parameter name keeps its place, so the order of the parameters stays canonical. A second
 * run finds no old form and changes nothing. The table has no unique index on (email_index, search_key),
 * so a rewrite cannot collide at the database level. Returns the number of rows changed.
 */
function al_migrate_salary_keys(PDO $pdo): int {
    $rows = $pdo->query("SELECT id, search_key FROM subscriptions WHERE search_key LIKE '%salary\\_bucket=%' ESCAPE '\\'")->fetchAll();
    $n = 0;
    $upd = $pdo->prepare("UPDATE subscriptions SET search_key = ? WHERE id = ?");
    foreach ($rows as $r) {
        $new = preg_replace('/(^|&)salary_bucket=/', '$1salary_bucket%5B%5D=', (string)$r['search_key']);
        if ($new === null || $new === $r['search_key']) continue;
        $upd->execute([$new, $r['id']]);
        $n++;
    }
    return $n;
}

// ---- Crypto and tokens ----

/** Trim, lowercase, validate. Null when the text is not one address. */
function al_normalize_email(string $s): ?string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    if ($s === '' || strlen($s) > 254) return null;
    if (filter_var($s, FILTER_VALIDATE_EMAIL) === false) return null;
    return $s;
}

function al_email_index(string $email): string {
    return hash_hmac('sha256', $email, (string)base64_decode(al_config()['pepper'], true));
}

/** Seal text to the VPS key. Only the VPS can open it. */
function al_seal(string $plain): string {
    return base64_encode(sodium_crypto_box_seal($plain, (string)base64_decode(al_config()['vps_public_key'], true)));
}

function al_new_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function al_token_hash(string $token): string {
    return hash('sha256', $token);
}

function al_token_ok(string $token): bool {
    return preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token) === 1;
}

// ---- Housekeeping and limits ----

/** Runs on each write: old pending rows, spent login tokens, old rate rows, stale outbox rows. */
function al_housekeeping(PDO $pdo): void {
    $now = al_now();
    $pdo->prepare("DELETE FROM subscriptions WHERE status = 'pending' AND created_at < ?")
        ->execute([al_iso($now - ALERTS_PENDING_TTL)]);
    $pdo->prepare("DELETE FROM login_tokens WHERE expires_at < ?")->execute([al_iso($now)]);
    $pdo->prepare("DELETE FROM rate WHERE window_start < ?")->execute([$now - ALERTS_RATE_KEEP]);
    $pdo->prepare("DELETE FROM outbox WHERE created_at < ?")->execute([al_iso($now - ALERTS_OUTBOX_KEEP)]);
}

/** Count one request of this client. False when the client is over the limit for this hour. */
function al_rate_ok(PDO $pdo, string $ip): bool {
    $limit = (int)(al_config()['rate_per_hour'] ?? 10);
    $window = intdiv(al_now(), ALERTS_RATE_WINDOW) * ALERTS_RATE_WINDOW;
    $ip_hash = hash_hmac('sha256', $ip, (string)base64_decode(al_config()['pepper'], true));
    $pdo->prepare("INSERT INTO rate (ip_hash, window_start, count) VALUES (?, ?, 1)
        ON CONFLICT(ip_hash, window_start) DO UPDATE SET count = count + 1")->execute([$ip_hash, $window]);
    $stmt = $pdo->prepare("SELECT count FROM rate WHERE ip_hash = ? AND window_start = ?");
    $stmt->execute([$ip_hash, $window]);
    return (int)$stmt->fetchColumn() <= $limit;
}

/** The client address: Cloudflare's header when present (the site sits behind it), else the socket. */
function al_client_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

// ---- Search keys ----

/**
 * The query parameters of a canonical, non-empty search key, or null.
 * The key must equal search_key() of its own parameters (ADR 0001), so a
 * hand-made key that selects the same postings in another spelling is refused.
 */
function al_parse_key(string $key): ?array {
    if ($key === '' || strlen($key) > ALERTS_KEY_MAX) return null;
    parse_str($key, $params);
    if (!is_array($params) || $params === []) return null;
    if (search_key($params) !== $key) return null;
    [$valid, $err] = validated_query($params);
    return $err === null && $valid ? $valid : null;
}

function al_clean_label(string $label): string {
    $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '');
    $label = mb_substr($label, 0, ALERTS_LABEL_MAX, 'UTF-8');
    return $label === '' ? ALERTS_DEFAULT_LABEL : $label;
}

// ---- Outbox ----

function al_queue(PDO $pdo, string $kind, string $email, string $token, int $subscription_id): void {
    // A global ceiling, whatever the client address says (CF-Connecting-IP can be forged at the
    // origin). It keeps a flood of requests from filling the outbox, mailing one victim
    // repeatedly, or using up the daily send cap that the digests need (review 2026-11-15).
    $limit = (int)(al_config()['outbox_per_hour'] ?? 60);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM outbox WHERE created_at >= ?");
    $stmt->execute([al_iso(al_now() - 3600)]);
    if ((int)$stmt->fetchColumn() >= $limit) return;
    $payload = json_encode(['email' => $email, 'token' => $token, 'subscription_id' => $subscription_id]);
    $pdo->prepare("INSERT INTO outbox (kind, payload_sealed, created_at) VALUES (?, ?, ?)")
        ->execute([$kind, al_seal((string)$payload), al_iso()]);
}

/** Store a login token for the address and queue the `manage` email. */
function al_queue_login(PDO $pdo, string $email, string $email_index): void {
    $token = al_new_token();
    $pdo->prepare("INSERT INTO login_tokens (token_hash, email_index, expires_at) VALUES (?, ?, ?)")
        ->execute([al_token_hash($token), $email_index, al_iso(al_now() + ALERTS_LOGIN_TTL)]);
    al_queue($pdo, 'manage', $email, $token, 0);
}

// ---- Subscribe ----

/**
 * One subscribe request. Returns 'sent' (the same answer for every valid
 * request, including a known address, a full address and a honeypot hit),
 * 'invalid-email', 'invalid-key' or 'rate'. It never says whether the address
 * has subscriptions.
 *
 * @param array $in cheie, sort, nume, email, website (honeypot)
 */
function al_subscribe(PDO $pdo, array $in, string $ip): string {
    $key = (string)($in['cheie'] ?? '');
    if (al_parse_key($key) === null) return 'invalid-key';
    $email = al_normalize_email((string)($in['email'] ?? ''));
    if ($email === null) return 'invalid-email';
    if (trim((string)($in['website'] ?? '')) !== '') return 'sent';   // honeypot: look the same, store nothing

    al_housekeeping($pdo);
    if (!al_rate_ok($pdo, $ip)) return 'rate';

    $sort = (string)($in['sort'] ?? '');
    if (!in_array($sort, ALERTS_SORTS, true)) $sort = '';
    $label = al_clean_label((string)($in['nume'] ?? ''));
    $index = al_email_index($email);
    $max = (int)(al_config()['max_per_email'] ?? 20);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, status FROM subscriptions WHERE email_index = ? AND search_key = ?");
        $stmt->execute([$index, $key]);
        $existing = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE email_index = ?");
        $stmt->execute([$index]);
        $count = (int)$stmt->fetchColumn();

        if ($existing && $existing['status'] === 'pending') {
            // Subscribed before, not confirmed: a new token, a new confirmation email.
            $token = al_new_token();
            $pdo->prepare("UPDATE subscriptions SET token_hash = ?, token_sealed = ? WHERE id = ?")
                ->execute([al_token_hash($token), al_seal($token), $existing['id']]);
            al_queue($pdo, 'confirm', $email, $token, (int)$existing['id']);
        } elseif ($existing || $count >= $max) {
            // Already confirmed, or the address is full: no new row. A link to the list lets the owner manage it.
            al_queue_login($pdo, $email, $index);
        } else {
            $token = al_new_token();
            $pdo->prepare("INSERT INTO subscriptions
                    (email_sealed, email_index, search_key, sort, label, token_hash, token_sealed, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
                ->execute([al_seal($email), $index, $key, $sort, $label,
                    al_token_hash($token), al_seal($token), al_iso()]);
            al_queue($pdo, 'confirm', $email, $token, (int)$pdo->lastInsertId());
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return 'sent';
}

// ---- Links: confirm, manage, login ----

/** The subscription row when the id and token match; null otherwise. */
function al_auth(PDO $pdo, int $id, string $token): ?array {
    if (!al_token_ok($token)) return null;
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $hash = $row ? (string)$row['token_hash'] : str_repeat('0', 64);
    return hash_equals($hash, al_token_hash($token)) && $row ? $row : null;
}

/** The email_index of a valid, unexpired login token; null otherwise. */
function al_login_auth(PDO $pdo, string $token): ?string {
    if (!al_token_ok($token)) return null;
    $stmt = $pdo->prepare("SELECT token_hash, email_index, expires_at FROM login_tokens WHERE token_hash = ?");
    $stmt->execute([al_token_hash($token)]);
    $row = $stmt->fetch();
    $hash = $row ? (string)$row['token_hash'] : str_repeat('0', 64);
    if (!$row || !hash_equals($hash, al_token_hash($token))) return null;
    if ((string)$row['expires_at'] < al_iso()) return null;
    return (string)$row['email_index'];
}

/** The first change made with a login link marks it used. The link still works until it expires. */
function al_login_mark_used(PDO $pdo, string $token): void {
    $pdo->prepare("UPDATE login_tokens SET used_at = COALESCE(used_at, ?) WHERE token_hash = ?")
        ->execute([al_iso(), al_token_hash($token)]);
}

/** Set a pending subscription confirmed. Idempotent. */
function al_confirm(PDO $pdo, array $row): void {
    if ($row['status'] === 'confirmed') return;
    al_housekeeping($pdo);
    $pdo->prepare("UPDATE subscriptions SET status = 'confirmed', confirmed_at = ? WHERE id = ?")
        ->execute([al_iso(), $row['id']]);
}

/** Every subscription of an address, oldest first. No sealed fields. */
function al_list(PDO $pdo, string $email_index): array {
    $stmt = $pdo->prepare("SELECT id, search_key, sort, label, status, created_at, confirmed_at
        FROM subscriptions WHERE email_index = ? ORDER BY id");
    $stmt->execute([$email_index]);
    return $stmt->fetchAll();
}

/** Delete one subscription of this address. Returns the count of deleted rows. */
function al_delete_one(PDO $pdo, string $email_index, int $id): int {
    al_housekeeping($pdo);
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = ? AND email_index = ?");
    $stmt->execute([$id, $email_index]);
    return $stmt->rowCount();
}

function al_delete_all(PDO $pdo, string $email_index): int {
    al_housekeeping($pdo);
    $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE email_index = ?");
    $stmt->execute([$email_index]);
    return $stmt->rowCount();
}

/**
 * „Abonamentele mele”: when the address has subscriptions, store a login token
 * and queue the email. The caller shows the same answer either way.
 * Returns 'ok' or 'rate'.
 */
function al_login_request(PDO $pdo, string $raw_email, string $ip): string {
    $email = al_normalize_email($raw_email);
    if ($email === null) return 'invalid-email';
    al_housekeeping($pdo);
    if (!al_rate_ok($pdo, $ip)) return 'rate';
    $index = al_email_index($email);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE email_index = ?");
    $stmt->execute([$index]);
    if ((int)$stmt->fetchColumn() > 0) al_queue_login($pdo, $email, $index);
    return 'ok';
}
