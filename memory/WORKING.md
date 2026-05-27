# WORKING.md — Current Task State

*Update this file after completing work. Read it first after compaction.*

## Current Task
Review PHP crawler sitemap fallback mechanics for `wp-plugin/lw-audit-store`, specifically whether sitemap traversal can under-crawl when the first sitemap index entries contain very few URLs.

## Status
Review completed and narrow fix applied. The PHP crawler did not literally stop because the first child sitemap had one URL, but it only inspected the first two child sitemap files from a sitemap index, which could under-crawl sites whose early sitemap entries are tiny. Increased bounded child sitemap inspection to 10.

## Blockers
Mission Control tooling is not available in this Codex session, so findings were recorded locally and reported in chat.

## Next Steps
1. If the legacy Netlify implementation is still used anywhere, mirror the same sitemap-index limit change in `builds/internal-link-checker/netlify/functions/crawl.js`.
2. Consider adding a small crawler test harness/mocked WordPress HTTP layer before deeper sitemap traversal changes.
3. Regenerate `dist/lw-audit-store-0.3.0.zip` before using the zip artifact.

## Notes
2026-05-12: PHP lint passed for all plugin PHP files. No Composer/npm build is required for `wp-plugin/lw-audit-store`; `builds/internal-link-checker/package.json` belongs to the legacy Netlify implementation, not the WP plugin.
2026-05-21: Updated `class-crawler.php` to inspect up to 10 child sitemaps from a sitemap index instead of 2. `php -l wp-plugin/lw-audit-store/includes/class-crawler.php` passed.
2026-05-21: Bumped crawler fetch timeout from 8s to 15s and made sitemap candidate/sub-sitemap fetches use the same `FETCH_TIMEOUT`. PHP lint passed.
2026-05-21: Temporarily commented out scan rate-limit enforcement in `class-rest-controller.php` for live repeated scan testing. Added `// NOTE` reminder to re-enable before production. PHP lint passed.
2026-05-21: Fixed likely PictureCorrect sitemap issue: crawler now treats `www` and non-`www` variants as the same internal origin, accepts redirects between them, filters sitemap URLs with the relaxed match, and tries alternate `www`/non-`www` sitemap candidate origins. PHP lint passed.
2026-05-21: Expanded scan reach for testing: `MAX_PAGES` 75 -> 100, `MAX_TIME_S` 50 -> 55, `CONCURRENCY` 3 -> 5, sitemap fallback now samples up to `MAX_PAGES`, and REST `set_time_limit` 60 -> 65. PHP lint passed for crawler and REST controller.
