#!/usr/bin/env node
/**
 * Canonical fast CI preflight. `npm run ci:preflight` [--json] [--self-test]
 */
import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { GOV_CODES, formatGovError, classifyStepFailure, classifySameShaRerun } from './ci/gov-codes.mjs';
import { validateBranchName } from './ci/branch-policy.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const selfTest = process.argv.includes('--self-test');
const jsonOut = process.argv.includes('--json');

const git = (args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

function resolveBase(env = process.env) {
  if (env.PR_BASE_SHA) return env.PR_BASE_SHA;
  if (env.GITHUB_BASE_SHA) return env.GITHUB_BASE_SHA;
  if (env.GITHUB_BASE_REF) return `origin/${env.GITHUB_BASE_REF.replace(/^refs\/heads\//, '')}`;
  return 'origin/main';
}

function checkBranch(errors) {
  let branch = process.env.GITHUB_HEAD_REF || '';
  try { if (!branch) branch = git(['rev-parse', '--abbrev-ref', 'HEAD']); } catch { /* */ }
  const v = validateBranchName(branch || 'HEAD');
  if (!v.ok) {
    errors.push(formatGovError({
      code: GOV_CODES.BRANCH, message: v.message, actual: branch,
      policy: 'scripts/ci/branch-policy.mjs', fix: v.fix,
    }));
  }
}

function checkGenerated(errors) {
  const r = spawnSync(process.execPath, [path.join(ROOT, 'scripts/sync-generated.mjs'), '--check'], { cwd: ROOT, encoding: 'utf8' });
  if (r.status !== 0) {
    errors.push(formatGovError({
      code: GOV_CODES.GENERATED,
      message: (r.stderr || r.stdout || 'generate failed').trim().slice(0, 400),
      actual: 'generate failed', policy: 'scripts/sync-generated.mjs',
      fix: 'npm run sync:generated (generated files are git-ignored; fix the bad docs/changes, docs/staff-updates or docs/parent-updates file)',
    }));
  }
}

function checkLegacySize(errors, warnings) {
  const base = resolveBase();
  try { git(['rev-parse', '--verify', base]); }
  catch {
    warnings.push(formatGovError({
      code: GOV_CODES.BASE, message: `Base ${base} missing`, actual: base,
      policy: 'Stacked PRs need PR_BASE_SHA', fix: 'git fetch origin main', blocking: false,
    }));
    return;
  }
  const out = execFileSync('git', [
    'diff', '--numstat', `${base}...HEAD`, '--',
    ':!*.lock', ':!**/package-lock.json', ':!package-lock.json',
    ':!**/composer.lock', ':!composer.lock', ':!*.sql', ':!*.sql.gz',
    ':!*.min.js', ':!*.min.css', ':!*.log', ':!**/*.log',
    ':!*phpstan-baseline.neon', ':!**/*.generated.js', ':!.cursor/skills/**',
  ], { cwd: ROOT, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
  let total = 0;
  for (const line of out.split('\n')) {
    const [a, d] = line.split('\t');
    if (!a || a === '-') continue;
    total += (Number(a) || 0) + (Number(d) || 0);
  }
  const changedNames = git(['diff', '--name-only', `${base}...HEAD`]).split('\n').filter(Boolean);
  const touchesProduction = changedNames.some((name) => /^(backend\/app\/|backend\/routes\/|backend\/database\/|frontend\/src\/)/.test(name));
  const hardLimit = touchesProduction ? 700 : 1300;
  if (total > hardLimit) {
    errors.push(formatGovError({
      code: GOV_CODES.SIZE, message: `Legacy PR size ${total} > ${hardLimit}`,
      actual: JSON.stringify({ total, base, hardLimit, touchesProduction }), policy: 'presubmit.yml CHECK 2',
      fix: 'Split PR. Stacked: export PR_BASE_SHA=<base>. Risk-based gate = G2.',
    }));
  } else if (total > 400) {
    warnings.push(formatGovError({
      code: GOV_CODES.SIZE, message: `PR size ${total} > 400 (recommended)`,
      actual: String(total), policy: 'presubmit CHECK 2', fix: 'Consider splitting', blocking: false,
    }));
  }
}

function checkWorkflowYaml(errors) {
  const dir = path.join(ROOT, '.github/workflows');
  if (!fs.existsSync(dir)) return;
  for (const name of fs.readdirSync(dir).filter((n) => /\.ya?ml$/.test(n))) {
    const text = read(path.join('.github/workflows', name));
    if (/^<<<<<<< /m.test(text) || !/^on:/m.test(text) || !/^jobs:/m.test(text)) {
      errors.push(formatGovError({
        code: GOV_CODES.WORKFLOW_YAML, message: `Workflow invalid or conflicted: ${name}`,
        actual: name, policy: 'WORKFLOW_SYNTAX', fix: `Fix .github/workflows/${name}`,
      }));
    }
  }
}

// Same triage-token shape as the .gitleaks.toml rule (#4022); the bare prefix alone never matches.
const SECRET_PATTERNS = [
  [/AKIA[0-9A-Z]{16}/, 'AWS key'],
  [/-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----/, 'private key'],
  [/ghp_[A-Za-z0-9]{36}/, 'GitHub PAT'],
  [/(?:\d+\|)?alltrue_triage_[A-Za-z0-9]{40}(?![A-Za-z0-9])/, 'AllTrue triage token'],
];

function findSecretLabels(text) {
  return SECRET_PATTERNS.filter(([re]) => re.test(text)).map(([, label]) => label);
}

function checkSecrets(errors) {
  let diff = '';
  try {
    diff = execFileSync('git', ['diff', '-U0', `${resolveBase()}...HEAD`], {
      cwd: ROOT, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024,
    });
  } catch { return; }
  for (const label of findSecretLabels(diff)) {
    errors.push(formatGovError({
      code: GOV_CODES.SECRET, message: `Possible secret in diff: ${label}`,
      actual: label, policy: 'Secret Scan', fix: 'Remove secret; rotate if pushed',
    }));
  }
}

async function main() {
  if (selfTest) {
    if (classifyStepFailure({ step: 'Branch naming', logSnippet: 'Invalid branch name' }).primary !== 'BRANCH_NAMING_POLICY') throw new Error('taxonomy');
    if (classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'success', headShaSame: true }).kind !== 'flake') throw new Error('flake');
    if (!validateBranchName('sec/x').ok || validateBranchName('agent/x').ok) throw new Error('branch');
    const triage = `+ token = "12|${'alltrue_'}triage_${'Ab3'.repeat(13)}x"`; // built at runtime so this file never holds a token
    if (findSecretLabels(triage).join() !== 'AllTrue triage token') throw new Error('triage token not detected');
    if (findSecretLabels(`${'alltrue_'}triage_${'Ab3'.repeat(13)}x`).join() !== 'AllTrue triage token') throw new Error('bare triage secret not detected');
    if (findSecretLabels("'secret_prefix' => 'alltrue_triage_', see 1|alltrue_triage_forged").length) throw new Error('triage prefix false positive');
    console.log('ci-preflight self-test: OK');
    return;
  }
  const errors = [];
  const warnings = [];
  checkBranch(errors);
  checkGenerated(errors);
  checkWorkflowYaml(errors);
  checkSecrets(errors);
  checkLegacySize(errors, warnings);
  const result = { ok: errors.length === 0, errors, warnings };
  if (jsonOut) console.log(JSON.stringify(result, null, 2));
  else {
    console.log('=== AllTrue CI Preflight ===');
    for (const w of warnings) { console.log(`WARN  ${w.code}: ${w.message}`); if (w.fix) console.log(`      fix: ${w.fix}`); }
    for (const e of errors) { console.log(`FAIL  ${e.code}: ${e.message}`); if (e.actual) console.log(`      actual: ${e.actual}`); if (e.fix) console.log(`      fix: ${e.fix}`); }
    console.log(errors.length ? `\n${errors.length} blocking issue(s).` : 'OK — governance checks passed.');
  }
  process.exit(errors.length ? 1 : 0);
}

main().catch((err) => { console.error(err); process.exit(2); });
