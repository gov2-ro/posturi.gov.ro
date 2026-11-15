<?php
/**
 * UX-14 — command-line door of the email alerts (ADR 0003).
 *
 * The VPS calls this over SSH:  php <path>/bin/alerts.php <command> [args]
 * Each command reads JSON on stdin where it needs input and prints one JSON
 * document on stdout. No command prints a plain address: the host cannot open
 * a sealed one. Exit code 0 on success, 1 on a failure (the JSON has "error").
 *
 *   pull                       the confirmed subscriptions and the outbox
 *   outbox-done <ids>          delete sent outbox rows
 *   delete <ids>               delete subscriptions (a blocked address)
 *   candidates                 stdin [{id, since}] -> new postings per subscription
 *   digest-done <id> <iso>     set last_digest_at
 *
 * <ids> is a comma-separated list. bin/ is not web-requestable (.htaccess, router.php).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../query.php';
require_once __DIR__ . '/../feeds/_feed.php';
require_once __DIR__ . '/../inc/alerts.php';

/** Print one JSON document and stop. */
function al_cli_out(array $data, int $code = 0): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($code);
}

/** @return int[] */
function al_cli_ids(array $args): array {
    $ids = [];
    foreach ($args as $a) {
        foreach (preg_split('/[,\s]+/', $a, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (!ctype_digit($part)) al_cli_out(['error' => 'bad id'], 1);
            $ids[] = (int)$part;
        }
    }
    return array_values(array_unique($ids));
}

function al_cli_in(): array {
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) al_cli_out(['error' => 'stdin is not JSON'], 1);
    return $data;
}

/** New postings of one saved search, created after $since. */
function al_cli_candidates(string $search_key, string $since): array {
    $params = al_parse_key($search_key);
    if ($params === null) return ['error' => 'bad search key'];
    $_GET = $params;
    $t0 = new DateTimeImmutable($since, new DateTimeZone('UTC'));
    $site = rtrim((string)al_config()['site_url'], '/');
    $items = [];
    foreach (feed_rows('j.created_at DESC, j.id DESC', 1000) as $r) {
        if (empty($r['created_at'])) continue;
        try {
            $made = new DateTimeImmutable((string)$r['created_at'], new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            continue;
        }
        if ($made <= $t0) continue;
        $items[] = [
            'id'       => (int)$r['id'],
            'title'    => (string)$r['title'],
            'employer' => (string)($r['employer_name_disp'] ?? ''),
            'place'    => place_label($r + ['judet_name' => $r['judet_name_disp'] ?? ($r['judet_name'] ?? '')]),
            'deadline' => $r['apply_deadline'] ?? null,
            'url'      => $site . job_url($r),
        ];
    }
    return ['total' => count($items), 'items' => array_slice($items, 0, 50)];
}

if (!al_enabled()) al_cli_out(['error' => 'alerts are not configured on this host'], 1);

$cmd = $argv[1] ?? '';
$args = array_slice($argv, 2);
$pdo = al_db();

try {
    switch ($cmd) {
        case 'pull':
            al_housekeeping($pdo);
            $subs = $pdo->query("SELECT id, email_sealed, token_sealed, search_key, sort, label, confirmed_at, last_digest_at
                FROM subscriptions WHERE status = 'confirmed' ORDER BY id")->fetchAll();
            $out = $pdo->query("SELECT id, kind, payload_sealed, created_at FROM outbox ORDER BY id")->fetchAll();
            al_cli_out(['subscriptions' => $subs, 'outbox' => $out]);

        case 'outbox-done':
            $ids = al_cli_ids($args);
            $n = 0;
            foreach ($ids as $id) {
                $stmt = $pdo->prepare("DELETE FROM outbox WHERE id = ?");
                $stmt->execute([$id]);
                $n += $stmt->rowCount();
            }
            al_cli_out(['deleted' => $n]);

        case 'delete':
            $ids = al_cli_ids($args);
            $n = 0;
            foreach ($ids as $id) {
                $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = ?");
                $stmt->execute([$id]);
                $n += $stmt->rowCount();
            }
            al_cli_out(['deleted' => $n, 'ids' => $ids]);

        case 'candidates':
            $asked = al_cli_in();
            $result = [];
            foreach ($asked as $q) {
                $id = (int)($q['id'] ?? 0);
                $stmt = $pdo->prepare("SELECT search_key FROM subscriptions WHERE id = ? AND status = 'confirmed'");
                $stmt->execute([$id]);
                $key = $stmt->fetchColumn();
                if ($key === false) {
                    $result[] = ['id' => $id, 'error' => 'gone'];
                    continue;
                }
                try {
                    $result[] = ['id' => $id] + al_cli_candidates((string)$key, (string)($q['since'] ?? ''));
                } catch (Throwable $e) {
                    $result[] = ['id' => $id, 'error' => 'since is not a time'];
                }
            }
            al_cli_out(['candidates' => $result]);

        case 'digest-done':
            $ids = al_cli_ids([$args[0] ?? '']);
            try {
                $when = new DateTimeImmutable((string)($args[1] ?? ''), new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                al_cli_out(['error' => 'bad time'], 1);
            }
            if (count($ids) !== 1) al_cli_out(['error' => 'one id needed'], 1);
            $stmt = $pdo->prepare("UPDATE subscriptions SET last_digest_at = ? WHERE id = ?");
            $stmt->execute([al_iso($when->getTimestamp()), $ids[0]]);
            al_cli_out(['updated' => $stmt->rowCount()]);

        default:
            al_cli_out(['error' => 'unknown command', 'commands' => ['pull', 'outbox-done', 'delete', 'candidates', 'digest-done']], 1);
    }
} catch (Throwable $e) {
    al_cli_out(['error' => get_class($e) . ': ' . $e->getMessage()], 1);
}
