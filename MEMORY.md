# MEMORY.md — Glitch ⚡

## Created
2026-02-25

## About Me
- **Name:** Glitch
- **Role:** Builder (Vibe Coder)
- **OpenClaw ID:** glitch

## Last Heartbeat
**2026-02-27 00:40 UTC**

## Current Tasks
**1. Onboarding: Glitch — Meet the squad & scope the free internal link checker tool**
- Task ID: `kh7fb8gn1e0hq75cwb3agjs8m181tn90`
- Status: `in_progress`
- Deliverable: "Technical Spec: Internal Link Health Checker — MVP" (doc ID: `m97316xv7d95q7my8mmmzdybjd81vpdh`)

**2. Build Homepage HTML — Single Page LinkWhisper Redesign**
- Task ID: `kh70cc3z11g62hkprtseg0bc9x81vb8t`
- Status: `done` ✅ — H1 updated to Boss's final wording, deploy-ready for Matt
- File: `/home/sprite/agents/glitch/builds/homepage/index.html`
- H1: "Stop losing rankings. Fix your internal link building in 60 seconds."
- Kelly confirmed all 12 sections, responsive, IntersectionObserver animations, testimonials from Rex, pricing toggle scaffolded for Matt

**3. Redesign homepage features section — alternating layout, benefit-driven**
- Task ID: `kh7ff4dbamtndgk59ssmm75y7581vbtc`
- Status: `review` ✅ — Section 7 Jared Bauman quote + "Shotkit — Agency Owner" attribution applied
- File: `/home/sprite/agents/glitch/builds/homepage/index.html`
- 6 rows: Orphan Finder / AI Suggestions / Bulk Accept / Reports / Auto-Linking / ROI Dashboard (coming soon)
- Row 4 (Reports): Option A micro-quote in place
- Section 7 hero testimonial: full quote + "Shotkit — Agency Owner" attribution ✅
- ROI Dashboard: `action=""` empty — needs Boss's Kit.com URL + ~10 min to wire

## Heartbeat 00:40 UTC 2026-02-27

**What happened since last heartbeat (23:40 UTC → 00:40 UTC):**
- Rex (00:10 UTC): Cookie duration CONFIRMED 30 days — live fetch of linkwhisper.com/become-an-affiliate/ page. Not a default guess. Closes the cookie speculation.
- Rex: Added affiliate quote framing for Boss — longevity angle ("wrote a review in 2022, still earning in 2026") is the key differentiator vs. LinkBoss
- Kelly synthesis (00:15 UTC): Cookie confirmed. Boss decision still needed to bump to 60 days. Pen's page one find/replace away from deploy.
- Pen (00:20 UTC): $97 price fix applied in doc — v2 is source of truth. Commission math updated ($29.10/sale).
- Max (00:30 UTC): Acked all drops. Flagged: old live `/become-an-affiliate/` page must be REPLACED not supplemented — duplicate URL with conflicting cookie = support tickets + PageRank split.
- Kelly greenlighted my calculator build at 23:45 UTC — I was waiting for that. Built it.

**What I did this heartbeat:**
- Built commission calculator widget: `builds/affiliate-page/calculator-widget.js`
- Built preview test harness: `builds/affiliate-page/calculator-preview.html`
- Math: $97 × 30% = $29.10/sale, 0.5% CTR, 1% CR conservative / 2% optimistic toggle
- Year 2+ renewals row (70% retention) = silent LinkBoss differentiator in the UI
- Caught and fixed a division-by-12 bug in initial draft before committing
- Clarified Max's 301 redirect concern: issue is PageRank split (not de-indexing), also flagged to check for hardcoded `/affiliate/` or `/affiliates/` shortlinks in plugin/emails
- Committed to git: `feat: affiliate commission calculator widget build`
- Dropped full build comment on affiliate task @Pen @Kelly @Max

**Calculator math at 10k readers:**
- Conservative (1% CR): $15/mo, $175/yr, $122/yr renewals
- Optimistic (2% CR): $29/mo, $349/yr, $244/yr renewals

**Status:**
- Calculator widget: ✅ DONE — ready for Pen to review copy framing, Matt to deploy with page
- `/become-an-affiliate/` page build: waiting on Boss cookie confirmation → Pen fills placeholders → I build the full HTML (~2hrs)
- Features section: `review` — still waiting Boss sign-off
- Anam Hassan window: ~11 hours remaining (closes 12:00 UTC Feb 27) — Boss action only

## Heartbeat 23:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Pen delivered full `/become-an-affiliate/` page copy draft (23:20 UTC) — doc m9771dwj8d7pr60037fs58d6as81wn60, tagged me to build against it
- Kelly synthesis (23:30 UTC): page structurally complete, two gates before deploy — (1) Boss confirm cookie duration, (2) $97 price patch ($77 appears 3× in Pen's doc, stale)
- Max (23:31 UTC): flagged calculator math needs updating — $97 base lifts conservative output from ~$23/mo to ~$29/mo per closing customer, Year 1 total lifts ~$564 → ~$699
- ⚠️ Max flagged: Anam Hassan (wordpress.com/blog) outreach window closes ~12:00 UTC Feb 27 — Boss action only
- Rex + Kelly: approved conservative/optimistic dual-scenario for calculator (1% CR default, 2% toggle)

**What I did this heartbeat:**
- Read Pen's affiliate page copy doc in full — confirmed structure, implementation notes, placeholder positions
- Updated calculator spec in my head: $97 base price, conservative 1% CR default (Rex's call), optimistic 2% toggle
- Dropped detailed build status comment on affiliate task @Pen @Kelly @Max — confirmed calculator can build now (no new gates), offered to start widget this cycle
- Key insight: calculator JS can use `[COOKIE DURATION]` as a JS variable — I don't need Boss's cookie confirmation to start the widget build

**Status:** Waiting on Kelly green-light to start commission calculator widget build (~45 min). All other builds deploy-ready pending Boss gates.

## Heartbeat 22:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Affiliate task: Rex dropped competitive affiliate program intel (22:10 UTC) — LinkBoss "30% recurring" is actually 15% after first invoice (bait-and-switch confirmed via FirstPromoter), GotchSEO affiliate review redirecting to Rankability signup page (live SERP bleed), AffPaying listing 0 reviews + unknown cookie duration
- Pen delivered Email 3 v2 with Rex's commission intel baked in (22:20 UTC) — Kelly approved as final
- Pen also recommended `/become-an-affiliate/` page refresh to win GotchSEO SERP — Kelly approved as a task, waiting Boss signal + cookie duration confirmation
- Max confirmed Email 3 v2 (22:31 UTC), suggested 24hr gap after Email 2 for top-50 personal send, added commission calculator idea for affiliate page

**What I did this heartbeat:**
- Read full affiliate task comments — absorbed Rex's competitive intel, Pen's v2 Email 3, Max's suggestions
- Dropped commission calculator build spec on affiliate task: 2-3 slider inputs (readers, CTR, lifetime), instant earnings output (monthly + year 1 + year 2+ renewals), ~50 lines vanilla JS, ~45 min build time
- Key insight: The 2-year earnings math is a visual differentiator vs. LinkBoss — their 15% drop shows directly in the "Year 2+ renewals" line without naming them
- Sequencing: Pen writes page copy → Dev adds calculator → Matt deploys. One-heartbeat add-on.
- No code changes this cycle — all builds remain deploy-ready

## Heartbeat 21:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Squad activity was entirely in outreach task: Max, Pen, Rex iterating on Email 3 P.S. routing and 60-day lock
- Pen delivered Email 3 P.S. variants (21:20 UTC): Option A for Backlinko + Kinsta, none for aijourn
- Max confirmed routing + updated outreach doc to v8 (21:30 UTC)
- Rex flagged Marketer Milk article URL correction: `/best-seo-tools/` 404s → correct is `/blog/best-seo-tools` (21:10 UTC)
- Kelly synthesis at 21:30 UTC closed all open threads — 60-day language locked everywhere
- Anam Hassan window: ~14.5 hrs remaining at 21:30 UTC (closes ~12:00 UTC Feb 27) — Boss's action, still open

**What I did this heartbeat:**
- Audited UTM URL doc (m97fdjvyfx64y7zca368g31pks81whmn) — found stale entries
- Removed blogtyrant.com from Tier A table (was dropped from Sequence A two heartbeats ago, hadn't been cleaned up from doc)
- Added aijourn.com to Tier A table (the actual Sequence A replacement, was missing entirely)
- Corrected marketermilk.com notes: article URL is `/blog/best-seo-tools` not `/best-seo-tools/` (404), noted Omid Ghiam as contact
- Commented on outreach task @Max @Rex with full change log
- No code changes needed this cycle — all builds remain deploy-ready

**Status:** UTM doc now current with v8 outreach strategy. Features section still in review. All builds waiting Boss deploy decision.

## Heartbeat 20:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Max @mentioned Dev (20:30 UTC): flagged stale "30 Days" in free tool landing page bridge CTA button — needed update to "60 Days" to match body copy + JSON-LD schema (Pen had fixed schema at 20:20 UTC)
- Pen also updated free tool bridge section and all 5 comparison pages with 60-day MBG language (20:20 UTC)
- Rex: "no free trial" gap identified as root cause of piracy behavior — LW has no try-before-buy option, which is WHY people look for nulled versions (20:10 UTC)
- Kelly synthesis: 60-day MBG is the answer to "no free trial" objection — should be surfaced more prominently as risk-reversal

**What I did this heartbeat:**
- Audited `internal-link-checker/index.html` for stale "30" day references
- Found exactly one: bridge CTA button at line 892
- Fixed: `"Try LinkWhisper — 30 Days Risk-Free →"` → `"Try LinkWhisper — 60 Days Risk-Free →"`
- Confirmed all other guarantee references already clean (bridge note, FAQ body x2, JSON-LD schema)
- Committed: `fix: bridge CTA 30→60 days risk-free (Max's guarantee consistency audit)`
- Commented on landing page task @Max with full audit results

**Status:** Free tool landing page fully consistent — zero "30-day" references remain. All guarantee language = 60 days. Deploy-ready.

## Heartbeat 19:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Rex expanded DMCA piracy scope: 6+ active sites, 8 URLs total (was 2)
- Max @mentioned Dev (19:30 UTC): flagged that piracy-displaced searchers will search "link whisper free version" post-DMCA — free tool landing page should capture that intent
- Pen added WP.org verified-install one-liner to comparison pages — Kelly approved
- Kelly synthesis confirmed my DMCA technical note was the most actionable squad path

**What I did this heartbeat:**
- Audited free tool landing page (`internal-link-checker/index.html`) for "link whisper free" search intent
- Gap found: no FAQ entry for "Is there a free version of LinkWhisper?" — exactly the query piracy-displaced searchers use
- Added FAQ entry #7 to both visible accordion + FAQPage JSON-LD structured data — eligible for Google rich snippet
- Copy answers the intent cleanly: free tool = yes, free plugin = no, 30-day MBG bridge
- Committed: `feat: add 'free version of LinkWhisper?' FAQ entry (Max's DMCA piracy search intent flag)`
- Commented on outreach task @Max with full change log

**Status:** All builds deploy-ready. Free tool landing page now captures piracy displacement intent. Waiting Boss deploy decision.

## Heartbeat 18:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Pen added two items to `lw-vs-linkboss.html`: skeptic recovery opener (Section 4) + social proof line (Section 7) — Kelly confirmed, both deployed ✅
- Rex confirmed Anam Hassan Twitter = `@anamhasssan` (triple S) — Boss action today, window ~12hrs
- Rex found piracy sites distributing nulled LW Pro: weadown.com + up4vn.com — Kelly routed DMCA action to Boss
- Rex confirmed LinkBoss social proof gap: 19 Trustpilot reviews vs LW's 400+ WP.org reviews — Pen's social proof line already in page
- Max confirmed auto-link rules angle baked into Instantly sequence Email 1 + Email 3 P.S.

**What I did this heartbeat:**
- Verified `lw-vs-linkboss.html` current state — all additions confirmed in file (skeptic opener line 266, auto-link counter line 271, social proof line 331)
- Dropped technical DMCA + nulled plugin piracy note on outreach task: Freemius GPL monitoring, Google DMCA removal path, host-level takedown faster than domain registrar, backdoor injection pattern explanation for Boss + Matt
- No code changes needed this cycle — page is current and deploy-ready

**Status:** All 6 comparison pages deploy-ready. Features section still in review. Homepage in review. All waiting Boss deploy decision.

## Heartbeat 17:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Pen flagged `lw-vs-linkboss.html` counter-paragraph at line 270 missing explicit auto-link rules mention (17:20 UTC)
- Kelly approved the addition + @mentioned Dev to implement (17:30 UTC)
- Max confirmed right call, flagged "No credits" wording ambiguity → recommended "No per-link cost" (17:31 UTC)

**What I did this heartbeat:**
- Added Pen's 3-sentence auto-link rules block to `lw-vs-linkboss.html` at line 270 — surgical, no restructuring
- Used Max's wording suggestion: "No per-link cost" instead of "No credits" (correct distinction: credits = optional AI upgrade, not auto-link workflow)
- Committed: `feat: add auto-link rules counter-paragraph to lw-vs-linkboss.html`
- Commented on comparison task @Kelly @Pen @Max — confirmed done, all 6 pages still deploy-ready pending Boss
- Marked 8 notifications read

**Status:** lw-vs-linkboss.html fully deploy-ready. All 6 comparison pages waiting on Boss deploy call.

## Heartbeat 16:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Max @mentioned Dev (16:30 UTC): requested pre-built UTM URL list as a doc for all Instantly outreach targets — no manual construction = no typos
- Features section task still sitting at `review`, no new action needed

**What I did this heartbeat:**
- Built UTM URL list doc (m97fdjvyfx64y7zca368g31pks81whmn) — all 11 targets from Rex's v2 list, markdown table + plain-text CSV block, two notes flagged (tool URL placeholder + kinsta.com verify)
- Commented on outreach task tagging @Max with doc link + 3 pre-send notes
- Checked in on features section task — confirmed build complete, waiting Kelly/Boss deploy sign-off

---

## Heartbeat 15:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Outreach task fully loaded: Pen delivered 3 pitches (backlinko.com, aijourn.com, wordpress.com/blog) at 15:20 UTC; Max ack'd at 15:31 UTC updating outreach doc to v6
- Max replacing blogtyrant.com target with Rex's Sequence A replacement; Anam Hassan contact path identified by Rex
- Comparison pages: all 6 deploy-ready, Kelly synthesis at 13:35 UTC — deploy distribution plan in place
- Affiliate resource page: Kelly green-lit build spec (12:40 UTC) BUT sequencing says wait — blocked on (a) comparison page URLs post-deploy and (b) free tool URL. Not urgent, Email 2 sends Mar 10-11.
- All squad tasks holding pattern on Boss gates (CSV export, deploy confirmation)

**What I did this heartbeat:**
- Dropped UTM tracking spec on outreach task: recommended per-contact `utm_content` variable substitution in Instantly for per-source attribution on free tool + comparison page links. 15-min addition before sends go out.
- Confirmed affiliate resource page build is correctly on hold (waiting on Kelly green-light post-deploy)
- No code changes needed this cycle

## Heartbeat 12:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Pen ack'd homepage H1 + stats bar implementation (12:20 UTC) — confirmed both correct ✅
- Pen flagged "Save 12 hours/week" label for Boss sign-off before deploy (survey-backed, just needs greenlight)
- New task created: Affiliate Re-engagement Project (3,500 dormant affiliates) — assigned to Rex/Max
- Rex delivered segmentation framework (4 tiers: Ghost/Tried-Stopped/1-2 Sales/Active); all blocked on Boss's Freemius CSV export
- Max delivered full 3-email sequence drafts + Pen delivered affiliate swipe copy kit
- Kelly queued Dev (me) to build affiliate resource landing page — after free tool ships
- Max's Email 2 references `[Full resource page with banners: URL — Dev builds this]` — that's mine to build
- Comparison pages: Kelly confirmed final Boss answer = LW Shopify is poll/manual ✅ — Pen making final lw-vs-linkboss.html edit, then all 5 deploy-ready
- Roundup outreach task in progress: Rex target list v1 delivered, Pen wrote correction email for wppool.dev, Kelly/Max debating timing (send now vs wait for Mar 5-6 tool launch)

**What I did this heartbeat:**
- Dropped technical spec for affiliate resource page on affiliate task: single-page HTML, one-click copy buttons for swipe files, banner download placeholders, no-login public URL, ~2-3hr build time
- Ack'd Pen's homepage comment: confirmed stat label location for Boss edit if needed
- Marked 7 notifications read

## Heartbeat 07:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Boss confirmed LW Shopify = poll/manual (no webhook-based real-time sync) — via Kelly at 07:30 UTC
- Kelly green-lit lw-vs-linkboss.html for deploy pending Boss's publish sequence confirmation for all 5 pages
- Rex already built the Shopify feature matrix (beat me to it) — content type parity confirmed, webhook gap confirmed
- Pen shipped Draft v3 with updated Shopify framing (maturity angle, no beta claims) — approved by Kelly
- WP.org listing: Boss decision = remove free tool cross-references for now; Pen to revise
- Max created Shopify SEO outreach task (blocked on Boss webhook answer — now answered: no)

**What I did this heartbeat:**
- Found lw-vs-linkboss.html still had old Shopify framing (beta/copy-paste/Any CMS claims) — 5 instances
- Updated all 5 to match Pen's Draft v3: maturity angle ("since 2020 vs. 2025"), no superiority claims, added HTML comment flagging poll/manual confirmation
- Committed: `fix: update lw-vs-linkboss Shopify framing to Draft v3 (Pen) — maturity angle, remove beta/copy-paste claims`
- Commented on comparison page task with full change log
- Marked 4 notifications read

**Deploy status as of 07:40 UTC:**
- lw-vs-linkboss.html: ✅ fully deploy-ready
- Waiting: Boss publish sequence confirmation for all 5 pages

## Heartbeat 06:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Kelly posted new standing protocol on homepage task: all future HTML builds must be sent to Boss via Telegram + path noted in task comments
- Rex dropped LinkBoss Shopify v2.0 competitive intel on Shopify review task — LW comparison page Shopify claim is now a potential liability (LinkBoss has full Shopify since May 2025)
- Kelly + Rex @mentioned me on FB/Google Ads task: confirmed they want `data_freshness` field in CSV parser, first field printed
- Max confirmed Shopify re-engagement email can run independently of comparison page hold (honest feedback ask, no feature-parity claims)
- Kelly held comparison page deploy pending Boss's one-question answer: LW Shopify feature parity vs. LinkBoss v2.0?

**What I did this heartbeat:**
- Dropped full CSV parser spec on FB/Google Ads task: `data_freshness` field confirmed (first-field, staleness warning banner), four analysis questions baked as named extraction fields
- Offered to fetch LW Shopify App Store data for feature parity matrix on Shopify task — waiting for Kelly's go or Matt's direct answer
- Attempted to send HTML files to Boss via Telegram (new protocol) — need Boss's Telegram target ID to route; flagged to Kelly
- Marked 19 notifications read

## Heartbeat 05:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Kelly approved the internal link checker landing page HTML at 05:00 UTC ✅
- Rex flagged 50K vs 40K install count discrepancy + three options for [X] links built stat
- Pen endorsed 40K (converts better, "active" signal), and suggested Kyle Roof as 2nd testimonial (outcome story > uncertain aggregate stats)
- Kelly synthesised Boss decision brief — two paths ready to ship

**What I did this heartbeat:**
- Fixed all 3 `50,000+` instances → `40,000+` in landing page HTML (Rex/Pen research confirmed this)
- Baked in Kyle Roof second testimonial as commented-out Path B block in HTML — one uncomment from live
- Committed: `fix: 50K→40K active sites (Rex/Pen research); add Kyle Roof Path B in HTML comment (Boss decision)`
- Commented on landing page task: explained exactly what changed + clear PATH A / PATH B instructions for Matt
- Dropped CSV parser offer on FB/Google Ads task: Node.js normaliser for when Boss's data exports land
- Marked 2 notifications read

## Heartbeat 04:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Kelly unblocked the Reddit pipeline: Gmail App Password `veyq fnkc jvzn gazf` posted in task comment (also already in .env)
- Pen shipped full landing page copy for Internal Link Health Checker (doc `m97cddrjd6qfppsck96h4nf85h81xt79`) — Kelly approved, Max added distribution layer
- WP.org listing copy fully complete (Pen + Rex + Kelly) — gated on tool launch, in Boss review queue
- Rex flagged new task: FB + Google Ads Analysis — blocked on Boss's ad account data
- Max dropped WP.org distribution layer: UTM granularity, changelog as launch moment

**What I did this heartbeat:**
- Reddit pipeline: Ran dry-run — IMAP connects clean, 0 unread F5Bot emails (test email already read). Pipeline is fully operational.
- Set up OpenClaw cron job `f2dae64d-6b42-4ea6-a803-00126c543f73` (`*/30 * * * *`) — pipeline now runs every 30 min, live mode
- Marked Reddit pipeline task as DONE ✅
- Read Pen's landing page copy (Kelly-approved) — kicked off sub-agent build for `/internal-link-checker/index.html`
- Commented on WP.org listing task: subdomain vs subdirectory SEO note, pre-built UTM URL for Boss

## Current Tasks
**Active:**
1. **Internal Link Health Checker landing page** — sub-agent building `/home/sprite/agents/glitch/builds/internal-link-checker/index.html` (from Pen's approved copy)
2. **Onboarding task** (`kh7fb8gn1e0hq75cwb3agjs8m181tn90`) — still in_progress
3. **Features section** (`kh7ff4dbamtndgk59ssmm75y7581vbtc`) — in review

**Done this session:**
- Reddit pipeline (`kh7a33hcwa1kedbt5b95z3dp6981vkbv`) — DONE ✅ + cron wired

## Heartbeat 02:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Pen wrote post-support review email copy (Phase 2) — "A small ask — if Link Whisper's back on track" — Kelly approved as best single email in the stack
- Max closed the Dhanya wiring loop: full three-automation guard architecture complete (null / maybe_later / post-support), all guarded by `review_email_sent` tag
- Kelly confirmed Phase 2 needs zero dev work if Dhanya is on standard support email

**What I did this heartbeat:**
- Clarified my Phase 2 role (not needed unless Dhanya uses a custom WP-based ticket tool)
- Dropped Kit.com native Gmail integration tip: Gmail label → Kit.com auto-tag, no manual process, no Zapier cost, ~10 min setup
- Recipe: Dhanya labels thread "LW-Resolved" → Kit.com auto-tags `ticket_resolved` → 48h delay → Pen's email fires

## Heartbeat 01:40 UTC 2026-02-26
**What happened since last heartbeat:**
- Rex responded to my dismissal-state edge case with full PHP payload spec for Kit.com webhook — four `prompt_state` routing branches (null/maybe_later/dismissed/reviewed)
- Kelly folded Rex's spec into the PRD as a footnote, confirmed two-state-machine architecture
- Pen wrote copy for the `maybe_later` email path ("Still happy to hear from you") — distinct from the null/first-touch path (Max's 23:30 UTC copy)
- Kelly approved Pen's copy, confirmed two separate Kit.com sequences needed
- Max delivered full Kit.com wiring spec: two automations, one `review_email_sent` guard tag, prompt_state as tag at entry
- Both @mentions resolved — Kelly + Rex were acknowledging my 00:40 UTC notes

**What I did this heartbeat:**
- Delivered final technical checklist for Matt (6 items) + PHP transient guard for milestone deduplication edge case
- Flagged potential duplicate-webhook race condition and provided fix code
- Marked 2 notifications as read

## Heartbeat 00:40 UTC 2026-02-26
**What happened since last heartbeat:**
- PRD for in-product review capture fully loaded: Rex wrote it, Kelly approved, Pen added production-ready floating card copy, Max added email amplification layer (Freemius `license.activated` + Kit.com), Rex confirmed Freemius webhooks DON'T fire on behavioral events — custom `wp_remote_post()` hook required for milestones
- Max called out that Matt should dual-fire hooks: in-product card + Kit.com webhook from same event
- Video script (Pen v2) approved by Kelly — production package fully loaded, waiting on Boss tool selection (Descript/HeyGen)
- No new @mentions on my tasks

**What I did this heartbeat:**
- Dropped async implementation notes on PRD task: `wp_schedule_single_event()` pattern to avoid inline HTTP latency on suggestion-accept clicks, dual-fire fan-out architecture, WP-Cron caveat for managed hosts, and dismissal-state edge case (don't send email if user dismissed in-app)

## Heartbeat 22:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Product video task activated: Rex dropped demo moment research (FatStacksBlog quote, 3 aha moments: bulk inbound build, auto-link counter 0→108, orphan page reveal)
- Max added distribution layer: 3 YouTube cuts from one production, outreach template update, staging site tip for clean counter demo
- No new @mentions on my tasks beyond Kelly's 21:45 UTC ack (already logged)
- Reddit pipeline still blocked — waiting on Boss's Gmail IMAP credentials

**What I did this heartbeat:**
- Dropped technical screen recording setup notes on product video task: demo site setup (WP Test Data, 60+ posts in 2 min), auto-link counter clean-shot technique, resolution guidance (1080p min / 4K preferred), cursor highlighting tools (Screenium/Mouseposé), clip variant extraction notes, free tool lightbox embed offer, and shot list callout request for Pen
- No new code needed this cycle — holding pattern on boss gates

## Heartbeat 21:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Pen + Kelly approved ROI Dashboard waitlist CTA copy (21:20–21:30 UTC)
- Kelly @mentioned me: apply Pen's copy to the coming-soon row + check if shotkit.com URL can be added to Section 7
- Max posted ROI Dashboard welcome email framing notes (believers/skeptics model) — no action needed from Dev
- Max's Kit.com setup brief still pending Boss's URL

**What I did this heartbeat:**
- Updated ROI Dashboard row (Row 6): h3, desc paragraph, input placeholder — Pen's approved primary copy
- Added shotkit.com URL attribution to Section 7 hero testimonial (Jared Bauman)
- Committed: `feat: apply approved ROI Dashboard copy (Pen/Kelly 21:30 UTC) + Shotkit URL attribution`
- Marked 4 notifications read

## Heartbeat 20:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Kelly @mentioned me: fill Shopify launch year (2020) into lw-vs-linkboss.html — confirmed via Shopify App Store listing
- Pen @mentioned me + Rex: Section 7 hero testimonial — use full Jared Bauman quote + "Shotkit — Agency Owner" attribution
- Max @mentioned me: ROI Dashboard waitlist form — Kit.com setup brief for Boss; confirmed the HTML `action=""` is empty, waiting on Boss's Kit.com URL
- All 5 comparison pages + hub page moved to review ✅ (Kelly confirmed, Pen submitted)
- Shopify review task (2.9 avg) fully actioned: Pen's 4 response copies written, Kelly approved, Rex confirmed root cause of "Insufficient Permissions" bug, Max added re-engagement campaign layer

**What I did this heartbeat:**
- Filled Shopify year placeholders in `lw-vs-linkboss.html`: `since 2020` in FAQ JSON-LD + prose paragraph comment updated
- Updated Section 7 hero testimonial in `index.html`: full quote ("thousands of dollars... don't have to pay someone") + "Shotkit — Agency Owner" attribution
- Committed: `fix: fill Shopify 2020 year in lw-vs-linkboss.html; update Section 7 Jared Bauman attribution + quote per Pen`
- Commented on comparison page task + features section task with actioned confirmations
- Flagged to Boss (via features task comment): Kit.com `action=` URL needed for ROI Dashboard waitlist; offered GA4 event tracking add-on (~3 lines) at same time

## Heartbeat 19:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Pen @mentioned me on homepage features task: Row 4 Jared Bauman micro-quote needs trimming — use Option A (agency efficiency angle, no "thousands of dollars" dilution)
- New task created: "Shopify App Store — Fix Review Health (2.9 avg, 12 reviews)" — Rex's full diagnostic, Pen drafted 4 response copies, Kelly approved, now in review for Boss
- Max added distribution email play: re-engage 90-day Shopify users for review prompt, direct link to `/reviews/new`
- Kelly's synthesis flagged Dev (me) as potential builder for Shopify in-app review prompt (pending Boss's Shopify priority confirmation)
- All 5 comparison page HTML files previously built and committed
- Reddit pipeline still blocked on Gmail IMAP credentials (Boss queue)

**What I did this heartbeat:**
- Applied Pen's Option A micro-quote fix to Row 4 (Jared Bauman): `"We don't have to pay someone to go in and manually add internal links on all of our client sites."` — "thousands of dollars" line preserved for Section 7 hero testimonials
- Committed: `fix: apply Pen's Option A micro-quote for Jared Bauman row (agency efficiency angle)`
- Dropped Shopify in-app review prompt build spec on Shopify task: Matt handles backend + App Bridge hook, I handle UI component (~30 min), waiting on Boss greenlight

## Heartbeat 14:40 UTC 2026-02-25
**What happened since last heartbeat:**
- New task assigned: "Redesign homepage features section — alternating layout, benefit-driven" (task ID: `kh7ff4dbamtndgk59ssmm75y7581vbtc`)
- Kelly confirmed Boss approved both uncertain features: Auto-Linking Rules (full row) + ROI Dashboard (coming-soon treatment)
- Max @mentioned me with conversion note: keep Boss's feature order but write AI Suggestions copy as speed-of-insight, not what-the-AI-does
- Pen started LW vs Top 5 Competitors comparison series; Rex dropped intel brief for pages 2-5

**What I did this heartbeat:**
- Built full alternating features section in `/home/sprite/agents/glitch/builds/homepage/index.html`
- 6 feature rows: Orphan Finder, AI Suggestions, Bulk Accept, Reports, Auto-Linking, ROI Dashboard
- Each row: colour tag, benefit headline, desc, 3 outcomes, screenshot placeholder; micro-quotes on rows 1/3/4
- ROI Dashboard: blue "Coming Q2 2026" badge, muted row, inline email waitlist input
- Applied Max's copy guidance for AI Suggestions (speed-of-insight angle)
- Committed to git, moved task to review

## Heartbeat 13:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Kelly got Boss's H1 decision: "Stop losing rankings. Fix your internal link building in 60 seconds." (not the orphaned pages version)
- Max @mentioned me on the comparison series task: requested FAQPage JSON-LD schema template for all 5 comparison pages
- Pen assigned to write LW vs Top 5 Competitors series (5 pages total) — Pen starts after LW vs LinkBoss ships
- LW vs LinkBoss comparison page: Draft v2 approved by Kelly (pricing corrected to $50/$350/yr from Rex's live check)

**What I did this heartbeat:**
- Updated homepage H1 in `/home/sprite/agents/glitch/builds/homepage/index.html` — Boss's confirmed wording
- Commented on homepage task: H1 swap confirmed, file deploy-ready for Matt
- Commented on comparison series task: delivered FAQPage JSON-LD template, per-page answer guidance for Pen, offered to wire schema into LW vs LinkBoss HTML once file is ready

## Heartbeat 12:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Pen shipped LW vs LinkBoss comparison page (Draft v1 complete, Kelly approved, in review)
- Max added distribution strategy to comparison page task (UTM strategy, ranking/conversion notes)
- Rex flagged Linkbot intel on outreach task — Kelly excluded Linkbot from outreach, created comparison page task
- Rex dropped more LinkBoss SERP intel on WP.org branded search task
- Kelly/Max/NPS spec still sitting in Boss's queue — no new unblocks

**What I did this heartbeat:**
- Commented on LW vs LinkBoss comparison page task: UTM deep-link CTA integration plan, FAQPage + Product JSON-LD schema note for Matt, flagged tool URL dependency on CTA section

## Heartbeat 11:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Rex dropped WPBeginner live intel: LW is #7, Internal Link Juicer is #5 in "best free SEO tools" roundup
- Kelly + Max endorsed Rex's "diagnostic complement, not a plugin" pitch angle
- Max added batch session trigger idea for NPS: catch users immediately after accepting 5+ suggestions in one sitting
- Rex recommended raising threshold to 25+ accepted suggestions (not 10), three-milestone chain (25→100→500), personal/founder tone
- Everything still in Boss's queue — no new unblocks

**What I did this heartbeat:**
- Commented on NPS review task: PHP transient implementation for Max's batch session trigger, Rex's milestone chain (25→100→500) mapped to lw_nps_milestone user_meta field, Matt's split (10 lines in accept handler) vs my front-end widget (4 hrs). Spec is now complete.

## Heartbeat 10:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Max shipped outreach list for "best free SEO tools" roundups (12 posts, 7 community targets, 3-tier prioritization, pitch template)
- Both blog posts (Pen) now approved by Kelly — awaiting Boss for publish timing
- Email sequence (Max) approved by Kelly — awaiting Boss approval
- All major deliverables in review, waiting on Boss: homepage HTML, email sequence, blog posts
- Max's outreach list blocked on: tool URL from Dev + Boss launch date decision

**What I did this heartbeat:**
- Commented on Max's outreach list task: tool URL timing explanation, "zero-install" pitch angle strategy, Netlify URL pattern prediction, Phase 2 embed badge idea for Tier A promoters
- Reviewed squad activity — no @mentions, both my tasks holding steady (homepage in review, onboarding in_progress)

## Heartbeat 09:40 UTC 2026-02-25
**What happened since last heartbeat:**
- Homepage HTML shipped and moved to review — Kelly reviewed and confirmed all requirements met
- Pen shipped Blog Post 1 ("60-second audit") — Kelly approved with one copy note, Post 2 in progress
- Max drafted 3-email Kit.com sequence for free tool users — now in review
- New tasks created: NPS review capture widget (inbox, low priority) + PRD for same
- Homepage now waiting for Boss: H1 choice, pricing confirmation, screenshots, FAQ placeholder

**What I did this heartbeat:**
- Commented on NPS review capture task: full build spec (WP hooks, dismissal logic, two-path routing, 4hr estimate, banner fix = 15 min for Matt)
- Commented on Max's email sequence task: Kit.com score-tier tagging implementation (tag on submit, branch Email 2/3 by score band), offered to build the JS integration once Max's form is ready

## Resolved Blockers
- **ESP confirmed: Kit.com** ✅ (was blocking item #2)

## Still Blocking
1. Boss/Kelly: Confirm Netlify + vanilla JS stack for free tool
2. @Pen: "Orphaned pages" — SEO language or friendlier term?
3. Boss: ROI Estimator tab — MVP or Phase 2?
4. Boss: Homepage pricing confirmation, screenshots
5. Boss: Review and approve homepage before Matt can deploy
6. Boss: NPS widget — green light to build when ready
7. Boss: Kit.com ROI Dashboard waitlist form URL → wire into homepage `action=` attr (~10 min)
8. Boss: LW Shopify feature parity vs LinkBoss v2.0? (collections sync, webhooks, real-time sync) — unlocks comparison page Shopify claim
9. Kelly: Boss's Telegram target ID needed for direct HTML file delivery (new standing protocol)

## Key Decisions Made
- Dead-end pages metric added (Quackers' suggestion)
- Shareable score cards: Phase 2
- Positioning: "zero-install, 60-second scan"
- ESP: Kit.com — score-tier tagging planned for Email 2/3 branching
- NPS widget: standalone JS + WP hook, ~4hr build when off back burner
- NPS trigger threshold updated: 25+ accepted suggestions (not 10) per Rex's Freemius A/B test data
- NPS milestone chain: 25→100→500, tracked via lw_nps_milestone user_meta (spec complete)
- Batch session trigger: PHP transients (1hr TTL), triggers on 5+ accepts in one session + lifetime >= threshold

## Squad Status (as of this heartbeat)
- Kelly: Approved homepage + Blog Post 1, coordinating all
- Pen: Shipping Blog Post 2 next heartbeat
- Max: Email sequence in review, distribution plans ready
- Rex: Content overlap research done, NPS trigger logic documented
- Glitch: Homepage in review, waiting on Boss sign-off to start free tool build

## Next Steps
- Homepage: Waiting for Boss review/approval
- Free tool: Waiting for Netlify stack sign-off → then start crawler function (Day 1-2)
- NPS widget: Back burner — brief spec documented, ready to build when Kelly/Boss say go
- Kit.com JS integration: Ready to build in ~30 min once Max's form ID is live

## Task History
- 2026-02-25: Onboarding task — technical spec delivered
- 2026-02-25: Homepage HTML — built, Kelly-approved, in review

## Learnings
- LinkBoss requires plugin install + cloud account. Zero-friction is our differentiator.
- Netlify free tier: 60s timeout matches crawl window perfectly.
- Score-first / gate-second UX is proven model for free tool email capture.
- Kit.com score-tier tagging = easy 30-min JS integration, enables branched email sequences.
- NPS non-dismissible banner: bad UX, Matt fix = 15 min (add user_meta check).
