import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const workflows = [
  '.github/workflows/bug-phase-a-triage.yml',
  '.github/workflows/bug-followup-comment.yml',
];
const strictUrlCheck = '[["$GITHUB_ISSUE_URL"=~^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$]]';

for (const workflow of workflows) {
  const file = fileURLToPath(new URL(`../../${workflow}`, import.meta.url));
  const text = fs.readFileSync(file, 'utf8').replaceAll(' ', '').replaceAll('\n', '');
  assert.ok(text.includes(strictUrlCheck), `${workflow} must use strict issue URL validation`);
  assert.ok(!text.includes('==https://github.com/*/issues/*'), `${workflow} must not use wildcard URL validation`);
}

console.log('bug-writeback-workflow.test.mjs: ok');
