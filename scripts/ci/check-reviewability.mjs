#!/usr/bin/env node
/**
 * Risk-based reviewability gate (Presubmit CHECK 2).
 * Base-aware via PR_BASE_SHA / GITHUB_BASE_SHA / GITHUB_BASE_REF.
 * Founder exception: file must already exist on origin/main (not PR head).
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  classifyNumstat, evaluateReviewability, gitNumstat, resolveDiffBase, matchFounderException,
} from './diff-classifier.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CHECK_ID = 'reviewability';

function gitShowMain(rel) {
  try {
    return execFileSync('git', ['show', `origin/main:${rel}`], { cwd: ROOT, encoding: 'utf8' });
  } catch {
    return null;
  }
}

const base = resolveDiffBase();
const prNumber = process.env.PR_NUMBER || process.env.GITHUB_PR_NUMBER || '';
const headSha = process.env.GITHUB_SHA || execFileSync('git', ['rev-parse', 'HEAD'], { cwd: ROOT, encoding: 'utf8' }).trim();

let numstat;
try {
  numstat = gitNumstat(base, 'HEAD', ROOT);
} catch (e) {
  console.error('GOV-BASE-001: cannot diff against', base, e.message);
  process.exit(1);
}

const classified = classifyNumstat(numstat);
const evaled = evaluateReviewability(classified);
console.log(JSON.stringify({
  base, headSha, prNumber, score: evaled.score, productionCode: evaled.productionCode,
  highRiskLines: evaled.highRiskLines, mode: evaled.mode, counts: classified.counts,
  warnings: evaled.warnings, blockers: evaled.blockers,
}, null, 2));

if (evaled.warnings.length) {
  for (const w of evaled.warnings) {
    console.log(`::warning title=GOV-SIZE-001::Reviewability warning ${w.reason}=${w.actual} (limit ${w.limit})`);
  }
}

if (evaled.ok) {
  console.log('✅ Reviewability OK');
  process.exit(0);
}

// Founder exception from origin/main only (process control — not crypto identity)
if (prNumber) {
  const rel = `.github/founder-exceptions/PR-${prNumber}-${headSha}.json`;
  const raw = gitShowMain(rel);
  if (raw) {
    let exc;
    try { exc = JSON.parse(raw); } catch { exc = null; }
    const m = matchFounderException(exc, { prNumber, headSha, checkId: CHECK_ID });
    if (m.ok) {
      console.log(`::warning title=Founder exception::Waived ${CHECK_ID} via ${rel} (${m.note})`);
      process.exit(0);
    }
    console.error('Founder exception present but invalid:', m.reason);
  }
}

console.error('❌ GOV-SIZE-001: reviewability hard-block');
console.error('blockers:', JSON.stringify(evaled.blockers));
console.error('fix: split vertical slice; set PR_BASE_SHA for stacked PRs; or Founder commits exception to main:');
console.error(`  .github/founder-exceptions/PR-<n>-<exact-head-sha>.json (waived_check="${CHECK_ID}")`);
process.exit(1);
