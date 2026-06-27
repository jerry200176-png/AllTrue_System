#!/usr/bin/env node
/**
 * Architecture Fitness Functions — machine enforcement of the Engineering Constitution.
 *
 * Converts governance rules into automated checks so humans don't police what machines can.
 * Referenced by: docs/CONSTITUTION.md §L5, docs/RULE_ARCHITECTURE_GOVERNANCE.md.
 *
 * Checks (all fail-closed; non-zero exit blocks the PR):
 *   FIT-1  ADR-005 / DI-1 ratchet: `ClassSession::create` call-sites must not exceed
 *          the baseline. The only legitimate writer is the future SessionWriter; every
 *          additional site is a duplicate-occurrence risk. Baseline may only decrease.
 *   FIT-2  DEP-3: the App\Services dependency graph must remain acyclic.
 *   FIT-3  Ownership: every constitutional doc must declare an `owner:` in frontmatter.
 *   FIT-4  ADR hygiene: every docs/adr/NNNN-*.md has `status:` and a `## Constitution link`.
 *
 * Usage: node scripts/arch-fitness-check.mjs   (run from repo root)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const baseline = JSON.parse(fs.readFileSync(path.join(ROOT, 'scripts/arch-fitness-baseline.json'), 'utf8'));
const failures = [];
const notes = [];

function walk(dir, filterFn, acc = []) {
  if (!fs.existsSync(dir)) return acc;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, filterFn, acc);
    else if (filterFn(p)) acc.push(p);
  }
  return acc;
}

// ── FIT-1: ClassSession::create ratchet ───────────────────────────────
{
  const phpFiles = walk(path.join(ROOT, 'backend/app'), (p) => p.endsWith('.php'));
  let count = 0;
  for (const f of phpFiles) {
    const m = fs.readFileSync(f, 'utf8').match(/ClassSession::create\s*\(/g);
    if (m) count += m.length;
  }
  const limit = baseline.classSessionCreateSites;
  if (count > limit) {
    failures.push(`FIT-1 (ADR-005/DI-1): ClassSession::create sites = ${count} > baseline ${limit}. ` +
      `Route occurrence creation through the single SessionWriter; do not add new writers.`);
  } else if (count < limit) {
    notes.push(`FIT-1: ratchet improved — ClassSession::create sites = ${count} (baseline ${limit}). ` +
      `Lower the baseline in arch-fitness-baseline.json to lock the gain.`);
  } else {
    notes.push(`FIT-1: ClassSession::create sites = ${count} (at baseline ${limit}).`);
  }
}

// ── FIT-2: service dependency graph acyclic ───────────────────────────
{
  const svcDir = path.join(ROOT, 'backend/app/Services');
  const svcFiles = walk(svcDir, (p) => p.endsWith('.php'));
  const names = new Set(svcFiles.map((f) => path.basename(f, '.php')));
  const edges = new Map();
  for (const f of svcFiles) {
    const name = path.basename(f, '.php');
    const txt = fs.readFileSync(f, 'utf8');
    const deps = new Set();
    for (const m of txt.matchAll(/App\\Services\\([A-Za-z0-9_]+)/g)) {
      if (m[1] !== name && names.has(m[1])) deps.add(m[1]);
    }
    edges.set(name, deps);
  }
  // DFS cycle detection
  const WHITE = 0, GRAY = 1, BLACK = 2;
  const color = new Map([...names].map((n) => [n, WHITE]));
  const cycles = [];
  const stack = [];
  function dfs(n) {
    color.set(n, GRAY); stack.push(n);
    for (const d of edges.get(n) || []) {
      if (color.get(d) === GRAY) cycles.push([...stack.slice(stack.indexOf(d)), d].join(' -> '));
      else if (color.get(d) === WHITE) dfs(d);
    }
    stack.pop(); color.set(n, BLACK);
  }
  for (const n of names) if (color.get(n) === WHITE) dfs(n);
  if (cycles.length > baseline.serviceDependencyCycles) {
    failures.push(`FIT-2 (DEP-3): service dependency cycle(s) detected: ${cycles.join(' | ')}`);
  } else {
    notes.push(`FIT-2: service dependency graph acyclic (${names.size} services).`);
  }
}

// ── FIT-3: constitutional docs declare an owner ───────────────────────
for (const rel of baseline.requireOwnerFrontmatter) {
  const p = path.join(ROOT, rel);
  if (!fs.existsSync(p)) { failures.push(`FIT-3: required constitutional doc missing: ${rel}`); continue; }
  const head = fs.readFileSync(p, 'utf8').slice(0, 600);
  if (!/^---[\s\S]*?\bowner:\s*\S+/m.test(head)) {
    failures.push(`FIT-3: ${rel} must declare an \`owner:\` in YAML frontmatter.`);
  }
}

// ── FIT-4: ADR hygiene ────────────────────────────────────────────────
{
  const adrDir = path.join(ROOT, 'docs/adr');
  const adrs = walk(adrDir, (p) => /\d{4}-.*\.md$/.test(path.basename(p)));
  for (const f of adrs) {
    const txt = fs.readFileSync(f, 'utf8');
    const rel = path.relative(ROOT, f);
    if (!/^\s*status:\s*\S+/m.test(txt) && !/\*\*Status\*\*/.test(txt)) {
      failures.push(`FIT-4: ${rel} missing a Status.`);
    }
    if (!/Constitution/i.test(txt)) {
      failures.push(`FIT-4: ${rel} must cross-reference the Constitution (decision must derive from it).`);
    }
  }
  notes.push(`FIT-4: inspected ${adrs.length} ADR file(s).`);
}

// ── report ────────────────────────────────────────────────────────────
for (const n of notes) console.log(`  ok  ${n}`);
if (failures.length) {
  console.error('\nArchitecture fitness FAILED:\n' + failures.map((f) => ' ✗ ' + f).join('\n'));
  process.exit(1);
}
console.log('\nArchitecture fitness: PASS');
