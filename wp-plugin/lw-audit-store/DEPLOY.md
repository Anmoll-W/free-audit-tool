# LW Audit Store — Deploy Guide

Plugin version: 0.3.0
Target: linkwhisper.com (production WP)

---

## What this plugin does

- **`POST /wp-json/lw/v1/scan`** — crawls a public website (up to 75 pages, 50s budget) and returns a link-health report. Used by the React audit page on linkwhisper.com. Replaces the old Netlify function.
- **`POST /wp-json/lw/v1/emails`** — captures the visitor email after they see results, sends the audit email via `wp_mail`, and subscribes them to Kit.com. Failed Kit syncs are retried hourly via WP-Cron.
- **WP admin → Settings → LW Audit** — operator config: Kit credentials, sender identity, CORS allow-list, physical address (CAN-SPAM).
- **WP admin → LW Audit (top-level menu)** — read-only dashboard listing every captured submission and its delivery status.

The plugin is the **system of record** for every free-tool acquisition. Kit.com is the delivery layer only.

---

## Install

1. Upload the unzipped `lw-audit-store/` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → LW Audit Store**.
3. Verify schema:
   ```sql
   SHOW TABLES LIKE '%lw_audits%';
   -- expected: wp_lw_audits, wp_lw_audits_errors
   ```
4. Verify the cron is scheduled:
   ```sql
   SELECT option_value FROM wp_options WHERE option_name = 'cron' \G
   -- look for `lw_audit_kit_retry`
   ```

---

## Configure — required before first capture

Go to **Settings → LW Audit** and fill in:

| Field | Required? | Notes |
|---|---|---|
| HMAC Shared Secret | Optional | Only needed if you flip the controller to require signed requests. Frontend currently does not sign. Min 32 chars when set. |
| Kit.com API Key | Required | From Kit account settings → API. |
| Kit.com Form ID | Required | The form that subscribers should land on. |
| Kit.com Tag ID | Optional | Tag applied to every subscriber after add. |
| From Email | Required | Must be a domain wp_mail can authenticate (typically `support@linkwhisper.com`). |
| From Name | Required | Displayed sender name. |
| Reply-To Email | Required | Where unsubscribe replies land. |
| **Physical Mailing Address** | **Required** | **CAN-SPAM Sec. 5(a)(5)** — appears in every email footer. Multi-line OK. |
| Extra CORS Origins | **Required for any non-prod environment** | Newline-separated, no trailing slash, include scheme (e.g. `http://localhost:8080`). Built-in allow-list already includes `linkwhisper.com`, `www.linkwhisper.com`, `audit.linkwhisper.com`, the prod Netlify host. **Any other origin (staging URL, dev `localhost:*`, alternate domain) must be added here or the React app will fail with a misleading "Network error" UI message.** |

### CORS allow-list — hard install step

The React app at `https://linkwhisper.com/internal-link-checker` calls this plugin same-origin (the bundle ships inside the WP theme), so production needs no CORS config. **Every other environment does.** If you skip the Extra CORS Origins setting on a staging install, the browser will reject the email-submit OPTIONS preflight (the `Idempotency-Key` request header is not in WP core's default Allow-Headers), the React app will show "Network error. Please check your connection.", and curl from the same machine will still return 200 — making it look like a frontend bug. It isn't.

**Verify the allow-list took effect after editing the setting:**

```bash
curl -i -X OPTIONS \
  "https://YOUR-STAGING-HOST/wp-json/lw/v1/emails" \
  -H "Origin: https://YOUR-FRONTEND-ORIGIN" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Idempotency-Key, Content-Type"
```

Expected response headers:
- `Access-Control-Allow-Origin: https://YOUR-FRONTEND-ORIGIN`
- `Access-Control-Allow-Headers: Content-Type, X-LW-Idempotency-Key, Idempotency-Key, X-LW-Signature`
- `Access-Control-Max-Age: 86400`

If `Idempotency-Key` is missing from the Allow-Headers list, your origin isn't in the allow-list — re-check Settings → LW Audit → Extra CORS Origins (one origin per line, no trailing slash, include scheme).

---

## Verify — first scan + capture

1. Visit `https://linkwhisper.com/internal-link-checker`.
2. Paste a small WordPress site URL (e.g. a personal blog) → **Check My Site**.
3. Wait for results (10–50s). You should see a score, four stat cards, and the top issues.
4. Enter a test email → **Unlock report**.
5. In WP admin → **LW Audit** → confirm the row appears with `email_status: sent` and `kit_status: synced` (or `pending` if Kit was slow — the cron will retry within an hour).
6. Check the inbox: subject begins with `Your link health score: …`. Footer must show:
   - The "you received this email because…" line
   - Your physical mailing address
   - The Unsubscribe link

---

## Operations

### Cron retry (Kit.com)

Failed Kit subscribes are retried hourly, up to 3 attempts. After the third failure the row is flagged `kit_dead` and not retried again. Manually retry by running:

```sql
UPDATE wp_lw_audits SET kit_status = 'pending', kit_attempts = 0 WHERE id = …;
```

Then wait for the next hourly tick (or trigger via WP-Cron's "Run now" if you have a cron viewer plugin).

### Rotating the HMAC secret

If signed requests are ever enabled: rotate the secret in **Settings → LW Audit**, then immediately update the same value in the React app's build env. Roll-forward only — there is no grace window.

### Inspecting failures

`wp_lw_audits_errors` records every write failure with timestamp + reason. Sort by `created_at DESC` for the most recent issues.

---

## Known limitations (v0.3.0)

- **Inline mail + Kit dispatch.** The capture endpoint sends `wp_mail` and calls Kit synchronously. Kit timeout is 3s; total inline budget is ~5s worst case. Async dispatch (background queue) is a follow-up.
- **No HMAC enforcement.** The controller accepts unsigned POSTs from allow-listed origins. Add HMAC enforcement once the operator workflow is settled.
- **Crawler is single-process.** No queue, no horizontal scaling. Each scan ties up one PHP-FPM worker for up to 50s. With the 10/hour rate limit per IP this is fine; revisit if traffic grows.
- **No retry for `wp_mail` failures.** If `wp_mail` returns false the row is marked `email_status: mail_failed` and never retried automatically. Watch the dashboard.

---

## Uninstall

Deleting the plugin via Plugins → Delete drops both tables. **There is no undo.** Take a `mysqldump` of `wp_lw_audits` and `wp_lw_audits_errors` first if there is any chance you'll want the data back.

Deactivating (without deleting) preserves the data.

---

## Files in this plugin

```
lw-audit-store/
├── lw-audit-store.php         — bootstrap, version, requires
├── readme.txt                 — wp.org-style metadata
├── uninstall.php              — drops tables on delete
├── DEPLOY.md                  — this file
├── includes/
│   ├── class-installer.php    — schema + dbDelta
│   ├── class-settings.php     — admin Settings page
│   ├── class-kit-client.php   — Kit.com API wrapper (3s timeout)
│   ├── class-mailer.php       — wp_mail + template render
│   ├── class-crawler.php      — PHP crawler (was Netlify function)
│   ├── class-rest-controller.php — /scan + /emails routes, CORS, rate limit
│   ├── class-cron.php         — hourly Kit retry
│   └── class-admin-page.php   — read-only dashboard
└── templates/
    └── email-audit-results.html — audit email layout
```
