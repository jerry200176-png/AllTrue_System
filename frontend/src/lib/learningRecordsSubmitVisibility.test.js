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
  /teacherFilterTab\.value = 'pending'[\s\S]*teacherPriorityFilter\.value = 'all'[\s\S]*feedbackFilter\.value = 'all'[\s\S]*filters\.subject = ''/,
  'teacher submit success must clear priority, feedback, and subject filters so filled pending is not filtered out',
);
assert.match(
  source,
  /lr-download-toast--page[\s\S]*z-index:\s*14000/,
  'page-level download/submit toast must stack above the learning-record modal overlay',
);
assert.match(
  source,
  /已送出，等待主任核准/,
  'teacher submit success must announce awaiting-director confirmation',
);
assert.match(
  source,
  /lr-download-toast--page/,
  'submit toast must render at page level outside the closed modal',
);
assert.match(
  source,
  /recordHasBody:\s*!!\(statusSource && hasLearningRecordBody\(statusSource\)\)/,
  'schedule status fallback must require a ClassSession-bound row with body',
);

console.log('learning records submit visibility contract tests passed');
