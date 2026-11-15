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
- **Accepted for the prototype (pax, 2026-11-15):** the free plan adds the Brevo branding to each email. Brevo records opens and clicks of transactional emails, and we found no setting that turns this off. pax wants the click rate. The subscribe form and the privacy text must say that the provider measures opens and clicks. The consent at subscription covers this measurement.
- **Brevo settings (read in the dashboard 2026-11-15, nothing changed):** Transactional → Settings → Tracking has „Anonymous email tracking”, set to No. With Yes, Brevo counts opens and clicks but does not link them to an address. Proposal: set it to Yes; the click rate stays. Retention rules: logs are deleted after 1 month, and previews are never stored. The domain setup can also brand the tracking links with our own domain.


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
5. **Unsubscribe.** Each email has a link to the host that deletes that subscription immediately. The mail-client button (`List-Unsubscribe`) is Brevo's: Brevo adds its own header to transactional emails, and the API does not accept standard headers. That button puts the address on the Brevo blocked list, and step 6 deletes all its subscriptions. To check on the first test email ("Show original"): the header pair and its DKIM signature.
6. **Bounces, complaints and Brevo unsubscribes (decided 2026-11-15).** Before each send, the VPS reads the Brevo blocked list. For each blocked address, it asks the host over SSH to delete every row with that `email_index`. The host has no webhook endpoint. When a person subscribes again after a block, the VPS removes the address from the blocked list before it sends the confirmation.
7. **Manage from the website (decided 2026-11-15): no accounts, one-time links.** A page „Abonamentele mele” asks for the address. The host computes `email_index` and stores a one-time login token (hash only, valid 1 hour). The VPS sends the link with the next confirmation pass, so it arrives within 5 minutes. The page always answers the same way, so it does not show whether an address has subscriptions. The link opens a list of the subscriptions of that address, with delete and „delete all”. The manage link in each digest opens the same list.

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
  - User accounts with passwords. They add a password store, a reset flow and more personal data. A one-time link proves control of the address, which is all that managing subscriptions needs.
  - The host sends the confirmation itself. That puts a provider key on the shared host, and a leaked key can send spam from our domain.
  - Everything on the VPS. That exposes the box that holds Postgres and the LLM keys.
  - Everything on the host. It has no backups and no alerts, and the host does not know when new data arrives.

## Open
- Check on the Brevo account (opened by pax 2026-11-15) before the build: on a test email, Brevo's `List-Unsubscribe` and `List-Unsubscribe-Post` headers. Answered 2026-11-15 from a search summary, not from the Brevo documentation: transactional sends do not create contacts; tracking cannot be turned off; free-plan emails carry the Brevo branding.
- Check that the shared host has `php` CLI with `sodium` on the SSH path (`ssh <host> 'php -v; php -m'`).
- The privacy text in Romanian: what we store, where, for how long, and how to delete it.
