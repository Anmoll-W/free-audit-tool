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

const fetch   = require("node-fetch");
const cheerio = require("cheerio");
const {
  MAX_PAGES, MAX_DEPTH, MAX_TIME_MS, FETCH_TIMEOUT, CONCURRENCY,
  normalise, originOf, detectWordPress, dedupeLinks,
  createFetcher,
} = require("../../../../shared/crawler-core");

const { fetchPage, fetchSitemapUrls } = createFetcher(fetch);

// ─── Constants ──────────────────────────────────────────────────────────────
const GENERIC_ANCHORS = new Set([
  "click here", "here", "read more", "read this", "this", "link",
  "more", "learn more", "continue", "full article", "source",
  "view", "see more", "find out more", "click", "go here",
]);

// ─── Helpers ────────────────────────────────────────────────────────────────

/** Check if a href looks like an internal link (same origin) */
function isInternal(href, origin) {
  const norm = normalise(href, origin + "/");
  if (!norm) return false;
  return originOf(norm) === origin;
}

/** Parse a page: extract internal links + check noindex + WP detection */
function parsePage(html, pageUrl, origin) {
  const $ = cheerio.load(html);

  const robots  = $('meta[name="robots"]').attr("content") || "";
  const noindex = robots.toLowerCase().includes("noindex");

  const isWordPress = detectWordPress(html);

  const links = [];
  $("a[href]").each((_, el) => {
    const href   = $(el).attr("href");
    const anchor = ($(el).text() || "").trim().toLowerCase().replace(/\s+/g, " ");
    const norm   = normalise(href, pageUrl);
    if (norm && isInternal(norm, origin)) {
      links.push({ to: norm, anchor });
    }
  });

  return { noindex, links, isWordPress };
}

/** Run BFS crawl. Returns the link graph + page metadata + crawl meta. */
async function crawl(startUrl, origin) {
  const startTime  = Date.now();
  const visited    = new Map();
  const queue      = [{ url: startUrl, depth: 0 }];
  const inQueue    = new Set([startUrl]);
  const rootUrl    = startUrl;
  let hitCrawlCap  = false;
  let hitTimeCap   = false;
  let anyWordPress = false;

  const processPage = async ({ url, depth }) => {
    if (visited.has(url)) return;
    if (visited.size >= MAX_PAGES) { hitCrawlCap = true; return; }
    if (Date.now() - startTime > MAX_TIME_MS) { hitTimeCap = true; return; }

    const result = await fetchPage(url);
    if (!result) {
      visited.set(url, { depth, noindex: false, linksOut: [], fetchFailed: true, isWordPress: false });
      return;
    }

    const { html, finalUrl } = result;
    const normFinal = normalise(finalUrl, origin + "/") || url;

    if (originOf(normFinal) !== origin) {
      visited.set(url, { depth, noindex: false, linksOut: [], redirectedOffDomain: true, isWordPress: false });
      return;
    }

    const { noindex, links, isWordPress } = parsePage(html, normFinal, origin);
    if (isWordPress) anyWordPress = true;
    const uniqueLinks = dedupeLinks(links);

    visited.set(url, { depth, noindex, linksOut: uniqueLinks, isWordPress });

    if (depth < MAX_DEPTH) {
      for (const { to } of uniqueLinks) {
        if (!inQueue.has(to) && !visited.has(to)) {
          inQueue.add(to);
          queue.push({ url: to, depth: depth + 1 });
        }
      }
    }
  };

  while (queue.length > 0 && visited.size < MAX_PAGES && Date.now() - startTime < MAX_TIME_MS) {
    const batch = queue.splice(0, CONCURRENCY);
    await Promise.all(batch.map(processPage));
  }

  if (!hitCrawlCap && queue.length > 0) hitCrawlCap = true;
  if (Date.now() - startTime > MAX_TIME_MS) hitTimeCap = true;

  return { visited, meta: { hitCrawlCap, hitTimeCap, anyWordPress, queueRemaining: queue.length, rootUrl } };
}

/**
 * Build a link graph from sitemap URLs by fetching each page's outbound links.
 * Used as fallback when BFS crawl gets blocked (e.g. Cloudflare).
 */
async function crawlViaSitemap(origin, startTime) {
  const sitemapUrls = await fetchSitemapUrls(origin);
  if (sitemapUrls.length === 0) return null;

  const internalUrls = sitemapUrls
    .map(u => normalise(u, origin + "/"))
    .filter(u => u && originOf(u) === origin)
    .slice(0, 50);

  if (internalUrls.length === 0) return null;

  const visited = new Map();
  const rootUrl = normalise(origin + "/", origin + "/") || origin + "/";

  const BATCH_SIZE = 5;
  for (let i = 0; i < internalUrls.length && Date.now() - startTime < 40_000; i += BATCH_SIZE) {
    const batch = internalUrls.slice(i, i + BATCH_SIZE);
    await Promise.all(batch.map(async (url) => {
      if (visited.has(url)) return;
      const depth = url === rootUrl ? 0 : 1;
      const result = await fetchPage(url);
      if (!result) {
        visited.set(url, { depth, noindex: false, linksOut: [], fetchFailed: true, isWordPress: false });
        return;
      }
      const { noindex, links, isWordPress } = parsePage(result.html, url, origin);
      visited.set(url, { depth, noindex, linksOut: dedupeLinks(links), isWordPress });
    }));
  }

  return {
    visited,
    meta: {
      hitCrawlCap:      internalUrls.length > visited.size,
      hitTimeCap:       Date.now() - startTime >= 50_000,
      anyWordPress:     Array.from(visited.values()).some(v => v.isWordPress),
      queueRemaining:   0,
      rootUrl,
      usedSitemap:      true,
      sitemapPageCount: internalUrls.length,
    },
  };
}

// ─── Scoring ────────────────────────────────────────────────────────────────

function analyse(graph, crawlMeta) {
  const inbound = new Map();
  for (const [pageUrl, data] of graph) {
    if (!inbound.has(pageUrl)) inbound.set(pageUrl, []);
    for (const { to, anchor } of (data.linksOut || [])) {
      if (!inbound.has(to)) inbound.set(to, []);
      inbound.get(to).push({ from: pageUrl, anchor });
    }
  }

  const pageCount = graph.size;
  const pages = [];
  const rootUrl = crawlMeta?.rootUrl || Array.from(graph.keys())[0];

  let totalAnchors  = 0;
  let genericAnchors = 0;

  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;

    const linksIn   = (inbound.get(url) || []).filter(l => graph.has(l.from));
    const linksOut  = (data.linksOut || []).filter(l => graph.has(l.to));
    const isOrphan  = linksIn.length === 0 && url !== rootUrl;
    const isDeadEnd = linksOut.length === 0;
    const isLowDensity = linksOut.length > 0 && linksOut.length < 2 && !isDeadEnd;
    const isDeepPage   = data.depth >= MAX_DEPTH;

    for (const { anchor } of [...linksIn, ...linksOut]) {
      totalAnchors++;
      if (GENERIC_ANCHORS.has(anchor.slice(0, 30))) genericAnchors++;
    }

    pages.push({
      url, depth: data.depth, noindex: data.noindex,
      linksInCount: linksIn.length, linksOutCount: linksOut.length,
      isOrphan, isDeadEnd, isLowDensity, isDeepPage,
      issues: [
        isOrphan      && "orphan",
        isDeadEnd     && url !== rootUrl && "dead-end",
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

  let score = 100;
  score -= Math.min(30, orphanCount   * 3);
  score -= Math.min(20, deadEndCount  * 2);
  score -= Math.min(15, lowDenseCount * 1);
  score -= Math.min(10, deepPageCount * 1);
  const genericDeduction = genericPct > 20 ? Math.min(15, (genericPct - 20) * 0.5) : 0;
  score -= genericDeduction;
  score = Math.max(0, Math.round(score));

  const isSinglePageCrawl = pageCount <= 1;
  const isWordPress        = crawlMeta?.anyWordPress || false;
  const hitCrawlCap        = crawlMeta?.hitCrawlCap  || false;
  const hitTimeCap         = crawlMeta?.hitTimeCap   || false;
  const isPartialScan      = hitCrawlCap || hitTimeCap;

  // 0–64 = Critical · 65–84 = Needs Work · 85–100 = Healthy
  const bucket = score >= 85 ? "healthy" : score >= 65 ? "needs-work" : "critical";
  const bucketLabel = { "healthy": "Healthy", "needs-work": "Needs Work", "critical": "Critical" }[bucket];
  const bucketMessage = isSinglePageCrawl
    ? "We could only access 1 page. The site may be blocking crawlers. Results are unreliable."
    : {
        "healthy":    "Your internal linking is solid. A few tweaks could still add value.",
        "needs-work": "Some issues are dragging down your SEO value. Fix these for quick wins.",
        "critical":   "Significant internal linking gaps. These are costing you organic traffic.",
      }[bucket];

  const metrics = {
    pagesCrawled:     pageCount,
    orphanPages:      orphanCount,
    deadEndPages:     deadEndCount,
    lowDensity:       lowDenseCount,
    deepPages:        deepPageCount,
    genericAnchorPct: Math.round(genericPct),
    avgLinksPerPage,
  };

  const findings = [];
  if (!isSinglePageCrawl) {
    if (orphanCount > 0)   findings.push({ type: "orphan",      label: `${orphanCount} orphaned page${orphanCount !== 1 ? "s" : ""} found`,        detail: "No other page links to these — search engines struggle to find and rank them." });
    if (deadEndCount > 0)  findings.push({ type: "dead-end",    label: `${deadEndCount} dead-end page${deadEndCount !== 1 ? "s" : ""}`,             detail: "Pages that don't link out anywhere — link equity stops here instead of flowing." });
    if (lowDenseCount > 0) findings.push({ type: "low-density", label: `${lowDenseCount} page${lowDenseCount !== 1 ? "s" : ""} with thin linking`, detail: "Fewer than 2 internal links out — not enough link equity distribution." });
    if (deepPageCount > 0) findings.push({ type: "deep",        label: `${deepPageCount} hard-to-reach page${deepPageCount !== 1 ? "s" : ""}`,      detail: "These require 4+ clicks from your homepage — Google may deprioritise them." });
    if (genericPct > 20)   findings.push({ type: "anchor",      label: `${Math.round(genericPct)}% generic anchor text`, detail: 'Links using "click here", "read more" etc. miss the chance to signal relevance.' });
  }

  const warnings = [];
  if (isSinglePageCrawl) {
    warnings.push({ type: "blocked",  message: "We could only access 1 page. The site may be blocking crawlers. Results are unreliable." });
  }
  if (isPartialScan && !isSinglePageCrawl) {
    warnings.push({ type: "partial",  message: `Scanned ${pageCount} pages. Your site may have more — score is based on a sample.` });
  }
  if (!isWordPress) {
    warnings.push({ type: "not-wordpress", message: "This doesn't appear to be a WordPress site. The tool is optimised for WordPress — results for other platforms may be less accurate." });
  }

  const preview = {
    score:            isSinglePageCrawl ? null : score,
    bucket:           isSinglePageCrawl ? "unreliable" : bucket,
    bucketLabel:      isSinglePageCrawl ? "Unreliable" : bucketLabel,
    bucketMessage, metrics, topFindings: findings.slice(0, 3), warnings,
    isSinglePageCrawl, isPartialScan, isWordPress,
  };

  const fullReport = {
    score:       isSinglePageCrawl ? null : score,
    bucket:      isSinglePageCrawl ? "unreliable" : bucket,
    bucketLabel: isSinglePageCrawl ? "Unreliable" : bucketLabel,
    bucketMessage, metrics, findings, warnings,
    pages: pages
      .sort((a, b) => b.issues.length - a.issues.length || a.url.localeCompare(b.url))
      .map(p => ({ url: p.url, depth: p.depth, linksIn: p.linksInCount, linksOut: p.linksOutCount, issues: p.issues, noindex: p.noindex })),
  };

  return { preview, fullReport };
}

// ─── Handler ────────────────────────────────────────────────────────────────

exports.handler = async (event) => {
  const headers = {
    "Content-Type":                "application/json",
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Headers": "Content-Type",
  };

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

  if (!/^https?:\/\//i.test(rawUrl)) rawUrl = "https://" + rawUrl;

  let startUrl;
  try {
    const u = new URL(rawUrl);
    startUrl = u.origin + "/";
  } catch {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Invalid URL" }) };
  }

  const origin = originOf(startUrl);
  if (!origin) {
    return { statusCode: 400, headers, body: JSON.stringify({ error: "Could not parse URL origin" }) };
  }

  try {
    const crawlStartTime = Date.now();
    let { visited: graph, meta: crawlMeta } = await crawl(startUrl, origin);

    if (graph.size <= 1) {
      console.log("BFS blocked or single-page site — trying sitemap fallback");
      const sitemapResult = await crawlViaSitemap(origin, crawlStartTime);
      if (sitemapResult && sitemapResult.visited.size > 1) {
        graph     = sitemapResult.visited;
        crawlMeta = sitemapResult.meta;
        console.log(`Sitemap fallback: found ${graph.size} pages`);
      }
    }

    if (graph.size === 0) {
      return {
        statusCode: 200,
        headers,
        body: JSON.stringify({ error: "Could not crawl this site. It may be blocking crawlers or require JavaScript to render." }),
      };
    }

    const { preview, fullReport } = analyse(graph, crawlMeta);
    return { statusCode: 200, headers, body: JSON.stringify({ preview, fullReport }) };
  } catch (err) {
    console.error("Crawl error:", err);
    return { statusCode: 500, headers, body: JSON.stringify({ error: "Crawl failed. Please try again." }) };
  }
};
