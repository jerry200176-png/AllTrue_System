#!/usr/bin/env node
// ADR-003 DB:: ratchet. Fails (exit 1) if any protected controller has MORE
// `DB::` call sites than its baseline. `--write` rewrites the baseline (use only
// when intentionally lowering it via a decomposition PR). Advisory in CI first.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const baselinePath = join(root, 'scripts', 'controller-db-baseline.json');
const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));
const write = process.argv.includes('--write');

const countDb = (rel) => {
  let text;
  try { text = readFileSync(join(root, rel), 'utf8'); }
  catch { return null; } // file gone (e.g. fully decomposed) → treat as 0 below
  return (text.match(/DB::/g) || []).length;
};

let failed = false;
const updated = { ...baseline };
for (const [file, limit] of Object.entries(baseline)) {
  if (file.startsWith('_')) continue;
  const actual = countDb(file) ?? 0;
  const mark = actual > limit ? '❌' : actual < limit ? '⬇️' : '✅';
  console.log(`${mark} ${file}: ${actual} (baseline ${limit})`);
  if (actual > limit) failed = true;
  if (actual < limit) updated[file] = actual; // ratchet only tightens
}

if (write) {
  writeFileSync(baselinePath, JSON.stringify(updated, null, 2) + '\n');
  console.log('baseline rewritten (ratcheted down where applicable)');
  process.exit(0);
}

if (failed) {
  console.error('\nADR-003: new DB:: usage in a protected controller exceeds baseline.');
  console.error('Move new persistence logic into a domain service (see docs/ADR_003_layering_and_controller_db_ban.md).');
  process.exit(1);
}
console.log('\nADR-003 ratchet OK — no protected controller exceeded its DB:: baseline.');
