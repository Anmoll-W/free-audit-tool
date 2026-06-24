/**
 * Shared crawler utilities — LinkWhisper free audit tools.
 *
 * Zero external runtime dependencies. All external modules (node-fetch, cheerio)
 * are injected by each tool, keeping this file self-contained regardless of which
 * build's node_modules is on the resolution path.
 *
 * Exports:
 *   Constants : MAX_PAGES · MAX_DEPTH · MAX_TIME_MS · FETCH_TIMEOUT · CONCURRENCY
 *   URL utils : normalise · originOf · pathOf · slugToLabel
 *   HTML utils: detectWordPress
 *   Data utils: dedupeLinks · extractSitemapUrls
 *   Factory   : createFetcher(fetch) → { fetchPage, fetchSitemapUrls }
 *
 * Usage in each tool:
 *   const fetch = require('node-fetch');
 *   const { MAX_PAGES, normalise, ..., createFetcher } = require('../../../../shared/crawler-core');
 *   const { fetchPage, fetchSitemapUrls } = createFetcher(fetch);
 */

// ─── Constants ───────────────────────────────────────────────────────────────
const MAX_PAGES     = 100;
const MAX_DEPTH     = 4;
const MAX_TIME_MS   = 55_000;   // 55s — stay inside Netlify's 60s function limit
const FETCH_TIMEOUT = 8_000;    // per-page fetch timeout
const CONCURRENCY   = 3;        // parallel fetches in each BFS batch

// ─── URL Helpers ─────────────────────────────────────────────────────────────

/** Normalise a URL: strip hash + trailing slash, enforce http/https. */
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

/** Convert a URL slug to a human-readable label — fallback page title. */
function slugToLabel(url) {
  try {
    const path = new URL(url).pathname;
    if (path === "/" || path === "") return "Home";
    const parts = path.replace(/\/$/, "").split("/").filter(Boolean);
    const last  = parts[parts.length - 1] || "Page";
    return last
      .replace(/[-_]/g, " ")
      .replace(/\.[^.]+$/, "")
      .replace(/\b\w/g, c => c.toUpperCase());
  } catch {
    return "Page";
  }
}

// ─── HTML Helpers ─────────────────────────────────────────────────────────────

function detectWordPress(html) {
  const signals = [
    "wp-content", "wp-json", "wp-login", "wp-includes",
    "wordpress", "/xmlrpc.php", "wp-emoji",
    'generator" content="WordPress',
  ];
  const lower = html.toLowerCase();
  return signals.some(s => lower.includes(s));
}

// ─── Data Utilities ───────────────────────────────────────────────────────────

/** Keep first occurrence of each target URL, drop duplicates. */
function dedupeLinks(links) {
  const seen = new Set();
  return links.filter(({ to }) => {
    if (seen.has(to)) return false;
    seen.add(to);
    return true;
  });
}

/** Extract all <loc> URLs from a sitemap or sitemap-index XML string. */
function extractSitemapUrls(xml) {
  const urls = [];
  const re   = /<loc>\s*(https?:\/\/[^\s<]+)\s*<\/loc>/gi;
  let m;
  while ((m = re.exec(xml)) !== null) urls.push(m[1].trim());
  return urls;
}

// ─── Network Factory ──────────────────────────────────────────────────────────

/**
 * Returns { fetchPage, fetchSitemapUrls } bound to the provided fetch function.
 *
 * Injecting fetch instead of requiring node-fetch here keeps this file free of
 * external dependencies — each tool passes in its own require('node-fetch').
 */
function createFetcher(fetch) {
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

  async function fetchSitemapUrls(origin) {
    const candidates = [
      `${origin}/sitemap.xml`,
      `${origin}/sitemap_index.xml`,
      `${origin}/wp-sitemap.xml`,
    ];

    for (const sitemapUrl of candidates) {
      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 10_000);
        const res = await fetch(sitemapUrl, {
          signal: controller.signal,
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
              const r2  = await fetch(sub, {
                signal: c2.signal,
                headers: { "User-Agent": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" },
                redirect: "follow",
              });
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

  return { fetchPage, fetchSitemapUrls };
}

// ─── Exports ─────────────────────────────────────────────────────────────────

module.exports = {
  MAX_PAGES,
  MAX_DEPTH,
  MAX_TIME_MS,
  FETCH_TIMEOUT,
  CONCURRENCY,
  normalise,
  originOf,
  pathOf,
  slugToLabel,
  detectWordPress,
  dedupeLinks,
  extractSitemapUrls,
  createFetcher,
};
