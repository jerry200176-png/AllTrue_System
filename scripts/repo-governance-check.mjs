#!/usr/bin/env node
/**
 * Repo Governance Check — machine verification for self-governing repo.
 *
 * Hard fails (product speed safe via ratchets, not absolute utopia targets):
 *  - Contaminated / oversized PR diffs (junk home paths OR >N files)
 *  - Token / size ceiling regressions vs baseline.json
 *  - Expired SOP handoff content
 *  - Missing governance frontmatter on registered policy files
 *  - Unresolved CONTRADICTION_REGISTRY rows (status not Resolved)
 *
 * Soft warnings: progress toward absolute Exit Criteria budgets.
 *
 * Flags:
 *   --write          rewrite baseline ceilings downward where actual improved
 *   --skip-pr-diff   skip PR contamination check (local default / schedule)
 *   --json           print machine-readable summary to stdout (last line)
 *
 * Owner / TTL / Version / Retirement: see scripts/repo-governance-baseline.json#_meta
 */
import { execSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const baselinePath = join(root, 'scripts', 'repo-governance-baseline.json');
const write = process.argv.includes('--write');
const skipPrDiff = process.argv.includes('--skip-pr-diff');
const asJson = process.argv.includes('--json');

const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));

/** @type {string[]} */
const errors = [];
/** @type {string[]} */
const warnings = [];
/** @type {Record<string, unknown>} */
const metrics = {};

function read(rel) {
  return readFileSync(join(root, rel), 'utf8');
}

function exists(rel) {
  return existsSync(join(root, rel));
}

function estimateTokens(text) {
  const cjk = (text.match(/[\u4e00-\u9fff]/g) || []).length;
  const other = text.length - cjk;
  return Math.floor(other / 4 + cjk / 1.8);
}

const ALWAYS_ON_STRICT = [
  '.cursorrules',
  '.cursor/rules/alltrue-system.mdc',
  '.cursor/rules/p0-gate.mdc',
  '.cursor/rules/agent-long-running.mdc',
  '.cursor/rules/auto-frontend-deploy.mdc',
  '.cursor/rules/tech-debt.mdc',
  '.cursor/rules/user-facing-communication.mdc',
];

const ALWAYS_ON_CLOUD = [...ALWAYS_ON_STRICT, 'AGENTS.md', 'CLAUDE.md'];

const OBEDIENT_BUNDLE = [
  ...ALWAYS_ON_CLOUD,
  'docs/INDEX.md',
  'docs/AI_REGRESSION_LESSONS.md',
  'docs/SOP_MATURITY.md',
  'docs/TECH_DEBT.md',
];

function sumTokens(files) {
  let total = 0;
  for (const f of files) {
    if (!exists(f)) {
      warnings.push(`missing tracked context file: ${f}`);
      continue;
    }
    total += estimateTokens(read(f));
  }
  return total;
}

function parseFrontMatter(raw) {
  const m = raw.match(/^---\n([\s\S]*?)\n---/);
  if (!m) return null;
  /** @type {Record<string, string>} */
  const out = {};
  for (const line of m[1].split('\n')) {
    const kv = line.match(/^([A-Za-z0-9_]+):\s*(.+)$/);
    if (kv) out[kv[1].trim()] = kv[2].trim().replace(/^["']|["']$/g, '');
  }
  return out;
}

function checkGovernanceFrontmatter() {
  const requiredKeys = ['owner', 'governance_version', 'ttl_days', 'retirement', 'last_reviewed'];
  for (const rel of baseline.required_governance_frontmatter || []) {
    if (!exists(rel)) {
      errors.push(`${rel}: registered governance object missing`);
      continue;
    }
    const fm = parseFrontMatter(read(rel));
    if (!fm) {
      errors.push(`${rel}: missing YAML frontmatter (owner/ttl/version/retirement)`);
      continue;
    }
    for (const k of requiredKeys) {
      if (!fm[k]) errors.push(`${rel}: frontmatter missing ${k}`);
    }
    const ttl = Number(fm.ttl_days || 0);
    const reviewed = fm.last_reviewed ? new Date(fm.last_reviewed) : null;
    if (reviewed && !Number.isNaN(reviewed.getTime()) && ttl > 0) {
      const days = Math.floor((Date.now() - reviewed.getTime()) / 86400000);
      if (days > ttl * 2) {
        errors.push(`${rel}: governance TTL expired (${days}d > 2×${ttl}d) — review or retire`);
      } else if (days > ttl) {
        warnings.push(`${rel}: governance review overdue (${days}d > ${ttl}d)`);
      }
    }
  }
}

function checkTokenRatchets() {
  const actual = {
    always_on_strict: sumTokens(ALWAYS_ON_STRICT),
    always_on_cloud_session: sumTokens(ALWAYS_ON_CLOUD),
    index: exists('docs/INDEX.md') ? estimateTokens(read('docs/INDEX.md')) : 0,
    obedient_bundle: sumTokens(OBEDIENT_BUNDLE),
  };
  metrics.tokens = actual;

  const ceilings = baseline.tokens || {};
  const updated = { ...baseline, tokens: { ...ceilings } };
  for (const [key, limit] of Object.entries(ceilings)) {
    const val = actual[key];
    if (typeof val !== 'number') continue;
    const mark = val > limit ? 'FAIL' : val < limit ? 'DOWN' : 'OK';
    console.log(`[tokens] ${mark} ${key}: ${val} (ceiling ${limit})`);
    if (val > limit) {
      errors.push(`token regression ${key}: ${val} > ceiling ${limit} (do not grow always-on/INDEX/obedient bundle)`);
    }
    if (val < limit) updated.tokens[key] = val;
  }

  // Soft absolute targets from Design Review Exit Criteria (do not block shipping)
  if (actual.obedient_bundle > 15000) {
    warnings.push(`Exit Criteria gap: obedient_bundle ${actual.obedient_bundle} > absolute target 15000`);
  }
  if (actual.always_on_strict > 4000) {
    warnings.push(`Exit Criteria gap: always_on_strict ${actual.always_on_strict} > absolute target 4000`);
  }

  return updated;
}

function checkIndexLines() {
  if (!exists('docs/INDEX.md')) return;
  const lines = read('docs/INDEX.md').split('\n').length;
  metrics.index_lines = lines;
  const hard = baseline.limits?.index_lines_hard ?? 520;
  console.log(`[size] INDEX lines=${lines} (hard ${hard})`);
  if (lines > hard) {
    errors.push(`docs/INDEX.md lines ${lines} > hard ${hard} — split to portals; do not grow mega-index`);
  }
}

function checkSopHandoff() {
  if (!exists('docs/SOP_MATURITY.md')) return;
  const text = read('docs/SOP_MATURITY.md');
  const m = text.match(/## 🔴 進行中狀態[\s\S]*?(?=\n## )/);
  if (!m) {
    warnings.push('SOP_MATURITY missing handoff section marker');
    return;
  }
  const section = m[0];
  metrics.sop_handoff_chars = section.length;
  if (/下一個 Agent|Stop-the-line：#|待 merge \/ 待 CEO/.test(section)) {
    errors.push('SOP_MATURITY handoff still contains expired operational todos — clear section (self-governing TTL)');
  }
  const dateM = section.match(/更新時間：(\d{4}-\d{2}-\d{2})/);
  if (dateM) {
    const days = Math.floor((Date.now() - new Date(dateM[1]).getTime()) / 86400000);
    const maxAge = baseline.limits?.sop_handoff_max_age_days ?? 14;
    metrics.sop_handoff_age_days = days;
    // Empty handoff with fresh date is OK forever; only age matters if section is "active"
    const active = !/目前無有效 session handoff/.test(section);
    if (active && days > maxAge) {
      errors.push(`SOP_MATURITY active handoff age ${days}d > ${maxAge}d`);
    }
  }
}

function checkContradictions() {
  if (!exists('docs/CONTRADICTION_REGISTRY.md')) return;
  const text = read('docs/CONTRADICTION_REGISTRY.md');
  const rows = [...text.matchAll(/\| \*\*K\d+\*\* \|[^|]+\|[^|]+\|[^|]+\|([^|]+)\|/g)];
  let unresolved = 0;
  for (const r of rows) {
    const status = (r[1] || '').trim();
    if (!/Resolved/i.test(status)) unresolved += 1;
  }
  metrics.unresolved_k = unresolved;
  const max = baseline.kpi?.max_unresolved_contradiction_rows ?? 0;
  console.log(`[kpi] unresolved contradiction rows=${unresolved} (max ${max})`);
  if (unresolved > max) {
    errors.push(`CONTRADICTION_REGISTRY has ${unresolved} unresolved K-rows (max ${max})`);
  }
}

function checkPrContamination() {
  if (skipPrDiff) {
    console.log('[pr] skipped (--skip-pr-diff)');
    return;
  }
  const base = process.env.GITHUB_BASE_REF
    ? `origin/${process.env.GITHUB_BASE_REF}`
    : 'origin/main';
  let names = '';
  try {
    // Ensure base exists when possible
    try {
      execSync(`git rev-parse --verify ${base}`, { cwd: root, stdio: 'pipe' });
    } catch {
      try {
        execSync(`git fetch --no-tags --depth=1 origin ${process.env.GITHUB_BASE_REF || 'main'}`, {
          cwd: root,
          stdio: 'pipe',
        });
      } catch {
        warnings.push(`cannot resolve ${base} for PR contamination check`);
        return;
      }
    }
    names = execSync(`git diff --name-only ${base}...HEAD`, { cwd: root, encoding: 'utf8' });
  } catch (e) {
    warnings.push(`pr diff failed: ${e.message}`);
    return;
  }
  const files = names
    .split('\n')
    .map((s) => s.replace(/^"|"$/g, '').trim())
    .filter(Boolean);
  metrics.pr_changed_files = files.length;
  const hard = baseline.limits?.pr_changed_files_hard ?? 300;
  console.log(`[pr] changed_files=${files.length} (hard ${hard})`);

  const junkRe =
    /(^|\/)\.vnc\/|(^|\/)\.face(\.icon)?$|(^|\/)\.xsession-errors|(^|\/)\.lesshst$|(^|\/)\.gitconfig$|(^|\/)\.claude\.json$/;
  const junk = files.filter((f) => junkRe.test(f));
  if (junk.length) {
    errors.push(`contaminated PR paths (${junk.length}): ${junk.slice(0, 8).join(', ')} — close & recreate from main`);
  }
  if (files.length > hard) {
    errors.push(
      `PR changed_files ${files.length} > ${hard} — contaminated/oversized; split or recreate (GitHub diff also caps at 300)`,
    );
  }
}

function checkRemoteBranchAge() {
  let out = '';
  try {
    out = execSync(
      'git for-each-ref --format="%(committerdate:iso8601)|%(refname:short)" refs/remotes/origin',
      {
        cwd: root,
        encoding: 'utf8',
      },
    );
  } catch {
    warnings.push('branch age: no remotes (skip)');
    return;
  }
  const now = Date.now();
  let over14 = 0;
  let total = 0;
  for (const line of out.trim().split('\n')) {
    if (!line.trim()) continue;
    const [date, ref] = line.split('|');
    const name = (ref || '').replace(/^origin\//, '');
    if (!name || name === 'HEAD' || name === 'main') continue;
    total += 1;
    const t = Date.parse(date);
    if (!Number.isFinite(t)) continue;
    const days = (now - t) / 86400000;
    if (days > 14) over14 += 1;
  }
  metrics.remote_branches = total;
  metrics.branches_over_14d = over14;
  const max = baseline.kpi?.max_remote_branches_over_14d ?? 12;
  console.log(`[kpi] branches_over_14d=${over14}/${total} (max ${max})`);
  if (over14 > max) {
    errors.push(`stale branches over 14d: ${over14} > ${max} — run branch-hygiene / delete orphans`);
  }
}

function maturityGrade(actualTokens, errCount) {
  // A = Exit Criteria machine backbone essentially met for context + no hard errors
  if (errCount > 0) return 'C';
  if (actualTokens.obedient_bundle <= 15000 && actualTokens.always_on_strict <= 4000) return 'A-';
  if (actualTokens.obedient_bundle <= 25000) return 'B';
  return 'B-';
}

// --- run ---
console.log('Repo Governance Check');
console.log(`baseline version: ${baseline._meta?.version || 'unknown'} owner=${baseline._meta?.owner || '?'}`);

checkGovernanceFrontmatter();
const updatedBaseline = checkTokenRatchets();
checkIndexLines();
checkSopHandoff();
checkContradictions();
checkPrContamination();
checkRemoteBranchAge();

const grade = maturityGrade(metrics.tokens || {}, errors.length);
metrics.maturity_grade_estimate = grade;
metrics.errors = errors.length;
metrics.warnings = warnings.length;

if (write) {
  updatedBaseline._meta = {
    ...baseline._meta,
    last_reviewed: new Date().toISOString().slice(0, 10),
  };
  writeFileSync(baselinePath, `${JSON.stringify(updatedBaseline, null, 2)}\n`);
  console.log('baseline rewritten (tightened where improved)');
}

if (process.env.GITHUB_STEP_SUMMARY) {
  const lines = [
    '### Repo Governance Check',
    '',
    `- Maturity estimate: **${grade}**`,
    `- Errors: ${errors.length}`,
    `- Warnings: ${warnings.length}`,
    `- obedient_bundle≈${metrics.tokens?.obedient_bundle ?? '?'} (Exit target ≤15000)`,
    `- always_on_strict≈${metrics.tokens?.always_on_strict ?? '?'} (Exit target ≤4000)`,
    `- PR files: ${metrics.pr_changed_files ?? 'n/a'}`,
    `- branches >14d: ${metrics.branches_over_14d ?? 'n/a'}`,
    '',
  ];
  writeFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join('\n')}\n`, { flag: 'a' });
}

for (const w of warnings) console.error(`WARN: ${w}`);
for (const e of errors) console.error(`ERROR: ${e}`);

if (asJson) {
  console.log(JSON.stringify({ ok: errors.length === 0, grade, metrics, errors, warnings }));
}

if (errors.length) {
  console.error(`\nrepo-governance-check: FAIL (${errors.length} error(s))`);
  process.exit(1);
}

console.log(`\nrepo-governance-check: OK (grade≈${grade}, ${warnings.length} warning(s))`);
