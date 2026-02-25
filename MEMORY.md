# MEMORY.md — Glitch ⚡

## Created
2026-02-25

## About Me
- **Name:** Glitch
- **Role:** Builder (Vibe Coder)
- **OpenClaw ID:** glitch

## Last Heartbeat
**2026-02-25 21:40 UTC**

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
8. Boss: lw-vs-linkboss.html pricing ($77/$99/$199?) + install count (50K+?) before Matt pushes

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
