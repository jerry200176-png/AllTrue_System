import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const queue = read('.github/scripts/land-queue.mjs');
const dispatched = [...new Set([...queue.matchAll(/'([a-z-]+\.yml)'/g)].map((m) => m[1]))]
  .filter((f) => fs.existsSync(path.join(root, '.github/workflows', f)) && f !== 'land-queue.yml');

// Required check contexts per dispatched workflow; names must not drift.
const jobNames = {
  'presubmit.yml': ['Presubmit Checks'],
  'ci.yml': ['Control Plane Contract Lint', 'Golden scenarios report', 'PHPUnit Feature & Unit Tests', 'Vite Frontend Build'],
  'docs-integrity.yml': ['Docs Integrity Check'],
  'secret-scan.yml': ['gitleaks scan'],
  'agent-provenance.yml': ['Agent Session Provenance'],
};

test('queue dispatches exactly the workflows covered here and passes pr_number', () => {
  assert.deepEqual(dispatched.sort(), Object.keys(jobNames).sort());
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
  assert.equal(y.split('github.event.pull_request.number || inputs.pr_number').length - 1, 3);
  assert.match(y, /workflow_dispatch' && inputs\.pr_number != ''/);
});

test('queue dispatches CI on main once after a merge', () => {
  assert.match(queue, /ciForMainTip/);
  assert.match(queue, /'workflow', 'run', 'ci\.yml', '--repo', REPO, '--ref', 'main'/);
});
