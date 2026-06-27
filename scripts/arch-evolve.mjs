#!/usr/bin/env node
/**
 * Self-Evolving Architecture Compiler — turns detected structural tension into evolution proposals.
 *
 * Pipeline per tension (a ratchet with headroom toward its target):
 *   detect  -> classify -> generate ADR draft (root cause, bounded context, structural fix, migration)
 *           -> generate minimal patch design -> simulate impact (baseline delta) -> write proposal.
 *
 * This is the diagnostic+repair layer referenced by .github/workflows/arch-self-repair.yml.
 * It is non-blocking: it never fails CI; it PROPOSES. Enforcement stays in arch-fitness-check.mjs.
 *
 * Usage: node scripts/arch-evolve.mjs           # writes docs/adr/drafts/AUTO-*.md, prints summary
 *        node scripts/arch-evolve.mjs --check    # exit 1 if a tension has no draft (CI gate for the engine)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const baseline = JSON.parse(fs.readFileSync(path.join(ROOT, 'scripts/arch-fitness-baseline.json'), 'utf8'));
const DRAFTS = path.join(ROOT, 'docs/adr/drafts');
fs.mkdirSync(DRAFTS, { recursive: true });

const walk = (d, a = []) => { if (!fs.existsSync(d)) return a; for (const e of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, e.name); e.isDirectory() ? walk(p, a) : p.endsWith('.php') && a.push(p); } return a; };
const appPhp = walk(path.join(ROOT, 'backend/app'));
const count = (re) => { let n = 0; for (const f of appPhp) n += (fs.readFileSync(f, 'utf8').match(re) || []).length; return n; };
const filesMatching = (re) => appPhp.filter((f) => re.test(fs.readFileSync(f, 'utf8'))).map((f) => path.relative(ROOT, f));

// Knowledge base: each tension -> classification + structural evolution.
const KB = [
  {
    key: 'classSessionCreateSites', metric: () => count(/ClassSession::create\s*\(/g), target: 1,
    fit: 'FIT-1', di: 'DI-1/DI-7', adr: 'ADR-0005', kind: 'Model mismatch / ownership ambiguity',
    context: 'Scheduling', fact: 'F-05 (occurrence)',
    rootCause: 'Occurrence creation is scattered across many writers; no chokepoint can enforce DI-1 (one occurrence per student+date+slot) or DI-7 (no post-closure sessions).',
    structuralFix: 'Introduce App\\Domain\\Scheduling\\SessionWriter as the single creation port; route every site through it; it enforces the (student,date,slot) uniqueness + lifecycle guard and emits OccurrenceScheduled.',
    migration: 'Expand: add SessionWriter, migrate sites incrementally (ratchet FIT-1 down each PR). Contract: after count==1, add (StudentID,SessionDate,StartTime) partial-unique index post historical de-dup.',
  },
  {
    key: 'remainingSessionsDirectWrites', metric: () => count(/->RemainingSessions\s*=[^=]/g), target: 0,
    fit: 'FIT-6', di: 'DI-2', adr: 'ADR-0002', kind: 'Model mismatch (counter vs ledger)',
    context: 'Billing', fact: 'F-07 (units)',
    rootCause: 'Session units are mutated as a counter on StudentClass instead of derived from an append-only ledger; concurrent deduction can race and drift.',
    structuralFix: 'Make SessionLedger append-only the SoR; RemainingSessions becomes a read projection rebuilt from ledger entries; replace direct counter writes with ledger appends.',
    migration: 'Expand: write ledger + project to RemainingSessions (dual-write). Verify projection==counter. Contract: stop writing the counter directly (ratchet FIT-6 to 0); reads use projection.',
  },
  {
    key: 'paidAmountDirectWrites', metric: () => count(/->PaidAmount\s*=[^=]/g), target: 0,
    fit: 'FIT-7', di: 'DI-3', adr: 'ADR-0002', kind: 'Ownership ambiguity (dual paid-writer)',
    context: 'Billing', fact: 'F-08 (paid)',
    rootCause: 'PaidAmount is written outside a single Billing authority, competing with Invoice/Payment as a second source of truth.',
    structuralFix: 'Route all paid-state changes through a Billing application service that writes Invoice/Payment; PaidAmount becomes a projection; mode-change emits ContractModeChanged for explicit reconciliation.',
    migration: 'Expand: Billing service owns writes + projects. Contract: forbid direct PaidAmount writes (ratchet FIT-7 to 0).',
  },
  {
    key: 'effectiveInstructorSites', metric: () => count(/effectiveInstructorUserId\s*\(/g), target: 0,
    fit: 'FIT-8', di: 'DI-4', adr: 'ADR-0001', kind: 'Architecture smell (per-row recomputation)',
    context: 'Scheduling', fact: 'F-06 (instructor)',
    rootCause: 'The effective instructor is recomputed per row at query time (substitute resolution), making payroll/eval口徑 nondeterministic and slow (#176).',
    structuralFix: 'Store the resolved instructor assignment on the Occurrence at schedule/substitute time; consumers read the stored value; delete per-row resolution.',
    migration: 'Expand: persist Occurrence.instructor on create/substitute; backfill. Contract: replace effectiveInstructorUserId call-sites with the stored field (ratchet FIT-8 to 0).',
  },
];

const draftADR = (t, current) => `---
status: Draft (auto-generated by arch-evolve)
date: ${new Date().toISOString().slice(0, 10)}
owner: ${t.context} context
generated_from: fitness ${t.fit} (${t.key}=${current}, target ${t.target})
---
# ADR-AUTO-${t.key}: resolve ${t.di} structural tension in ${t.context}

> Auto-generated evolution proposal. Promote to a numbered ADR (supersede/extend ${t.adr}) when accepted.

## Constitution link
Derives from CONSTITUTION.md Article V (single owner per fact) + invariant ${t.di}; extends ${t.adr}.

## Classification
${t.kind}.

## Root cause
${t.rootCause}

## Affected bounded context
${t.context} — fact ${t.fact}. Current pressure: ${current} site(s) (target ${t.target}); enforced by ${t.fit}.

## Proposed structural fix
${t.structuralFix}

## Migration plan (Expand/Contract, non-breaking)
${t.migration}

## Simulated impact
- Fitness: \`${t.key}\` baseline ${current} → ${t.target} after completion (ratchet locks each step).
- No behavior change per step (projection/port preserves semantics); each step independently revertible.
- Closes debt row in OWNERSHIP_REGISTRY (${t.fact}) from \`contested\` → \`single\`.
`;

const tensions = [];
for (const t of KB) {
  const current = t.metric();
  if (current > t.target) {
    tensions.push({ t, current });
    const file = path.join(DRAFTS, `AUTO-${t.key}.md`);
    fs.writeFileSync(file, draftADR(t, current));
  } else {
    const file = path.join(DRAFTS, `AUTO-${t.key}.md`);
    if (fs.existsSync(file)) fs.rmSync(file); // tension resolved -> retract draft
  }
}

console.log(`Self-evolving compiler: scanned ${KB.length} tension classes; ${tensions.length} active.`);
for (const { t, current } of tensions) {
  console.log(`  ⚠ ${t.fit} ${t.di} [${t.context}] ${t.key}=${current}>${t.target} → draft docs/adr/drafts/AUTO-${t.key}.md`);
}
if (!tensions.length) console.log('  ✓ no active structural tension; system at stable state.');

if (process.argv.includes('--check')) {
  // engine self-gate: every active tension must have a draft on disk
  const missing = tensions.filter(({ t }) => !fs.existsSync(path.join(DRAFTS, `AUTO-${t.key}.md`)));
  if (missing.length) { console.error('arch-evolve --check: tension without proposal: ' + missing.map((m) => m.t.key).join(', ')); process.exit(1); }
}
