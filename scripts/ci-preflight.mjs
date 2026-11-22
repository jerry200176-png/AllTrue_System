#!/usr/bin/env node
/**
 * Canonical fast CI preflight. `npm run ci:preflight` [--json] [--self-test] [--routes]
 * --routes: only the route-registration check (lefthook pre-push, when backend/routes changed).
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
  // Match Presubmit CHECK 2: tests/docs count toward total, not the logic limit.
  const excludes = [
    ':!*.lock', ':!**/package-lock.json', ':!package-lock.json',
    ':!**/composer.lock', ':!composer.lock', ':!*.sql', ':!*.sql.gz',
    ':!*.min.js', ':!*.min.css', ':!*.log', ':!**/*.log',
    ':!*phpstan-baseline.neon', ':!**/*.generated.js', ':!.agents/skills/**', ':!.claude/skills/**', ':!.cursor/**',
  ];
  const branch = process.env.GITHUB_HEAD_REF || git(['rev-parse', '--abbrev-ref', 'HEAD']);
  if (/^chore\/docs-/.test(branch)) excludes.push(':!docs/CHANGELOG.md', ':!docs/archive/**');
  const stat = (extra = []) => {
    const out = execFileSync('git', ['diff', '--numstat', `${base}...HEAD`, '--', ...excludes, ...extra],
      { cwd: ROOT, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
    let add = 0; let del = 0;
    for (const line of out.split('\n')) {
      const [a, d] = line.split('\t');
      if (!a || a === '-') continue;
      add += Number(a) || 0; del += Number(d) || 0;
    }
    return { add, del };
  };
  const { add, del } = stat();
  const total = add + del;
  const logicStat = stat([':!backend/tests/**', ':!scripts/tests/**', ':!**/__tests__/**', ':!**/*.test.*',
    ':!**/*.spec.*', ':!frontend/e2e/**', ':!docs/**', ':!**/*.md']);
  const logic = logicStat.add + logicStat.del;
  const changedNames = git(['diff', '--name-only', '-z', `${base}...HEAD`]).split('\0').filter(Boolean);
  const touchesProduction = changedNames.some((name) => /^(backend\/app\/|backend\/routes\/|backend\/database\/|frontend\/src\/)/.test(name));
  const hardLimit = touchesProduction ? 700 : 1300;
  const totalLimit = 1500;
  // Preserve the native narrow cleanup exception, including its deletion ceiling.
  const deletionCleanup = add <= 30 && del > hardLimit;
  if (deletionCleanup ? del > 3000 : logic > hardLimit || total > totalLimit) {
    errors.push(formatGovError({
      code: GOV_CODES.SIZE,
      message: deletionCleanup ? `Deletion cleanup ${del} > 3000` : `PR size ${logic} logic lines (limit: ${hardLimit}), ${total} lines with tests/docs (limit: ${totalLimit})`,
      actual: JSON.stringify({ add, del, logic, total, base, hardLimit, totalLimit, touchesProduction }), policy: 'presubmit.yml CHECK 2',
      fix: 'Split PR. Stacked: export PR_BASE_SHA=<base>. Risk-based gate = G2.',
    }));
  } else if (!deletionCleanup && logic > 400) {
    warnings.push(formatGovError({
      code: GOV_CODES.SIZE, message: `PR logic size ${logic} > 400 (recommended)`,
      actual: JSON.stringify({ logic, total }), policy: 'presubmit CHECK 2', fix: 'Consider splitting', blocking: false,
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

// Same shape as the .gitleaks.toml `alltrue-triage-token` rule (#4022); the bare prefix alone never matches.
const TRIAGE_TOKEN_RE = /(?:\d+\|)?alltrue_triage_[A-Za-z0-9]{40}(?![A-Za-z0-9])/;

function checkSecrets(errors) {
  let diff = '';
  try {
    diff = execFileSync('git', ['diff', '-U0', `${resolveBase()}...HEAD`], {
      cwd: ROOT, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024,
    });
  } catch { return; }
  for (const [re, label] of [[/AKIA[0-9A-Z]{16}/, 'AWS key'], [/-----BEGIN (RSA |OPENSSH )?PRIVATE KEY-----/, 'private key'], [/ghp_[A-Za-z0-9]{36}/, 'GitHub PAT'], [TRIAGE_TOKEN_RE, 'AllTrue triage token']]) {
    if (re.test(diff)) {
      errors.push(formatGovError({
        code: GOV_CODES.SECRET, message: `Possible secret in diff: ${label}`,
        actual: label, policy: 'Secret Scan', fix: 'Remove secret; rotate if pushed',
      }));
    }
  }
}

// New triage route names must be added to the same push's allowlist.
function triageAllowlistGaps(diff, allowlist) {
  if (!allowlist) return [];
  const lines = diff.split('\n').filter((line) => /^\+(?!\+\+)/.test(line));
  const gaps = [];
  for (const line of lines) {
    const name = line.match(/->name\('(triage\.[^']+)'\)/)?.[1];
    if (name && !allowlist.includes(`'${name}'`)) gaps.push(name);
  }
  return gaps;
}

function checkApiRouteReference(errors, warnings) {
  const generator = 'scripts/generate-ref-api-routes.sh';
  const policy = `${generator} --check (also runs in required CI)`;
  if (!fs.existsSync(path.join(ROOT, 'backend/vendor/autoload.php'))) {
    warnings.push(formatGovError({
      code: GOV_CODES.GENERATED, message: 'Local API route reference check deferred: backend/vendor/autoload.php is missing',
      policy, fix: 'Install the locked backend dependencies to run the exact check before pushing', blocking: false,
    }));
    return;
  }
  if (git(['diff', '--name-only', 'HEAD', '--', 'backend', 'docs/REF_API_ROUTES.md'])) {
    warnings.push(formatGovError({
      code: GOV_CODES.GENERATED, message: 'Local API route reference check deferred: uncommitted files differ from the pushed backend',
      policy, fix: 'Commit the route and generated reference together to check the exact files before pushing', blocking: false,
    }));
    return;
  }
  // Check Laravel's full table (method, URI, action and auth) with the same generator as CI.
  const result = spawnSync('bash', [generator, '--check'], {
    cwd: ROOT,
    env: { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DATABASE_URL: '' },
    encoding: 'utf8',
    timeout: 30_000,
  });
  if (result.status === 0) return;
  const stale = result.stderr?.includes('REF_API_ROUTES.md is stale');
  errors.push(formatGovError({
    code: GOV_CODES.GENERATED,
    message: stale ? 'docs/REF_API_ROUTES.md is stale for the pushed branch' : 'The API route reference generator could not complete',
    actual: result.error?.code ?? result.signal ?? `exit ${result.status}`,
    policy,
    fix: stale ? `Run bash ${generator} and commit the generated reference` : `Run bash ${generator} --check to inspect the error`,
  }));
}

function checkRouteRegistration(errors, warnings) {
  let diff;
  try {
    const range = `${resolveBase()}...HEAD`;
    diff = git(['diff', '-U0', range, '--', 'backend/routes/api.php']);
  } catch { return; }
  if (!diff) return;
  checkApiRouteReference(errors, warnings);
  let allowlist = '';
  try { allowlist = read('backend/config/triage_tokens.php'); } catch { /* absent in minimal fixture repos: skip the allowlist check */ }
  for (const gap of triageAllowlistGaps(diff, allowlist)) {
    errors.push(formatGovError({
      code: GOV_CODES.GENERATED,
      message: `Triage route ${gap} is not in allowed_routes (every token gets 403)`,
      actual: gap, policy: 'backend/config/triage_tokens.php',
      fix: `Add '${gap}' to allowed_routes in backend/config/triage_tokens.php`,
    }));
  }
}

async function main() {
  if (selfTest) {
    const added = "+    Route::get('x', [C::class, 'x'])->name('triage.x');";
    if (triageAllowlistGaps(added, "'triage.y'").join() !== 'triage.x') throw new Error('route gaps not detected');
    if (triageAllowlistGaps(added, "'triage.x',").length) throw new Error('registered route flagged');
    const edited = "-    Route::get('x', fn () => 'a');\n+    Route::get('x', fn () => 'b');";
    if (triageAllowlistGaps(edited, "'triage.y'").length) throw new Error('handler-only edit flagged');
    if (classifyStepFailure({ step: 'Branch naming', logSnippet: 'Invalid branch name' }).primary !== 'BRANCH_NAMING_POLICY') throw new Error('taxonomy');
    if (classifySameShaRerun({ earlierConclusion: 'failure', laterConclusion: 'success', headShaSame: true }).kind !== 'flake') throw new Error('flake');
    if (!validateBranchName('sec/x').ok || validateBranchName('agent/x').ok) throw new Error('branch');
    const issued = `12|${'alltrue_'}triage_${'Ab3'.repeat(13)}x`; // built at runtime: this file never holds a token
    if (!TRIAGE_TOKEN_RE.test(`+ token = "${issued}"`) || !TRIAGE_TOKEN_RE.test(issued.slice(3))) throw new Error('triage token not detected');
    if (TRIAGE_TOKEN_RE.test("'secret_prefix' => 'alltrue_triage_', see 1|alltrue_triage_forged")) throw new Error('triage prefix false positive');
    console.log('ci-preflight self-test: OK');
    return;
  }
  const errors = [];
  const warnings = [];
  if (process.argv.includes('--routes')) {
    checkRouteRegistration(errors, warnings);
    for (const e of errors) console.log(`FAIL  ${e.code}: ${e.message}\n      fix: ${e.fix}`);
    for (const w of warnings) console.log(`WARN  ${w.code}: ${w.message}\n      fix: ${w.fix}`);
    process.exit(errors.length ? 1 : 0);
  }
  checkRouteRegistration(errors, warnings);
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
