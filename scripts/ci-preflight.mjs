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
const exists = (p) => fs.existsSync(path.join(ROOT, p));
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

function resolveBase(env = process.env) {
  if (env.PR_BASE_SHA) return env.PR_BASE_SHA;
  if (env.GITHUB_BASE_SHA) return env.GITHUB_BASE_SHA;
  if (env.GITHUB_BASE_REF) return `origin/${env.GITHUB_BASE_REF.replace(/^refs\/heads\//, '')}`;
  return 'origin/main';
}

function conflictHits(dirRel) {
  const abs = path.join(ROOT, dirRel);
  if (!fs.existsSync(abs)) return [];
  const hits = [];
  (function walk(d) {
    for (const ent of fs.readdirSync(d, { withFileTypes: true })) {
      const full = path.join(d, ent.name);
      if (ent.isDirectory()) walk(full);
      else if (/\.(json|md|ya?ml|js|mjs|sh)$/i.test(ent.name)) {
        const t = fs.readFileSync(full, 'utf8');
        if (/^<<<<<<< /m.test(t) || /^>>>>>>> /m.test(t)) hits.push(path.relative(ROOT, full));
      }
    }
  })(abs);
  return hits;
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

function checkProvenance(errors, warnings) {
  const used = exists('.agent-session/manifest.json')
    ? '.agent-session/manifest.json'
    : exists('.agent-session/human-authored.json') ? '.agent-session/human-authored.json' : null;
  if (!used) {
    errors.push(formatGovError({
      code: GOV_CODES.PROV_PARSE, message: 'Missing provenance manifest', actual: null,
      policy: 'scripts/check-agent-provenance.sh',
      fix: 'Create .agent-session/human-authored.json or agent-start manifest',
    }));
    return;
  }
  let parsed;
  try { parsed = JSON.parse(read(used)); }
  catch (e) {
    errors.push(formatGovError({
      code: GOV_CODES.PROV_PARSE, message: `JSON parse error: ${e.message}`, actual: used,
      policy: 'scripts/check-agent-provenance.sh', fix: `python3 -m json.tool ${used}`,
    }));
    return;
  }
  const markers = conflictHits('.agent-session');
  if (markers.length) {
    errors.push(formatGovError({
      code: GOV_CODES.PROV_MARKERS, message: 'Conflict markers in .agent-session',
      actual: markers.join(', '), policy: 'MERGE_CONFLICT_MARKERS',
      fix: `Remove conflict markers from ${markers.join(' ')}`,
    }));
  }
  if (!['agent-session', 'human-authored'].includes(parsed.provenance_type)) {
    errors.push(formatGovError({
      code: GOV_CODES.PROV_CONSISTENCY, message: 'Invalid provenance_type',
      actual: String(parsed.provenance_type), policy: 'check-agent-provenance.sh',
      fix: `Set provenance_type in ${used}`,
    }));
  }
  let branch = process.env.GITHUB_HEAD_REF || '';
  try { if (!branch) branch = git(['rev-parse', '--abbrev-ref', 'HEAD']); } catch { /* */ }
  if (parsed.provenance_type === 'agent-session' && parsed.branch && branch && parsed.branch !== branch) {
    errors.push(formatGovError({
      code: GOV_CODES.PROV_CONSISTENCY, message: 'manifest.branch != git branch',
      actual: `${parsed.branch} != ${branch}`, policy: 'check-agent-provenance.sh',
      fix: `Align branch in ${used}`,
    }));
  } else if (parsed.provenance_type === 'agent-session' && parsed.task_id && branch && !branch.includes(parsed.task_id)) {
    warnings.push(formatGovError({
      code: GOV_CODES.PROV_CONSISTENCY, message: 'task_id not in branch',
      actual: `task_id=${parsed.task_id}`, policy: 'check-agent-provenance.sh',
      fix: `git branch -m cursor/<slug>-${parsed.task_id}`, blocking: false,
    }));
  }
}

function checkGenerated(errors) {
  const r = spawnSync(process.execPath, [path.join(ROOT, 'scripts/sync-generated.mjs'), '--check'], { cwd: ROOT, encoding: 'utf8' });
  if (r.status !== 0) {
    errors.push(formatGovError({
      code: GOV_CODES.GENERATED,
      message: (r.stderr || r.stdout || 'generated drift').trim().slice(0, 400),
      actual: 'drift', policy: 'scripts/sync-generated.mjs',
      fix: 'npm run sync:generated && git add frontend/src/lib/*.generated.js',
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
  if (total > 700) {
    errors.push(formatGovError({
      code: GOV_CODES.SIZE, message: `Legacy PR size ${total} > 700`,
      actual: JSON.stringify({ total, base }), policy: 'presubmit.yml CHECK 2',
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

function checkSecrets(errors) {
  let diff = '';
  try {
    diff = execFileSync('git', ['diff', '-U0', `${resolveBase()}...HEAD`], {
      cwd: ROOT, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024,
    });
  } catch { return; }
  for (const [re, label] of [[/AKIA[0-9A-Z]{16}/, 'AWS key'], [/-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----/, 'private key'], [/ghp_[A-Za-z0-9]{36}/, 'GitHub PAT']]) {
    if (re.test(diff)) {
      errors.push(formatGovError({
        code: GOV_CODES.SECRET, message: `Possible secret in diff: ${label}`,
        actual: label, policy: 'Secret Scan', fix: 'Remove secret; rotate if pushed',
      }));
    }
  }
}

async function main() {
  if (selfTest) {
    if (classifyStepFailure({ step: 'Branch naming', logSnippet: 'Invalid branch name' }).primary !== 'BRANCH_NAMING_POLICY') throw new Error('taxonomy');
    if (classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'success', headShaSame: true }).kind !== 'flake') throw new Error('flake');
    if (!validateBranchName('sec/x').ok || validateBranchName('agent/x').ok) throw new Error('branch');
    console.log('ci-preflight self-test: OK');
    return;
  }
  const errors = [];
  const warnings = [];
  checkBranch(errors);
  checkProvenance(errors, warnings);
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
