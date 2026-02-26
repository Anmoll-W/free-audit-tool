/**
 * LinkWhisper Internal Link Health Checker — Crawler Function
 * Netlify serverless function (Node.js)
 *
 * Flow:
 *   POST /api/crawl { url: "https://example.com" }
 *   → BFS crawl up to MAX_PAGES pages, max MAX_DEPTH hops from root
 *   → Build link graph
 *   → Score + analyze
 *   → Return JSON { score, metrics, preview, fullReport }
 *
 * Note: fullReport is included in the response; the frontend gates
 * the display of it behind email capture client-side (no server secret
 * needed — the gate is UX, not auth). For a hardened version, swap to
 * a token-based reveal after email verification.
 */

const fetch = require("node-fetch");
const cheerio = require("cheerio");

// ─── Constants ──────────────────────────────────────────────────────────────
const MAX_PAGES     = 100;
const MAX_DEPTH     = 4;
const MAX_TIME_MS   = 55_000;          // 55s to stay inside Netlify's 60s limit
const FETCH_TIMEOUT = 8_000;           // per-page fetch timeout
const CONCURRENCY   = 3;              // parallel fetches
const GENERIC_ANCHORS = new Set([
  "click here", "here", "read more", "read this", "this", "link",
  "more", "learn more", "continue", "full article", "source",
  "view", "see more", "find out more", "click", "go here",
]);

// ─── Helpers ────────────────────────────────────────────────────────────────

/** Normalise a URL: strip hash, trailing slash, force lowercase scheme+host */
function normalise(raw, base) {
  try {
    const u = new URL(raw, base);
    if (!["http:", "https:"].includes(u.protocol)) return null;
    u.hash = "";
    // Strip trailing slash from pathname unless it's just "/"
    if (u.pathname.length > 1 && u.pathname.endsWith("/")) {
      u.pathname = u.pathname.slice(0, -1);
    }
    return u.href;
  } catch {
    return null;
  }
}

/** Extract the origin (scheme+host) from a URL string */
function originOf(url) {
  try { return new URL(url).origin; } catch { return null; }
}

/** Check if a href looks like an internal link (same origin) */
function isInternal(href, origin) {
  const norm = normalise(href, origin + "/");
  if (!norm) return false;
  return originOf(norm) === origin;
}

/** Fetch a page and return { html, finalUrl } or null on failure */
async function fetchPage(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT);
  try {
    const res = await fetch(url, {
      signal: controller.signal,
      headers: {
        "User-Agent": "LinkWhisper-HealthChecker/1.0 (+https://linkwhisper.com/internal-link-checker)",
        "Accept": "text/html,application/xhtml+xml",
      },
      redirect: "follow",
    });
    clearTimeout(timer);
    if (!res.ok) return null;
    const ct = res.headers.get("content-type") || "";
    if (!ct.includes("html")) return null;
    const html = await res.text();
    return { html, finalUrl: res.url };
  } catch {
    clearTimeout(timer);
    return null;
  }
}

/** Parse a page: extract internal links + check noindex */
function parsePage(html, pageUrl, origin) {
  const $ = cheerio.load(html);

  // Respect noindex — don't report on pages search engines can't see
  const robots = $('meta[name="robots"]').attr("content") || "";
  const noindex = robots.toLowerCase().includes("noindex");

  const links = [];
  $("a[href]").each((_, el) => {
    const href = $(el).attr("href");
    const anchor = ($(el).text() || "").trim().toLowerCase().replace(/\s+/g, " ");
    const norm = normalise(href, pageUrl);
    if (norm && isInternal(norm, origin)) {
      links.push({ to: norm, anchor });
    }
  });

  return { noindex, links };
}

/** Run BFS crawl. Returns the link graph + page metadata. */
async function crawl(startUrl, origin) {
  const startTime  = Date.now();
  const visited    = new Map();   // url → { depth, noindex, linksOut: [{to, anchor}] }
  const queue      = [{ url: startUrl, depth: 0 }];
  const inQueue    = new Set([startUrl]);

  const processPage = async ({ url, depth }) => {
    if (visited.has(url)) return;
    if (visited.size >= MAX_PAGES) return;
    if (Date.now() - startTime > MAX_TIME_MS) return;

    const result = await fetchPage(url);
    if (!result) {
      visited.set(url, { depth, noindex: false, linksOut: [], fetchFailed: true });
      return;
    }

    const { html, finalUrl } = result;
    const normFinal = normalise(finalUrl, origin + "/") || url;

    // If redirect took us off-domain, skip
    if (originOf(normFinal) !== origin) {
      visited.set(url, { depth, noindex: false, linksOut: [], redirectedOffDomain: true });
      return;
    }

    const { noindex, links } = parsePage(html, normFinal, origin);
    const uniqueLinks = dedupeLinks(links);

    visited.set(url, { depth, noindex, linksOut: uniqueLinks });

    // Enqueue unvisited internal links
    if (depth < MAX_DEPTH) {
      for (const { to } of uniqueLinks) {
        if (!inQueue.has(to) && !visited.has(to)) {
          inQueue.add(to);
          queue.push({ url: to, depth: depth + 1 });
        }
      }
    }
  };

  // BFS with limited concurrency
  while (queue.length > 0 && visited.size < MAX_PAGES && Date.now() - startTime < MAX_TIME_MS) {
    const batch = queue.splice(0, CONCURRENCY);
    await Promise.all(batch.map(processPage));
  }

  return visited;
}

/** Dedupe links within a page (keep first occurrence for anchor tracking) */
function dedupeLinks(links) {
  const seen = new Set();
  return links.filter(({ to }) => {
    if (seen.has(to)) return false;
    seen.add(to);
    return true;
  });
}

// ─── Scoring ────────────────────────────────────────────────────────────────

function analyse(graph) {
  // Build inbound link map
  const inbound = new Map();  // url → [{from, anchor}]
  for (const [pageUrl, data] of graph) {
    if (!inbound.has(pageUrl)) inbound.set(pageUrl, []);
    for (const { to, anchor } of (data.linksOut || [])) {
      if (!inbound.has(to)) inbound.set(to, []);
      inbound.get(to).push({ from: pageUrl, anchor });
    }
  }

  const pageCount = graph.size;
  const pages = [];

  // Gather all anchors for generic anchor % calculation
  let totalAnchors = 0;
  let genericAnchors = 0;

  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;

    const linksIn  = (inbound.get(url) || []).filter(l => graph.has(l.from));
    const linksOut = (data.linksOut || []).filter(l => graph.has(l.to));

    const isOrphan   = linksIn.length === 0 && url !== Array.from(graph.keys())[0];
    const isDeadEnd  = linksOut.length === 0;
    const isLowDensity = linksOut.length > 0 && linksOut.length < 2 && !isDeadEnd;
    const isDeepPage = data.depth >= MAX_DEPTH;

    for (const { anchor } of [...linksIn, ...linksOut]) {
      totalAnchors++;
      if (GENERIC_ANCHORS.has(anchor.slice(0, 30))) genericAnchors++;
    }

    pages.push({
      url,
      depth:         data.depth,
      noindex:       data.noindex,
      linksInCount:  linksIn.length,
      linksOutCount: linksOut.length,
      isOrphan,
      isDeadEnd,
      isLowDensity,
      isDeepPage,
      issues: [
        isOrphan      && "orphan",
        isDeadEnd     && url !== Array.from(graph.keys())[0] && "dead-end",
        isLowDensity  && "low-density",
        isDeepPage    && "deep",
      ].filter(Boolean),
    });
  }

  const orphanCount    = pages.filter(p => p.isOrphan).length;
  const deadEndCount   = pages.filter(p => p.isDeadEnd && !p.isOrphan).length;
  const lowDenseCount  = pages.filter(p => p.isLowDensity).length;
  const deepPageCount  = pages.filter(p => p.isDeepPage).length;
  const genericPct     = totalAnchors > 0 ? (genericAnchors / totalAnchors) * 100 : 0;
  const avgLinksPerPage = pageCount > 0
    ? Math.round(pages.reduce((s, p) => s + p.linksOutCount, 0) / pageCount * 10) / 10
    : 0;

  // Scoring: start at 100, apply deductions
  let score = 100;
  score -= Math.min(30, orphanCount    * 3);
  score -= Math.min(20, deadEndCount   * 2);
  score -= Math.min(15, lowDenseCount  * 1);
  score -= Math.min(10, deepPageCount  * 1);
  const genericDeduction = genericPct > 20 ? Math.min(15, (genericPct - 20) * 0.5) : 0;
  score -= genericDeduction;
  score = Math.max(0, Math.round(score));

  const bucket = score >= 85 ? "healthy"
               : score >= 65 ? "needs-work"
               : "critical";

  const bucketLabel = {
    "healthy":    "Healthy",
    "needs-work": "Needs Work",
    "critical":   "Critical",
  }[bucket];

  const bucketMessage = {
    "healthy":    "Your internal linking is solid. A few tweaks could still add value.",
    "needs-work": "Some issues are dragging down your SEO value. Fix these for quick wins.",
    "critical":   "Significant internal linking gaps. These are costing you organic traffic.",
  }[bucket];

  const metrics = {
    pagesCrawled: pageCount,
    orphanPages:  orphanCount,
    deadEndPages: deadEndCount,
    lowDensity:   lowDenseCount,
    deepPages:    deepPageCount,
    genericAnchorPct: Math.round(genericPct),
    avgLinksPerPage,
  };

  // Top 3 findings for the free preview
  const findings = [];
  if (orphanCount > 0)   findings.push({ type: "orphan",    label: `${orphanCount} orphaned pages found`,       detail: "No other page links to these — search engines struggle to find and rank them." });
  if (deadEndCount > 0)  findings.push({ type: "dead-end",  label: `${deadEndCount} dead-end pages`,            detail: "Pages that don't link out anywhere — link equity stops here instead of flowing." });
  if (lowDenseCount > 0) findings.push({ type: "low-density", label: `${lowDenseCount} pages with thin linking`, detail: "Fewer than 2 internal links out — not enough link equity distribution." });
  if (deepPageCount > 0) findings.push({ type: "deep",      label: `${deepPageCount} hard-to-reach pages`,     detail: "These require 4+ clicks from your homepage — Google may deprioritise them." });
  if (genericPct > 20)   findings.push({ type: "anchor",    label: `${Math.round(genericPct)}% generic anchor text`, detail: 'Links using "click here", "read more" etc. miss the chance to signal relevance.' });

  const preview = {
    score,
    bucket,
    bucketLabel,
    bucketMessage,
    metrics,
    topFindings: findings.slice(0, 3),
  };

  // Full report: sorted pages by issue severity
  const fullReport = {
    score,
    bucket,
    bucketLabel,
    bucketMessage,
    metrics,
    findings,
    pages: pages
      .sort((a, b) => b.issues.length - a.issues.length || a.url.localeCompare(b.url))
      .map(p => ({
        url:          p.url,
        depth:        p.depth,
        linksIn:      p.linksInCount,
        linksOut:     p.linksOutCount,
        issues:       p.issues,
        noindex:      p.noindex,
      })),
  };

  return { preview, fullReport };
}

// ─── Handler ────────────────────────────────────────────────────────────────

exports.handler = async (event) => {
  const headers = {
    "Content-Type": "application/json",
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Headers": "Content-Type",
  };

  // Handle CORS preflight
  if (event.httpMethod === "OPTIONS") {
    return { statusCode: 204, headers, body: "" };
  }

  if (event.httpMethod !== "POST") {
    return { statusCode: 405, headers, body: JSON.stringify({ error: "Method not allowed" }) };
  }

  let rawUrl;
  try {
    const body = JSON.parse(event.body || "{}");
    rawUrl = (body.url || "").trim();
  } catch {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Invalid JSON body" }) };
  }

  if (!rawUrl) {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Missing url parameter" }) };
  }

  // Normalise: ensure scheme
  if (!/^https?:\/\//i.test(rawUrl)) rawUrl = "https://" + rawUrl;

  let startUrl;
  try {
    const u = new URL(rawUrl);
    startUrl = u.origin + "/";   // always start from root
  } catch {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Invalid URL" }) };
  }

  const origin = originOf(startUrl);
  if (!origin) {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Could not parse URL origin" }) };
  }

  try {
    const graph = await crawl(startUrl, origin);

    if (graph.size === 0) {
      return {
        statusCode: 200,
        headers,
        body: JSON.stringify({
          error: "Could not crawl this site. It may be blocking crawlers or require JavaScript to render."
        }),
      };
    }

    const { preview, fullReport } = analyse(graph);

    return {
      statusCode: 200,
      headers,
      body: JSON.stringify({ preview, fullReport }),
    };
  } catch (err) {
    console.error("Crawl error:", err);
    return {
      statusCode: 500,
      headers,
      body: JSON.stringify({ error: "Crawl failed. Please try again." }),
    };
  }
};
