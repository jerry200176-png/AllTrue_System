import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

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
  assert.equal(y.split('github.event.pull_request.number || inputs.pr_number').length - 1, 4);
  assert.match(y, /workflow_dispatch' && inputs\.pr_number != ''/);
});

test('queue dispatches CI on main once after a merge', () => {
  assert.match(queue, /ciForMainTip/);
  assert.match(queue, /'workflow', 'run', 'ci\.yml', '--repo', REPO, '--ref', 'main'/);
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
