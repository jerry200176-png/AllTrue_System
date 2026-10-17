import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { checkJobEnvironments, DOCUMENTED_EXCEPTIONS, REQUIRED_ENVIRONMENT } from './production-ssh-environment.mjs';

const KEY = '        env:\n          K: ${{ secrets.PI_SSH_KEY }}\n';
const job = (id, props = '', secret = KEY) => `  ${id}:\n    runs-on: ubuntu-latest\n${props}    steps:\n      - run: x\n${secret}`;
const wf = (...jobs) => `name: t\non:\n  workflow_dispatch:\njobs:\n${jobs.join('')}`;
const ids = (text) => checkJobEnvironments(text).map((p) => p.job);

test('secret-bearing job without environment fails, with production-ssh passes', () => {
  assert.deepEqual(ids(wf(job('a'))), ['a']);
  assert.deepEqual(ids(wf(job('a', `    environment: ${REQUIRED_ENVIRONMENT}\n`))), []);
  assert.deepEqual(ids(wf(job('a', `    environment:\n      name: '${REQUIRED_ENVIRONMENT}'\n`))), []);
  assert.deepEqual(ids(wf(job('a', `    environment: { name: ${REQUIRED_ENVIRONMENT}, url: x }\n`))), []);
});

test('the required name comes from the exported constant, not a copy', () => {
  assert.deepEqual(ids(wf(job('a', '    environment: production-ssh-other\n'))), ['a']);
  for (const env of DOCUMENTED_EXCEPTIONS) assert.deepEqual(ids(wf(job('a', `    environment: ${env}\n`))), [], env);
});

test('other or unprovable environments fail', () => {
  for (const props of ['    environment: staging\n', '    environment: ${{ inputs.env }}\n', '    environment:\n      url: x\n']) {
    assert.deepEqual(ids(wf(job('a', props))), ['a'], props);
  }
});

test('environment inside a step does not count', () => {
  const secret = '        with:\n          environment: production-ssh\n' + KEY;
  assert.deepEqual(ids(wf(job('a', '', secret))), ['a']);
});

test('jobs without the secret need no environment; each secret job is judged alone', () => {
  assert.deepEqual(ids(wf(job('lint', '', ''), job('ssh'), job('ok', '    environment: production-ssh\n'))), ['ssh']);
});

test('secret detection: case-insensitive, PI_HOST/PI_USER, index form, dynamic, toJSON', () => {
  for (const s of ['${{ secrets.pi_ssh_key }}', '${{ secrets.PI_HOST }}', '${{ secrets.PI_USER }}', "${{ secrets['PI_HOST_KEY'] }}",
    "${{ secrets[format('{0}', inputs.k)] }}", '${{ toJSON(secrets) }}', '${{ secrets.PROD_DEPLOY_KEY }}']) {
    assert.deepEqual(ids(wf(job('a', '', `        env:\n          K: ${s}\n`))), ['a'], s);
  }
  assert.deepEqual(ids(`on: push\njobs: {}\n# PI_SSH_KEY in prose only`), []);
});

test('secret detection: whitespace inside ${{ }} (#4147 review)', () => {
  for (const s of ['${{ secrets . PI_SSH_KEY }}', '${{ secrets. PI_USER }}', "${{ secrets [ 'PI_SSH_KEY' ] }}",
    '${{ secrets [ inputs.k ] }}', '${{ toJSON ( secrets ) }}', '${{ Secrets . PROD_DEPLOY_KEY }}',
    "${{ join(secrets.*, ',') }}", "${{ join(secrets . *, ',') }}"]) {
    assert.deepEqual(ids(wf(job('a', '', `        env:\n          K: ${s}\n`))), ['a'], s);
    assert.deepEqual(ids(wf(job('a', `    environment: ${REQUIRED_ENVIRONMENT}\n`, `        env:\n          K: ${s}\n`))), [], s);
  }
});

test('quoted job keys and trailing comments are separate jobs', () => {
  const text = wf(job('a', '    environment: production-ssh\n'), job('"b"').replace('"b":', '"b":  # note'));
  assert.deepEqual(ids(text), ['b']);
});

test('secret-bearing workflows with unreadable structure fail closed', () => {
  assert.equal(checkJobEnvironments(`on: push\nenv:\n  K: \${{ secrets.PI_SSH_KEY }}\n`).length, 1);
  assert.equal(checkJobEnvironments(`on: push\nenv:\n  K: \${{ secrets.PI_SSH_KEY }}\njobs:\n${job('a', '    environment: production-ssh\n', '')}`).length, 1);
  assert.equal(checkJobEnvironments(`on: push\njobs: { a: { env: { K: \${{ secrets.PI_SSH_KEY }} } } }\n`).length, 1);
  const anchored = wf(job('a', '    environment: production-ssh\n', '        env: &e\n          K: ${{ secrets.PI_SSH_KEY }}\n'),
    job('b', '', '        env: *e\n'));
  assert.equal(checkJobEnvironments(anchored).length, 1);
});

test('reusable workflows: local caller with secrets: inherit passes, others fail', () => {
  const caller = (uses, secrets) => `on: push\njobs:\n  call:\n    uses: ${uses}\n    secrets: ${secrets}\n`;
  assert.deepEqual(ids(caller('./.github/workflows/x.yml', 'inherit')), []);
  assert.deepEqual(ids(caller('other/repo/.github/workflows/x.yml@v1', 'inherit')), ['call']);
  assert.deepEqual(ids(`on: push\njobs:\n  call:\n    uses: ./.github/workflows/x.yml\n    secrets:\n      K: \${{ secrets.PI_SSH_KEY }}\n`), ['call']);
});

test('every committed workflow satisfies the rule', () => {
  const dir = '.github/workflows';
  for (const f of readdirSync(dir).filter((n) => /\.ya?ml$/.test(n))) {
    assert.deepEqual(checkJobEnvironments(readFileSync(`${dir}/${f}`, 'utf8')), [], f);
  }
});
