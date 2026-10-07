import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../pages/LearningRecordsPage.vue', import.meta.url), 'utf8');

// issue 3760: after teacher submit, land on pending tab, clear unfilled priority, confirm toast.
assert.match(
  source,
  /teacherFilterTab\.value = 'pending'/,
  'teacher submit success must switch to 待審核 so the filled row stays visible',
);
assert.match(
  source,
  /teacherPriorityFilter\.value === 'unfilled'[\s\S]*teacherPriorityFilter\.value = 'all'/,
  'teacher submit success must clear 未填優先 so filled pending is not filtered out',
);
assert.match(
  source,
  /已送出，等待主任核准/,
  'teacher submit success must announce awaiting-director confirmation',
);

console.log('learning records submit visibility contract tests passed');
