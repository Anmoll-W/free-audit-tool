=== lw-audit-store ===
Contributors: linkwhisper
Tags: linkwhisper, audit, internal-use
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

System of record for Free Audit Tool submissions on linkwhisper.com. Internal-use plugin — not intended for wp.org.

== Description ==

Captures every Free Audit Tool submission (URL audited, scan stats, email, UTM) into a dedicated MySQL table on linkwhisper.com. Kit.com remains the email-delivery layer only — this plugin is the source of truth.

Phase 1 (this version) ships the schema only:

* `wp_lw_audits` — one row per audit submission
* `wp_lw_audits_errors` — write-failure log

REST endpoints, admin page, and Kit.com integration land in Phases 2-4.

== Installation ==

1. Upload the `lw-audit-store/` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Confirm the tables exist: `SHOW TABLES LIKE '%lw_audits%';` should return two rows.

== Data lifecycle ==

* Deactivate — tables preserved, plugin can be reactivated without data loss.
* Delete (uninstall) — drops `wp_lw_audits` + `wp_lw_audits_errors` and removes the schema-version option. No undo. Take a mysqldump first if uncertain.

== Changelog ==

= 0.1.0 =
* Phase 1 scaffold: plugin shell + DB schema (wp_lw_audits + wp_lw_audits_errors) via dbDelta. Activation, deactivation, uninstall hooks. No REST endpoints, no admin page, no Kit.com integration yet.
