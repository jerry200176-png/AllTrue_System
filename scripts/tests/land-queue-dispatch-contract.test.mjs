import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { ciForMainTip } from '../../.github/scripts/land-queue.mjs';

const root = path.resolve(import.meta.dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const queue = read('.github/scripts/land-queue.mjs');
const dispatched = [...new Set([...queue.matchAll(/'([a-z-]+\.yml)'/g)].map((m) => m[1]))]
  .filter((f) => fs.existsSync(path.join(root, '.github/workflows', f)) && f !== 'land-queue.yml');
// Dispatched only on batch branches (no pr_number); PRs run it natively.
const batchOnly = { 'codeql.yml': 'PHPStan Advisory (php)' };

// Required check contexts per dispatched workflow; names must not drift.
const jobNames = {
  'presubmit.yml': ['Presubmit Checks'],
  'ci.yml': ['Control Plane Contract Lint', 'Golden scenarios report', 'PHPUnit Feature & Unit Tests', 'Vite Frontend Build'],
  'docs-integrity.yml': ['Docs Integrity Check'],
  'secret-scan.yml': ['gitleaks scan'],
  'agent-provenance.yml': ['Agent Session Provenance'],
};

test('queue dispatches exactly the workflows covered here and passes pr_number', () => {
  assert.deepEqual(dispatched.sort(), [...Object.keys(jobNames), ...Object.keys(batchOnly)].sort());
  assert.match(queue, /'-f', `pr_number=\$\{n\}`/);
});

for (const [wf, names] of Object.entries(jobNames)) {
  test(`${wf}: pr_number input, head-SHA guard, PR-keyed concurrency, stable job names`, () => {
    const y = read(`.github/workflows/${wf}`);
    assert.match(y, /workflow_dispatch:\n    inputs:\n      pr_number:/);
    assert.match(y, /concurrency:\n  group: .*github\.event\.pull_request\.number \|\| inputs\.pr_number \|\| github\.ref/);
    assert.match(y, /\$GITHUB_SHA" \] && \[ "\$base" = main \]/);
    assert.match(y, /exit 1; \}/);
    for (const n of names) assert.ok(y.includes(`name: ${n}\n`), `${wf} lost job name ${n}`);
  });
}

test('presubmit reads PR number and runs the declaration gate under dispatch', () => {
  const y = read('.github/workflows/presubmit.yml');
  // concurrency + declaration body + CHECK 0d + CHECK 4A (labels for release-note-none) + CHECK 4B
  assert.equal(y.split('github.event.pull_request.number || inputs.pr_number').length - 1, 5);
  assert.match(y, /workflow_dispatch' && inputs\.pr_number != ''/);
  assert.match(y, /PR_LABELS="\$\(gh api "repos\/\$\{REPO\}\/pulls\/\$\{PR_NUMBER\}" --jq '\.labels\[\]\.name'\)"\n\s+fi\n\s+export PR_LABELS\n\s+node scripts\/check-release-notes-coverage\.mjs --base origin\/main --head HEAD/);
});

test('queue dispatches CI on main once after a merge', () => {
  assert.match(queue, /ciForMainTip/);
  assert.match(queue, /'workflow', 'run', 'ci\.yml', '--repo', REPO, '--ref', 'main'/);
});

const tip = 'a'.repeat(40);
const ciRun = (extra = {}) => ({ id: 10, head_sha: tip, head_branch: 'main', path: '.github/workflows/ci.yml', event: 'push', status: 'queued', conclusion: null, ...extra });
function mainCiStub(pages, { failAt = -1, moved = false } = {}) {
  const calls = [], waits = [], messages = [];
  let reads = 0, refs = 0, dispatches = 0;
  return {
    calls, waits, messages, dispatched: () => dispatches,
    run: () => ciForMainTip({
      readApi: (query) => {
        calls.push(query);
        if (query === 'git/ref/heads/main') return { object: { sha: moved && refs++ > 0 ? 'b'.repeat(40) : tip } };
        assert.equal(query, `actions/workflows/ci.yml/runs?branch=main&head_sha=${tip}&per_page=100`);
        if (reads === failAt) throw new Error('GitHub API unavailable');
        const runs = pages[Math.min(reads++, pages.length - 1)];
        return Array.isArray(runs) ? { total_count: runs.length, workflow_runs: runs } : runs;
      },
      wait: async (ms) => waits.push(ms), log: (message) => messages.push(message),
      dispatch: () => { dispatches += 1; },
    }),
  };
}

test('main CI: visible push/dispatch pending, running or successful exact-tip CI suppresses fallback', async () => {
  for (const event of ['push', 'workflow_dispatch']) {
    for (const status of ['queued', 'pending', 'waiting', 'requested', 'in_progress', 'completed']) {
      const stub = mainCiStub([[ciRun({ event, status, conclusion: status === 'completed' ? 'success' : null })]]);
      assert.deepEqual(await stub.run(), { action: 'reuse', sha: tip, runId: 10 });
      assert.equal(stub.dispatched(), 0);
      assert.deepEqual(stub.waits, []);
      assert.match(stub.messages[0], /reusing ci.yml run #10/);
    }
  }
});

test('main CI: delayed push visibility is read again before dispatch', async () => {
  const stub = mainCiStub([[], [], [ciRun()]]);
  assert.equal((await stub.run()).action, 'reuse');
  assert.deepEqual(stub.waits, [5000, 10000]);
  assert.equal(stub.dispatched(), 0);
});

test('main CI: a reused run cannot cover a main tip that moved during lookup', async () => {
  const stub = mainCiStub([[ciRun()]], { moved: true });
  await assert.rejects(stub.run, /main moved during CI lookup/);
  assert.equal(stub.dispatched(), 0);
});

test('main CI: token-made merge with no push dispatches exactly once after bounded reads', async () => {
  const stub = mainCiStub([[]]);
  assert.deepEqual(await stub.run(), { action: 'dispatch', sha: tip });
  assert.equal(stub.dispatched(), 1);
  assert.equal(stub.calls.filter((query) => query.includes('actions/workflows/ci.yml/runs')).length, 3);
  assert.equal(stub.calls.filter((query) => query === 'git/ref/heads/main').length, 2);
  assert.deepEqual(stub.waits, [5000, 10000]);
});

test('main CI: failed/cancelled history, other SHAs, workflows, branches and PR events do not suppress necessary CI', async () => {
  for (const run of [
    ciRun({ status: 'completed', conclusion: 'failure' }),
    ciRun({ status: 'completed', conclusion: 'cancelled' }),
    ciRun({ head_sha: 'b'.repeat(40) }), ciRun({ path: '.github/workflows/codeql.yml' }),
    ciRun({ head_branch: 'feature' }), ciRun({ event: 'pull_request' }),
  ]) {
    const stub = mainCiStub([[run]]);
    assert.equal((await stub.run()).action, 'dispatch');
    assert.equal(stub.dispatched(), 1);
  }
});

test('main CI: API errors, partial/unknown responses and main movement block dispatch with a clear reason', async () => {
  for (const failAt of [0, 1, 2]) {
    const stub = mainCiStub([[]], { failAt });
    await assert.rejects(stub.run, /main CI fallback blocked before dispatch.*GitHub API unavailable/);
    assert.equal(stub.dispatched(), 0);
  }
  for (const data of [null, {}, { total_count: 1, workflow_runs: [] }, { total_count: 0, workflow_runs: [], incomplete_results: true }, [ciRun({ status: 'unknown' })]]) {
    const stub = mainCiStub([data]);
    await assert.rejects(stub.run, /main CI fallback blocked before dispatch/);
    assert.equal(stub.dispatched(), 0);
  }
  const stub = mainCiStub([[]], { moved: true });
  await assert.rejects(stub.run, /main moved during CI lookup/);
  assert.equal(stub.dispatched(), 0);
});

test('main CI: initial ref failure blocks dispatch and an ambiguous dispatch failure is never retried', async () => {
  let writes = 0;
  await assert.rejects(() => ciForMainTip({ readApi: () => { throw new Error('ref lookup failed'); }, dispatch: () => { writes += 1; } }), /blocked before dispatch.*ref lookup failed/);
  assert.equal(writes, 0);
  await assert.rejects(() => ciForMainTip({
    readApi: (query) => query === 'git/ref/heads/main' ? { object: { sha: tip } } : { total_count: 0, workflow_runs: [] },
    wait: async () => {}, log: () => {}, dispatch: () => { writes += 1; throw new Error('network interrupted'); },
  }), /dispatch outcome unknown.*inspect existing runs before retry/);
  assert.equal(writes, 1);
});

test('batch-only workflows keep their required job name and are dispatchable without inputs', () => {
  for (const [wf, name] of Object.entries(batchOnly)) {
    const y = read(`.github/workflows/${wf}`);
    assert.match(y, /\n  workflow_dispatch:/);
    assert.ok(y.includes(`name: ${name}\n`), `${wf} lost job name ${name}`);
  }
  assert.match(queue, /BATCH_ONLY_WORKFLOWS = \['codeql\.yml'\]/);
});

test('every context the batch must prove is produced by a dispatched workflow', () => {
  const produced = new Set([...Object.values(jobNames).flat(), ...Object.values(batchOnly)]);
  // the live main ruleset's required contexts at the time of writing
  for (const c of ['Presubmit Checks', 'PHPStan Advisory (php)', 'PHPUnit Feature & Unit Tests', 'Vite Frontend Build', 'Docs Integrity Check', 'gitleaks scan', 'Golden scenarios report', 'Control Plane Contract Lint', 'Agent Session Provenance']) {
    assert.ok(produced.has(c), `no dispatched workflow produces ${c}`);
  }
});

test('queue never uses --admin and lands a batch only from the green verdict', () => {
  assert.doesNotMatch(queue, /--admin/);
  assert.equal(queue.split('landBatch(').length - 1, 2); // definition + the single call
  assert.match(queue, /else if \(v === 'land'\) landBatch\(/);
});

test('script never checks out or runs PR code, and each squash is pinned to the tested head', () => {
  assert.doesNotMatch(queue, /\bcheckout\b|execFileSync\('(?:node|bash|sh|npm)'/);
  assert.match(queue, /'--match-head-commit', sha/);
  assert.match(queue, /guardedSquash\(m\.n, m\.sha, /); // sha pinned at batch creation, never re-read from the PR
  assert.match(queue, /TRUSTED_AUTHORS\.has\(pr\.authorAssociation\)/);
});

test('author association is read via GraphQL (gh pr list has no such JSON field)', () => {
  const list = queue.match(/'pr', 'list'[^\n]*/)[0];
  assert.doesNotMatch(list, /authorAssociation/);
  assert.match(queue, /headRefOid authorAssociation/);
});

test('required-check pins come from the live ruleset, not a hardcoded app id', () => {
  assert.match(queue, /PINS = new Map\(checks\.map\(\(c\) => \[c\.context, c\.integration_id\]\)\)/);
  assert.doesNotMatch(queue.replace(/ACTIONS_APP_ID = 15368/, ''), /15368/);
});

test('missing check workflows are dispatched per workflow, not only when the rollup is empty', () => {
  assert.match(queue, /actions\/workflows\/\$\{wf\}\/runs\?head_sha=/);
  assert.doesNotMatch(queue, /if \(pr\.rollup\.length\) return/);
});

test('each batch squash is verified against the tested tree right after it lands', () => {
  assert.match(queue, /tree\(expect\) !== m\.tree/);
});

test('batch branch is cut from an empty child of main so every member is a real two-parent merge (no fast-forward)', () => {
  assert.match(queue, /BATCH_BASE_MESSAGE = 'land-queue batch base'/);
  assert.match(queue, /git\/commits`, '-f', `message=\$\{BATCH_BASE_MESSAGE\}`/);
  assert.match(queue, /ref=refs\/heads\/\$\{name\}`, '-f', `sha=\$\{root\}`/);
  assert.match(queue, /base: c\.parents\[0\]\?\.sha \?\? sha/);
});

test('Golden scenarios report runs on dispatched batch branches (no pr_number) and the queue filters owned refs', () => {
  const ci = read('.github/workflows/ci.yml');
  const golden = ci.slice(ci.indexOf('  golden_scenarios:'), ci.indexOf('\n  phpunit:'));
  assert.match(golden, /startsWith\(github\.ref, 'refs\/heads\/chore\/land-queue-batch-'\)/);
  assert.match(queue, /BATCH_PREFIX = 'chore\/land-queue-batch-'/);
  assert.match(queue, /matching-refs\/heads\/\$\{BATCH_PREFIX\}`\)\.filter\(/);
  assert.match(queue, /isBatchBase\(/);
});

test('presubmit skips only the per-PR gates on dispatched land-queue batch branches', () => {
  const y = read('.github/workflows/presubmit.yml');
  const detect = y.slice(y.indexOf('- name: Detect land-queue batch run'), y.indexOf('- name: PR dispatch guard'));
  assert.match(detect, /inputs\.pr_number == ''/); // a dispatched real PR (land-queue re-check) keeps every gate
  assert.match(detect, /startsWith\(github\.ref, 'refs\/heads\/chore\/land-queue-batch-'\)/);
  assert.match(detect, /commits\/\$\{GITHUB_SHA\}\/pulls/); // an open PR head never skips
  assert.match(detect, /LQ_BATCH=true/);
  const steps = y.split(/\n      - name: /).slice(1);
  const skipped = steps.filter((s) => s.includes("env.LQ_BATCH != 'true'")).map((s) => s.split('\n')[0]);
  assert.deepEqual(skipped.map((n) => /\[CHECK (\w+)\]/.exec(n)?.[1]).sort(), ['0d', '2', '4B']);
});

test('every squash goes through guardedSquash (fresh thread + final-head review re-read right before merging)', () => {
  assert.equal([...queue.matchAll(/\bsquash\(/g)].length, 0, 'call squash only via guardedSquash(n, sha, unresolvedThreads, readReview, squash)');
  const guarded = [...queue.matchAll(/guardedSquash\([^)]*unresolvedThreads, readReview, squash\)/g)].length;
  assert.equal(guarded, 2, 'serial path (processQueue) and batch path (landBatch) both re-check threads and review');
});

test('retired Agent Session Provenance stays a pass-through until the ruleset drops it', () => {
  // Founder 2026-10-14（三）#6 retired the manifest check, but the main ruleset still
  // requires the context, so the job must keep its name and must not run the deleted script.
  const wf = read('.github/workflows/agent-provenance.yml');
  assert.match(wf, /name: Agent Session Provenance/);
  assert.doesNotMatch(wf, /check-agent-provenance|\bif: github\.event\.pull_request\.user/);
});
