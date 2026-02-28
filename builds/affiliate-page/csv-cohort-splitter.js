#!/usr/bin/env node
/**
 * Freemius Affiliate CSV Cohort Splitter
 * 
 * Splits the WP Affiliate / Freemius affiliate export into 3 cohorts
 * by signup date, ready to load directly into separate Instantly sequences.
 * 
 * Usage:
 *   node csv-cohort-splitter.js affiliates.csv
 * 
 * Output files (same directory as input):
 *   affiliates-recent.csv      (0–90 days — recent dormants)
 *   affiliates-medium.csv      (91–365 days — medium dormants)
 *   affiliates-longdorm.csv    (365+ days — long-dormant, 1+ year)
 * 
 * Instantly sequence mapping:
 *   recent + medium  →  Sequence 1B (product news opener)
 *   longdorm         →  Sequence 1A — 1+ year sub-variant (social proof → accountability)
 *   Segment A only   →  Sequence 1A — 0-90 day sub-variant (accountability opener)
 * 
 * Freemius CSV expected columns (adjust SIGNUP_DATE_COLUMN if different):
 *   name, email, created_at (or signup_date), clicks, earnings, ...
 */

const fs = require('fs');
const path = require('path');

// ── Config ──────────────────────────────────────────────────────────────────

const SIGNUP_DATE_COLUMN = 'created_at'; // adjust if Freemius uses different header
const CUTOFF_RECENT = 90;   // days
const CUTOFF_MEDIUM = 365;  // days

// ── CSV Parser (zero dependencies) ──────────────────────────────────────────

function parseCSV(text) {
  const lines = text.trim().split('\n');
  const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g, ''));
  const rows = lines.slice(1).map(line => {
    const cols = line.match(/(".*?"|[^,]+|(?<=,)(?=,)|(?<=,)$|^(?=,))/g) || [];
    const row = {};
    headers.forEach((h, i) => {
      row[h] = (cols[i] || '').trim().replace(/^"|"$/g, '');
    });
    return row;
  });
  return { headers, rows };
}

function toCSV(headers, rows) {
  const escape = v => (v.includes(',') || v.includes('"') || v.includes('\n'))
    ? `"${v.replace(/"/g, '""')}"` : v;
  return [
    headers.join(','),
    ...rows.map(r => headers.map(h => escape(r[h] || '')).join(','))
  ].join('\n');
}

// ── Main ────────────────────────────────────────────────────────────────────

const inputFile = process.argv[2];
if (!inputFile) {
  console.error('Usage: node csv-cohort-splitter.js affiliates.csv');
  process.exit(1);
}

const inputPath = path.resolve(inputFile);
if (!fs.existsSync(inputPath)) {
  console.error(`File not found: ${inputPath}`);
  process.exit(1);
}

const text = fs.readFileSync(inputPath, 'utf-8');
const { headers, rows } = parseCSV(text);

if (!headers.includes(SIGNUP_DATE_COLUMN)) {
  console.warn(`Warning: column "${SIGNUP_DATE_COLUMN}" not found in CSV.`);
  console.warn(`Available columns: ${headers.join(', ')}`);
  console.warn(`Edit SIGNUP_DATE_COLUMN at top of this script to match your CSV.`);
  process.exit(1);
}

const NOW = Date.now();

const buckets = { recent: [], medium: [], longdorm: [] };

rows.forEach(row => {
  const signupDate = new Date(row[SIGNUP_DATE_COLUMN]);
  if (isNaN(signupDate)) {
    console.warn(`Skipping row with invalid date: ${row[SIGNUP_DATE_COLUMN]} (${row.email})`);
    return;
  }
  const daysSince = (NOW - signupDate.getTime()) / 86_400_000;

  if (daysSince <= CUTOFF_RECENT) {
    buckets.recent.push(row);
  } else if (daysSince <= CUTOFF_MEDIUM) {
    buckets.medium.push(row);
  } else {
    buckets.longdorm.push(row);
  }
});

const baseName = path.basename(inputFile, path.extname(inputFile));
const dir = path.dirname(inputPath);

const outputMap = {
  recent:   path.join(dir, `${baseName}-recent.csv`),
  medium:   path.join(dir, `${baseName}-medium.csv`),
  longdorm: path.join(dir, `${baseName}-longdorm.csv`),
};

Object.entries(outputMap).forEach(([bucket, outPath]) => {
  const count = buckets[bucket].length;
  if (count > 0) {
    fs.writeFileSync(outPath, toCSV(headers, buckets[bucket]), 'utf-8');
    console.log(`✅ ${bucket.padEnd(8)} → ${path.basename(outPath)} (${count} affiliates)`);
  } else {
    console.log(`⚪ ${bucket.padEnd(8)} → empty (0 affiliates)`);
  }
});

console.log(`\nTotal: ${rows.length} affiliates split into ${
  Object.values(buckets).filter(b => b.length > 0).length} cohort files.\n`);

console.log(`Instantly sequence mapping:`);
console.log(`  recent   (0-90d)  → Sequence 1A (accountability opener)`);
console.log(`  medium   (91-365d)→ Sequence 1B (product news opener)`);
console.log(`  longdorm (1yr+)   → Sequence 1A — 1+ year variant (social proof first)`);
console.log(`\nNote: Also filter by Segment A (0 clicks, 0 earnings) vs B/C (has activity)`);
console.log(`      before loading — the Freemius CSV includes clicks/earnings columns.`);
