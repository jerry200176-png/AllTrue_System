import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const wf = fs.readFileSync(new URL('../../.github/workflows/phpunit-random-nightly.yml', import.meta.url), 'utf8');
const ci = fs.readFileSync(new URL('../../.github/workflows/ci.yml', import.meta.url), 'utf8');

test('nightly random-order run: random order with a printed, replayable seed; never a PR trigger', () => {
  assert.match(wf, /--order-by=random --random-order-seed="\$SEED"/);
  assert.match(wf, /Random order seed: \$SEED/);
  assert.match(wf, /random\.Random\(int\(os\.environ\["SEED"\]\)\)\.shuffle\(files\)/);
  assert.match(wf, /on:\n  schedule:[\s\S]*workflow_dispatch:/);
  assert.doesNotMatch(wf.split('\njobs:')[0], /pull_request|push:/);
  assert.match(wf, /\^\[0-9\]\{1,10\}\$/); // replay input is validated
});

test('failure issue: one issue, updated by comment, issues:write only on the report job', () => {
  assert.match(wf, /Nightly random-order PHPUnit failure/);
  assert.match(wf, /gh issue comment[\s\S]*gh issue create/);
  assert.match(wf, /\npermissions:\n  contents: read\n/);
  assert.equal((wf.match(/issues: write/g) || []).length, 1);
  assert.match(wf, /report:[\s\S]*needs\.phpunit_random\.result == 'failure'/);
});

test('the PR CI stays untouched by W8: ci.yml has no random ordering', () => {
  assert.doesNotMatch(ci, /order-by=random/);
});
