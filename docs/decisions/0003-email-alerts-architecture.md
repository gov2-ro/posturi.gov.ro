# 0003 — Email alerts: the shared host keeps subscriptions, the VPS sends

Date: 2026-11-02; amended 2026-11-15. Status: **accepted** (2026-11-15). Applies to UX-14. Uses the search key of [ADR 0001](0001-saved-search-identity.md).

## Context

- The public site runs on a shared host (Dreamhost today). A later move to Hetzner shared hosting or to a Romanian host is possible.
- The VPS (`gov2-1`) runs the pipeline twice a day. It copies `posturi.sqlite` to the shared host over SSH. It has ntfy alerts, logs, backups (`ops/pull-backup.sh`) and the API keys.
- No host has a mail transfer agent (OPS-MAIL).
- pax decided (2026-11-02):
  - Use the hybrid design.
  - Encrypt the email addresses on the shared host.
  - Send one digest every 3 days.
  - DNS is on Cloudflare.

## Decision

### Provider, sender and controller (2026-11-15)
- **Provider: Brevo** (France; data in France and Germany). The free plan allows 300 emails per day. pax chose it over Scaleway TEM (300 per month free). When the cap is too small, first make the digest less frequent. Then consider a paid plan.
- **The daily cap is a hard limit.** The VPS counts the emails it sends each UTC day. Confirmation emails go first. The digest step sends the due subscriptions with the oldest last digest first, up to the remaining allowance. It carries the rest to the next run. It never sends past the cap.
- **Sender domain: `dispecerat.gov2.ro`.** SPF, DKIM and DMARC go in Cloudflare. The subdomain keeps the reputation of `gov2.ro` separate.
- **Data controller: pax, as a natural person.** gov2 is not an organisation. The privacy text names the controller and a contact address.
- **Shared host: Dreamhost (US) is accepted for now.** The subscriptions there hold the search filters and the IP-based rate limit in clear text. A later move to an EU host is planned, not scheduled.
- Brevo is a marketing platform too. Use only its transactional API. Do not upload subscribers to Brevo contact lists.


### Roles
- **The shared host owns the subscriptions.** It serves the subscribe form, the confirmation link and the unsubscribe link. It is the only authority on who is subscribed.
- **The VPS sends the emails.** It pulls the subscriptions over the SSH link that exists, decrypts the addresses (Python, PyNaCl) and calls the HTTPS API of the provider.
- **The shared host chooses the postings.** Only the shared host has PHP. It runs the one filter implementation (`build_filters()` / `feed_rows()`). The VPS asks for the matches over SSH. No second filter implementation exists.
- **The VPS holds the only key that decrypts.** The shared host can encrypt an address but cannot read it back.

### Data on the shared host: `subscriptions.sqlite`
- Location: outside the web root. The code deploy must never delete it, so add it to the excludes of `deploy-php.sh` by name. Do not depend on the `*.sqlite` pattern.
- Each row has these fields:
  - `id`.
  - `email_sealed`: `sodium_crypto_box_seal(email, VPS_PUBLIC_KEY)`.
  - `email_index`: HMAC-SHA256 of the normalised address, with a secret pepper. The pepper is in a config file outside the web root. This field finds duplicates and serves "delete all my subscriptions".
  - `search_key`, `sort`, `label`.
  - `token_hash`: SHA-256 of the manage/unsubscribe token. The token itself is only in the emails.
  - `status`: `pending` or `confirmed`.
  - `created_at`, `confirmed_at`.
- Unsubscribe deletes the row. The VPS reads a missing row as "unsubscribed".
- The pending rows that are older than 48 hours are deleted on the next write.
- Limits: a honeypot field, a rate limit per IP, and at most 20 subscriptions per `email_index`.

### Flow
1. **Subscribe.** The host validates the search key, seals the address, stores a `pending` row and shows „Verifică-ți emailul”.
2. **Confirmation email.** A VPS cron job runs every 5 minutes. It pulls the pending rows over SSH, decrypts them and sends the confirmation emails. The provider key stays on the VPS only. A confirmation therefore takes up to 5 minutes.
3. **Confirm.** The link goes to the host. The host sets `confirmed`.
4. **Digest.** This is the last step of `run-pipeline.sh` (the evening run only). The VPS pulls the confirmed rows. A subscription is due when 3 days have passed since its last digest (or since confirmation) **and** new postings exist (`created_at` > the last digest). The step runs after `deploy-php.sh --data-only` has shipped the new database. The VPS runs `ssh <host> php bin/alert-candidates.php` with the due subscription ids and their last `created_at`. The script returns JSON: the matching postings for each id (id, title, employer, place, deadline, URL). The JSON contains no addresses. The VPS decrypts the addresses, renders the digest and sends it. It sends nothing when nothing is new.
5. **Unsubscribe.** Each email has a link and the `List-Unsubscribe` + `List-Unsubscribe-Post` headers (RFC 8058). Both go to the host and delete the row immediately.
6. **Bounces and complaints.** The webhook of the provider goes to the host (a public URL). The host deletes the row. Alternative: the VPS reads the suppression list of the provider before each send.

### Data on the VPS
- `alerts.sqlite` holds the send log: `subscription_id`, `last_sent_at`, `last_posting_created_at`, and the result of the provider call. It holds no plain addresses.
- The private key is a file with mode 600, outside the checkout. **Back it up separately.** If the key is lost, every subscriber is lost.
- If the SSH pull fails, the VPS sends nothing and raises an ntfy alert. It never sends from a stale list.

## Consequences

- A copy of the shared host database shows the search queries and the confirmation status. It does not show the addresses.
- **Limit of the encryption:** an attacker who controls the shared host can change the form and read the new addresses as people type them. The encryption protects the stored list (backups, a leaked file, a host migration). It does not protect against a live compromise of the host.
- `email_index` is a keyed hash. An attacker who has both the database and the pepper can test guessed addresses. Without the pepper, the field is useless to them.
- **Portable host.** The shared host needs only PHP ≥ 7.2 with `sodium` and `pdo_sqlite`, a writable directory outside the web root, and SSH access from the VPS. It needs no cron job and no provider key. A move away from Dreamhost is a file copy.
- **Requirements on the VPS:** Python with PyNaCl (1.5.0 is installed; `SealedBox` reads what `sodium_crypto_box_seal` writes), the provider key, and two cron entries (every 5 minutes, and the step after the evening run). PHP is not needed: on 2026-11-02 gov2-1 had no PHP and no web server.
- **The shared host also needs** shell access over SSH for `php` CLI. The deploy already runs `ssh` on it (`deploy-php.sh`).
- **Alternatives not taken:**
  - The host sends the confirmation itself. That puts a provider key on the shared host, and a leaked key can send spam from our domain.
  - Everything on the VPS. That exposes the box that holds Postgres and the LLM keys.
  - Everything on the host. It has no backups and no alerts, and the host does not know when new data arrives.

## Open
- Check on a Brevo account, before the build: an account for a natural person; custom `List-Unsubscribe` and `List-Unsubscribe-Post` headers on the transactional API; open and click tracking off; whether transactional sends create Brevo contacts; Brevo branding on free-plan transactional emails.
- Check that the shared host has `php` CLI with `sodium` on the SSH path (`ssh <host> 'php -v; php -m'`).
- Whether the bounce webhook goes to the host or the VPS reads the suppression list. Proposal: the VPS reads the Brevo list of blocked addresses before each send, and asks the host over SSH to delete those rows. Then the host needs no public webhook endpoint.
- The privacy text in Romanian: what we store, where, for how long, and how to delete it.
