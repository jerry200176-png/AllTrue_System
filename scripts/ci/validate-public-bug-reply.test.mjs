import assert from 'node:assert/strict';

import { validatePublicBugReply } from './validate-public-bug-reply.mjs';

assert.deepEqual(
  validatePublicBugReply('我們已收到回報，會持續確認畫面與資料是否一致。'),
  [],
  'plain-language reply must pass',
);

const issues = validatePublicBugReply(
  '我們已修正 PR #2198 的 StudentClassController，StudentClass 內容請查看 scripts/fix.mjs。',
);
assert.deepEqual(
  issues.map(({ id }) => id),
  ['pr_issue', 'class_service', 'pascal_ident', 'file_ext'],
  'internal implementation details must fail closed',
);

assert.deepEqual(
  validatePublicBugReply(''),
  [{ id: 'empty', hint: '空白文案' }],
  'empty reply must fail closed',
);

console.log('validate-public-bug-reply.test.mjs: ok');
