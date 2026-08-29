import assert from 'node:assert/strict';
import fs from 'node:fs';

import {
  EXPECTED_PHASE_C_REPLY_COUNT,
  extractPhaseCPublicReplies,
  validatePhaseCPublicReplies,
} from './validate-phase-c-public-replies.mjs';

const source = fs.readFileSync(new URL('../../.github/workflows/bug-phase-c-allowlist.yml', import.meta.url), 'utf8');
const replies = extractPhaseCPublicReplies(source);
assert.equal(replies.length, EXPECTED_PHASE_C_REPLY_COUNT, 'Phase-C allowlist reply count must remain explicit');
assert.ok(replies.every(({ line, text }) => line > 0 && text.length > 0));
assert.ok(source.indexOf('Validate Phase-C public replies') < source.indexOf('Setup SSH'));

const sample = validatePhaseCPublicReplies('  "reply" => "已完成修正。",\n  "reply" => "已修正 PR #2198。",\n');
assert.equal(sample.replyCount, 2);
assert.deepEqual(sample.issues.map(({ id }) => id), ['pr_issue']);

console.log('validate-phase-c-public-replies.test.mjs: ok');
