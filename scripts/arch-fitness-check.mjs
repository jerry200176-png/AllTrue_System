#!/usr/bin/env node
/**
 * Architecture Fitness Functions — machine enforcement of the Engineering Constitution.
 * Humans do not police what a machine can. Fail-closed: any violation exits non-zero and blocks the PR.
 * Referenced by: docs/CONSTITUTION.md §L5, docs/RULE_ARCHITECTURE_GOVERNANCE.md, docs/OWNERSHIP_REGISTRY.md, docs/adr/.
 *
 * FIT-1  DI-1/ADR-005  ClassSession::create ratchet (single SessionWriter)
 * FIT-2  DEP-3         App\Services graph acyclic
 * FIT-3  Art. V        constitutional docs declare an owner
 * FIT-4  L2            ADR hygiene (status + Constitution link)
 * FIT-5  NM-7/ADR-008  status-literal duplication ratchet
 * FIT-6  DI-2          StudentClass.RemainingSessions direct-write ratchet (units owned by Billing ledger)
 * FIT-7  DI-3          Invoice.PaidAmount direct-write ratchet (paid owned by Billing)
 * FIT-8  DI-4          effectiveInstructorUserId call-site ratchet (instructor stored on Occurrence)
 * FIT-9  DI-6          Teacher:: write lock (identity is Party)
 * FIT-10 DEP-1         every Controller/Service mapped to a context (no undocumented context) + forbidden edges
 * FIT-11 Art. V        Ownership Registry completeness (every fact row has owner + single|ADR)
 * FIT-12 invariants    every DI-n has a present, resolvable automated enforcement
 * FIT-13 L3            required SOP docs present
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const J = (p) => JSON.parse(fs.readFileSync(path.join(ROOT, p), 'utf8'));
const baseline = J('scripts/arch-fitness-baseline.json');
const contexts = J('scripts/arch-contexts.json');
const invariants = J('scripts/arch-invariants.json');
const failures = [];
const ran = new Set();
const note = (s) => console.log(`  ok  ${s}`);

function walk(dir, filt, acc = []) {
  if (!fs.existsSync(dir)) return acc;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, filt, acc);
    else if (filt(p)) acc.push(p);
  }
  return acc;
}
const appPhp = walk(path.join(ROOT, 'backend/app'), (p) => p.endsWith('.php'));
const read = (f) => fs.readFileSync(f, 'utf8');

function ratchet(id, label, current, key) {
  ran.add(id);
  const limit = baseline[key];
  if (current > limit) failures.push(`${id} (${label}): ${current} > baseline ${limit}. Ratchet only decreases; raising requires an ADR.`);
  else note(`${id}: ${current} (baseline ${limit})${current < limit ? ' — improved; lower the baseline to lock it.' : ''}`);
}

// FIT-1
{ let n = 0; for (const f of appPhp) n += (read(f).match(/ClassSession::create\s*\(/g) || []).length; ratchet('FIT-1', 'DI-1 occurrence writer', n, 'classSessionCreateSites'); }

// FIT-2 service graph acyclic
let svcGraph = { svcFiles: [], edges: new Map(), ctxRule: null };
{
  ran.add('FIT-2');
  const svcFiles = walk(path.join(ROOT, 'backend/app/Services'), (p) => p.endsWith('.php'));
  const names = new Set(svcFiles.map((f) => path.basename(f, '.php')));
  const edges = new Map();
  for (const f of svcFiles) {
    const name = path.basename(f, '.php'); const deps = new Set();
    for (const m of read(f).matchAll(/App\\Services\\([A-Za-z0-9_]+)/g)) if (m[1] !== name && names.has(m[1])) deps.add(m[1]);
    edges.set(name, deps);
  }
  const color = new Map([...names].map((n) => [n, 0])); const stack = []; const cycles = [];
  const dfs = (n) => { color.set(n, 1); stack.push(n); for (const d of edges.get(n) || []) { if (color.get(d) === 1) cycles.push([...stack.slice(stack.indexOf(d)), d].join(' -> ')); else if (color.get(d) === 0) dfs(d); } stack.pop(); color.set(n, 2); };
  for (const n of names) if (color.get(n) === 0) dfs(n);
  if (cycles.length > baseline.serviceDependencyCycles) failures.push(`FIT-2 (DEP-3): service cycle(s): ${cycles.join(' | ')}`);
  else note(`FIT-2: service graph acyclic (${names.size} services).`);
  svcGraph = { svcFiles, edges };
}

// FIT-3 owner frontmatter
{ ran.add('FIT-3'); for (const rel of baseline.requireOwnerFrontmatter) { const p = path.join(ROOT, rel); if (!fs.existsSync(p)) failures.push(`FIT-3: missing ${rel}`); else if (!/^---[\s\S]*?\bowner:\s*\S+/m.test(read(p).slice(0, 800))) failures.push(`FIT-3: ${rel} must declare owner: in frontmatter`); } note('FIT-3: constitutional docs ownership checked.'); }

// FIT-4 ADR hygiene
{ ran.add('FIT-4'); const adrs = walk(path.join(ROOT, 'docs/adr'), (p) => /\d{4}-.*\.md$/.test(path.basename(p))); for (const f of adrs) { const t = read(f); const rel = path.relative(ROOT, f); if (!/^\s*status:\s*\S+/m.test(t)) failures.push(`FIT-4: ${rel} missing status`); if (!/Constitution/i.test(t)) failures.push(`FIT-4: ${rel} must reference the Constitution`); } note(`FIT-4: ${adrs.length} ADR(s) inspected.`); }

// FIT-5 status-literal duplication
{ let n = 0; const re = /['"]leave['"]\s*,\s*['"]leave_adjusted['"]\s*,\s*['"]excused['"]/; for (const f of appPhp) if (re.test(read(f))) n++; ratchet('FIT-5', 'NM-7 status-literal duplication', n, 'statusLiteralFiles'); }

// FIT-6 DI-2 RemainingSessions direct writes
{ let n = 0; for (const f of appPhp) n += (read(f).match(/->RemainingSessions\s*=[^=]/g) || []).length; ratchet('FIT-6', 'DI-2 units owned by Billing ledger', n, 'remainingSessionsDirectWrites'); }

// FIT-7 DI-3 PaidAmount direct writes
{ let n = 0; for (const f of appPhp) n += (read(f).match(/->PaidAmount\s*=[^=]/g) || []).length; ratchet('FIT-7', 'DI-3 paid owned by Billing', n, 'paidAmountDirectWrites'); }

// FIT-8 DI-4 effectiveInstructorUserId sites
{ let n = 0; for (const f of appPhp) n += (read(f).match(/effectiveInstructorUserId\s*\(/g) || []).length; ratchet('FIT-8', 'DI-4 instructor stored on Occurrence', n, 'effectiveInstructorSites'); }

// FIT-9 DI-6 Teacher writes locked
{ let n = 0; for (const f of appPhp) n += (read(f).match(/Teacher::(create|insert|updateOrCreate)/g) || []).length; ratchet('FIT-9', 'DI-6 identity is Party', n, 'teacherDirectWrites'); }

// FIT-10 context map completeness + forbidden edges (DEP-1)
{
  ran.add('FIT-10');
  const mod = walk(path.join(ROOT, 'backend/app/Http/Controllers'), (p) => p.endsWith('.php'))
    .concat(svcGraph.svcFiles);
  const ctxOf = (name) => contexts.rules.find((r) => new RegExp(r.match).test(name))?.context;
  const unmapped = [];
  for (const f of mod) { const name = path.basename(f, '.php'); if (name === 'Controller') continue; if (!ctxOf(name)) unmapped.push(name); }
  if (unmapped.length) failures.push(`FIT-10 (no undocumented context): unmapped module(s): ${unmapped.join(', ')}. Add a rule in arch-contexts.json.`);
  else note(`FIT-10: all ${mod.length} controllers/services mapped to a context.`);
  for (const f of svcGraph.svcFiles) {
    const name = path.basename(f, '.php'); const fromCtx = ctxOf(name);
    for (const dep of (svcGraph.edges.get(name) || [])) {
      const toCtx = ctxOf(dep);
      const rule = contexts.forbiddenEdges.find((e) => e.from === fromCtx);
      if (rule && rule.mustNotDependOn.includes(toCtx)) failures.push(`FIT-10 (DEP-1): ${name}[${fromCtx}] must not depend on ${dep}[${toCtx}] (use events).`);
    }
  }
}

// FIT-11 Ownership Registry completeness
{
  ran.add('FIT-11');
  const reg = read(path.join(ROOT, 'docs/OWNERSHIP_REGISTRY.md'));
  const rows = reg.split('\n').filter((l) => /^\|\s*F-\d{2}\s*\|/.test(l));
  if (rows.length < 10) failures.push(`FIT-11: Ownership Registry has only ${rows.length} fact rows (expected F-01..F-16).`);
  for (const r of rows) {
    const cells = r.split('|').map((c) => c.trim());
    const id = cells[1], owner = cells[3], status = cells[6], adr = cells[7];
    if (!owner) failures.push(`FIT-11: ${id} has no owner`);
    if (!/single|contested/.test(status || '')) failures.push(`FIT-11: ${id} has no ownership status`);
    if (/contested/.test(status || '') && !/\d{4}/.test(adr || '')) failures.push(`FIT-11: contested ${id} must cite an ADR`);
  }
  note(`FIT-11: ${rows.length} ownership facts validated.`);
}

// FIT-12 every invariant has a present enforcement
{
  ran.add('FIT-12');
  for (const [di, spec] of Object.entries(invariants.invariants)) {
    if (spec.test) { const p = path.join(ROOT, spec.test); if (!fs.existsSync(p)) failures.push(`FIT-12: ${di} test ${spec.test} missing`); else if (!read(p).includes(di)) failures.push(`FIT-12: ${di} test must tag ${di}`); }
    if (spec.fit && !ran.has(spec.fit)) failures.push(`FIT-12: ${di} references ${spec.fit} which did not run`);
  }
  note(`FIT-12: ${Object.keys(invariants.invariants).length} invariants have live enforcement.`);
}

// FIT-13 required SOP docs present
{ ran.add('FIT-13'); for (const rel of baseline.requiredSopDocs) if (!fs.existsSync(path.join(ROOT, rel))) failures.push(`FIT-13: required SOP doc missing: ${rel}`); note(`FIT-13: ${baseline.requiredSopDocs.length} SOP docs present.`); }

if (failures.length) { console.error('\nArchitecture fitness FAILED:\n' + failures.map((f) => ' ✗ ' + f).join('\n')); process.exit(1); }
console.log(`\nArchitecture fitness: PASS (${ran.size} checks).`);
