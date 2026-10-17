import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import {
  checkWriteGates, gatesForWorkflow, staleGateKeys, EXCEPTION_EVIDENCE, GATED_ENVIRONMENT, READ_ONLY_ENVIRONMENTS, WRITE_MARKERS,
} from './production-write-gate.mjs';

const KEY = '        env:\n          K: ${{ secrets.PI_SSH_KEY }}\n';
const job = (id, props = '', run = 'echo read') => `  ${id}:\n    runs-on: ubuntu-latest\n${props}    steps:\n      - run: ${run}\n${KEY}`;
const wf = (jobs, on = '  workflow_dispatch:\n') => `name: t\non:\n${on}jobs:\n${jobs.join('')}`;
const env = (name) => `    environment: ${name}\n`;
const RO = { writes: false, gate: 'production-ssh', evidence: 't.yml:1 read' };
const W = (gate, extra = {}) => ({ writes: true, gate, evidence: 't.yml:1 write', ...extra });
const ids = (text, gates) => checkWriteGates(text, gates).map((p) => p.job);

test('every secret-bearing job must be classified; jobs without the secret are ignored', () => {
  const plain = '  lint:\n    runs-on: ubuntu-latest\n    steps:\n      - run: x\n';
  assert.deepEqual(ids(wf([plain, job('a', env('production-ssh'))]), {}), ['a']);
  assert.deepEqual(ids(wf([plain, job('a', env('production-ssh'))]), { a: RO }), []);
});

test('read-only jobs: gate must match the environment; a write marker fails', () => {
  assert.deepEqual(ids(wf([job('a', env('production-ssh'))]), { a: { ...RO, gate: 'production-activation' } }), ['a']);
  assert.deepEqual(ids(wf([job('a', env('production-activation'))]), { a: { ...RO, gate: 'production-activation' } }), []);
  for (const run of ['php artisan migrate --force', 'ALLOW_PROD_REPAIR=1 php artisan x', 'php artisan y --apply',
    "mysql -e \"DROP DATABASE x\"", 'sed -i s/a/b/ .env', '$m->save();', 'BugReportService::addComment(1)', 'git reset --hard x']) {
    assert.deepEqual(ids(wf([job('a', env('production-ssh'), run)]), { a: RO }), ['a'], run);
  }
  for (const run of ['git merge-base --is-ancestor a b', "zgrep 'CREATE TABLE' f", 'Request::create("/x", "GET")']) {
    assert.deepEqual(ids(wf([job('a', env('production-ssh'), run)]), { a: RO }), [], run);
  }
});

test('writers must declare the gated environment constant', () => {
  assert.deepEqual(ids(wf([job('a', env('production-ssh'))]), { a: W(GATED_ENVIRONMENT) }), ['a']);
  assert.deepEqual(ids(wf([job('a', env(GATED_ENVIRONMENT))]), { a: W(GATED_ENVIRONMENT) }), []);
  assert.deepEqual(ids(wf([job('a', `    environment:\n      name: ${GATED_ENVIRONMENT}\n`)]), { a: W(GATED_ENVIRONMENT) }), []);
  assert.deepEqual(ids(wf([job('a', env('production-ssh'))]), { a: W('production-ssh') }), ['a']);
  assert.ok(READ_ONLY_ENVIRONMENTS.includes(GATED_ENVIRONMENT));
});

test('needs:<job> gate: same-file gate job on production-activation, needs listed, if requires success, no ||', () => {
  const gateJob = `  approve:\n    runs-on: ubuntu-latest\n    environment:\n      name: ${GATED_ENVIRONMENT}\n    steps:\n      - run: x\n`;
  const ok = `    needs: [resolve, approve]\n    if: \${{ always() && needs.approve.result == 'success' }}\n${env('production-ssh')}`;
  assert.deepEqual(ids(wf([gateJob, job('w', ok)]), { w: W('needs:approve') }), []);
  const blockNeeds = ok.replace('    needs: [resolve, approve]\n', '    needs:\n      - resolve\n      - approve\n');
  assert.deepEqual(ids(wf([gateJob, job('w', blockNeeds)]), { w: W('needs:approve') }), []);
  assert.deepEqual(ids(wf([gateJob, job('w', ok.replace("== 'success' }}", "== 'success' || true }}"))]), { w: W('needs:approve') }), ['w']);
  assert.deepEqual(ids(wf([gateJob, job('w', ok.replace('[resolve, approve]', '[resolve]'))]), { w: W('needs:approve') }), ['w']);
  assert.deepEqual(ids(wf([gateJob.replace(GATED_ENVIRONMENT, 'production-ssh'), job('w', ok)]), { w: W('needs:approve') }), ['w']);
  assert.deepEqual(ids(wf([job('w', ok)]), { w: W('needs:approve') }), ['w']);
});

test('founder-decision exceptions need a decision and every evidence field; scheduled ones need a schedule', () => {
  const text = wf([job('a', env('production-ssh'))]);
  const ev = Object.fromEntries(EXCEPTION_EVIDENCE.map((k) => [k, `${k} text`]));
  assert.deepEqual(ids(text, { a: W('founder-decision', ev) }), ['a']);
  assert.deepEqual(ids(text, { a: W('founder-decision', { decision: 'open: keep or gate' }) }), ['a']);
  assert.deepEqual(ids(text, { a: W('founder-decision', { decision: 'open: keep or gate', ...ev }) }), []);
  for (const k of EXCEPTION_EVIDENCE) {
    assert.deepEqual(ids(text, { a: W('founder-decision', { decision: 'd', ...ev, [k]: ' ' }) }), ['a'], k);
  }
  assert.deepEqual(ids(text, { a: W('founder-decision', { decision: 'd', ...ev, schedule: true }) }), ['a']);
  const scheduled = wf([job('a', env('production-ssh'))], "  schedule:\n    - cron: '0 1 * * *'\n  workflow_dispatch:\n");
  assert.deepEqual(ids(scheduled, { a: W('founder-decision', { decision: 'd', ...ev, schedule: true }) }), []);
  assert.deepEqual(ids(text, { a: W('anything') }), ['a']);
});

test('a scheduled writer needs no exception when it waits for the gated environment', () => {
  const scheduled = (e) => wf([job('a', env(e))], "  schedule:\n    - cron: '0 2 1 * *'\n  workflow_dispatch:\n");
  assert.deepEqual(ids(scheduled(GATED_ENVIRONMENT), { a: W(GATED_ENVIRONMENT) }), []);
  assert.deepEqual(ids(scheduled('production-ssh'), { a: W(GATED_ENVIRONMENT) }), ['a']);
});

test('evidence must cite file:line and writes must be boolean', () => {
  const text = wf([job('a', env('production-ssh'))]);
  assert.deepEqual(ids(text, { a: { ...RO, evidence: 'trust me' } }), ['a']);
  assert.deepEqual(ids(text, { a: { ...RO, writes: 'no' } }), ['a']);
});

test('stale entries fail: job gone from the file, or file gone', () => {
  assert.deepEqual(ids(wf([job('a', env('production-ssh'))]), { a: RO, gone: RO }), ['gone']);
  const inventory = { job_gates: { 'x.yml:a': RO, 'deleted.yml:b': RO } };
  assert.deepEqual(gatesForWorkflow(inventory, 'x.yml'), { a: RO });
  assert.deepEqual(staleGateKeys(inventory, ['x.yml']), ['deleted.yml:b']);
});

test('write markers are a non-empty list of regular expressions', () => {
  assert.ok(WRITE_MARKERS.length > 5 && WRITE_MARKERS.every((re) => re instanceof RegExp));
});

test('every committed workflow satisfies the rule against the committed inventory', () => {
  const inventory = JSON.parse(readFileSync('docs/governance/PRODUCTION_WORKFLOW_INVENTORY.json', 'utf8'));
  const dir = '.github/workflows';
  const files = readdirSync(dir).filter((n) => /\.ya?ml$/.test(n));
  assert.deepEqual(staleGateKeys(inventory, files), []);
  for (const f of files) {
    assert.deepEqual(checkWriteGates(readFileSync(`${dir}/${f}`, 'utf8'), gatesForWorkflow(inventory, f)), [], f);
  }
});
