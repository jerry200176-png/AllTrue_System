#!/usr/bin/env node
// Advisory UI "AI-feel" gate. Usage:
//   node scripts/design/impeccable-baseline.mjs check   (default; exit 1 on NEW findings)
//   node scripts/design/impeccable-baseline.mjs update  (rewrite the baseline)
// Reads `impeccable detect --json frontend/src` output from stdin or runs it.
// Counts per "file|rule" (not line numbers) so unrelated edits do not churn it.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const IMPECCABLE = 'impeccable@4.6.0'; // keep in sync with .github/workflows/ci.yml
const baselinePath = path.join(import.meta.dirname, 'impeccable-baseline.json');
const mode = process.argv[2] ?? 'check';
const root = process.cwd();

let raw;
try {
  raw = execFileSync('npx', ['-y', IMPECCABLE, 'detect', '--json', 'frontend/src'], {
    encoding: 'utf8', maxBuffer: 64 << 20, stdio: ['ignore', 'pipe', 'ignore'],
  });
} catch (e) { raw = e.stdout; } // exit 2 = findings present
const counts = {};
for (const f of JSON.parse(raw)) {
  const key = `${path.relative(root, f.file)}|${f.antipattern}`;
  counts[key] = (counts[key] ?? 0) + 1;
}

if (mode === 'update') {
  const sorted = Object.fromEntries(Object.entries(counts).sort());
  fs.writeFileSync(baselinePath, JSON.stringify(sorted, null, 2) + '\n');
  console.log(`baseline written: ${Object.values(counts).reduce((a, b) => a + b, 0)} findings`);
  process.exit(0);
}

const base = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
let fresh = 0;
for (const [key, n] of Object.entries(counts)) {
  const extra = n - (base[key] ?? 0);
  if (extra > 0) {
    fresh += extra;
    const [file, rule] = key.split('|');
    console.log(`::error file=${file}::impeccable ${rule}: ${extra} new finding(s) vs baseline`);
  }
}
const total = Object.values(counts).reduce((a, b) => a + b, 0);
console.log(`impeccable: ${total} findings, ${fresh} new vs baseline`);
process.exit(fresh ? 1 : 0);
