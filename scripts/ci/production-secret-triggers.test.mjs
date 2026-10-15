import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { checkWorkflow } from './production-secret-triggers.mjs';

const KEY = '        env:\n          K: ${{ secrets.PI_SSH_KEY }}\n';
const wf = (on) => `name: t\n${on}\njobs:\n  a:\n    runs-on: ubuntu-latest\n    steps:\n      - run: x\n${KEY}`;
const reasons = (on) => checkWorkflow(wf(on)).map((p) => `${p.event}: ${p.reason}`);

test('feature-branch push with production SSH key fails (the #classsession/attendance pattern)', () => {
  assert.deepEqual(reasons("on:\n  push:\n    branches:\n      - 'cursor/diag-classsession-**'\n  workflow_dispatch:"),
    ['push: branch filter includes non-main ref(s): cursor/diag-classsession-**']);
});

test('unsafe ref triggers fail', () => {
  for (const on of [
    'on: push',
    'on: [push, workflow_dispatch]',
    'on:\n  push:\n  workflow_dispatch:',
    'on:\n  push:\n    paths:\n      - a.md',
    'on:\n  push:\n    branches-ignore: [main]',
    'on:\n  push:\n    branches: [main]\n    tags: ["v*"]',
    'on:\n  push:\n    branches: [main, "release/*"]',
    'on:\n  pull_request:',
    'on:\n  pull_request:\n    branches: ["**"]',
    'on:\n  pull_request_target:\n    branches: [main]',
    'on:\n  push: { branches: [dev] }',
  ]) {
    assert.equal(checkWorkflow(wf(on)).length, 1, on);
  }
});

test('main-only and non-ref triggers pass', () => {
  for (const on of [
    'on:\n  workflow_dispatch:\n  schedule:\n    - cron: "0 1 * * *"',
    "on:\n  push:\n    branches: [main]\n    paths:\n      - 'operations/x.request.md'\n  workflow_dispatch:",
    "on:\n  push:\n    branches:\n      - 'main'",
    'on:\n  pull_request:\n    branches: ["main"]\n    types: [opened]',
    'on:\n  push: { branches: [main] }',
    'on:\n  workflow_run:\n    workflows: [CI]\n    types: [completed]',
  ]) {
    assert.deepEqual(checkWorkflow(wf(on)), [], on);
  }
});

test('workflows without production SSH secrets are out of scope', () => {
  assert.deepEqual(checkWorkflow('on: push\njobs: {}\n# mentions PI_SSH_KEY in prose only'), []);
});

test('every committed workflow satisfies the rule', () => {
  const dir = '.github/workflows';
  for (const f of readdirSync(dir).filter((n) => /\.ya?ml$/.test(n))) {
    assert.deepEqual(checkWorkflow(readFileSync(`${dir}/${f}`, 'utf8')), [], f);
  }
});
