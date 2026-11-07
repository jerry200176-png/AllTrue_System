import assert from 'node:assert/strict';
import test from 'node:test';
import { autolandDecision } from '../../.github/scripts/autoland.mjs';

const req = ['A'];
const H = 'a'.repeat(40);
const run = (name, conclusion) => ({ name, conclusion, status: 'COMPLETED', startedAt: '1' });
const meta = (o = {}) => ({ labels: ['autoland'], isDraft: false, base: 'main', isCrossRepository: false, ...o });
const pr = (o = {}) => ({ mergeStateStatus: 'CLEAN', behindBy: 0, sha: H, rollup: [run('A', 'SUCCESS')], unresolvedThreads: 0, authorAssociation: 'OWNER', review: { ok: true, via: 'codex' }, ...o });

test('queues when checks, threads and review are green', () => {
  assert.deepEqual(autolandDecision(meta(), pr(), req), { action: 'queue', reason: 'all gates green' });
});
test('a BEHIND but otherwise green PR is queued (the queue does update-branch)', () => {
  assert.equal(autolandDecision(meta(), pr({ mergeStateStatus: 'BEHIND', behindBy: 3 }), req).action, 'queue');
});
test('waits on each unmet gate', () => {
  assert.equal(autolandDecision(meta(), pr({ rollup: [run('A', 'FAILURE')] }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ rollup: [] }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ unresolvedThreads: 1 }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ unresolvedThreads: null }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ review: { ok: false, why: 'R3 needs a security-data-reviewer marker' } }), req).reason, 'review-pending');
  assert.equal(autolandDecision(meta(), pr({ review: undefined }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ mergeStateStatus: 'DIRTY' }), req).action, 'wait');
  assert.equal(autolandDecision(meta(), pr({ mergeStateStatus: 'BLOCKED' }), req).action, 'wait');
});
test('skips without the label, when already queued, drafts, forks, other bases, untrusted authors', () => {
  assert.equal(autolandDecision(meta({ labels: [] }), pr(), req).action, 'skip');
  assert.equal(autolandDecision(meta({ labels: ['autoland', 'queue'] }), pr(), req).action, 'skip');
  assert.equal(autolandDecision(meta({ isDraft: true }), pr(), req).action, 'skip');
  assert.equal(autolandDecision(meta({ isCrossRepository: true }), pr(), req).action, 'skip');
  assert.equal(autolandDecision(meta({ base: 'dev' }), pr(), req).action, 'skip');
  assert.equal(autolandDecision(meta(), pr({ authorAssociation: 'NONE' }), req).action, 'skip');
});

test('workflow: least privilege, default-branch checkout, no deployment or approval surface', async () => {
  const fs = await import('node:fs');
  const wf = fs.readFileSync(new URL('../../.github/workflows/autoland.yml', import.meta.url), 'utf8');
  assert.match(wf, /^permissions:\n  contents: read\n/m);
  assert.doesNotMatch(wf, /deployments:|id-token:|environment:|actions: write|contents: write|\bgh (pr merge|api[^\n]*approv)/i);
  assert.match(wf, /ref: \$\{\{ github\.event\.repository\.default_branch \}\}/);
  assert.match(wf, /persist-credentials: false/);
  for (const ev of ['pull_request_target:', 'issue_comment:', 'pull_request_review:', 'check_suite:', 'schedule:', 'workflow_dispatch:']) assert.ok(wf.includes(`  ${ev}`), ev);
  assert.match(wf, /cron: "\*\/10 \* \* \* \*"/);
  const js = fs.readFileSync(new URL('../../.github/scripts/autoland.mjs', import.meta.url), 'utf8');
  assert.doesNotMatch(js, /gh\('pr', 'merge'|--squash|deployments|\/approve/); // label only
});
