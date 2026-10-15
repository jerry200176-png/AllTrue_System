import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { checkWorkflow } from './production-secret-triggers.mjs';

const KEY = '        env:\n          K: ${{ secrets.PI_SSH_KEY }}\n';
const wf = (on, secret = KEY) => `name: t\n${on}\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: x\n${secret}`;
const reasons = (on) => checkWorkflow(wf(on)).map((p) => `${p.event}: ${p.reason}`);

test('feature-branch push with production SSH key fails (classsession/attendance pattern)', () => {
  assert.match(reasons("on:\n  push:\n    branches:\n      - 'cursor/diag-classsession-**'\n  workflow_dispatch:")[0],
    /^push: branch filter includes non-main ref\(s\): cursor\/diag-classsession-\*\*/);
});

test('branches-ignore reaches non-main refs and fails', () => {
  assert.match(reasons('on:\n  push:\n    branches-ignore:\n      - main')[0], /^push: branches-ignore filter reaches non-main refs/);
});

test('pull_request fails whatever its branch filter (base-branch filter, head runs)', () => {
  for (const on of ['on:\n  pull_request:', 'on:\n  pull_request:\n    branches: ["main"]\n    types: [opened]',
    'on:\n  pull_request_target:\n    branches: [main]', 'on: [pull_request]']) {
    assert.equal(checkWorkflow(wf(on)).length, 1, on);
  }
});

test('unsafe or unreadable trigger shapes fail closed', () => {
  for (const on of [
    'on: push',
    'on: [push, workflow_dispatch]',
    'on:\n  - push\n  - workflow_dispatch',
    'on:\n  push:\n  workflow_dispatch:',
    'on:\n  push:\n    paths:\n      - a.md',
    'on:\n  push:\n    branches: [main]\n    tags: ["v*"]',
    'on:\n  push:\n    branches: [main, "release/*"]',
    'on:\n  push: { branches: [main] }',
    'on:\n  "push":\n    branches: [dev]',
    "on:\n  'pull_request':",
    'on: { push: { branches: [main] } }',
    'on:\n  push:\n    branches: main',
    'name-only: true',
  ]) {
    assert.ok(checkWorkflow(wf(on)).length >= 1, on);
  }
});

test('main-only push and non-ref triggers pass', () => {
  for (const on of [
    'on:\n  workflow_dispatch:\n  schedule:\n    - cron: "0 1 * * *"',
    "on:\n  push:\n    branches: [main]\n    paths:\n      - 'operations/x.request.md'\n  workflow_dispatch:",
    "on:\n  push:\n    branches:\n      - 'main'  # request file",
    "\"on\":\n  push:\n    branches: [main]",
    'on:\n  workflow_run:\n    workflows: [CI]\n    types: [completed]',
  ]) {
    assert.deepEqual(checkWorkflow(wf(on)), [], on);
  }
});

test('secret detection: case-insensitive, index form, toJSON(secrets), secrets: inherit', () => {
  const on = 'on:\n  push:\n    branches: [dev]';
  for (const s of ['${{ secrets.pi_ssh_key }}', "${{ secrets['PI_SSH_KEY'] }}", '${{ secrets[format(\'{0}\', inputs.k)] }}',
    '${{ toJSON(secrets) }}', '${{ secrets.PROD_DEPLOY_KEY }}']) {
    assert.equal(checkWorkflow(wf(on, `        env:\n          K: ${s}\n`)).length, 1, s);
  }
  const caller = `name: t\n${on}\njobs:\n  a:\n    uses: ./.github/workflows/x.yml\n    secrets: inherit\n`;
  assert.equal(checkWorkflow(caller).length, 1);
  assert.deepEqual(checkWorkflow(`${on}\njobs: {}\n# PI_SSH_KEY in prose only`), []);
});

test('pull_request workflow passes only when the secret lives in a schedule-only job', () => {
  const head = 'name: t\non:\n  pull_request:\n    branches: [main]\n  schedule:\n    - cron: "0 1 * * 1"\njobs:\n  check:\n    runs-on: ubuntu-latest\n    steps:\n      - run: x\n';
  const notify = (cond) => `  notify:\n    if: ${cond}\n    runs-on: ubuntu-latest\n    steps:\n      - run: y\n${KEY}`;
  assert.deepEqual(checkWorkflow(head + notify("${{ failure() && github.event_name == 'schedule' }}")), []);
  assert.equal(checkWorkflow(head + notify("${{ github.event_name == 'schedule' || always() }}")).length, 1);
  assert.equal(checkWorkflow(head.replace('      - run: x\n', `      - run: x\n${KEY}`) + notify("github.event_name == 'schedule'")).length, 1);
  assert.equal(checkWorkflow(head.replace('jobs:', `env:\n  K: \${{ secrets.PI_SSH_KEY }}\njobs:`) + notify("github.event_name == 'schedule'")).length, 1);
});

test('every committed workflow satisfies the rule', () => {
  const dir = '.github/workflows';
  for (const f of readdirSync(dir).filter((n) => /\.ya?ml$/.test(n))) {
    assert.deepEqual(checkWorkflow(readFileSync(`${dir}/${f}`, 'utf8')), [], f);
  }
});
