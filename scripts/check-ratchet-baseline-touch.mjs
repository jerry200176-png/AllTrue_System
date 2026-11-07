#!/usr/bin/env node
// W4 (#4953): feature PRs must not rewrite ratchet baselines to lock in gains; those rewrites conflict
// across parallel PRs. Only chore/* branches (the weekly baseline PR) may touch them, except a change
// whose counts went UP (a reviewed regression).
// Usage: node scripts/check-ratchet-baseline-touch.mjs --base <sha> --branch <name>
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

// Only baselines that have an update command run by .github/workflows/ratchet-baseline-weekly.yml.
export const RATCHET_BASELINES = ['docs/design-style-baseline.json', 'scripts/controller-db-baseline.json'];

// Sum of every numeric leaf, ignoring "totals" (a derived duplicate of "files").
export function sumCounts(value, key = '') {
  if (key === 'totals') return 0;
  if (typeof value === 'number') return value;
  if (value && typeof value === 'object') {
    return Object.entries(value).reduce((s, [k, v]) => s + sumCounts(v, k), 0);
  }
  return 0;
}

// A pure file move keeps every per-file count and only changes the keys: allowed (CI needs the entry to follow).
const counts = (json) => JSON.stringify(Object.values(json.files ?? {}).map((v) => JSON.stringify(v)).sort());
export const isRelocation = (before, after) => before.files && after.files && counts(before) === counts(after)
  && JSON.stringify(Object.keys(before.files).sort()) !== JSON.stringify(Object.keys(after.files).sort());

// changes: [{ path, before: string|null, after: string|null }]. Returns violation strings.
export function findViolations(changes, branch) {
  if (branch.startsWith('chore/')) return [];
  const out = [];
  for (const { path, before, after } of changes) {
    if (!RATCHET_BASELINES.includes(path) || before === after) continue;
    const a = before == null ? null : JSON.parse(before);
    const b = after == null ? null : JSON.parse(after);
    if (a && b && isRelocation(a, b)) continue;
    const was = a ? sumCounts(a) : 0;
    const now = b ? sumCounts(b) : 0;
    if (now <= was) {
      out.push(`${path}: counts did not go up (${was} -> ${now}); lock in gains via the weekly baseline PR`);
    }
  }
  return out;
}

const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 << 20 });
const show = (rev, path) => {
  try { return git('show', `${rev}:${path}`); } catch { return null; }
};

function main() {
  const arg = (n) => process.argv[process.argv.indexOf(n) + 1];
  const base = arg('--base');
  const branch = arg('--branch') || '';
  if (!base) { console.error('--base required'); process.exit(2); }
  // Fail closed: any git/JSON error throws and exits non-zero.
  const changes = RATCHET_BASELINES.map((path) => ({ path, before: show(base, path), after: show('HEAD', path) }));
  const violations = findViolations(changes, branch);
  if (violations.length) {
    console.error(`::error::Ratchet baseline touched by a non-chore/ branch (${branch})`);
    for (const v of violations) console.error(`  ${v}`);
    process.exit(1);
  }
  console.log('ratchet baselines: no gain-only rewrite on this branch');
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) main();
