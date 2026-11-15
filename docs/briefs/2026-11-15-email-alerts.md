# Brief — UX-14 Email alerts for saved searches

Owner: Sonnet builds; Opus reviews. Date: 2026-11-15. Baseline: `cc21c68`.
Design: [ADR 0003](../decisions/0003-email-alerts-architecture.md). Search identity: [ADR 0001](../decisions/0001-saved-search-identity.md). Read both first. This brief adds three details to ADR 0003; they are marked **(amends ADR 0003)** below.

## Goal

A reader subscribes a saved search to email. They confirm by a link. Every 3 days, when new postings match, they get one digest. Each email has a link that deletes the subscription. A page „Abonamentele mele” lets a reader see and delete their subscriptions with a one-time link. There are no accounts and no passwords.

## Roles (from ADR 0003)

- **Shared host (PHP):** the forms, the links and `subscriptions.sqlite`. It can encrypt an address but cannot decrypt it. It has no Brevo key.
- **VPS (Python):** decrypts, sends through the Brevo transactional API, keeps the send log. It talks to the host only by `ssh <host> php <path>/bin/alerts.php …`.

## Constraints

- HTML pages are cached at the edge (`s-maxage=3600`). Every alerts route (`/alerte/…`) sends `Cache-Control: no-store` and is a POST where it changes data. A GET link from an email shows a page with a button; the button does the POST. Mail scanners follow GET links, so a GET must never confirm or delete.
- The host needs PHP ≥ 7.2 with `sodium` and `pdo_sqlite`. **Step 0** checks this.
- Use the role tokens and the button classes that exist. No raw palette classes and no hex values (CLAUDE.md). Use a gov2 DS component when one fits (`COMPONENTS.md` in the DS).
- Romanian UI copy: short sentences, active voice. The terms are **„abonare”** (the subscription), **„alertă pe email”** (the feature) and **„rezumat”** (the digest).
- Do not change `search_key()`, `searchKey()`, `build_filters()` or `feed_rows()`. Call them.
- Brevo: only `POST /v3/smtp/email` and the blocked-contacts endpoints (`GET /v3/smtp/blockedContacts`, `DELETE /v3/smtp/blockedContacts/{email}`). Never call a contacts or lists endpoint.
- Never log a plain address, on either side. Log subscription ids.

## Design details this brief adds

1. **Outbox (amends ADR 0003).** The host cannot send, so it queues each email it needs in an `outbox` table: `id`, `kind` (`confirm` | `manage`), `payload_sealed`, `created_at`. `payload_sealed` is `sodium_crypto_box_seal(json_encode({email, token, subscription_id}), VPS_PUBLIC_KEY)`. The VPS pulls the outbox, decrypts, sends, then asks the host to delete the rows it sent.
2. **Token in the row (amends ADR 0003).** Each subscription row also has `token_sealed`: the raw manage/unsubscribe token, sealed to the VPS key. The VPS decrypts it to build the links in each digest. The host keeps `token_hash` to check a link. A subscription token opens the list of every subscription with the same `email_index`, because holding it proves control of the address.
3. **Blocked addresses by id (amends ADR 0003).** The VPS cannot compute `email_index`, because the pepper is on the host. It decrypts the addresses it pulled, matches them to the Brevo blocked list, and asks the host to delete those subscription **ids**.
4. **The digest has its own schedule (amends ADR 0003).** ADR 0003 puts the digest at the end of `run-pipeline.sh`. Instead, it is a separate cron line at 19:30 Europe/Bucharest, after the evening run (18:33). A failed pipeline then does not block the digest, and a failed digest does not mark the pipeline failed. The digest reads whatever database the host serves.

## Work

### 0. Check the host (stop if it fails)

Run `ssh "$DEPLOY_HOST" 'php -v; php -m | grep -iE "sodium|pdo_sqlite"'` with the settings of `deploy-php.sh`. Record the output in the activity log. If `php` CLI or `sodium` is missing, stop and report to Opus.

### 1. Host storage and config

- A private directory outside the web root, from a new setting `ALERTS_DIR` (default `<DEPLOY_PATH>/../posturi-private`). It holds `subscriptions.sqlite` and `alerts-config.php`.
- `alerts-config.php` returns `['vps_public_key' => <base64>, 'pepper' => <base64>, 'site_url' => 'https://posturi.gov2.ro']`. Generate the pepper once. pax copies the VPS public key in (step 6).
- Add `ALERTS_DIR` to the excludes of `deploy-php.sh` **by name**, as ADR 0003 says, even though it is outside the web root today.
- Schema, created on first use:
  - `subscriptions`: the fields of ADR 0003, plus `token_sealed`, `sort`, `label`, `last_digest_at` (written by the VPS through `alerts.php`).
  - `outbox`: as above.
  - `login_tokens`: `token_hash`, `email_index`, `expires_at` (1 hour), `used_at`.
  - `rate`: `ip_hash` (HMAC with the pepper), `window_start`, `count`.
- Housekeeping on each write: delete `pending` rows older than 48 h, expired login tokens and old rate rows.

### 2. Host pages (`webapp-php/pages/alerts_*.php`, routes in `index.php`)

- **Subscribe.** On /salvate/, each saved search gets a button „Alertă pe email” next to „RSS”. It opens a small form: address, a honeypot field, and the consent text (step 5). The POST validates the search key with `search_key()`, refuses the empty key, checks the limits (rate per IP; at most 20 subscriptions per `email_index`), stores a `pending` row and a `confirm` outbox row, and shows „Verifică-ți emailul. Linkul ajunge în cel mult 5 minute.” The answer is the same when the address already has this search, so the form shows nothing about an address.
- **Confirm** (`/alerte/confirma/{id}/{token}`): GET shows the search and a button; POST sets `confirmed`.
- **Manage** (`/alerte/abonari/{id}/{token}`): lists every subscription of the same `email_index` (label, the filters as chips or plain text, a link to the list). Each one has „Șterge”; the page has „Șterge toate”. POST only.
- **„Abonamentele mele”** (`/alerte/`): asks for the address. The POST always answers „Dacă ai abonări, îți trimitem un link în cel mult 5 minute.” When the address has subscriptions, it stores a login token and a `manage` outbox row. The link opens the manage page.
- Link from the footer or from /salvate/ to `/alerte/`.

### 3. Host CLI: `webapp-php/bin/alerts.php`

- Refuses to run unless `PHP_SAPI === 'cli'`. Deploy it with the code; add `bin/` to the web-root deny rules (`.htaccess`) and test that a web request for it gets 403 or 404.
- Subcommands, each JSON in, JSON out, no plain addresses anywhere:
  - `pull`: the confirmed subscriptions (`id`, `email_sealed`, `token_sealed`, `search_key`, `sort`, `label`, `confirmed_at`, `last_digest_at`) and the outbox rows.
  - `outbox-done <ids>`: delete those outbox rows.
  - `delete <ids>`: delete those subscriptions (blocked addresses).
  - `candidates`: stdin `[{id, since}]`; for each id, run `feed_rows()` with the parameters of its `search_key` and return the postings created after `since` (id, title, employer, place, deadline, URL). Find the field that holds the first-seen time of a posting in `posturi.sqlite`. If there is no such field, stop and ask.
  - `digest-done <id> <iso time>`: set `last_digest_at`.

### 4. VPS: `ops/alerts.py`

- Settings from `.env`: `BREVO_API_KEY`, `ALERTS_KEY_FILE` (private key, mode 600, outside the checkout), `ALERTS_SENDER` (`alerte@dispecerat.gov2.ro`), `ALERTS_DAILY_CAP` (default 280, under Brevo's 300), and the `DEPLOY_HOST`/`DEPLOY_PATH` that `deploy-php.sh` uses. Use PyNaCl `SealedBox`.
- `ops/alerts.py keygen`: writes the key pair and prints the public key for `alerts-config.php`.
- `ops/alerts.py outbox` (cron every 5 min): pull; send each outbox email; `outbox-done` for the sent ones. Before a `confirm`, delete the address from the Brevo blocked list (a reader who subscribes again).
- `ops/alerts.py digest` (cron 19:30): read the Brevo blocked list and `delete` the matching ids; pick the due subscriptions (3 days since `last_digest_at` or `confirmed_at`); oldest first; `candidates`; send one digest per subscription that has new postings; `digest-done` after each successful send.
- The daily cap: `alerts.sqlite` counts the sends per UTC day. Confirmation and manage emails go first. The digest stops at the cap and leaves the rest for the next day.
- Email content: plain HTML that renders without images, Romanian, the posting list (at most 20, then „și încă N” with a link), the search link, „Șterge această abonare”, „Toate abonările mele”, and one line that the provider counts opens and clicks without linking them to the person.
- On an SSH or Brevo failure: send nothing more, exit non-zero, and push through `ops/ntfy.py`. Never send from a list older than this run.
- `--dry-run` for every subcommand: prints what it would send (ids and counts only).

### 5. Privacy text (draft for pax)

Add a section to /despre/#confidentialitate: who the controller is (pax; leave `[NUME]` and `[ADRESĂ DE CONTACT]` as placeholders), what is stored and where (sealed address and search on the shared host in the US; key and send log on the VPS; Brevo in the EU for sending, logs kept 1 month), anonymous open and click counting, why (consent), how long (until deletion; pending rows 48 h), how to delete (any email, or `/alerte/`), and the right to complain to ANSPDCP. The consent sentence on the form says the same in two lines and links here. pax approves the text before the deploy.

### 6. Operations (document; pax runs)

In `docs/deploy-vps.md`: install PyNaCl if needed; `ops/alerts.py keygen`; copy the public key to the host config; **add the private key to pax's manual backup**; the two cron lines; the `.env` entries. Do not edit the live crontab or the host config yourself.

## Tests

- PHP (`webapp-php/tests/alerts_test.php`, plain harness, add to CI): seal and pepper round trip with a test key; subscribe refuses the empty key, a honeypot hit, the 21st subscription and the rate limit; the same answer for a known and an unknown address; GET never changes a row; token checks use `hash_equals`; pending and token expiry; `alerts.php` subcommands on a fixture database; `candidates` against the fixture `posturi.sqlite`.
- Browser (Playwright): subscribe from /salvate/, confirm, manage, delete all — reading the outbox through `alerts.php` instead of email.
- Python (next to the other ops tests): the cap order, the due rule, blocked-id deletion, dry-run, and no send after a failed pull. Use a fake Brevo and a fake `alerts.php`; no network.

## Stop and ask Opus when

- Step 0 fails, or the first-seen field of a posting does not exist.
- A step needs a change to ADR 0001, `search_key()`, `build_filters()`, a token or `.g2-` class, or a file outside `webapp-php/`, `ops/`, `deploy-php.sh` and the docs.
- A Brevo endpoint behaves differently from this brief.

## Done when

All tests pass locally and in CI; the activity log has the entry (Done / Hard / Decided, with what you measured and what you assumed); the backlog marks UX-14 `[~]` with what is left for pax (keys, cron, privacy text, Brevo domain, first live test).
