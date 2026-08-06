import assert from 'node:assert/strict';
import { resolveTeacherAliasIds, courseBelongsToTeacherAlias } from './teacherAliasMatch.js';

// 沒有 alias_ids（一般情況：一位老師一個帳號）→ 退回自己的 ID
assert.deepEqual(
  resolveTeacherAliasIds(null, 73),
  [73],
  'no teacher entry → fallback to the single teacherId',
);
assert.deepEqual(
  resolveTeacherAliasIds({ id: 73, alias_ids: [] }, 73),
  [73],
  'empty alias_ids → fallback to the single teacherId',
);

// 有 alias_ids（同一人掛兩個帳號，UI 合併顯示成一欄）→ 回傳完整別名集合
assert.deepEqual(
  resolveTeacherAliasIds({ id: 73, alias_ids: [73, 260] }, 73),
  [73, 260],
  'merged teacher entry → both alias account IDs',
);

// in-app #219/#223 regression: 課程掛在「輸家」帳號（260）身上，
// 欄位代表 ID 是「贏家」帳號（73，課程數較多），比對別名集合仍要能命中。
const aliasIds = resolveTeacherAliasIds({ id: 73, alias_ids: [73, 260] }, 73);
const courseOnLosingAlias = { id: 3153, teacher_id: 260 };
assert.equal(
  courseBelongsToTeacherAlias(courseOnLosingAlias, aliasIds),
  true,
  'a course tagged with the losing alias account must still match the merged teacher column',
);

const courseOnUnrelatedTeacher = { id: 9999, teacher_id: 999 };
assert.equal(
  courseBelongsToTeacherAlias(courseOnUnrelatedTeacher, aliasIds),
  false,
  'a course for a different teacher must not match',
);

// 接受 Set 或 array 兩種輸入
assert.equal(
  courseBelongsToTeacherAlias(courseOnLosingAlias, new Set(aliasIds)),
  true,
  'accepts a pre-built Set as well as an array',
);

console.log('teacherAliasMatch.test.js — 6 passed ✅');
