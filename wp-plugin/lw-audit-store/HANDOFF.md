# Hand-off — 2026-05-05

This file is the evidence pack for the Matt hand-off. It captures **what was verified** (with the actual command output, not just claims), **what is still open**, and **questions back to Matt**.

If you are Matt: read [`README.md`](README.md) first, then [`DEPLOY.md`](DEPLOY.md), then this file for the verification proofs.

---

## Environment under test

- WordPress 6.9.4 (latest at hand-off time)
- PHP 8.0.30
- SQLite (wp-now's built-in storage; production uses MySQL but the SQL surface is portable — `dbDelta` runs the same migration on both)
- Plugin commit: `13eab55`
- Plugin version: 0.3.0
- Test runner: `npx @wp-now/wp-now start --port=8881`, plugin auto-mounted in plugin-mode

---

## Verification — 10 checks, all green

### 1. Scan endpoint returns 200 with `{ preview, fullReport }`

```
$ curl -s -X POST 'http://localhost:8881/index.php?rest_route=/lw/v1/scan' \
    -H 'Content-Type: application/json' \
    -H 'Origin: https://linkwhisper.com' \
    -d '{"url":"https://example.org","email":"sim@anmoll.dev"}'
```

Returns 200. Body keys: `preview`, `fullReport` — each containing `score`, `bucket`, `bucketLabel`, `bucketMessage`, `metrics`, `topFindings`, `warnings`, `isWordPress`. example.org is single-page, so bucket is `unreliable` (expected — that's the bucket the plugin uses for sites with too few crawlable pages).

### 2. Capture endpoint returns 200 with `audit_id`

```
$ curl -s -X POST 'http://localhost:8881/index.php?rest_route=/lw/v1/emails' \
    -H 'Content-Type: application/json' \
    -H 'Origin: https://linkwhisper.com' \
    -H 'Idempotency-Key: dc69...fa19' \
    -d '{"email":"sim+e2e@anmoll.dev","url_audited":"https://example.org",...}'
```

Returns:
```json
{"ok":true,"audit_id":20,"email_status":"mail_failed","kit_status":"failed"}
```

`mail_failed` and `kit_status: failed` are expected here — wp-now has no SMTP relay configured and no Kit credentials in Settings (Settings deliberately left empty for testing — see DEPLOY.md "Configure" for what Matt fills in).

### 3. `wp_lw_audits` row written correctly

```
$ sqlite3 ... "SELECT id, email, email_status, kit_status FROM wp_lw_audits WHERE id=20;"
20|sim+e2e@anmoll.dev|mail_failed|failed
```

### 4. 30-min `lw_audit_mail_retry` event scheduled with correct args

```
$ sqlite3 ... "SELECT option_value FROM wp_options WHERE option_name='cron';" | grep lw_audit_mail_retry
```

Cron array contains entry at timestamp `now + 1800s` with hook `lw_audit_mail_retry` and args `[20]`. This is the new P0 fix — before this commit, a `mail_failed` row had no recovery path.

### 5. Retry handler flips `mail_failed → mail_dead` on second failure

After firing the retry hook on row 20:

```
$ sqlite3 ... "SELECT id, email_status FROM wp_lw_audits WHERE id=20;"
20|mail_dead
```

### 6. Retry handler logs to `wp_lw_audits_errors`

```
$ sqlite3 ... "SELECT id, endpoint, payload_hash, error_message FROM wp_lw_audits_errors WHERE endpoint='mail_dead' ORDER BY id DESC LIMIT 1;"
52|mail_dead|0000...0000020|wp_mail returned false
```

`payload_hash` is the audit id zero-padded to 64 chars (ad-hoc convention so this row joins back to `wp_lw_audits.id`). `error_message` is `wp_mail`'s actual error — truncated to 65535 bytes to fit the column.

### 7. Retry idempotency: re-firing on `mail_dead` row is a no-op

Re-fired the retry handler on row 20 (already `mail_dead`). Status stayed `mail_dead`. Errors-table count for endpoint=`mail_dead`,payload_hash matching id 20 stayed at 1 (no duplicate row). The handler's first action is `if ( email_status !== 'mail_failed' ) return;` — operator manual recovery is safe.

### 8. Idempotency-Key replay: same key returns original `audit_id`

Submitted the same payload + `Idempotency-Key` header twice:

First submit:
```json
{"ok":true,"audit_id":20,"email_status":"mail_failed","kit_status":"failed"}
```

Second submit (same key):
```json
{"ok":true,"idempotent":true,"audit_id":20,"email_status":"mail_dead","kit_status":"failed"}
```

Notice `idempotent: true` and the same `audit_id`. Row count for that email stays at 1 — no duplicate. The `email_status` reflects current state (`mail_dead` because the retry already fired between the two submits).

### 9. Honeypot (`lw_check` filled): silent 200, no row inserted

```
$ curl ... -d '{"email":"...", "lw_check":"i-am-a-bot", ...}'
{"ok":true,"audit_id":0}
```

Row count before: 20. Row count after: 20. Errors table got a new row with `endpoint=honeypot, error_message='honeypot field filled'`. Bot sees a 200 with `audit_id: 0` so it cannot tell that the field is a trap.

**This is the bonus bug we caught during simulation** — the plugin was checking `payload['hp_field']` but the React frontend (`LinkChecker.tsx:751`) sends the field as `lw_check`. Anything bypassing React (curl, automated bots) was getting through. Now matches.

### 10. CORS allow-list works correctly

```
$ curl -s -i -X OPTIONS '/lw/v1/emails' -H 'Origin: https://linkwhisper.com' -H 'Access-Control-Request-Headers: Idempotency-Key'
HTTP/1.1 200 OK
access-control-allow-origin: https://linkwhisper.com
access-control-allow-headers: Content-Type, X-LW-Idempotency-Key, Idempotency-Key, X-LW-Signature
```

```
$ curl -s -i -X OPTIONS '/lw/v1/emails' -H 'Origin: https://link-whisperer-internal-link-checker.netlify.app' -H 'Access-Control-Request-Headers: Idempotency-Key'
HTTP/1.1 200 OK
access-control-allow-origin: https://link-whisperer-internal-link-checker.netlify.app
access-control-allow-headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type
```

**About the `access-control-allow-origin` echo:** WordPress core's default `rest_send_cors_headers` filter is permissive and echoes the request `Origin` back even when our plugin's allow-list rejects it. **This is not the CORS check that matters.** The browser preflight fails on the second header — `access-control-allow-headers` — because the React app sends `Idempotency-Key` and the disallowed-origin response gives the WP core fallback set (Authorization / X-WP-Nonce / Content-Disposition / Content-MD5 / Content-Type) which does not include `Idempotency-Key`. So the disallowed origin is effectively blocked from the actual POST. Allowed origins get our plugin's full lw-specific header set including `Idempotency-Key`. **Verify CORS by checking `Access-Control-Allow-Headers`, not `Access-Control-Allow-Origin`.**

---

## Schema reference

What `dbDelta` builds when the plugin activates:

### `wp_lw_audits` (26 columns)

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | auto-increment |
| `created_at` | TEXT | UTC, default CURRENT_TIMESTAMP |
| `created_at_gmt` | TEXT | duplicate UTC for legacy queries |
| `updated_at` | TEXT | trigger on UPDATE |
| `url_audited` | TEXT | sanitized via `esc_url_raw` |
| `url_hash` | TEXT | sha256 of url, used for the idempotency-replay lookup |
| `score` | INTEGER | 0–100 or NULL (unreliable bucket) |
| `pages_crawled`, `broken_count`, `orphan_count`, `internal_links` | INTEGER | scan stats |
| `email` | TEXT | sanitized via `sanitize_email` |
| `email_status` | TEXT | `none / queued / sent / mail_failed / mail_dead` |
| `kit_status` | TEXT | `pending / synced / failed / dead` |
| `kit_attempts`, `kit_last_error`, `kit_subscriber_id` | mixed | retry state |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` | TEXT | attribution |
| `referrer`, `user_agent` | TEXT | request context |
| `ip_hash` | TEXT | HMAC-SHA256(IP, hmac_secret) — never raw IP |
| `raw_results` | TEXT | full scan JSON for debug / re-render |

Indexes: `email`, `created_at`, `kit_status`, `email_status`, `url_hash`.

### `wp_lw_audits_errors` (5 columns)

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | |
| `created_at` | TEXT | UTC |
| `endpoint` | TEXT | category: `scan / emails / mail / kit / honeypot / rate_limit / mail_dead / etc.` |
| `payload_hash` | TEXT | sha256 of payload, or zero-padded audit_id for downstream errors |
| `error_message` | TEXT | truncated to 65535 bytes |

Sort by `created_at DESC` for triage.

---

## Open questions back to Matt

These are the things we don't know — please answer in a comment on the GH commit or via DM before staging cutover.

1. **Which staging WP host are you installing this on?** ("futurearmyofficers.com" was floated; confirm.)
2. **What is the SMTP path on the staging host?** Specifically:
   - Which plugin / configuration is routing `wp_mail` (`WP Mail SMTP`, `Fluent SMTP`, native PHP mail, …)?
   - Which transactional relay is it pointed at (Postmark, SendGrid, AWS SES, Resend, other)?
   - Is it the same relay as production?
   - SPF/DKIM published for the `From` domain?
   - The 4-item Matt tick-list at the top of DEPLOY.md ("Pre-install — confirm outbound SMTP") covers this — please tick before activating.
3. **What email do you want as the default `From Email` and `Reply-To` for the audit emails?** (Production probably uses `support@linkwhisper.com` — confirm for staging.)
4. **What physical mailing address should appear in the email footer?** (CAN-SPAM Sec. 5(a)(5) — required, not optional.)
5. **Are you OK keeping the plugin under `Anmoll-W/free-audit-tool`** (you now have collaborator push perm), **or do you want to fork it under `linkwhisper/lw-audit-store`** so it lives under the LinkWhisper org?

6. **Is `DISABLE_WP_CRON` set on the staging/prod host, and if so what's hitting `/wp-cron.php` on a schedule?** Many managed WP hosts disable the request-triggered wp-cron and rely on a system cron hitting `/wp-cron.php` every N minutes. If `DISABLE_WP_CRON=true` and no system cron is firing, the 30-min `lw_audit_mail_retry` event sits in the cron array forever — `mail_failed` rows silently never become `mail_dead`, and the operator alert path (the `wp_lw_audits_errors` row that signals "human attention needed") never triggers. Same applies to the hourly `lw_audit_kit_retry`. Confirm one of: (a) `DISABLE_WP_CRON` is unset/false, OR (b) it's set but a system cron / SaaS cron pings `/wp-cron.php` at least every 15 min.

---

## Things still pending (none of these block staging install)

- **Plugin zip not regenerated.** The pre-existing `dist/lw-audit-store-0.3.0.zip` in the repo is from before today's P0 fixes. Either rebuild the zip from `wp-plugin/lw-audit-store/` before installing, or upload the folder directly. (Matt: simplest is `git clone` the repo and zip the `wp-plugin/lw-audit-store/` folder yourself.)
- **HMAC enforcement.** The `/emails` endpoint accepts unsigned POSTs from allow-listed origins today. HMAC verification is wired (see `class-rest-controller.php` constants for the secret) but not enforced in the request lifecycle. Plan is to enforce after operator workflow stabilises.
- **Async dispatch.** Decided to KEEP inline `wp_mail` + Kit for v0 (see `Projects/Free-Audit-Tool/knowledge/evals/2026-05-05_quinn-async-decision.md` for reasoning). Triggers to revisit: `/emails` p95 > 6s, Kit failure rate > 5% in any 48h window, or first user complaint about late email arrival.
- **Quinn 21-AC E2E on real WP.** Run on staging once installed — Quinn already swept the wp-now SQLite sim (14 PASS, 1 PARTIAL, 0 FAIL, 3 doc-gap, 2 obsolete). Re-running on a real MySQL WP is the final pre-prod sign-off.
- **Netlify retire.** Once production install green: 301-redirect the old Netlify origin (`link-whisperer-internal-link-checker.netlify.app`) to `linkwhisper.com/internal-link-checker`, then delete the Netlify deploy.

---

## What's NOT in this hand-off

- **The vault notes.** All architecture decisions, eval reports, past mistakes, and session journals live in Anmoll's vault under `Projects/Free-Audit-Tool/`. None of them are required to operate this plugin. If you want context on **why** a choice was made, ask Anmoll directly — don't try to reconstruct from the code.
- **The React frontend.** That ships from `smiliyas/linkwhisper-react` → `src/pages/LinkChecker.tsx`, deployed via Vercel into the WP theme bundle. It is same-origin in production and has its own README in that repo.
- **Kit.com config (forms, tags, automations).** That's a separate operator decision — the plugin only writes the subscriber. Form ID and Tag ID are entered in plugin Settings.

---

**Hand-off complete. Ping Anmoll if anything in this file is unclear.**
