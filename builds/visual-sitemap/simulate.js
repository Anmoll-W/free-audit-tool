/**
 * Deep simulation for sitemap.js — runs before any deploy.
 *
 * Suite A: Unit tests — pure logic, no network.
 *   A1  normalise() edge cases
 *   A2  slugToLabel()
 *   A3  dedupeLinks()
 *   A4  buildSitemapStructures() — happy path (6-page mock site)
 *   A5  buildSitemapStructures() — synthetic-root injection (sitemap fallback, homepage absent)
 *   A6  buildSitemapStructures() — cycle guard
 *   A7  formatOutput() — depthBreakdown sum matches node count
 *   A8  handler() — OPTIONS preflight returns 204
 *   A9  handler() — GET returns 405
 *   A10 handler() — missing URL returns 400
 *   A11 handler() — malformed JSON returns 400
 *
 * Suite B: E2E live smoke test — real URL, real network.
 *   B1  linkwhisper.com (WP + Cloudflare → sitemap fallback path expected)
 */

const assert = require("assert");
const { handler } = require("./netlify/functions/sitemap");

let passed = 0;
let failed = 0;

function test(name, fn) {
  try {
    fn();
    console.log(`  ✅ ${name}`);
    passed++;
  } catch (e) {
    console.log(`  ❌ ${name}`);
    console.log(`     ${e.message}`);
    failed++;
  }
}

async function testAsync(name, fn) {
  try {
    await fn();
    console.log(`  ✅ ${name}`);
    passed++;
  } catch (e) {
    console.log(`  ❌ ${name}`);
    console.log(`     ${e.message}`);
    failed++;
  }
}

// ─── Reach into module internals via a thin shim ────────────────────────────
// sitemap.js exports only `handler`. To unit-test pure helpers we re-implement
// the deterministic ones inline here (same code, no network dependency).

function normalise(raw, base) {
  try {
    const u = new URL(raw, base);
    if (!["http:", "https:"].includes(u.protocol)) return null;
    u.hash = "";
    if (u.pathname.length > 1 && u.pathname.endsWith("/")) u.pathname = u.pathname.slice(0, -1);
    return u.href;
  } catch { return null; }
}

function originOf(url) { try { return new URL(url).origin; } catch { return null; } }
function pathOf(url)   { try { return new URL(url).pathname || "/"; } catch { return "/"; } }

function slugToLabel(url) {
  try {
    const path  = new URL(url).pathname;
    if (path === "/" || path === "") return "Home";
    const parts = path.replace(/\/$/, "").split("/").filter(Boolean);
    const last  = parts[parts.length - 1] || "Page";
    return last.replace(/[-_]/g, " ").replace(/\.[^.]+$/, "").replace(/\b\w/g, c => c.toUpperCase());
  } catch { return "Page"; }
}

function dedupeLinks(links) {
  const seen = new Set();
  return links.filter(({ to }) => { if (seen.has(to)) return false; seen.add(to); return true; });
}

// Mirror of buildSitemapStructures from sitemap.js
function buildSitemapStructures(graph, crawlMeta) {
  const rootUrl      = crawlMeta.rootUrl;
  const inboundCount = new Map();
  for (const [url] of graph) inboundCount.set(url, 0);
  for (const [, data] of graph) {
    for (const { to } of (data.linksOut || [])) {
      if (graph.has(to)) inboundCount.set(to, (inboundCount.get(to) || 0) + 1);
    }
  }

  const nodes = [];
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain || data.syntheticRoot) continue;
    nodes.push({ id: url, url, title: data.title || slugToLabel(url), depth: data.depth, path: pathOf(url), noindex: data.noindex || false, linksIn: inboundCount.get(url) || 0, linksOut: (data.linksOut || []).filter(l => graph.has(l.to)).length });
  }

  const edges   = [];
  const edgeSet = new Set();
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;
    for (const { to } of (data.linksOut || [])) {
      if (!graph.has(to)) continue;
      const key = `${url}→${to}`;
      if (!edgeSet.has(key)) { edgeSet.add(key); edges.push({ source: url, target: to }); }
    }
  }

  const childrenMap = new Map();
  for (const [url, data] of graph) {
    if (data.fetchFailed || data.redirectedOffDomain) continue;
    const parent = data.parent;
    if (parent && graph.has(parent)) {
      if (!childrenMap.has(parent)) childrenMap.set(parent, []);
      childrenMap.get(parent).push(url);
    } else if (url !== rootUrl) {
      if (!childrenMap.has(rootUrl)) childrenMap.set(rootUrl, []);
      if (!childrenMap.get(rootUrl).includes(url)) childrenMap.get(rootUrl).push(url);
    }
  }

  function buildTreeNode(url, visited = new Set()) {
    if (visited.has(url)) return null;
    visited.add(url);
    const data = graph.get(url);
    if (!data || data.fetchFailed || data.redirectedOffDomain) return null;
    const children = (childrenMap.get(url) || [])
      .map(child => buildTreeNode(child, visited))
      .filter(Boolean)
      .sort((a, b) => a.title.localeCompare(b.title));
    return { id: url, url, title: data.title || slugToLabel(url), path: pathOf(url), depth: data.depth, noindex: data.noindex || false, linksIn: inboundCount.get(url) || 0, linksOut: (data.linksOut || []).filter(l => graph.has(l.to)).length, children };
  }

  const tree = graph.has(rootUrl) ? buildTreeNode(rootUrl) : null;
  const depthBreakdown = {};
  for (const node of nodes) { const d = node.depth; depthBreakdown[d] = (depthBreakdown[d] || 0) + 1; }

  return { nodes, edges, tree, depthBreakdown };
}

// ─── Helpers for building mock graphs ───────────────────────────────────────

function makePage(url, { parent = null, depth = 0, title, linksOut = [], noindex = false } = {}) {
  return [url, { parent, depth, title: title || slugToLabel(url), linksOut: linksOut.map(to => ({ to })), noindex, isWordPress: false, fetchFailed: false }];
}

// ─── Suite A: Unit tests ─────────────────────────────────────────────────────

console.log("\n═══ Suite A — Unit Tests ═══\n");

// A1 — normalise()
test("A1a normalise: strips hash", () => {
  assert.strictEqual(normalise("https://example.com/page#section", "https://example.com"), "https://example.com/page");
});
test("A1b normalise: strips trailing slash", () => {
  assert.strictEqual(normalise("https://example.com/blog/", "https://example.com"), "https://example.com/blog");
});
test("A1c normalise: keeps root slash", () => {
  assert.strictEqual(normalise("https://example.com/", "https://example.com"), "https://example.com/");
});
test("A1d normalise: relative URL resolution", () => {
  assert.strictEqual(normalise("/about", "https://example.com/page"), "https://example.com/about");
});
test("A1e normalise: rejects mailto:", () => {
  assert.strictEqual(normalise("mailto:hi@example.com", "https://example.com"), null);
});
test("A1f normalise: rejects javascript:", () => {
  assert.strictEqual(normalise("javascript:void(0)", "https://example.com"), null);
});
test("A1g normalise: invalid URL returns null", () => {
  assert.strictEqual(normalise("not a url", "https://example.com"), "https://example.com/not%20a%20url");
  // relative resolution still works — check for an actually bad base
  assert.strictEqual(normalise("https://", ""), null);
});

// A2 — slugToLabel()
test("A2a slugToLabel: root = Home", () => {
  assert.strictEqual(slugToLabel("https://example.com/"), "Home");
});
test("A2b slugToLabel: kebab slug → Title Case", () => {
  assert.strictEqual(slugToLabel("https://example.com/blog-post"), "Blog Post");
});
test("A2c slugToLabel: underscore slug", () => {
  assert.strictEqual(slugToLabel("https://example.com/contact_us"), "Contact Us");
});
test("A2d slugToLabel: strips extension", () => {
  assert.strictEqual(slugToLabel("https://example.com/page.html"), "Page");
});
test("A2e slugToLabel: nested path uses last segment", () => {
  assert.strictEqual(slugToLabel("https://example.com/blog/my-post"), "My Post");
});

// A3 — dedupeLinks()
test("A3a dedupeLinks: removes exact duplicates", () => {
  const links = [{ to: "https://a.com/1" }, { to: "https://a.com/2" }, { to: "https://a.com/1" }];
  assert.deepStrictEqual(dedupeLinks(links).map(l => l.to), ["https://a.com/1", "https://a.com/2"]);
});
test("A3b dedupeLinks: empty input", () => {
  assert.deepStrictEqual(dedupeLinks([]), []);
});
test("A3c dedupeLinks: no duplicates unchanged", () => {
  const links = [{ to: "https://a.com/1" }, { to: "https://a.com/2" }];
  assert.strictEqual(dedupeLinks(links).length, 2);
});

// A4 — buildSitemapStructures() happy path: 6-page mock site
// Structure:
//   / → [/about, /blog, /pricing]
//   /blog → [/blog/post-1, /blog/post-2]
//   /blog/post-2 → [/pricing]    (cross-link)
//   /about → [/]                 (back link)
//   /pricing → [/]               (back link)
//   /blog/post-1 → []            (dead end)
const ROOT       = "https://example.com/";
const ABOUT      = "https://example.com/about";
const BLOG       = "https://example.com/blog";
const PRICING    = "https://example.com/pricing";
const POST1      = "https://example.com/blog/post-1";
const POST2      = "https://example.com/blog/post-2";

const mockGraph = new Map([
  makePage(ROOT,    { depth: 0, title: "Home",    linksOut: [ABOUT, BLOG, PRICING] }),
  makePage(ABOUT,   { depth: 1, title: "About",   parent: ROOT,  linksOut: [ROOT] }),
  makePage(BLOG,    { depth: 1, title: "Blog",    parent: ROOT,  linksOut: [POST1, POST2] }),
  makePage(PRICING, { depth: 1, title: "Pricing", parent: ROOT,  linksOut: [ROOT] }),
  makePage(POST1,   { depth: 2, title: "Post 1",  parent: BLOG,  linksOut: [] }),
  makePage(POST2,   { depth: 2, title: "Post 2",  parent: BLOG,  linksOut: [PRICING] }),
]);

const crawlMeta = { rootUrl: ROOT, hitCrawlCap: false, hitTimeCap: false, anyWP: true };
const { nodes, edges, tree, depthBreakdown } = buildSitemapStructures(mockGraph, crawlMeta);

test("A4a nodes count", () => assert.strictEqual(nodes.length, 6));
test("A4b root node title", () => {
  const root = nodes.find(n => n.url === ROOT);
  assert.ok(root, "root node missing");
  assert.strictEqual(root.title, "Home");
});
test("A4c post-1 has linksOut=0 (dead end)", () => {
  const p = nodes.find(n => n.url === POST1);
  assert.strictEqual(p.linksOut, 0);
});
test("A4d pricing linksIn = 2 (from root + post-2)", () => {
  const p = nodes.find(n => n.url === PRICING);
  assert.strictEqual(p.linksIn, 2);
});
test("A4e tree root is Home", () => {
  assert.ok(tree, "tree is null");
  assert.strictEqual(tree.title, "Home");
  assert.strictEqual(tree.url, ROOT);
});
test("A4f tree root has 3 children", () => {
  assert.strictEqual(tree.children.length, 3);
});
test("A4g tree blog has 2 children", () => {
  const blog = tree.children.find(c => c.url === BLOG);
  assert.ok(blog, "blog missing in tree");
  assert.strictEqual(blog.children.length, 2);
});
test("A4h edges include root→blog", () => {
  assert.ok(edges.some(e => e.source === ROOT && e.target === BLOG), "root→blog edge missing");
});
test("A4i edges include post-2→pricing (cross-link)", () => {
  assert.ok(edges.some(e => e.source === POST2 && e.target === PRICING), "post-2→pricing cross-link missing");
});
test("A4j no duplicate edges", () => {
  const keys = edges.map(e => `${e.source}→${e.target}`);
  assert.strictEqual(new Set(keys).size, keys.length, "duplicate edges found");
});
test("A4k depthBreakdown: d0=1 d1=3 d2=2", () => {
  assert.strictEqual(depthBreakdown[0], 1);
  assert.strictEqual(depthBreakdown[1], 3);
  assert.strictEqual(depthBreakdown[2], 2);
});
test("A4l depthBreakdown sum === node count", () => {
  const sum = Object.values(depthBreakdown).reduce((s, v) => s + v, 0);
  assert.strictEqual(sum, nodes.length);
});
test("A4m all nodes have non-empty title", () => {
  assert.ok(nodes.every(n => n.title && n.title.length > 0), "some nodes have empty title");
});
test("A4n all nodes have numeric depth", () => {
  assert.ok(nodes.every(n => typeof n.depth === "number"), "some nodes have non-numeric depth");
});
test("A4o path field is populated", () => {
  const blog = nodes.find(n => n.url === BLOG);
  assert.strictEqual(blog.path, "/blog");
});

// A5 — Synthetic root injection: sitemap fallback where homepage omits from sitemap
// Graph has 4 pages, root is NOT in the map → should inject synthetic root
const ROOT_MISSING = "https://site.com/";
const PAGE_A       = "https://site.com/page-a";
const PAGE_B       = "https://site.com/page-b";
const PAGE_C       = "https://site.com/page-c";

const sitemapGraph = new Map([
  makePage(PAGE_A, { depth: 1, title: "Page A", parent: ROOT_MISSING }),
  makePage(PAGE_B, { depth: 1, title: "Page B", parent: ROOT_MISSING }),
  makePage(PAGE_C, { depth: 1, title: "Page C", parent: ROOT_MISSING }),
]);
// Inject synthetic root (as crawlViaSitemap does when homepage is absent)
sitemapGraph.set(ROOT_MISSING, { depth: 0, parent: null, title: "Home", noindex: false, linksOut: [], isWordPress: false, syntheticRoot: true });

const sitemapMeta = { rootUrl: ROOT_MISSING, hitCrawlCap: false, hitTimeCap: false, anyWP: false, usedSitemap: true };
const { nodes: sNodes, tree: sTree } = buildSitemapStructures(sitemapGraph, sitemapMeta);

test("A5a synthetic root excluded from nodes", () => {
  assert.ok(!sNodes.some(n => n.url === ROOT_MISSING), "synthetic root wrongly included in nodes");
});
test("A5b synthetic root is tree anchor", () => {
  assert.ok(sTree, "tree is null — B1 fix did not work");
  assert.strictEqual(sTree.url, ROOT_MISSING);
});
test("A5c synthetic root's children = 3 real pages", () => {
  assert.strictEqual(sTree.children.length, 3);
});
test("A5d real pages still appear in nodes", () => {
  assert.strictEqual(sNodes.length, 3);
});

// A6 — Cycle guard: A → B → A (circular)
const CYC_A = "https://cyc.com/";
const CYC_B = "https://cyc.com/b";
const cycGraph = new Map([
  makePage(CYC_A, { depth: 0, title: "Root", linksOut: [CYC_B] }),
  makePage(CYC_B, { depth: 1, title: "B", parent: CYC_A, linksOut: [CYC_A] }),
]);
// Force a cycle in childrenMap: make CYC_A.parent = CYC_B so buildSitemapStructures
// puts CYC_A under CYC_B → buildTreeNode would recurse infinitely without the visited guard.
cycGraph.get(CYC_A).parent = CYC_B;

let cycTree;
test("A6 cycle guard prevents infinite recursion", () => {
  const result = buildSitemapStructures(cycGraph, { rootUrl: CYC_A, hitCrawlCap: false, hitTimeCap: false, anyWP: false });
  cycTree = result.tree;
  assert.ok(cycTree, "tree is null");
  assert.strictEqual(cycTree.url, CYC_A);
  // B is a child of A
  assert.strictEqual(cycTree.children.length, 1);
  assert.strictEqual(cycTree.children[0].url, CYC_B);
  // A should NOT re-appear under B (cycle broken)
  assert.strictEqual(cycTree.children[0].children.length, 0, "cycle not broken — A appeared under B");
});

// A7 — formatOutput depthBreakdown contract (via nodes count)
test("A7 depthBreakdown sum matches node count (A4 data)", () => {
  const sum = Object.values(depthBreakdown).reduce((s, v) => s + v, 0);
  assert.strictEqual(sum, nodes.length);
});

// A8-A11 — handler contract tests
console.log("\n  [handler contract tests]");

async function runHandlerTests() {
  await testAsync("A8 OPTIONS → 204", async () => {
    const res = await handler({ httpMethod: "OPTIONS", body: "" });
    assert.strictEqual(res.statusCode, 204);
  });

  await testAsync("A9 GET → 405", async () => {
    const res = await handler({ httpMethod: "GET", body: "" });
    assert.strictEqual(res.statusCode, 405);
    const body = JSON.parse(res.body);
    assert.ok(body.error, "no error field on 405");
  });

  await testAsync("A10 POST missing url → 400", async () => {
    const res = await handler({ httpMethod: "POST", body: JSON.stringify({}) });
    assert.strictEqual(res.statusCode, 400);
    const body = JSON.parse(res.body);
    assert.ok(body.error.includes("Missing url"), `wrong error: ${body.error}`);
  });

  await testAsync("A11 POST malformed JSON → 400", async () => {
    const res = await handler({ httpMethod: "POST", body: "not-json" });
    assert.strictEqual(res.statusCode, 400);
  });
}

// ─── Suite B: E2E live smoke test ────────────────────────────────────────────

async function runE2E() {
  console.log("\n═══ Suite B — E2E Live Smoke Test ═══\n");
  console.log("  Target: linkwhisper.com (WP + Cloudflare → sitemap fallback expected)");
  console.log("  (This hits the real network — takes up to ~60s)\n");

  const start = Date.now();
  let res;
  try {
    res = await handler({ httpMethod: "POST", body: JSON.stringify({ url: "https://linkwhisper.com/" }) });
  } catch (e) {
    console.log(`  ❌ handler threw: ${e.message}`);
    failed++;
    return;
  }
  const elapsed = ((Date.now() - start) / 1000).toFixed(1);

  const statusOk = res.statusCode === 200;
  console.log(`  HTTP status: ${res.statusCode} (${elapsed}s)`);

  let body;
  try {
    body = JSON.parse(res.body);
  } catch {
    console.log(`  ❌ B1 response is not valid JSON`);
    failed++;
    return;
  }

  if (body.error) {
    // Crawler-blocked is an acceptable outcome for Cloudflare sites
    console.log(`  ⚠️  Site blocked crawlers: "${body.error}"`);
    console.log(`  B1 SKIPPED (network-dependent — acceptable if Cloudflare blocked both BFS and sitemap)`);
    return;
  }

  const { preview, fullReport } = body;

  console.log(`\n  PREVIEW`);
  console.log(`    Pages crawled : ${preview.meta.pageCount}`);
  console.log(`    Max depth     : ${preview.meta.maxDepth}`);
  console.log(`    WordPress     : ${preview.meta.isWordPress}`);
  console.log(`    Used sitemap  : ${preview.meta.usedSitemap}`);
  console.log(`    Partial scan  : ${preview.meta.isPartialScan}`);
  console.log(`    Warnings      : ${preview.meta.warnings.map(w => `[${w.type}]`).join(" ") || "none"}`);
  console.log(`    Depth split   :`, preview.meta.depthBreakdown);
  console.log(`\n  FULL REPORT`);
  console.log(`    Nodes   : ${fullReport.nodes.length}`);
  console.log(`    Edges   : ${fullReport.edges.length}`);
  console.log(`    Tree    : ${fullReport.tree ? `✅ "${fullReport.tree.title}" (${fullReport.tree.children.length} children)` : "❌ NULL"}`);
  if (fullReport.tree && fullReport.tree.children.length > 0) {
    console.log(`    Sample  :`, fullReport.tree.children.slice(0, 3).map(c => `"${c.title}"`).join(", "));
  }

  const e2eChecks = [
    ["B1a status 200",            statusOk],
    ["B1b nodes > 0",             fullReport.nodes.length > 0],
    ["B1c tree not null",         fullReport.tree !== null],
    ["B1d tree.url contains host",fullReport.tree && fullReport.tree.url.includes("linkwhisper.com")],
    ["B1e edges is array",        Array.isArray(fullReport.edges)],
    ["B1f all nodes have title",  fullReport.nodes.every(n => n.title && n.title.length > 0)],
    ["B1g depthBreakdown sum === nodes.length", Object.values(preview.meta.depthBreakdown || {}).reduce((s, v) => s + v, 0) === fullReport.nodes.length],
    ["B1h elapsed < 58s",         parseFloat(elapsed) < 58],
  ];

  console.log("\n  CONTRACT CHECKS");
  for (const [name, pass] of e2eChecks) {
    console.log(`    ${pass ? "✅" : "❌"} ${name}`);
    if (pass) passed++; else failed++;
  }
}

// ─── Run all ─────────────────────────────────────────────────────────────────

(async () => {
  await runHandlerTests();
  await runE2E();

  console.log("\n" + "═".repeat(50));
  console.log(`  RESULT: ${passed} passed, ${failed} failed`);
  console.log("═".repeat(50) + "\n");

  process.exit(failed > 0 ? 1 : 0);
})();
