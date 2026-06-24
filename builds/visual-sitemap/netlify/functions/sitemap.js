/**
 * LinkWhisper Visual Sitemap Generator — Netlify Serverless Function
 *
 * Flow:
 *   POST /api/sitemap { url: "https://example.com" }
 *   → BFS crawl up to MAX_PAGES pages, max MAX_DEPTH hops from root
 *   → Extract page titles (from <title> or first <h1>)
 *   → Track parent-child relationships during BFS
 *   → Build hierarchical tree + flat graph (nodes + edges) for visual rendering
 *   → Return JSON { preview, fullReport }
 *
 * preview  — free: depth breakdown, page count, top-level pages (up to 5)
 * fullReport — full tree structure + graph nodes/edges for D3 / force-graph rendering
 *
 * Same email-gate model as internal-link-checker: fullReport is included in the
 * response; frontend gates its display behind email capture.
 */

const fetch   = require("node-fetch");
const cheerio = require("cheerio");

// ─── Constants ──────────────────────────────────────────────────────────────
const MAX_PAGES     = 100;
const MAX_DEPTH     = 4;
const MAX_TIME_MS   = 55_000;   // 55s to stay inside Netlify's 60s function limit
const FETCH_TIMEOUT = 8_000;    // per-page fetch timeout
const CONCURRENCY   = 3;        // parallel fetches

// ─── URL Helpers ────────────────────────────────────────────────────────────

function normalise(raw, base) {
  try {
    const u = new URL(raw, base);
    if (!["http:", "https:"].includes(u.protocol)) return null;
    u.hash = "";
    if (u.pathname.length > 1 && u.pathname.endsWith("/")) {
      u.pathname = u.pathname.slice(0, -1);
    }
    return u.href;
  } catch {
    return null;
  }
}

function originOf(url) {
  try { return new URL(url).origin; } catch { return null; }
}

function pathOf(url) {
  try { return new URL(url).pathname || "/"; } catch { return "/"; }
}

/** Short human-readable label from a URL path — used as fallback title */
function slugToLabel(url) {
  try {
    const path = new URL(url).pathname;
    if (path === "/" || path === "") return "Home";
    const parts = path.replace(/\/$/, "").split("/").filter(Boolean);
    const last  = parts[parts.length - 1] || "Page";
    return last
      .replace(/[-_]/g, " ")
      .replace(/\.[^.]+$/, "")          // strip extension
      .replace(/\b\w/g, c => c.toUpperCase());
  } catch {
    return "Page";
  }
}

// ─── Fetch + Parse ──────────────────────────────────────────────────────────

async function fetchPage(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT);
  try {
    const res = await fetch(url, {
      signal: controller.signal,
      headers: {
        "User-Agent":      "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
        "Accept":          "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.5",
        "Accept-Encoding": "gzip, deflate",
        "Cache-Control":   "no-cache",
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

function detectWordPress(html) {
  const signals = ["wp-content", "wp-json", "wp-includes", "wp-emoji", 'generator" content="WordPress'];
  const lower   = html.toLowerCase();
  return signals.some(s => lower.includes(s));
}

/**
 * Parse a page: extract title, all internal links, noindex flag, WP detection.
 * Returns { title, links: [{to}], noindex, isWordPress }
 */
function parsePage(html, pageUrl, origin) {
  const $ = cheerio.load(html);

  // Title: prefer <title> tag, then first <h1>, then URL slug
  const metaTitle = ($("title").first().text() || "").trim().replace(/\s+/g, " ");
  const h1Title   = ($("h1").first().text()    || "").trim().replace(/\s+/g, " ");
  const title     = metaTitle || h1Title || slugToLabel(pageUrl);

  // Robots noindex
  const robots  = ($('meta[name="robots"]').attr("content") || "").toLowerCase();
  const noindex = robots.includes("noindex");

  const isWordPress = detectWordPress(html);

  // Internal links — anchor text omitted (unused in sitemap; crawl.js uses it for scoring)
  const links = [];
  $("a[href]").each((_, el) => {
    const href = $(el).attr("href");
    const norm = normalise(href, pageUrl);
    if (norm && originOf(norm) === origin) {
      links.push({ to: norm });
    }
  });

  return { title, links, noindex, isWordPress };
}

// ─── Sitemap Fallback (mirrors internal-link-checker) ───────────────────────

function extractSitemapUrls(xml) {
  const urls = [];
  const re   = /<loc>\s*(https?:\/\/[^\s<]+)\s*<\/loc>/gi;
  let m;
  while ((m = re.exec(xml)) !== null) urls.push(m[1].trim());
  return urls;
}

async function fetchSitemapUrls(origin) {
  const candidates = [
    `${origin}/sitemap.xml`,
    `${origin}/sitemap_index.xml`,
    `${origin}/wp-sitemap.xml`,
  ];

  for (const url of candidates) {
    try {
      const ctrl  = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 10_000);
      const res   = await fetch(url, {
        signal: ctrl.signal,
        headers: { "User-Agent": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" },
        redirect: "follow",
      });
      clearTimeout(timer);
      if (!res.ok) continue;
      const ct = res.headers.get("content-type") || "";
      if (!ct.includes("xml") && !ct.includes("text")) continue;
      const xml  = await res.text();
      const urls = extractSitemapUrls(xml);
      if (urls.length === 0) continue;

      const isSitemapIndex = xml.includes("<sitemapindex") || xml.includes("<sitemap>");
      if (isSitemapIndex) {
        const subSitemaps = urls.filter(u => u.includes("sitemap")).slice(0, 2);
        const allUrls = [];
        for (const sub of subSitemaps) {
          try {
            const c2  = new AbortController();
            const t2  = setTimeout(() => c2.abort(), 8_000);
            const r2  = await fetch(sub, { signal: c2.signal, headers: { "User-Agent": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" }, redirect: "follow" });
            clearTimeout(t2);
            if (!r2.ok) continue;
            allUrls.push(...extractSitemapUrls(await r2.text()));
            if (allUrls.length >= MAX_PAGES) break;
          } catch { continue; }
        }
        return allUrls.slice(0, MAX_PAGES);
      }
      return urls.slice(0, MAX_PAGES);
    } catch { continue; }
  }
  return [];
}

// ─── BFS Crawl ──────────────────────────────────────────────────────────────

/**
 * BFS crawl. Returns a Map of url → page data, including parent tracking
 * so we can reconstruct the site tree.
 */
async function crawl(startUrl, origin) {
  const startTime = Date.now();
  // url → { depth, parent, title, noindex, linksOut, isWordPress, fetchFailed }
  const visited   = new Map();
  const queue     = [{ url: startUrl, depth: 0, parent: null }];
  const inQueue   = new Set([startUrl]);
  let hitCrawlCap = false;
  let hitTimeCap  = false;
  let anyWP       = false;

  const processPage = async ({ url, depth, parent }) => {
    if (visited.has(url)) return;
    if (visited.size >= MAX_PAGES) { hitCrawlCap = true; return; }
    if (Date.now() - startTime > MAX_TIME_MS) { hitTimeCap = true; return; }

    const result = await fetchPage(url);
    if (!result) {
      visited.set(url, { depth, parent, title: slugToLabel(url), noindex: false, linksOut: [], fetchFailed: true, isWordPress: false });
      return;
    }

    const { html, finalUrl } = result;
    const normFinal = normalise(finalUrl, origin + "/") || url;

    if (originOf(normFinal) !== origin) {
      visited.set(url, { depth, parent, title: slugToLabel(url), noindex: false, linksOut: [], redirectedOffDomain: true, isWordPress: false });
      return;
    }

    const { title, links, noindex, isWordPress } = parsePage(html, normFinal, origin);
    if (isWordPress) anyWP = true;

    const uniqueLinks = dedupeLinks(links);
    visited.set(url, { depth, parent, title, noindex, linksOut: uniqueLinks, isWordPress });

    if (depth < MAX_DEPTH) {
      for (const { to } of uniqueLinks) {
        if (!inQueue.has(to) && !visited.has(to)) {
          inQueue.add(to);
          queue.push({ url: to, depth: depth + 1, parent: url });
        }
      }
    }
  };

  // Guard: only start a batch if we have enough headroom for the worst-case fetch.
  // Without this, a batch can start at MAX_TIME_MS - 1ms and run 8s past Netlify's 60s limit.
  while (queue.length > 0 && visited.size < MAX_PAGES && Date.now() - startTime < MAX_TIME_MS - FETCH_TIMEOUT) {
    const batch = queue.splice(0, CONCURRENCY);
    await Promise.all(batch.map(processPage));
  }

  if (!hitCrawlCap && queue.length > 0) hitCrawlCap = true;
  if (Date.now() - startTime > MAX_TIME_MS) hitTimeCap = true;

  return { visited, meta: { hitCrawlCap, hitTimeCap, anyWP, rootUrl: startUrl } };
}

function dedupeLinks(links) {
  const seen = new Set();
  return links.filter(({ to }) => {
    if (seen.has(to)) return false;
    seen.add(to);
    return true;
  });
}

/** Sitemap-based fallback (same as link-checker, adds title extraction) */
async function crawlViaSitemap(origin, startTime) {
  const sitemapUrls = await fetchSitemapUrls(origin);
  if (sitemapUrls.length === 0) return null;

  const internalUrls = sitemapUrls
    .map(u => normalise(u, origin + "/"))
    .filter(u => u && originOf(u) === origin)
    .slice(0, 50);

  if (internalUrls.length === 0) return null;

  const rootUrl = normalise(origin + "/", origin + "/") || origin + "/";
  const visited = new Map();

  const BATCH = 5;
  for (let i = 0; i < internalUrls.length && Date.now() - startTime < 40_000; i += BATCH) {
    const batch = internalUrls.slice(i, i + BATCH);
    await Promise.all(batch.map(async (url) => {
      if (visited.has(url)) return;
      const depth = url === rootUrl ? 0 : 1;
      const parent = url === rootUrl ? null : rootUrl;
      const result = await fetchPage(url);
      if (!result) {
        visited.set(url, { depth, parent, title: slugToLabel(url), noindex: false, linksOut: [], fetchFailed: true, isWordPress: false });
        return;
      }
      const { title, links, noindex, isWordPress } = parsePage(result.html, url, origin);
      visited.set(url, { depth, parent, title, noindex, linksOut: dedupeLinks(links), isWordPress });
    }));
  }

  // B1 fix: many sitemaps (e.g. Yoast WP) omit the homepage / from <loc> entries.
  // Without a root node, buildTreeNode can't build the tree — inject a synthetic root
  // so the tree renderer always has an anchor point.
  if (!visited.has(rootUrl)) {
    visited.set(rootUrl, {
      depth: 0, parent: null,
      title: slugToLabel(rootUrl),
      noindex: false, linksOut: [], isWordPress: false,
      syntheticRoot: true,
    });
  }

  return {
    visited,
    meta: {
      hitCrawlCap:      internalUrls.length > visited.size,
      hitTimeCap:       Date.now() - startTime >= 50_000,
      anyWP:            Array.from(visited.values()).some(v => v.isWordPress),
      rootUrl,
      usedSitemap:      true,
      sitemapPageCount: internalUrls.length,
    },
  };
}

// ─── Build Sitemap Structures ────────────────────────────────────────────────

/**
 * Build the full graph representation.
 *
 * nodes — one per crawled page: { id, url, title, depth, linksIn, linksOut, path, noindex }
 * edges — one per unique directed link: { source, target }
 * tree  — nested tree rooted at the homepage, children = pages first discovered via that parent
 *
 * The tree is built from parent-tracking in BFS. Every page that has no
 * parent in the visited map (e.g. orphans discovered via sitemap fallback)
 * are attached as children of the root.
 */
function buildSitemapStructures(graph, crawlMeta) {
  const rootUrl = crawlMeta.rootUrl;

  // ── Build inbound-link counts ──
  const inboundCount = new Map();
  for (const [url] of graph) inboundCount.set(url, 0);
  for (const [, data] of graph) {
    for (const { to } of (data.linksOut || [])) {
      if (graph.has(to)) inboundCount.set(to, (inboundCount.get(to) || 0) + 1);
    }
  }

  // ── Flat nodes list ──
  const nodes = [];
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain || data.syntheticRoot) continue;
    nodes.push({
      id:        url,
      url,
      title:     data.title || slugToLabel(url),
      depth:     data.depth,
      path:      pathOf(url),
      noindex:   data.noindex || false,
      linksIn:   inboundCount.get(url) || 0,
      linksOut:  (data.linksOut || []).filter(l => graph.has(l.to)).length,
    });
  }

  // ── Edges list ──
  const edges = [];
  const edgeSet = new Set();
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;
    for (const { to } of (data.linksOut || [])) {
      if (!graph.has(to)) continue;
      const key = `${url}→${to}`;
      if (!edgeSet.has(key)) {
        edgeSet.add(key);
        edges.push({ source: url, target: to });
      }
    }
  }

  // ── Hierarchical tree ──
  // Build children map from parent tracking
  const childrenMap = new Map();  // parent url → [child url]
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;
    const parent = data.parent;
    if (parent && graph.has(parent)) {
      if (!childrenMap.has(parent)) childrenMap.set(parent, []);
      childrenMap.get(parent).push(url);
    } else if (url !== rootUrl) {
      // Orphan (no traceable parent) — attach to root
      if (!childrenMap.has(rootUrl)) childrenMap.set(rootUrl, []);
      if (!childrenMap.get(rootUrl).includes(url)) {
        childrenMap.get(rootUrl).push(url);
      }
    }
  }

  function buildTreeNode(url, visited = new Set()) {
    if (visited.has(url)) return null;   // prevent cycles
    visited.add(url);
    const data     = graph.get(url);
    if (!data || data.fetchFailed || data.redirectedOffDomain) return null;
    const children = (childrenMap.get(url) || [])
      .map(childUrl => buildTreeNode(childUrl, visited))
      .filter(Boolean)
      .sort((a, b) => a.title.localeCompare(b.title));

    return {
      id:       url,
      url,
      title:    data.title || slugToLabel(url),
      path:     pathOf(url),
      depth:    data.depth,
      noindex:  data.noindex || false,
      linksIn:  inboundCount.get(url) || 0,
      linksOut: (data.linksOut || []).filter(l => graph.has(l.to)).length,
      children,
    };
  }

  const tree = graph.has(rootUrl) ? buildTreeNode(rootUrl) : null;

  // ── Depth breakdown ──
  const depthBreakdown = {};
  for (const node of nodes) {
    const d = node.depth;
    depthBreakdown[d] = (depthBreakdown[d] || 0) + 1;
  }

  return { nodes, edges, tree, depthBreakdown };
}

// ─── Format Output ───────────────────────────────────────────────────────────

function formatOutput(graph, crawlMeta) {
  const pageCount        = graph.size;
  const isSinglePage     = pageCount <= 1;
  const isPartialScan    = crawlMeta.hitCrawlCap || crawlMeta.hitTimeCap;
  const isWordPress      = crawlMeta.anyWP || false;
  const usedSitemap      = crawlMeta.usedSitemap || false;

  const { nodes, edges, tree, depthBreakdown } = buildSitemapStructures(graph, crawlMeta);

  // Top-level pages (depth 0 or 1, sorted by linksIn desc)
  const topPages = nodes
    .filter(n => n.depth <= 1)
    .sort((a, b) => b.linksIn - a.linksIn)
    .slice(0, 8)
    .map(n => ({ url: n.url, title: n.title, path: n.path, depth: n.depth, linksIn: n.linksIn }));

  const warnings = [];
  if (isSinglePage) {
    warnings.push({ type: "blocked", message: "We could only access 1 page. The site may be blocking crawlers." });
  } else if (isPartialScan) {
    warnings.push({ type: "partial", message: `Scanned ${pageCount} pages. Your site may have more — this is a sample.` });
  }
  if (!isWordPress) {
    warnings.push({ type: "not-wordpress", message: "This doesn't appear to be a WordPress site. Results may be less accurate." });
  }
  if (usedSitemap) {
    warnings.push({ type: "sitemap-mode", message: "Built from your sitemap (crawler was blocked). Structure may not reflect exact navigation depth." });
  }

  const meta = {
    pageCount,
    maxDepth:    nodes.length > 0 ? Math.max(...nodes.map(n => n.depth)) : 0,
    depthBreakdown,
    isWordPress,
    isPartialScan,
    isSinglePage,
    usedSitemap,
    warnings,
  };

  // preview — free results: summary + top-level pages only (no full tree)
  const preview = {
    meta,
    topPages,
    depthBreakdown,
    // Shallow tree (root + depth-1 children only) for a teaser
    shallowTree: tree
      ? { ...tree, children: (tree.children || []).slice(0, 5).map(c => ({ ...c, children: [] })) }
      : null,
  };

  // fullReport — complete tree + graph data for D3 / force rendering
  const fullReport = {
    meta,
    tree,
    nodes,
    edges,
  };

  return { preview, fullReport };
}

// ─── Handler ─────────────────────────────────────────────────────────────────

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
    const crawlStart = Date.now();
    let { visited: graph, meta: crawlMeta } = await crawl(startUrl, origin);

    // Sitemap fallback if BFS was blocked.
    // Skip if < 15s remains — sitemap fetch + page crawls need at least that.
    const timeElapsed = Date.now() - crawlStart;
    if (graph.size <= 1 && timeElapsed < MAX_TIME_MS - 15_000) {
      console.log("BFS blocked — trying sitemap fallback");
      const sitemapResult = await crawlViaSitemap(origin, crawlStart);
      if (sitemapResult && sitemapResult.visited.size > 1) {
        graph     = sitemapResult.visited;
        crawlMeta = sitemapResult.meta;
        console.log(`Sitemap fallback: ${graph.size} pages`);
      }
    }

    if (graph.size === 0) {
      return {
        statusCode: 200,
        headers,
        body: JSON.stringify({ error: "Could not crawl this site. It may be blocking crawlers or require JavaScript to render." }),
      };
    }

    const { preview, fullReport } = formatOutput(graph, crawlMeta);

    return {
      statusCode: 200,
      headers,
      body: JSON.stringify({ preview, fullReport }),
    };
  } catch (err) {
    console.error("Sitemap crawl error:", err);
    return {
      statusCode: 500,
      headers,
      body: JSON.stringify({ error: "Sitemap generation failed. Please try again." }),
    };
  }
};
